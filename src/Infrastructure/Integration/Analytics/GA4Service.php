<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Integration\Analytics;

use PkStore\Settings\SettingsManager;

final class GA4Service {
    public function __construct(private readonly SettingsManager $s) {}

    public function register(): void {
        add_action('wp_head', [$this, 'injectGtag'], 1);
        add_action('wp_footer', [$this, 'injectEvents']);
        add_action('woocommerce_checkout_create_order', [$this, 'captureConsent'], 10, 2);
        add_action('woocommerce_payment_complete', [$this, 'serverSidePurchase']);
    }

    private function hasStatisticsConsent(): bool {
        return function_exists('wp_has_consent') && wp_has_consent('statistics');
    }

    public function captureConsent(\WC_Order $order, array $data = []): void {
        if ($this->hasStatisticsConsent()) {
            $order->update_meta_data('_pkstore_ga4_statistics_consent', 'yes');
        } else {
            $order->delete_meta_data('_pkstore_ga4_statistics_consent');
        }
        $order->save_meta_data();
    }

    public function injectGtag(): void {
        $measurementId = $this->s->get('GA4_MEASUREMENT_ID');
        if (!$measurementId || !$this->hasStatisticsConsent()) return;

        echo "<script async src=\"" . esc_url('https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($measurementId)) . "\"></script>\n";
        echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js($measurementId) . "',{'send_page_view':true});</script>\n";
    }

    public function injectEvents(): void {
        if (!$this->s->get('GA4_MEASUREMENT_ID') || !$this->hasStatisticsConsent()) return;
        if (is_product()) $this->injectViewItem();
        if (is_cart()) $this->injectViewCart();
        if (is_checkout()) $this->injectBeginCheckout();
        if (is_order_received_page()) $this->injectPurchase();
    }

    private function injectViewItem(): void {
        global $product;
        if (!$product instanceof \WC_Product) return;
        $price = (float) $product->get_price();
        echo "<script>gtag('event','view_item',{currency:'PKR',value:" . esc_js((string) $price) . ",items:[{item_id:'" . esc_js((string) ($product->get_sku() ?: $product->get_id())) . "',item_name:'" . esc_js($product->get_name()) . "',price:" . esc_js((string) $price) . "}]});</script>\n";
    }

    private function injectViewCart(): void {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return;
        $items = array_values(array_map(
            static fn(array $item): array => [
                'item_id' => (string) ($item['data']->get_sku() ?: $item['data']->get_id()),
                'item_name' => $item['data']->get_name(),
                'quantity' => (int) $item['quantity'],
                'price' => (float) $item['data']->get_price(),
            ],
            $cart->get_cart()
        ));
        $value = (float) $cart->get_total('edit');
        echo "<script>gtag('event','view_cart',{currency:'PKR',value:" . esc_js((string) $value) . ",items:" . wp_json_encode($items) . "});</script>\n";
    }

    private function injectBeginCheckout(): void {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return;
        $items = array_values(array_map(
            static fn(array $item): array => [
                'item_id' => (string) ($item['data']->get_sku() ?: $item['data']->get_id()),
                'item_name' => $item['data']->get_name(),
                'quantity' => (int) $item['quantity'],
                'price' => (float) $item['data']->get_price(),
            ],
            $cart->get_cart()
        ));
        $value = (float) $cart->get_total('edit');
        echo "<script>gtag('event','begin_checkout',{currency:'PKR',value:" . esc_js((string) $value) . ",items:" . wp_json_encode($items) . "});</script>\n";
    }

    private function orderItems(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $quantity = (int) $item->get_quantity();
            if ($quantity < 1) continue;
            $product = $item->get_product();
            $items[] = [
                'item_id' => (string) ($product instanceof \WC_Product ? ($product->get_sku() ?: $product->get_id()) : $item->get_product_id()),
                'item_name' => $item->get_name(),
                'quantity' => $quantity,
                'price' => (float) $item->get_total() / $quantity,
            ];
        }
        return $items;
    }

    private function injectPurchase(): void {
        $orderId = absint(get_query_var('order-received'));
        if (!$orderId) return;
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order
            || $order->get_meta('_pkstore_ga4_statistics_consent', true) !== 'yes'
            || $order->get_meta('_pkstore_ga4_purchase_fired', true) === 'yes') return;

        $order->update_meta_data('_pkstore_ga4_purchase_fired', 'yes');
        if ((int) $order->save() !== $orderId) return;

        echo "<script>gtag('event','purchase',{transaction_id:'" . esc_js($order->get_order_number()) . "',currency:'PKR',value:" . esc_js((string) (float) $order->get_total()) . ",tax:" . esc_js((string) (float) $order->get_total_tax()) . ",items:" . wp_json_encode($this->orderItems($order)) . "});</script>\n";
    }

    public function serverSidePurchase(int $orderId): void {
        $apiSecret = $this->s->get('GA4_API_SECRET');
        $measurementId = $this->s->get('GA4_MEASUREMENT_ID');
        if (!$apiSecret || !$measurementId) return;

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order
            || $order->get_meta('_pkstore_ga4_statistics_consent', true) !== 'yes'
            || $order->get_meta('_pkstore_ga4_server_purchase_sent', true) === 'yes') return;

        $clientId = (string) $order->get_meta('_ga4_client_id', true);
        if ($clientId === '') return;

        $payload = [
            'client_id' => $clientId,
            'events' => [[
                'name' => 'purchase',
                'params' => [
                    'transaction_id' => $order->get_order_number(),
                    'currency' => 'PKR',
                    'value' => (float) $order->get_total(),
                    'items' => $this->orderItems($order),
                ],
            ]],
        ];
        $response = wp_remote_post(
            'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($measurementId) . '&api_secret=' . rawurlencode($apiSecret),
            ['timeout' => 10, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200
            || (int) wp_remote_retrieve_response_code($response) >= 300) return;

        $order->update_meta_data('_pkstore_ga4_server_purchase_sent', 'yes');
        $order->save_meta_data();
    }
}
