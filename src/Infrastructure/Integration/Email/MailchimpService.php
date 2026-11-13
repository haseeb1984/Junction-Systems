<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Integration\Email;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class MailchimpService {
    private const BASE='https://api.mailchimp.com/3.0';
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}

    private function request(string $method, string $endpoint, array $body=[]): ?array {
        $apiKey=$this->s->get('MAILCHIMP_API_KEY'); if (!$apiKey) return null;
        [$key,$dc]=array_pad(explode('-',$apiKey,2),2,'us1');
        $url=str_replace('api.mailchimp.com',"{$dc}.api.mailchimp.com",self::BASE).$endpoint;
        $opts=['method'=>strtoupper($method),'timeout'=>15,'headers'=>['Authorization'=>'Basic '.base64_encode('anystring:'.$apiKey),'Content-Type'=>'application/json']];
        if ($body) $opts['body']=wp_json_encode($body);
        $r=wp_remote_request($url,$opts);
        if (is_wp_error($r)) return null;
        return json_decode(wp_remote_retrieve_body($r),true);
    }

    public function subscribeCustomer(\WC_Order $order): void {
        $listId=$this->s->get('MAILCHIMP_LIST_ID'); if (!$listId) return;
        $email=$order->get_billing_email(); $hash=md5(strtolower($email));
        $this->request('PUT'.'/lists/'.$listId.'/members/'.$hash,['email_address'=>$email,'status_if_new'=>'subscribed','merge_fields'=>['FNAME'=>$order->get_billing_first_name(),'LNAME'=>$order->get_billing_last_name(),'PHONE'=>$order->get_billing_phone()]]);
    }

    public function trackPurchase(\WC_Order $order): void {
        $storeId=$this->s->get('MAILCHIMP_STORE_ID'); if (!$storeId) return;
        $items=array_values(array_map(fn($i)=>['id'=>(string)$i->get_id(),'product_id'=>(string)$i->get_product_id(),'product_variant_id'=>(string)$i->get_variation_id(),'quantity'=>$i->get_quantity(),'price'=>(float)$i->get_total()/$i->get_quantity()],$order->get_items()));
        $this->request('POST'.'/ecommerce/stores/'.$storeId.'/orders',['id'=>'WC-'.$order->get_id(),'customer'=>['id'=>'WC-'.$order->get_customer_id(),'email_address'=>$order->get_billing_email(),'first_name'=>$order->get_billing_first_name(),'last_name'=>$order->get_billing_last_name()],'currency_code'=>'PKR','order_total'=>(float)$order->get_total(),'lines'=>$items]);
    }

    public function register(): void {
        add_action('woocommerce_payment_complete',[$this,'onOrderPaid']);
        add_action('woocommerce_checkout_order_processed',[$this,'onOrderPaid']);
    }

    public function onOrderPaid(int $orderId): void {
        $order=wc_get_order($orderId); if (!$order) return;
        try { $this->subscribeCustomer($order); $this->trackPurchase($order); }
        catch (\Throwable $e) { $this->log->error('Mailchimp sync failed.',['error'=>$e->getMessage()]); }
    }
}
