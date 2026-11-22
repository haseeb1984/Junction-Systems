<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Payment;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
class JazzCashGateway extends \WC_Payment_Gateway {
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
    public function process_payment($order_id):array{
        $order=wc_get_order($order_id);
        if (!$order || $order->is_paid()) { wc_add_notice('This order cannot be paid through JazzCash.','error'); return ['result'=>'fail']; }
        $mid=$this->cfg->get('JAZZCASH_MERCHANT_ID');$pass=$this->cfg->get('JAZZCASH_PASSWORD');$ik=$this->cfg->get('JAZZCASH_INTEGRITY_KEY');
        if(!$mid||!$pass||!$ik){wc_add_notice('JazzCash not configured. Contact store admin.','error');return['result'=>'fail'];}
        if (get_woocommerce_currency() !== 'PKR') {
            wc_add_notice('JazzCash is available only when the store currency is PKR.', 'error');
            return ['result' => 'fail'];
        }
        $formattedTotal = wc_format_decimal($order->get_total(), 2, false);
        if (!is_string($formattedTotal) || !preg_match('/^(0|[1-9][0-9]*)\\.[0-9]{2}$/', $formattedTotal)) {
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

        $isSandbox=$this->cfg->get('JAZZCASH_SANDBOX','0')==='1';
        $txn='T'.gmdate('YmdHis').(string)random_int(10000,99999);$amt=str_pad($minorUnits,12,'0',STR_PAD_LEFT);$dt=date('YmdHis');$exp=date('YmdHis',strtotime('+30 minutes'));
        // JazzCash POSTs transaction results to pp_ReturnURL. The callback
        // endpoint must receive that POST before the customer is redirected.
        $callbackUrl = function_exists('WC') && WC() && method_exists(WC(), 'api_request_url')
            ? WC()->api_request_url('jazzcash_callback')
            : add_query_arg('wc-api', 'jazzcash_callback', home_url('/'));
        $pd=['pp_Version'=>'2.0','pp_TxnType'=>'MWALLET','pp_Language'=>'EN','pp_MerchantID'=>$mid,'pp_Password'=>$pass,'pp_TxnRefNo'=>$txn,'pp_Amount'=>$amt,'pp_TxnCurrency'=>'PKR','pp_TxnDateTime'=>$dt,'pp_BillReference'=>(string)$order_id,'pp_Description'=>"Order #{$order_id}",'pp_TxnExpiryDateTime'=>$exp,'pp_ReturnURL'=>$callbackUrl,'pp_MobileNumber'=>preg_replace('/[^\d]/','', $order->get_billing_phone()),'pp_CNIC'=>''];
        ksort($pd);$hs=$ik.'&'.implode('&',array_values($pd));$pd['pp_SecureHash']=strtoupper(hash_hmac('sha256',$hs,$ik));
        $order->update_meta_data('_pkstore_jazzcash_txn_ref',$txn);
        $order->update_meta_data('_pkstore_jazzcash_expected_amount',$amt);
        $order->update_meta_data('_pkstore_jazzcash_merchant_id',$mid);
        $order->save();
        $r=wp_remote_post($isSandbox?self::S:self::L,['timeout'=>15,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($pd)]);
        if(is_wp_error($r)){wc_add_notice('Payment connection failed.','error');return['result'=>'fail'];}
        $b=json_decode(wp_remote_retrieve_body($r),true);
        if(($b['pp_ResponseCode']??'')==='000'){$order->update_status('pending','JazzCash payment initiated.');return['result'=>'success','redirect'=>$this->get_return_url($order)];}
        wc_add_notice('JazzCash error: '.($b['pp_ResponseMessage']??'Unknown'),'error');return['result'=>'fail'];
    }
    public function handleCallback():void{
        $payload = wp_unslash($_POST);
        $ik = $this->cfg->get('JAZZCASH_INTEGRITY_KEY');
        $receivedHash = (string)($payload['pp_SecureHash'] ?? '');
        $orderId = absint($payload['pp_BillReference'] ?? 0);

        if (!$ik || !$receivedHash || !$orderId) {
            status_header(400);
            exit;
        }

        $signedPayload = array_filter(
            $payload,
            static fn($key) => str_starts_with((string)$key, 'pp') && $key !== 'pp_SecureHash',
            ARRAY_FILTER_USE_KEY
        );
        ksort($signedPayload);
        $expectedHash = strtoupper(hash_hmac('sha256', $ik . '&' . implode('&', array_values($signedPayload)), $ik));
        if (!hash_equals($expectedHash, strtoupper($receivedHash))) {
            status_header(400);
            exit;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            status_header(404);
            exit;
        }

        $txnRef = (string)($payload['pp_TxnRefNo'] ?? '');
        $amount = (string)($payload['pp_Amount'] ?? '');
        $currency = (string)($payload['pp_TxnCurrency'] ?? '');
        $merchant = (string)($payload['pp_MerchantID'] ?? '');
        $expectedTxn = (string)$order->get_meta('_pkstore_jazzcash_txn_ref');
        $expectedAmount = (string)$order->get_meta('_pkstore_jazzcash_expected_amount');
        $expectedMerchant = (string)$order->get_meta('_pkstore_jazzcash_merchant_id');

        if (!$expectedTxn || !$expectedAmount || !$expectedMerchant
            || !hash_equals($expectedTxn, $txnRef)
            || !hash_equals($expectedAmount, $amount)
            || !hash_equals($expectedMerchant, $merchant)
            || $currency !== 'PKR'
            || ($payload['pp_ResponseCode'] ?? '') !== '000') {
            $order->add_order_note('JazzCash callback rejected: transaction details did not match the initiated payment.');
            status_header(400);
            exit;
        }

        // Replayed callbacks must not complete or mutate an already paid order.
        if (!$order->is_paid()) {
            $order->payment_complete($txnRef);
            $order->add_order_note('JazzCash payment confirmed. TxID: ' . $txnRef);
        }

        // Return the customer to the normal WooCommerce receipt after the
        // signed transaction response has been verified and applied.
        wp_safe_redirect($this->get_return_url($order));
        exit;
    }
}

