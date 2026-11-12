<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Payment\Foree;
use JunctionStore\Settings\SettingsManager;

class ForeePayGateway extends \WC_Payment_Gateway {
    private SettingsManager $s;
    public function __construct() {
        $this->id='foree_pay'; $this->method_title='Foree Pay (Card)';
        $this->method_description='Accept debit/credit cards via Foree Pay terminal.';
        $this->has_fields=true; $this->supports=['products','refunds'];
        $this->init_form_fields(); $this->init_settings();
        $this->title=$this->get_option('title','Pay by Card (Foree Pay)');
        $this->s=SettingsManager::getInstance();
        add_action('woocommerce_update_options_payment_gateways_'.$this->id,[$this,'process_admin_options']);
        add_action('woocommerce_api_foree_callback',[$this,'handleCallback']);
    }
    public function init_form_fields(): void {
        $this->form_fields=['enabled'=>['title'=>'Enable','type'=>'checkbox','default'=>'no'],'title'=>['title'=>'Title','type'=>'text','default'=>'Pay by Card'],'description'=>['title'=>'Description','type'=>'text','default'=>'Secure card payment via Foree Pay.']];
    }
    public function process_payment($order_id): array {
        $order=$this->getOrder($order_id); if(!$order) return ['result'=>'failure'];
        $apiKey=$this->s->get('FOREE_API_KEY'); $merchantId=$this->s->get('FOREE_MERCHANT_ID');
        if(!$apiKey||!$merchantId) { wc_add_notice('Foree Pay is not configured.','error'); return ['result'=>'failure']; }
        $payload=['merchant_id'=>$merchantId,'order_id'=>$order->get_order_number(),'amount'=>number_format((float)$order->get_total(),2,'.',''),'currency'=>'PKR','customer_email'=>$order->get_billing_email(),'customer_phone'=>$order->get_billing_phone(),'return_url'=>home_url('/wc-api/foree_callback/'),'cancel_url'=>wc_get_checkout_url(),'description'=>'Order #'.$order->get_order_number()];
        $isSandbox=$this->s->get('FOREE_SANDBOX','1')==='1';
        $base=$isSandbox?'https://sandbox.foreepay.pk':'https://api.foreepay.pk';
        $r=wp_remote_post($base.'/api/v1/payment/initiate',['timeout'=>15,'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$apiKey],'body'=>wp_json_encode($payload)]);
        if(is_wp_error($r)){wc_add_notice('Payment gateway error.','error');return['result'=>'failure'];}
        $b=json_decode(wp_remote_retrieve_body($r),true);
        if(empty($b['redirect_url'])){wc_add_notice($b['message']??'Payment failed.','error');return['result'=>'failure'];}
        $order->update_meta_data('_foree_session_id',$b['session_id']??''); $order->save();
        return['result'=>'success','redirect'=>$b['redirect_url']];
    }
    public function handleCallback(): void {
        $sessionId=sanitize_text_field($_GET['session_id']??'');
        $status=sanitize_text_field($_GET['status']??'');
        $orderId=sanitize_text_field($_GET['order_id']??'');
        $order=wc_get_order((int)$orderId);
        if(!$order){wp_redirect(wc_get_checkout_url());exit;}
        if($status==='SUCCESS'){$order->payment_complete($sessionId);wp_redirect($this->get_return_url($order));}
        else{wc_add_notice('Payment was not completed. Please try again.','error');wp_redirect(wc_get_checkout_url());}
        exit;
    }
    private function getOrder(int $id): ?\WC_Order { $o=wc_get_order($id); return ($o instanceof \WC_Order)?$o:null; }
}
