<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;

final class ShippingRegistry {
    public function __construct(private readonly ServiceContainer $container, private readonly FeatureManager $features) {}

    public function register(): void {
        add_filter('woocommerce_shipping_methods', function(array $methods): array {
            if ($this->features->isEnabled('courier_tcs')) {
                $methods['pkstore_tcs'] = \PkStore\Infrastructure\WooCommerce\Shipping\TcsShippingMethod::class;
            }
            if ($this->features->isEnabled('courier_leopards')) {
                $methods['pkstore_leopards'] = \PkStore\Infrastructure\WooCommerce\Shipping\LeopardsShippingMethod::class;
            }
            if ($this->features->isEnabled('courier_trax')) {
                $methods['pkstore_trax'] = \PkStore\Infrastructure\WooCommerce\Shipping\TraxShippingMethod::class;
            }
            return $methods;
        });
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterGatewaysForDigital']);
        if ($this->hasEnabledCourier()) {
            add_action('woocommerce_payment_complete', [$this, 'autoBookShipment']);
            add_action('pkstore_tracking_sync_cron', [$this, 'syncTrackingUpdates']);
            if (!wp_next_scheduled('pkstore_tracking_sync_cron')) {
                wp_schedule_event(time() + 300, 'hourly', 'pkstore_tracking_sync_cron');
            }
        }
    }

    private function hasEnabledCourier(): bool {
        return $this->features->isEnabled('courier_tcs')
            || $this->features->isEnabled('courier_leopards')
            || $this->features->isEnabled('courier_trax');
    }

    public function autoBookShipment(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) return;
        if ($this->isDigitalOnlyOrder($order)) {
            $order->add_order_note('[PK Store] Digital order — courier booking skipped. Download links sent via email.');
            return;
        }
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time()+10,'pkstore_retry_courier_booking',['order_id'=>$orderId],'pkstore-logistics');
        } elseif (!wp_next_scheduled('pkstore_retry_courier_booking', [$orderId])) {
            wp_schedule_single_event(time()+10,'pkstore_retry_courier_booking',[$orderId]);
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
        if (!$this->hasEnabledCourier() || !function_exists('wc_get_orders')) return;

        // Use WooCommerce's order abstraction rather than querying wp_posts:
        // HPOS stores orders outside the posts table.
        $page = max(1, (int) get_option('pkstore_tracking_sync_page', 1));
        $result = wc_get_orders([
            'type' => 'shop_order',
            'status' => ['wc-shipped', 'wc-out-for-delivery', 'wc-delivery-failed', 'wc-on-hold'],
            'limit' => 100,
            'paged' => $page,
            'return' => 'objects',
            'paginate' => true,
            'orderby' => 'modified',
            'order' => 'ASC',
        ]);

        if (!is_object($result) || !isset($result->orders, $result->max_num_pages)) {
            error_log('[PK Store] Tracking sync query did not return a valid paginated order result.');
            return;
        }
        $maxPages = max(1, (int) $result->max_num_pages);
        if ($page > $maxPages) {
            update_option('pkstore_tracking_sync_page', 1, false);
            return;
        }

        $courierMap = [
            'tcs' => ['courier_tcs', \PkStore\Infrastructure\Logistics\TcsCourier::class],
            'leopards' => ['courier_leopards', \PkStore\Infrastructure\Logistics\LeopardsCourier::class],
            'trax' => ['courier_trax', \PkStore\Infrastructure\Logistics\TraxCourier::class],
        ];

        foreach ($result->orders as $order) {
            if (!($order instanceof \WC_Order)) continue;
            $trackingNumber = (string) $order->get_meta('_tracking_courier');
            $courierCode = strtolower((string) $order->get_meta('_booked_courier'));
            if ($trackingNumber === '' || str_starts_with($trackingNumber, 'PENDING-')
                || !isset($courierMap[$courierCode])) {
                continue;
            }
            [$feature, $courierClass] = $courierMap[$courierCode];
            if (!$this->features->isEnabled($feature)) continue;

            try {
                $update = $this->container->make($courierClass)->getTrackingUpdate($trackingNumber);
                if (!$update) continue;

                $nextStatus = $update->status->toWooCommerceStatus();
                if ($update->status->canAdvanceFrom($order->get_status())
                    && $order->get_status() !== $nextStatus) {
                    $location = $update->location !== '' ? ' (' . sanitize_text_field($update->location) . ')' : '';
                    $description = $update->description !== '' ? ': ' . sanitize_text_field($update->description) : '';
                    $order->update_status(
                        $nextStatus,
                        '[PK Store][' . $courierCode . '] ' . ucwords(str_replace('_', ' ', $update->status->value)) . $location . $description
                    );
                }

                if ($update->status === \PkStore\Domain\Logistics\DTO\TrackingStatus::Delivered
                    && str_contains($order->get_payment_method(), 'cod')
                    && !$order->get_meta('_cod_delivered_at')) {
                    $order->update_meta_data('_cod_delivered_at', current_time('mysql'));
                    $order->update_meta_data('_cod_delivered_by', $courierCode);
                    $order->save_meta_data();
                    do_action('pkstore_cod_delivered', $order->get_id(), $courierCode, $trackingNumber);
                }
            } catch (\Throwable $error) {
                error_log('[PK Store] Tracking sync failed for order ' . (int) $order->get_id() . ' (' . get_class($error) . ').');
            }
        }

        update_option('pkstore_tracking_sync_page', $page >= $maxPages ? 1 : $page + 1, false);
    }

    private function isDigitalOnlyOrder(\WC_Order $order): bool {
        foreach ($order->get_items() as $item) {
            $p = $item->get_product();
            if ($p instanceof \WC_Product && !$p->is_virtual() && !$p->is_downloadable()) return false;
        }
        return true;
    }
}
