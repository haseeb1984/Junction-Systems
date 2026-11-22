<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Integration\WhatsApp;

use PkStore\Application\Inventory\InventoryProductRepositoryInterface;
use PkStore\Container\ServiceContainer;
use PkStore\Domain\Inventory\Repository\StockRepositoryInterface;

final class WhatsAppHooks {
    public function __construct(private readonly ServiceContainer $c) {}

    public function register(): void {
        add_action('woocommerce_order_status_processing', [$this, 'onOrderConfirmed'], 10, 1);
        add_action('woocommerce_order_status_shipped', [$this, 'onOrderShipped'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'onOrderDelivered'], 10, 1);
        add_action('pkstore_whatsapp_low_stock_scan', [$this, 'scanLowStock']);

        add_action('rest_api_init', function (): void {
            register_rest_route('pkstore/v1', '/webhooks/whatsapp', [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'handleWebhook'],
                'permission_callback' => '__return_true',
            ]);
        });

        if (!wp_next_scheduled('pkstore_whatsapp_low_stock_scan')) {
            wp_schedule_event(time() + 600, 'hourly', 'pkstore_whatsapp_low_stock_scan');
        }
    }

    private function sendOnce(\WC_Order $order, string $metaKey, callable $send): void {
        if ($order->get_meta($metaKey, true)) return;
        try {
            if ($send()) {
                $order->update_meta_data($metaKey, current_time('mysql'));
                $order->save_meta_data();
            }
        } catch (\Throwable $error) {
            error_log('[PK Store WhatsApp] Notification failed (' . get_class($error) . ').');
        }
    }

    public function onOrderConfirmed(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) return;
        $this->sendOnce($order, '_pkstore_wa_order_confirmation_sent', fn(): bool =>
            $this->c->make(WhatsAppService::class)->sendOrderConfirmation($order)
        );
    }

    public function onOrderShipped(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) return;
        $tracking = (string) $order->get_meta('_tracking_courier', true);
        $courier = (string) $order->get_meta('_booked_courier', true);
        if ($tracking === '' || str_starts_with($tracking, 'PENDING-')) return;
        $this->sendOnce($order, '_pkstore_wa_shipped_sent', fn(): bool =>
            $this->c->make(WhatsAppService::class)->sendShippingUpdate($order, $tracking, $courier)
        );
    }

    public function onOrderDelivered(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) return;
        $this->sendOnce($order, '_pkstore_wa_delivered_sent', fn(): bool =>
            $this->c->make(WhatsAppService::class)->sendDeliveryConfirmation($order)
        );
    }

    public function scanLowStock(): void {
        $adminPhone = \PkStore\Settings\SettingsManager::getInstance()->get('WHATSAPP_ADMIN_PHONE');
        if (!$adminPhone) return;
        try {
            $products = $this->c->make(InventoryProductRepositoryInterface::class)->findBelowReorderPoint();
            $stockRepo = $this->c->make(StockRepositoryInterface::class);
            $activeIds = [];
            foreach ($products as $product) {
                $stock = $stockRepo->getTotalStock($product->id);
                if ($stock > $product->reorderPoint) continue;
                $activeIds[] = $product->id;
                $key = 'pkstore_wa_low_stock_' . (int) $product->id;
                if (get_transient($key)) continue;
                $sent = $this->c->make(WhatsAppService::class)->sendLowStockAlert($adminPhone, $product->name, $stock);
                if ($sent) set_transient($key, 1, DAY_IN_SECONDS);
            }
            // Clear alert suppression once stock is replenished above the threshold.
            $allProducts = $this->c->make(InventoryProductRepositoryInterface::class)->findAll(500, 0, '');
            foreach ($allProducts as $product) {
                if (in_array($product->id, $activeIds, true)) continue;
                delete_transient('pkstore_wa_low_stock_' . (int) $product->id);
            }
        } catch (\Throwable $error) {
            error_log('[PK Store WhatsApp] Low-stock scan failed (' . get_class($error) . ').');
        }
    }

    public function handleWebhook(\WP_REST_Request $req): \WP_REST_Response {
        $settings = \PkStore\Settings\SettingsManager::getInstance();
        if ($req->get_method() === 'GET') {
            $mode = (string) $req->get_param('hub.mode');
            $token = (string) $req->get_param('hub.verify_token');
            $challenge = (string) $req->get_param('hub.challenge');
            $expected = (string) $settings->get('WHATSAPP_VERIFY_TOKEN');
            if ($mode === 'subscribe' && $expected !== '' && $challenge !== ''
                && hash_equals($expected, $token)) {
                return new \WP_REST_Response($challenge, 200);
            }
            return new \WP_REST_Response('Forbidden', 403);
        }

        $appSecret = (string) $settings->get('WHATSAPP_APP_SECRET');
        $signature = (string) $req->get_header('X-Hub-Signature-256');
        $body = (string) $req->get_body();
        if ($appSecret === '' || !str_starts_with($signature, 'sha256=')) {
            return new \WP_REST_Response(['status'=>'signature_required'], 401);
        }
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $body, $appSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            return new \WP_REST_Response(['status'=>'invalid_signature'], 401);
        }

        // Acknowledge only authenticated events. Do not log raw message payloads or PII.
        $event = json_decode($body, true);
        if (!is_array($event)) {
            return new \WP_REST_Response(['status'=>'invalid_payload'], 400);
        }
        return new \WP_REST_Response(['status'=>'accepted'], 200);
    }
}
