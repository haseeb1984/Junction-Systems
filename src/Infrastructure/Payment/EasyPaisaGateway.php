<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Payment;

use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class EasyPaisaGateway extends \WC_Payment_Gateway {
    private const SANDBOX_BASE = 'https://easypaystg.easypaisa.com.pk/easypay-service/rest/v4';
    private const PRODUCTION_BASE = 'https://easypay.easypaisa.com.pk/easypay-service/rest/v4';

    private LoggerInterface $log;
    private SettingsManager $cfg;

    public function __construct(LoggerInterface $log = null, SettingsManager $cfg = null) {
        $this->id = 'easypaisa';
        $this->method_title = 'Easypaisa';
        $this->method_description = 'Pay using your Easypaisa Mobile Account.';
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->log = $log ?? new \PkStore\Infrastructure\Logger\WpLogger();
        $this->cfg = $cfg ?? SettingsManager::getInstance();

        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', 'Easypaisa');
        $this->description = $this->get_option('description', 'Approve the payment request in your Easypaisa app.');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_easypaisa_ipn', [$this, 'handleIpn']);
    }

    public function is_available(): bool {
        return parent::is_available()
            && get_woocommerce_currency() === 'PKR'
            && (string) $this->cfg->get('EASYPAISA_USERNAME') !== ''
            && (string) $this->cfg->get('EASYPAISA_PASSWORD') !== ''
            && (string) $this->cfg->get('EASYPAISA_STORE_ID') !== ''
            && (string) $this->cfg->get('EASYPAISA_ACCOUNT_NUMBER') !== '';
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => ['title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable Easypaisa', 'default' => 'yes'],
            'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'Easypaisa'],
            'description' => ['title' => 'Description', 'type' => 'textarea', 'default' => 'Approve the payment request in your Easypaisa app.'],
        ];
    }

    public function payment_fields(): void {
        if ($this->description) echo wpautop(wptexturize($this->description));
        echo '<p>Use the Pakistani mobile number linked to your Easypaisa Mobile Account in the billing phone field. A payment request will be sent to that account.</p>';
    }

    public function validate_fields(): bool {
        $phone = isset($_POST['billing_phone'])
            ? $this->normalizeMobileNumber((string) wc_clean(wp_unslash($_POST['billing_phone'])))
            : '';
        if (!preg_match('/^03[0-9]{9}$/', $phone)) {
            wc_add_notice('Enter a valid Pakistani mobile number linked to your Easypaisa account (03XXXXXXXXX).', 'error');
            return false;
        }
        return true;
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order || $order->is_paid() || in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
            wc_add_notice('This order cannot be paid through Easypaisa.', 'error');
            return ['result' => 'fail'];
        }
        if (get_woocommerce_currency() !== 'PKR') {
            wc_add_notice('Easypaisa is available only when the store currency is PKR.', 'error');
            return ['result' => 'fail'];
        }

        $username = (string) $this->cfg->get('EASYPAISA_USERNAME');
        $password = (string) $this->cfg->get('EASYPAISA_PASSWORD');
        $storeId = (string) $this->cfg->get('EASYPAISA_STORE_ID');
        $accountNum = (string) $this->cfg->get('EASYPAISA_ACCOUNT_NUMBER');
        if ($username === '' || $password === '' || $storeId === '' || $accountNum === '') {
            wc_add_notice('Easypaisa is not configured. Contact the store administrator.', 'error');
            return ['result' => 'fail'];
        }

        $phone = $this->normalizeMobileNumber((string) $order->get_billing_phone());
        $email = sanitize_email((string) $order->get_billing_email());
        if (!preg_match('/^03[0-9]{9}$/', $phone) || !is_email($email)) {
            wc_add_notice('A valid Easypaisa mobile number and billing email are required.', 'error');
            return ['result' => 'fail'];
        }

        $existingOrderRef = (string) $order->get_meta('_pkstore_easypaisa_order_id');
        $existingState = (string) $order->get_meta('_pkstore_easypaisa_state');
        if ($existingOrderRef !== '' && $existingState !== 'failed') {
            $existingStatus = $this->inquireTransactionStatus($existingOrderRef);
            if (is_array($existingStatus)) {
                $state = $this->applyProviderStatus($order, $existingStatus);
                if ($order->is_paid() || $state === 'paid') {
                    return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
                }
                if ($state !== 'failed') {
                    wc_add_notice('An Easypaisa payment is already pending or its status is unavailable. Please do not retry; contact the store if it does not update.', 'error');
                    return ['result' => 'fail'];
                }
            } else {
                wc_add_notice('The previous Easypaisa payment status could not be verified. Please do not retry until the store reconciles it.', 'error');
                return ['result' => 'fail'];
            }
        }

        $amount = wc_format_decimal($order->get_total(), 2, false);
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/', $amount) || (float) $amount <= 0) {
            wc_add_notice('The order total is outside the supported Easypaisa amount range.', 'error');
            return ['result' => 'fail'];
        }

        try {
            $orderRef = 'J' . (int) $order_id . strtoupper(bin2hex(random_bytes(4)));
        } catch (\Throwable $error) {
            $this->log->error('Easypaisa order reference generation failed.', ['error_class' => get_class($error)]);
            wc_add_notice('Easypaisa payment could not be prepared. Please contact the store administrator.', 'error');
            return ['result' => 'fail'];
        }

        $isSandbox = $this->cfg->get('EASYPAISA_SANDBOX', '1') !== '0';
        $baseUrl = $isSandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
        $payload = [
            'orderId' => $orderRef,
            'storeId' => $storeId,
            'transactionAmount' => $amount,
            'transactionType' => 'MA',
            'mobileAccountNo' => $phone,
            'emailAddress' => $email,
        ];

        $order->update_meta_data('_pkstore_easypaisa_order_id', $orderRef);
        $order->update_meta_data('_pkstore_easypaisa_store_id', $storeId);
        $order->update_meta_data('_pkstore_easypaisa_expected_amount', $amount);
        $order->update_meta_data('_pkstore_easypaisa_state', 'unknown');
        $order->update_status('pending', 'Awaiting Easypaisa Mobile Account confirmation.');
        $order->save();

        $response = wp_remote_post($baseUrl . '/initiate-ma-transaction', [
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Credentials' => base64_encode($username . ':' . $password),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $order->add_order_note('Easypaisa initiation request did not return a response. Status is unknown; do not initiate another charge until reconciliation.');
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            $order->add_order_note('Easypaisa returned an unreadable initiation response; status must be reconciled before another charge.');
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        if ((string) ($body['orderId'] ?? '') !== $orderRef
            || (string) ($body['storeId'] ?? '') !== $storeId) {
            $order->add_order_note('Easypaisa initiation response did not match the submitted order/store reference. Status is unknown; manual reconciliation is required.');
            $order->update_meta_data('_pkstore_easypaisa_state', 'review');
            $order->save();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        if ((string) ($body['responseCode'] ?? '') !== '0000') {
            $responseCode = sanitize_text_field((string) ($body['responseCode'] ?? ''));
            $responseMessage = sanitize_text_field((string) ($body['responseDesc'] ?? ''));
            if (!in_array($responseCode, ['0002', '0005', '0006', '0007', '0008', '0010', '0013', '0014'], true)) {
                $order->add_order_note('Easypaisa returned a non-final or unknown initiation response; status must be reconciled before another charge.');
                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }
            $order->update_meta_data('_pkstore_easypaisa_state', 'failed');
            $order->update_status('failed', 'Easypaisa initiation failed: ' . ($responseMessage !== '' ? $responseMessage : $responseCode));
            wc_add_notice('Easypaisa could not initiate this payment. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $order->update_meta_data('_pkstore_easypaisa_transaction_id', sanitize_text_field((string) ($body['transactionId'] ?? '')));
        $order->update_meta_data('_pkstore_easypaisa_state', 'pending');
        $order->add_order_note('Easypaisa payment request sent. The customer must approve it in the Easypaisa Mobile Account app.');
        $order->save();

        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    public function handleIpn(): void {
        $rawUrl = trim((string) wp_unslash($_GET['url'] ?? $_GET['URL'] ?? ''));
        $orderRef = (string) wp_unslash($_GET['orderId'] ?? '');
        $accountNum = (string) wp_unslash($_GET['accountNum'] ?? '');

        if ($rawUrl !== '') {
            $parsed = wp_parse_url($rawUrl);
            $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
            $host = strtolower((string) ($parsed['host'] ?? ''));
            $port = isset($parsed['port']) ? (int) $parsed['port'] : 443;
            $allowedHosts = ['easypaystg.easypaisa.com.pk', 'easypay.easypaisa.com.pk'];
            $path = (string) ($parsed['path'] ?? '');
            $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
            if ($scheme !== 'https' || $port !== 443 || !in_array($host, $allowedHosts, true)
                || strpos($path, '/order-status/') === false || count($segments) < 2) {
                status_header(400);
                exit;
            }
            $accountNum = (string) $segments[count($segments) - 2];
            $orderRef = (string) $segments[count($segments) - 1];
        }

        $configuredAccount = (string) $this->cfg->get('EASYPAISA_ACCOUNT_NUMBER');
        if ($orderRef === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderRef)
            || $configuredAccount === '' || !hash_equals($configuredAccount, $accountNum)) {
            status_header(400);
            exit;
        }

        $orders = wc_get_orders([
            'limit' => 2,
            'return' => 'objects',
            'meta_key' => '_pkstore_easypaisa_order_id',
            'meta_value' => $orderRef,
        ]);
        if (!is_array($orders) || count($orders) !== 1 || !$orders[0] instanceof \WC_Order
            || $orders[0]->get_payment_method() !== $this->id) {
            status_header(is_array($orders) && count($orders) > 1 ? 409 : 404);
            exit;
        }
        $order = $orders[0];

        $status = $this->inquireTransactionStatus($orderRef);
        if (!is_array($status)) {
            status_header(503);
            exit;
        }
        $result = $this->applyProviderStatus($order, $status);
        status_header($result === 'pending' ? 503 : 200);
        echo 'OK';
        exit;
    }

    private function inquireTransactionStatus(string $orderRef): ?array {
        $username = (string) $this->cfg->get('EASYPAISA_USERNAME');
        $password = (string) $this->cfg->get('EASYPAISA_PASSWORD');
        $storeId = (string) $this->cfg->get('EASYPAISA_STORE_ID');
        $accountNum = (string) $this->cfg->get('EASYPAISA_ACCOUNT_NUMBER');
        if ($username === '' || $password === '' || $storeId === '' || $accountNum === '') return null;

        $isSandbox = $this->cfg->get('EASYPAISA_SANDBOX', '1') !== '0';
        $baseUrl = $isSandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
        $response = wp_remote_post($baseUrl . '/inquire-transaction', [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Credentials' => base64_encode($username . ':' . $password),
            ],
            'body' => wp_json_encode([
                'orderId' => $orderRef,
                'storeId' => $storeId,
                'accountNum' => $accountNum,
            ]),
        ]);
        if (is_wp_error($response)) {
            $this->log->warning('Easypaisa transaction inquiry failed.', ['error_class' => 'WP_Error']);
            return null;
        }
        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->log->warning('Easypaisa transaction inquiry returned a non-success HTTP status.', ['http_status' => $httpStatus]);
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)
            || (string) ($body['orderId'] ?? '') !== $orderRef
            || (string) ($body['storeId'] ?? '') !== $storeId
            || (string) ($body['accountNum'] ?? '') !== $accountNum
            || !in_array((string) ($body['responseCode'] ?? ''), ['0000', '0001', '0003', '0004', '0005', '0006', '0007', '0010'], true)) {
            $this->log->warning('Easypaisa transaction inquiry returned an unverified response.', [
                'response_code' => is_array($body) ? sanitize_text_field((string) ($body['responseCode'] ?? 'unknown')) : 'invalid',
            ]);
            return null;
        }
        return $body;
    }

    private function applyProviderStatus(\WC_Order $order, array $status): string {
        $responseCode = (string) ($status['responseCode'] ?? '');
        if ($responseCode !== '0000') {
            if ($responseCode === '0001') return 'pending';
            if ($responseCode === '0003') {
                if (!$order->is_paid()) {
                    $order->update_meta_data('_pkstore_easypaisa_state', 'failed');
                    $order->update_status('failed', 'Easypaisa inquiry reports that the transaction reference was not found.');
                    return 'failed';
                }
                $order->add_order_note('Easypaisa inquiry reports an unknown transaction reference for an already-paid order. Manual reconciliation is required.');
            } else {
                $order->add_order_note('Easypaisa inquiry could not verify merchant/order details. Manual reconciliation is required.');
            }
            $order->update_meta_data('_pkstore_easypaisa_state', 'review');
            $order->save();
            return 'review';
        }

        $expectedAmount = wc_format_decimal($order->get_meta('_pkstore_easypaisa_expected_amount'), 2, false);
        $reportedAmount = wc_format_decimal((string) ($status['transactionAmount'] ?? ''), 2, false);
        if ($expectedAmount === '' || $reportedAmount === '' || !hash_equals($expectedAmount, $reportedAmount)) {
            $order->add_order_note('Easypaisa status rejected: reported amount does not match the order. Manual reconciliation is required.');
            $order->update_meta_data('_pkstore_easypaisa_state', 'review');
            $order->save();
            return 'review';
        }

        $paymentMode = strtoupper((string) ($status['paymentMode'] ?? ''));
        $reportedPhone = $this->normalizeMobileNumber((string) ($status['msisdn'] ?? ''));
        $expectedPhone = $this->normalizeMobileNumber((string) $order->get_billing_phone());
        if ($paymentMode !== 'MA' || $reportedPhone === '' || $expectedPhone === ''
            || !hash_equals($expectedPhone, $reportedPhone)) {
            $order->add_order_note('Easypaisa status rejected: payment mode or customer mobile number does not match this order. Manual reconciliation is required.');
            $order->update_meta_data('_pkstore_easypaisa_state', 'review');
            $order->save();
            return 'review';
        }

        $transactionStatus = strtoupper((string) ($status['transactionStatus'] ?? ''));
        $transactionId = sanitize_text_field((string) ($status['transactionId'] ?? ''));
        if ($transactionStatus === 'PAID') {
            if (!$order->is_paid() && !in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
                $order->update_meta_data('_pkstore_easypaisa_state', 'confirmed');
                if ($transactionId !== '') $order->update_meta_data('_pkstore_easypaisa_transaction_id', $transactionId);
                $order->payment_complete($transactionId !== '' ? $transactionId : (string) $order->get_meta('_pkstore_easypaisa_order_id'));
                $order->add_order_note('Easypaisa payment confirmed by authenticated transaction-status inquiry.');
            } elseif (!$order->is_paid()) {
                $order->add_order_note('Easypaisa reports PAID for a cancelled/refunded order. Manual reconciliation is required.');
                $order->update_meta_data('_pkstore_easypaisa_state', 'review');
                $order->save();
                return 'review';
            }
            return 'paid';
        }

        if (in_array($transactionStatus, ['FAILED', 'BLOCKED', 'EXPIRED', 'REVERSED'], true)) {
            if (!$order->is_paid()) {
                $order->update_meta_data('_pkstore_easypaisa_state', 'failed');
                $order->update_status('failed', 'Easypaisa transaction status: ' . sanitize_text_field($transactionStatus));
            } else {
                $order->add_order_note('Easypaisa reports ' . sanitize_text_field($transactionStatus) . ' for an already-paid order. Manual reconciliation is required.');
                $order->update_meta_data('_pkstore_easypaisa_state', 'review');
                $order->save();
                return 'review';
            }
            return 'failed';
        }

        $order->update_meta_data('_pkstore_easypaisa_state', 'pending');
        $order->save();
        return 'pending';
    }

    private function normalizeMobileNumber(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (preg_match('/^92(3[0-9]{9})$/', $digits, $matches)) {
            $digits = '0' . $matches[1];
        } elseif (preg_match('/^(3[0-9]{9})$/', $digits, $matches)) {
            $digits = '0' . $matches[1];
        }
        return $digits;
    }
}
