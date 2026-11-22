<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Integration\WhatsApp;

use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class WhatsAppService {
    private const BASE = 'https://graph.facebook.com/';

    public function __construct(
        private readonly SettingsManager $s,
        private readonly LoggerInterface $log
    ) {}

    private function sendTemplate(string $phone, string $templateKey, array $values): bool {
        $token = $this->s->get('WHATSAPP_TOKEN');
        $phoneId = $this->s->get('WHATSAPP_PHONE_ID');
        $templateName = $this->s->get($templateKey);
        $language = $this->s->get('WHATSAPP_TEMPLATE_LANGUAGE', 'en_US');
        $version = $this->s->get('WHATSAPP_GRAPH_API_VERSION', 'v26.0');

        if ($token === '' || $phoneId === '' || $templateName === '') {
            $this->log->warning('WhatsApp template message skipped because credentials or approved template name are missing.', [
                'template_key' => $templateKey,
            ]);
            return false;
        }
        if (!preg_match('/^v[0-9]{1,2}\.0$/', $version)) $version = 'v26.0';

        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '92' . substr($cleanPhone, 1);
        } elseif (!str_starts_with($cleanPhone, '92')) {
            $cleanPhone = '92' . $cleanPhone;
        }
        if (!preg_match('/^92[0-9]{10}$/', $cleanPhone)) {
            $this->log->warning('WhatsApp message skipped because recipient number is invalid.');
            return false;
        }

        $parameters = [];
        foreach ($values as $value) {
            $text = trim(wp_strip_all_tags((string) $value));
            $parameters[] = [
                'type' => 'text',
                'text' => function_exists('mb_substr') ? mb_substr($text, 0, 1024) : substr($text, 0, 1024),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $cleanPhone,
            'type' => 'template',
            'template' => [
                'name' => sanitize_key($templateName),
                'language' => ['code' => sanitize_text_field($language)],
                'components' => [[
                    'type' => 'body',
                    'parameters' => $parameters,
                ]],
            ],
        ];

        $response = wp_remote_post(self::BASE . $version . '/' . rawurlencode($phoneId) . '/messages', [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->log->error('WhatsApp template send transport failure.', [
                'error_code' => sanitize_key((string) $response->get_error_code()),
            ]);
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 200 || $status === 201) return true;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $providerCode = is_array($body) ? (string) ($body['error']['code'] ?? '') : '';
        $this->log->error('WhatsApp template send rejected by provider.', [
            'http_status' => $status,
            'provider_error_code' => sanitize_text_field($providerCode),
            'template_key' => $templateKey,
        ]);
        return false;
    }

    public function sendOrderConfirmation(\WC_Order $order): bool {
        $items = implode(', ', array_map(
            static fn($item) => $item->get_name() . ' x' . $item->get_quantity(),
            $order->get_items()
        ));
        return $this->sendTemplate($order->get_billing_phone(), 'WHATSAPP_TEMPLATE_ORDER_CONFIRMATION', [
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $items,
            'PKR ' . number_format((float) $order->get_total(), 2),
        ]);
    }

    public function sendShippingUpdate(\WC_Order $order, string $trackingNumber, string $courier): bool {
        return $this->sendTemplate($order->get_billing_phone(), 'WHATSAPP_TEMPLATE_SHIPPED', [
            $order->get_order_number(),
            $trackingNumber,
            strtoupper($courier),
        ]);
    }

    public function sendDeliveryConfirmation(\WC_Order $order): bool {
        return $this->sendTemplate($order->get_billing_phone(), 'WHATSAPP_TEMPLATE_DELIVERED', [
            $order->get_billing_first_name(),
            $order->get_order_number(),
        ]);
    }

    public function sendLowStockAlert(string $adminPhone, string $productName, int $stock): bool {
        return $this->sendTemplate($adminPhone, 'WHATSAPP_TEMPLATE_LOW_STOCK', [
            $productName,
            (string) $stock,
        ]);
    }

    public static function getWebhookUrl(): string {
        return rest_url('pkstore/v1/webhooks/whatsapp');
    }
}
