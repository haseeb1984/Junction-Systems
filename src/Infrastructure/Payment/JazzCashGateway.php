<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Payment;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
class JazzCashGateway extends \WC_Payment_Gateway {
    private LoggerInterface $log;
    private SettingsManager $cfg;
    private const L='https://payments.jazzcash.com.pk/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction';
    private const S='https://sandbox.jazzcash.com.pk/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction';
    public function __construct(LoggerInterface $log=null, SettingsManager $cfg=null){
        $this->id='jazzcash_mwallet';$this->method_title='JazzCash';$this->method_description='Pay via JazzCash Mobile Wallet or Debit/Credit Card';$this->has_fields=true;$this->supports=['products'];
        $this->log=$log??new \PkStore\Infrastructure\Logger\WpLogger();$this->cfg=$cfg??\PkStore\Settings\SettingsManager::getInstance();
        $this->init_form_fields();$this->init_settings();$this->title=$this->get_option('title','JazzCash');$this->description=$this->get_option('description','Pay securely via JazzCash');
        add_action('woocommerce_update_options_payment_gateways_'.$this->id,[$this,'process_admin_options']);
        add_action('woocommerce_api_jazzcash_callback',[$this,'handleCallback']);
    }
    public function init_form_fields():void{$this->form_fields=['enabled'=>['title'=>'Enable/Disable','type'=>'checkbox','label'=>'Enable JazzCash','default'=>'yes'],'title'=>['title'=>'Title','type'=>'text','default'=>'JazzCash'],'description'=>['title'=>'Description','type'=>'textarea','default'=>'Pay via JazzCash Mobile Wallet or Debit Card']];}
    public function payment_fields(): void {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        woocommerce_form_field('jazzcash_cnic_last6', [
            'type' => 'text',
            'label' => 'CNIC last 6 digits (JazzCash verification)',
            'required' => true,
            'class' => ['form-row-wide'],
            'custom_attributes' => [
                'inputmode' => 'numeric',
                'pattern' => '[0-9]{6}',
                'maxlength' => '6',
                'autocomplete' => 'off',
            ],
        ], '');
    }

    public function validate_fields(): bool {
        $cnic = isset($_POST['jazzcash_cnic_last6'])
            ? trim((string) wc_clean(wp_unslash($_POST['jazzcash_cnic_last6'])))
            : '';
        $phone = isset($_POST['billing_phone'])
            ? preg_replace('/\D+/', '', (string) wc_clean(wp_unslash($_POST['billing_phone'])))
            : '';
        if (!preg_match('/^[0-9]{6}$/', $cnic)) {
            wc_add_notice('Enter the last 6 digits of your CNIC for JazzCash payment.', 'error');
            return false;
        }
        if (!preg_match('/^03[0-9]{9}$/', (string) $phone)) {
            wc_add_notice('Enter a valid Pakistani mobile number (03XXXXXXXXX) for JazzCash payment.', 'error');
            return false;
        }
        return true;
    }

    private function createSecureHash(array $fields, string $integrityKey): ?string {
        $signedFields = array_filter(
            $fields,
            static fn($key) => str_starts_with((string) $key, 'pp') && $key !== 'pp_SecureHash',
            ARRAY_FILTER_USE_KEY
        );
        foreach ($signedFields as $key => $value) {
            if (!is_scalar($value)) return null;
            $signedFields[$key] = (string) $value;
        }
        ksort($signedFields);
        $hashInput = $integrityKey . '&' . implode('&', array_values($signedFields));
        return strtoupper(hash_hmac('sha256', $hashInput, $integrityKey));
    }

    private function verifySignedResponse(array $payload, string $integrityKey): bool {
        $received = strtoupper((string) ($payload['pp_SecureHash'] ?? ''));
        if ($received === '') return false;
        $expected = $this->createSecureHash($payload, $integrityKey);
        return $expected !== null && hash_equals($expected, $received);
    }
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order || $order->is_paid()) {
            wc_add_notice('This order cannot be paid through JazzCash.', 'error');
            return ['result' => 'fail'];
        }

        $mid = (string) $this->cfg->get('JAZZCASH_MERCHANT_ID');
        $pass = (string) $this->cfg->get('JAZZCASH_PASSWORD');
        $ik = (string) $this->cfg->get('JAZZCASH_INTEGRITY_KEY');
        if ($mid === '' || $pass === '' || $ik === '') {
            wc_add_notice('JazzCash is not configured. Contact the store administrator.', 'error');
            return ['result' => 'fail'];
        }
        if (get_woocommerce_currency() !== 'PKR') {
            wc_add_notice('JazzCash is available only when the store currency is PKR.', 'error');
            return ['result' => 'fail'];
        }

        $cnic = isset($_POST['jazzcash_cnic_last6'])
            ? trim((string) wc_clean(wp_unslash($_POST['jazzcash_cnic_last6'])))
            : '';
        $phone = preg_replace('/\D+/', '', (string) $order->get_billing_phone());
        if (!preg_match('/^[0-9]{6}$/', $cnic) || !preg_match('/^03[0-9]{9}$/', $phone)) {
            wc_add_notice('Valid JazzCash mobile number and CNIC last 6 digits are required.', 'error');
            return ['result' => 'fail'];
        }

        $formattedTotal = wc_format_decimal($order->get_total(), 2, false);
        if (!is_string($formattedTotal) || !preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/', $formattedTotal)) {
            wc_add_notice('The order total cannot be represented as a JazzCash PKR amount.', 'error');
            return ['result' => 'fail'];
        }
        [$wholeRupees, $paisa] = explode('.', $formattedTotal, 2);
        $minorUnits = ltrim($wholeRupees . $paisa, '0');
        $minorUnits = $minorUnits === '' ? '0' : $minorUnits;
        if ($minorUnits === '0' || strlen($minorUnits) > 12) {
            wc_add_notice('The order total is outside the supported JazzCash amount range.', 'error');
            return ['result' => 'fail'];
        }

        $isSandbox = $this->cfg->get('JAZZCASH_SANDBOX', '0') === '1';
        $txn = 'T' . gmdate('YmdHis') . (string) random_int(10000, 99999);
        $amount = str_pad($minorUnits, 12, '0', STR_PAD_LEFT);
        $txnDateTime = date('YmdHis');
        $expiryDateTime = date('YmdHis', strtotime('+30 minutes'));
        $callbackUrl = add_query_arg('wc-api', 'jazzcash_callback', home_url('/'));
        $payload = [
            'pp_Version' => '2.0',
            'pp_TxnType' => 'MWALLET',
            'pp_Language' => 'EN',
            'pp_MerchantID' => $mid,
            'pp_SubMerchantID' => '',
            'pp_Password' => $pass,
            'pp_TxnRefNo' => $txn,
            'pp_Amount' => $amount,
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => $txnDateTime,
            'pp_BillReference' => (string) $order_id,
            'pp_Description' => 'Order #' . (int) $order_id,
            'pp_TxnExpiryDateTime' => $expiryDateTime,
            'pp_ReturnURL' => $callbackUrl,
            'pp_MobileNumber' => $phone,
            'pp_CNIC' => $cnic,
        ];

        try {
            $payload['pp_SecureHash'] = $this->calculateSecureHash($payload);
        } catch (\Throwable $hashError) {
            $this->log->error('JazzCash request signature could not be generated.', [
                'error_class' => get_class($hashError),
            ]);
            wc_add_notice('JazzCash payment could not be prepared. Please contact the store administrator.', 'error');
            return ['result' => 'fail'];
        }

        $order->update_meta_data('_pkstore_jazzcash_txn_ref', $txn);
        $order->update_meta_data('_pkstore_jazzcash_expected_amount', $amount);
        $order->update_meta_data('_pkstore_jazzcash_merchant_id', $mid);
        $order->update_meta_data('_pkstore_jazzcash_txn_datetime', $txnDateTime);
        $order->update_meta_data('_pkstore_jazzcash_expiry_datetime', $expiryDateTime);
        $order->update_status('pending', 'Awaiting JazzCash mobile wallet confirmation.');
        $order->save();

        $response = wp_remote_post($isSandbox ? self::S : self::L, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            $order->add_order_note('JazzCash request could not reach the payment service.');
            wc_add_notice('Payment connection failed. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            $order->add_order_note('JazzCash returned an unreadable payment response.');
            wc_add_notice('JazzCash returned an invalid response. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        if (!$this->verifySecureHash($body)
            || (string) ($body['pp_TxnRefNo'] ?? '') !== $txn
            || (string) ($body['pp_Amount'] ?? '') !== $amount
            || (string) ($body['pp_MerchantID'] ?? '') !== $mid
            || (string) ($body['pp_TxnCurrency'] ?? '') !== 'PKR'
            || (string) ($body['pp_BillReference'] ?? '') !== (string) $order_id
            || (isset($body['pp_TxnDateTime']) && (string) $body['pp_TxnDateTime'] !== $txnDateTime)) {
            $order->add_order_note('JazzCash response rejected: signature or transaction details did not match the initiated payment.');
            wc_add_notice('JazzCash could not verify the payment response. Please contact the store administrator.', 'error');
            return ['result' => 'fail'];
        }

        $responseCode = (string) ($body['pp_ResponseCode'] ?? '');
        if ($responseCode === '000') {
            $retrievalReference = sanitize_text_field((string) ($body['pp_RetrievalReferenceNo'] ?? ''));
            $order->payment_complete($txn);
            $order->add_order_note('JazzCash payment confirmed.' . ($retrievalReference !== '' ? ' Retrieval reference: ' . $retrievalReference : ''));
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        if ($responseCode === '157') {
            $order->add_order_note('JazzCash payment is pending customer/provider confirmation.');
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        $message = sanitize_text_field((string) ($body['pp_ResponseMessage'] ?? 'Payment was declined.'));
        $order->update_status('failed', 'JazzCash payment failed: ' . $message);
        wc_add_notice('JazzCash payment failed. ' . $message, 'error');
        return ['result' => 'fail'];
    }

    private function calculateSecureHash(array $payload): string {
        $secret = (string) $this->cfg->get('JAZZCASH_INTEGRITY_KEY');
        if ($secret === '') throw new \RuntimeException('JazzCash integrity key is not configured.');

        $fields = array_filter(
            $payload,
            static fn($key): bool => str_starts_with(strtolower((string) $key), 'pp')
                && strtolower((string) $key) !== 'pp_securehash',
            ARRAY_FILTER_USE_KEY
        );
        ksort($fields, SORT_STRING);
        $message = $secret . '&' . implode('&', array_map('strval', array_values($fields)));
        $encoded = iconv('UTF-8', 'ISO-8859-1', $message);
        if ($encoded === false) throw new \RuntimeException('JazzCash signature contains characters outside the required encoding.');
        return strtoupper(hash_hmac('sha256', $encoded, $secret));
    }

    private function verifySecureHash(array $payload): bool {
        $received = strtoupper((string) ($payload['pp_SecureHash'] ?? ''));
        if ($received === '') return false;
        try {
            return hash_equals($this->calculateSecureHash($payload), $received);
        } catch (\Throwable) {
            return false;
        }
    }

    public function handleCallback(): void {
        $payload = !empty($_POST) ? wp_unslash($_POST) : [];
        if (!is_array($payload) || !$payload) {
            $rawBody = file_get_contents('php://input');
            $decoded = is_string($rawBody) ? json_decode($rawBody, true) : null;
            $payload = is_array($decoded) ? $decoded : [];
        }

        if (!$this->verifySecureHash($payload)) {
            status_header(400);
            exit;
        }

        $billReference = (string) ($payload['pp_BillReference'] ?? '');
        if ($billReference === '' || !ctype_digit($billReference)) {
            status_header(400);
            exit;
        }
        $orderId = absint($billReference);
        $order = wc_get_order($orderId);
        if (!$order) {
            status_header(404);
            exit;
        }

        $txnRef = (string) ($payload['pp_TxnRefNo'] ?? '');
        $amount = (string) ($payload['pp_Amount'] ?? '');
        $currency = (string) ($payload['pp_TxnCurrency'] ?? '');
        $merchant = (string) ($payload['pp_MerchantID'] ?? '');
        $txnDateTime = (string) ($payload['pp_TxnDateTime'] ?? '');
        $responseCode = (string) ($payload['pp_ResponseCode'] ?? '');
        $expectedTxn = (string) $order->get_meta('_pkstore_jazzcash_txn_ref');
        $expectedAmount = (string) $order->get_meta('_pkstore_jazzcash_expected_amount');
        $expectedMerchant = (string) $order->get_meta('_pkstore_jazzcash_merchant_id');
        $expectedDateTime = (string) $order->get_meta('_pkstore_jazzcash_txn_datetime');

        if ($expectedTxn === '' || $expectedAmount === '' || $expectedMerchant === '' || $expectedDateTime === ''
            || !hash_equals($expectedTxn, $txnRef)
            || !hash_equals($expectedAmount, $amount)
            || !hash_equals($expectedMerchant, $merchant)
            || !hash_equals($expectedDateTime, $txnDateTime)
            || $currency !== 'PKR'
            || (string) $billReference !== (string) $orderId
            || !in_array($responseCode, ['000', '157'], true)) {
            $order->add_order_note('JazzCash callback rejected: transaction details did not match the initiated payment.');
            status_header(400);
            exit;
        }

        if ($order->is_paid()) {
            status_header(200);
            echo 'OK';
            exit;
        }

        if (in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
            $order->add_order_note('JazzCash callback received for an order that is already cancelled or refunded; manual reconciliation is required.');
            status_header(409);
            exit;
        }

        if ($responseCode === '000') {
            $retrievalReference = sanitize_text_field((string) ($payload['pp_RetrievalReferenceNo'] ?? ''));
            $order->payment_complete($txnRef);
            $order->add_order_note('JazzCash payment confirmed by callback.' . ($retrievalReference !== '' ? ' Retrieval reference: ' . $retrievalReference : ''));
        } else {
            $order->update_status('pending', 'JazzCash payment remains pending customer/provider confirmation.');
        }

        status_header(200);
        echo 'OK';
        exit;
    }
}

