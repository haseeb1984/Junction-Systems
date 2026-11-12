<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\WooCommerce;
use JunctionStore\Container\ServiceContainer;

final class ShippingRegistry {
    public function __construct(private readonly ServiceContainer $container) {}

    public function register(): void {
        add_filter('woocommerce_shipping_methods', function(array $methods): array {
            $methods['pkstore_tcs']      = \JunctionStore\Infrastructure\WooCommerce\Shipping\TcsShippingMethod::class;
            $methods['pkstore_leopards'] = \JunctionStore\Infrastructure\WooCommerce\Shipping\LeopardsShippingMethod::class;
            $methods['pkstore_trax']     = \JunctionStore\Infrastructure\WooCommerce\Shipping\TraxShippingMethod::class;
            return $methods;
        });
        add_action('woocommerce_payment_complete',             [$this, 'autoBookShipment']);
        add_filter('woocommerce_available_payment_gateways',   [$this, 'filterGatewaysForDigital']);
        add_action('pkstore_tracking_sync_cron',               [$this, 'syncTrackingUpdates']);
    }

    public function autoBookShipment(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) return;
        if ($this->isDigitalOnlyOrder($order)) {
            $order->add_order_note('[Junction Store] Digital order — courier booking skipped. Download links sent via email.');
            return;
        }
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time()+10,'pkstore_retry_courier_booking',['order_id'=>$orderId],'pkstore-logistics');
        }
    }

    public function filterGatewaysForDigital(array $gateways): array {
        if (!is_checkout() || !WC()->cart) return $gateways;
        foreach (WC()->cart->get_cart() as $item) {
            $p = $item['data'] ?? null;
            if ($p instanceof \WC_Product && !$p->is_virtual() && !$p->is_downloadable()) return $gateways;
        }
        unset($gateways['cod']); // All digital — remove CoD
        return $gateways;
    }

    public function syncTrackingUpdates(): void {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.post_type='shop_order' AND p.post_status IN ('wc-shipped','wc-out-for-delivery') LIMIT 100");
        if (empty($ids)) return;
        foreach ($ids as $orderId) {
            $order   = wc_get_order((int)$orderId);
            $tn      = $order ? (string)$order->get_meta('_tracking_courier') : '';
            $courier = $order ? (string)$order->get_meta('_booked_courier')  : '';
            if (empty($tn) || empty($courier) || str_starts_with($tn,'PENDING-')) continue;
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time(),'pkstore_update_tracking',['order_id'=>(int)$orderId,'tracking'=>$tn,'courier'=>$courier],'pkstore-logistics');
            }
        }
    }

    private function isDigitalOnlyOrder(\WC_Order $order): bool {
        foreach ($order->get_items() as $item) {
            $p = $item->get_product();
            if ($p instanceof \WC_Product && !$p->is_virtual() && !$p->is_downloadable()) return false;
        }
        return true;
    }
}
