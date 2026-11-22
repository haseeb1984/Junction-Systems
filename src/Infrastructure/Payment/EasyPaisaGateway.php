<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Payment;

use PkStore\Settings\SettingsManager;

final class EasyPaisaGateway extends \WC_Payment_Gateway {
    private const LIVE_BASE = 'https://easypay.easypaisa.com.pk/easypay-service/rest/v4';
    private const SANDBOX_BASE = 'https://easypaystg.easypaisa.com.pk/easypay-service/rest/v4';

    private SettingsManager $cfg;

    public function __construct(?SettingsManager $cfg = null) {
        $this->id = 'easypaisa';
        $this->method_title = 'EasyPaisa';
        $this->method_description = 'Pay securely through an EasyPaisa Mobile Account.';
        $this->has_fields = true;
        $this->supports = ['products'];
        $this->cfg = $cfg ?? SettingsManager::getInstance();

        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', 'EasyPaisa');
        $this->description = $this->get_option('description', 'Pay using your EasyPaisa mobile account.');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_easypaisa_ipn', [$this, 'handleIpn']);
        add_action('woocommerce_thankyou_easypaisa', [$this, 'verifyOnReturn'], 10, 1);
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable EasyPaisa',
                'default' => 'no',
            ],
            'title' => [
                'title' => 'Title',
                'type' => 'text',
                'default' => 'EasyPaisa',
            ],
            'description' => [
                'title' => 'Description',
                'type' => 'textarea',
                'default' => 'Pay using your EasyPaisa mobile account.',
            ],
        ];
    }

    public function is_available(): bool {
        return parent::is_available()
            && get_woocommerce_currency() === 'PKR'
            && $this->credentials() !== null;
    }

    public function payment_fields(): void {
        if ($this->description !== '') {
            echo wpautop(wp_kses_post($this->description));
        }
        echo '<p class="form-row form-row-wide">';
        echo '<label for="easypaisa_mobile_account_no">EasyPaisa mobile number <span class="required">*</span></label>';
        echo '<input id="easypaisa_mobile_account_no" name="easypaisa_mobile_account_no" type="tel" inputmode="numeric" autocomplete="tel" placeholder="03XXXXXXXXX" required>';
        echo '</p>';
    }

    public function validate_fields(): bool {
        $mobile = $this->normalizeMobileNumber((string) wp_unslash($_POST['easypaisa_mobile_account_no'] ?? ''));
        if ($mobile === null) {
            wc_add_notice('Enter a valid 11-digit EasyPaisa mobile account number.', 'error');
            return false;
        }
        return true;
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->is_paid()) {
            wc_add_notice('This order cannot be paid through EasyPaisa.', 'error');
            return ['result' => 'fail'];
        }
        if (get_woocommerce_currency() !== 'PKR') {
            wc_add_notice('EasyPaisa is available only when the store currency is PKR.', 'error');
            return ['result' => 'fail'];
        }

        $credentials = $this->credentials();
        if ($credentials === null) {
            wc_add_notice('EasyPaisa is not configured. Contact the store administrator.', 'error');
            return ['result' => 'fail'];
        }

        $mobile = $this->normalizeMobileNumber((string) wp_unslash($_POST['easypaisa_mobile_account_no'] ?? ''));
        if ($mobile === null) {
            wc_add_notice('Enter a valid 11-digit EasyPaisa mobile account number.', 'error');
            return ['result' => 'fail'];
        }

        $email = sanitize_email((string) $order->get_billing_email());
        if ($email === '' || !is_email($email)) {
            wc_add_notice('A valid billing email is required for EasyPaisa payments.', 'error');
            return ['result' => 'fail'];
        }

        $amount = wc_format_decimal($order->get_total(), 2, false);
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/', $amount)
            || (float) $amount <= 0) {
            wc_add_notice('The order total cannot be represented as a valid EasyPaisa PKR amount.', 'error');
            return ['result' => 'fail'];
        }

        $providerOrderId = 'EP' . (int) $order->get_id() . strtoupper(bin2hex(random_bytes(3)));
        $storeId = (string) $credentials['store_id'];
        $order->update_meta_data('_pkstore_easypaisa_order_id', $providerOrderId);
        $order->update_meta_data('_pkstore_easypaisa_expected_amount', $amount);
        $order->update_meta_data('_pkstore_easypaisa_store_id', $storeId);
        $order->update_meta_data('_pkstore_easypaisa_account_number', (string) $credentials['account_number']);
        $order->update_meta_data('_pkstore_easypaisa_mobile_hash', hash_hmac('sha256', $mobile, wp_salt('auth')));
        $order->update_meta_data('_pkstore_easypaisa_state', 'initiating');
        $order->save();

        try {
            $response = $this->apiRequest('initiate-ma-transaction', [
                'orderId' => $providerOrderId,
                'storeId' => $storeId,
                'transactionAmount' => $amount,
                'transactionType' => 'MA',
                'mobileAccountNo' => $mobile,
                'emailAddress' => $email,
            ], $credentials);
        } catch (\Throwable $error) {
            $order->update_meta_data('_pkstore_easypaisa_state', 'unknown');
            $order->add_order_note('EasyPaisa initiation status is unknown. Reconcile the transaction with the provider before retrying.');
            $order->save();
            error_log('[PK Store EasyPaisa] Initiation request failed (' . get_class($error) . ').');
            wc_add_notice('EasyPaisa could not confirm the payment request. Please contact the store before retrying.', 'error');
            return ['result' => 'fail'];
        }

        $responseCode = (string) ($response['responseCode'] ?? '');
        if ($responseCode !== '0000'
            || !hash_equals($providerOrderId, (string) ($response['orderId'] ?? ''))
            || !hash_equals($storeId, (string) ($response['storeId'] ?? ''))) {
            $order->update_meta_data('_pkstore_easypaisa_state', 'failed');
            $order->add_order_note('EasyPaisa did not accept the payment request. Provider response code: ' . sanitize_text_field($responseCode));
            $order->save();
            wc_add_notice('EasyPaisa could not initiate this payment. Please try another payment method.', 'error');
            return ['result' => 'fail'];
        }

        $transactionId = sanitize_text_field((string) ($response['transactionId'] ?? ''));
        if ($transactionId !== '') {
            $order->update_meta_data('_pkstore_easypaisa_transaction_id', $transactionId);
        }
        $order->update_meta_data('_pkstore_easypaisa_state', 'pending');
        $order->update_status('pending', 'EasyPaisa Mobile Account payment request initiated. Awaiting provider confirmation.');
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    public function handleIpn(): void {
        $providerOrderId = sanitize_text_field((string) wp_unslash($_REQUEST['orderId'] ?? $_POST['orderId'] ?? ''));
        if ($providerOrderId === '' || strlen($providerOrderId) > 64 || !function_exists('wc_get_orders')) {
            status_header(400);
            exit;
        }

        $orders = wc_get_orders([
            'limit' => 2,
            'return' => 'objects',
            'meta_key' => '_pkstore_easypaisa_order_id',
            'meta_value' => $providerOrderId,
        ]);
        if (!is_array($orders) || count($orders) !== 1 || !$orders[0] instanceof \WC_Order) {
            status_header(count((array) $orders) > 1 ? 409 : 404);
            exit;
        }

        $this->applyProviderStatus($orders[0]);
        status_header(200);
        echo 'OK';
        exit;
    }

    public function verifyOnReturn(int $orderId): void {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && $order->get_payment_method() === $this->id) {
            $this->applyProviderStatus($order);
        }
    }

    public function applyProviderStatus(\WC_Order $order): bool {
        if ($order->is_paid()) return true;
        if (in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
            $order->update_meta_data('_pkstore_easypaisa_state', 'late_payment_review');
            $order->add_order_note('EasyPaisa confirmation arrived after the order was cancelled/refunded. Manual reconciliation is required.');
            $order->save();
            return false;
        }

        $credentials = $this->credentials();
        $providerOrderId = (string) $order->get_meta('_pkstore_easypaisa_order_id');
        $expectedAmount = (string) $order->get_meta('_pkstore_easypaisa_expected_amount');
        $expectedStoreId = (string) $order->get_meta('_pkstore_easypaisa_store_id');
        $expectedAccount = (string) $order->get_meta('_pkstore_easypaisa_account_number');
        if ($credentials === null || $providerOrderId === '' || $expectedAmount === ''
            || $expectedStoreId === '' || $expectedAccount === '') {
            return false;
        }

        try {
            $response = $this->apiRequest('inquire-transaction', [
                'orderId' => $providerOrderId,
                'storeId' => $expectedStoreId,
                'accountNum' => $expectedAccount,
            ], $credentials);
        } catch (\Throwable $error) {
            error_log('[PK Store EasyPaisa] Status inquiry failed (' . get_class($error) . ').');
            return false;
        }

        $responseOrderId = (string) ($response['orderId'] ?? '');
        $responseStoreId = (string) ($response['storeId'] ?? '');
        $responseAccount = (string) ($response['accountNum'] ?? '');
        $responseAmount = wc_format_decimal((string) ($response['transactionAmount'] ?? ''), 2, false);
        $responseCode = (string) ($response['responseCode'] ?? '');
        $transactionStatus = (string) ($response['transactionStatus'] ?? '');
        $transactionId = (string) ($response['transactionId'] ?? '');

        if ($responseCode !== '0000'
            || !hash_equals($providerOrderId, $responseOrderId)
            || !hash_equals($expectedStoreId, $responseStoreId)
            || !hash_equals($expectedAccount, $responseAccount)
            || !is_string($responseAmount)
            || !hash_equals($expectedAmount, $responseAmount)) {
            $order->add_order_note('EasyPaisa status inquiry did not match the initiated order, merchant account, or amount. Payment was not completed.');
            $order->update_meta_data('_pkstore_easypaisa_state', 'verification_mismatch');
            $order->save();
            return false;
        }

        if ($transactionStatus === 'PAID') {
            $order->update_meta_data('_pkstore_easypaisa_state', 'paid');
            if ($transactionId !== '') {
                $order->update_meta_data('_pkstore_easypaisa_transaction_id', sanitize_text_field($transactionId));
            }
            $order->save();
            $order->payment_complete($transactionId !== '' ? sanitize_text_field($transactionId) : $providerOrderId);
            $order->add_order_note('EasyPaisa payment confirmed by server-to-server status inquiry.');
            return true;
        }

        if (in_array($transactionStatus, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
            $order->update_meta_data('_pkstore_easypaisa_state', 'failed');
            $order->update_status('failed', 'EasyPaisa transaction status: ' . sanitize_text_field($transactionStatus));
            $order->save();
            return false;
        }

        $order->update_meta_data('_pkstore_easypaisa_state', 'pending');
        $order->save();
        return false;
    }

    private function credentials(): ?array {
        $username = $this->cfg->get('EASYPAISA_USERNAME');
        $password = $this->cfg->get('EASYPAISA_PASSWORD');
        $storeId = $this->cfg->get('EASYPAISA_STORE_ID');
        $accountNumber = $this->cfg->get('EASYPAISA_ACCOUNT_NUMBER');
        if ($username === '' || $password === '' || $storeId === '' || $accountNumber === ''
            || !ctype_digit($storeId)) {
            return null;
        }
        return [
            'username' => $username,
            'password' => $password,
            'store_id' => $storeId,
            'account_number' => $accountNumber,
            'sandbox' => $this->cfg->get('EASYPAISA_SANDBOX', '1') === '1',
        ];
    }

    private function apiRequest(string $endpoint, array $payload, array $credentials): array {
        $base = $credentials['sandbox'] ? self::SANDBOX_BASE : self::LIVE_BASE;
        $response = wp_remote_post($base . '/' . $endpoint, [
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Credentials' => base64_encode($credentials['username'] . ':' . $credentials['password']),
            ],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('EasyPaisa provider request failed.');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new \RuntimeException('EasyPaisa provider returned an invalid response.');
        }
        return $body;
    }

    private function normalizeMobileNumber(string $raw): ?string {
        $digits = preg_replace('/\D/', '', $raw);
        if (!is_string($digits)) return null;
        if (str_starts_with($digits, '92')) $digits = '0' . substr($digits, 2);
        return preg_match('/^03[0-9]{9}$/', $digits) === 1 ? $digits : null;
    }
}
