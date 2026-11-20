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
        $isSandbox=$this->cfg->get('JAZZCASH_SANDBOX','0')==='1';
        $txn='T'.gmdate('YmdHis').(string)random_int(10000,99999);$amt=str_pad((string)((int)($order->get_total()*100)),12,'0',STR_PAD_LEFT);$dt=date('YmdHis');$exp=date('YmdHis',strtotime('+30 minutes'));
        $pd=['pp_Version'=>'2.0','pp_TxnType'=>'MWALLET','pp_Language'=>'EN','pp_MerchantID'=>$mid,'pp_Password'=>$pass,'pp_TxnRefNo'=>$txn,'pp_Amount'=>$amt,'pp_TxnCurrency'=>'PKR','pp_TxnDateTime'=>$dt,'pp_BillReference'=>(string)$order_id,'pp_Description'=>"Order #{$order_id}",'pp_TxnExpiryDateTime'=>$exp,'pp_ReturnURL'=>$this->get_return_url($order),'pp_MobileNumber'=>preg_replace('/[^\d]/','', $order->get_billing_phone()),'pp_CNIC'=>''];
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
            static fn($key) => str_starts_with((string)$key, 'pp_') && $key !== 'pp_SecureHash',
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

        status_header(200);
        echo 'OK';
        exit;
    }
}

