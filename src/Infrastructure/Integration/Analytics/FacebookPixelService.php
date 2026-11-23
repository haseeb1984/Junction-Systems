<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Integration\Analytics;

use PkStore\Settings\SettingsManager;

final class FacebookPixelService {
    public function __construct(private readonly SettingsManager $s) {}

    public function register(): void {
        add_action('wp_head', [$this, 'injectPixel'], 1);
        add_action('wp_footer', [$this, 'injectEvents']);
        add_action('woocommerce_checkout_create_order', [$this, 'captureConsent'], 10, 2);
        add_action('woocommerce_payment_complete', [$this, 'serverSideConversion']);
    }

    private function hasMarketingConsent(): bool {
        return function_exists('wp_has_consent') && wp_has_consent('marketing');
    }

    public function captureConsent(\WC_Order $order, array $data = []): void {
        if ($this->hasMarketingConsent()) {
            $order->update_meta_data('_pkstore_facebook_marketing_consent', 'yes');
            $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
            if ($userAgent !== '') {
                $order->update_meta_data('_pkstore_facebook_client_user_agent', substr($userAgent, 0, 512));
            }
        } else {
            $order->delete_meta_data('_pkstore_facebook_marketing_consent');
            $order->delete_meta_data('_pkstore_facebook_client_user_agent');
        }
        $order->save_meta_data();
    }

    public function injectPixel(): void {
        $pixelId = $this->s->get('FACEBOOK_PIXEL_ID');
        if (!$pixelId || !$this->hasMarketingConsent()) return;
        echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . esc_js($pixelId) . "');fbq('track','PageView');</script>\n";
    }

    public function injectEvents(): void {
        if (!$this->s->get('FACEBOOK_PIXEL_ID') || !$this->hasMarketingConsent()) return;
        if (is_product()) $this->fireViewContent();
        if (is_checkout()) $this->fireInitiateCheckout();
        if (is_order_received_page()) $this->firePurchase();
    }

    private function fireViewContent(): void {
        global $product;
        if (!$product instanceof \WC_Product) return;
        $sku = (string) ($product->get_sku() ?: $product->get_id());
        echo "<script>fbq('track','ViewContent',{content_ids:['" . esc_js($sku) . "'],content_type:'product',value:" . esc_js((string) (float) $product->get_price()) . ",currency:'PKR'});</script>\n";
    }

    private function fireInitiateCheckout(): void {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return;
        $ids = array_values(array_map(
            static fn(array $item): string => (string) ($item['data']->get_sku() ?: $item['data']->get_id()),
            $cart->get_cart()
        ));
        echo "<script>fbq('track','InitiateCheckout',{content_ids:" . wp_json_encode($ids) . ",currency:'PKR',value:" . esc_js((string) (float) $cart->get_total('edit')) . ",num_items:" . (int) $cart->get_cart_contents_count() . "});</script>\n";
    }

    private function purchaseEventId(\WC_Order $order): string {
        $orderId = (int) $order->get_id();
        if ($orderId < 1) return '';
        global $wpdb;
        if (!($wpdb instanceof \wpdb)) return '';

        $lockName = 'pkstore_facebook_event_' . $orderId;
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 10));
        if ($wpdb->last_error !== '' || (int) $locked !== 1) return '';

        try {
            $freshOrder = wc_get_order($orderId);
            if (!($freshOrder instanceof \WC_Order)
                || $freshOrder->get_meta('_pkstore_facebook_marketing_consent', true) !== 'yes') return '';

            $eventId = (string) $freshOrder->get_meta('_pkstore_facebook_purchase_event_id', true);
            if ($eventId !== '') return $eventId;

            $eventId = wp_generate_uuid4();
            $freshOrder->update_meta_data('_pkstore_facebook_purchase_event_id', $eventId);
            if ((int) $freshOrder->save() !== $orderId) return '';
            return $eventId;
        } finally {
            $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            if ($wpdb->last_error !== '' || (int) $released !== 1) {
                error_log('[PK Store] Meta purchase event lock release could not be confirmed.');
            }
        }
    }

    private function purchaseContentIds(\WC_Order $order): array {
        $ids = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $ids[] = (string) ($product instanceof \WC_Product ? ($product->get_sku() ?: $product->get_id()) : $item->get_product_id());
        }
        return array_values($ids);
    }

    private function firePurchase(): void {
        $orderId = absint(get_query_var('order-received'));
        if (!$orderId) return;
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order
            || $order->get_meta('_pkstore_facebook_marketing_consent', true) !== 'yes'
            || $order->get_meta('_pkstore_facebook_purchase_fired', true) === 'yes') return;

        $eventId = $this->purchaseEventId($order);
        if ($eventId === '') return;
        $order->update_meta_data('_pkstore_facebook_purchase_fired', 'yes');
        if ((int) $order->save() !== $orderId) return;

        $payload = [
            'content_ids' => $this->purchaseContentIds($order),
            'content_type' => 'product',
            'currency' => 'PKR',
            'value' => (float) $order->get_total(),
        ];
        echo "<script>fbq('track','Purchase'," . wp_json_encode($payload) . ",{eventID:'" . esc_js($eventId) . "'});</script>\n";
    }

    public function serverSideConversion(int $orderId): void {
        $token = $this->s->get('FACEBOOK_CAPI_TOKEN');
        $pixelId = $this->s->get('FACEBOOK_PIXEL_ID');
        if (!$token || !$pixelId) return;

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order
            || $order->get_meta('_pkstore_facebook_marketing_consent', true) !== 'yes'
            || $order->get_meta('_pkstore_facebook_server_purchase_sent', true) === 'yes') return;

        $email = strtolower(trim((string) $order->get_billing_email()));
        $phone = preg_replace('/[^\d]/', '', (string) $order->get_billing_phone());
        $userData = [];
        if ($email !== '') $userData['em'] = [hash('sha256', $email)];
        if ($phone !== '') $userData['ph'] = [hash('sha256', $phone)];
        $userAgent = (string) $order->get_meta('_pkstore_facebook_client_user_agent', true);
        if ($userAgent === '') return;
        $userData['client_user_agent'] = $userAgent;
        if (!$userData) return;

        $eventId = $this->purchaseEventId($order);
        if ($eventId === '') return;
        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => time(),
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/'),
                'user_data' => $userData,
                'custom_data' => [
                    'currency' => 'PKR',
                    'value' => (float) $order->get_total(),
                    'content_ids' => $this->purchaseContentIds($order),
                    'content_type' => 'product',
                    'order_id' => $order->get_order_number(),
                ],
            ]],
        ];
        $response = wp_remote_post(
            'https://graph.facebook.com/v27.0/' . rawurlencode($pixelId) . '/events?access_token=' . rawurlencode($token),
            ['timeout' => 10, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200
            || (int) wp_remote_retrieve_response_code($response) >= 300) return;

        $result = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($result) || (int) ($result['events_received'] ?? 0) < 1) return;
        $order->update_meta_data('_pkstore_facebook_server_purchase_sent', 'yes');
        $order->save_meta_data();
    }
}
