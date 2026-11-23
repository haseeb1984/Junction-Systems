<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Integration\Email;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class KlaviyoService {
    private const BASE='https://a.klaviyo.com/api';
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}

    private function request(string $method, string $endpoint, array $body=[]): bool {
        $apiKey=$this->s->get('KLAVIYO_PRIVATE_KEY'); if (!$apiKey) return false;
        $opts=['method'=>strtoupper($method),'timeout'=>15,'headers'=>['Authorization'=>'Klaviyo-API-Key '.$apiKey,'Content-Type'=>'application/json','revision'=>'2024-02-15']];
        if ($body) $opts['body']=wp_json_encode($body);
        $r=wp_remote_request(self::BASE.$endpoint,$opts);
        return !is_wp_error($r)&&wp_remote_retrieve_response_code($r)<300;
    }

    public function trackEvent(string $eventName, string $email, array $properties=[]): void {
        $this->request('POST'.'/events',['data'=>['type'=>'event','attributes'=>['metric'=>['data'=>['type'=>'metric','attributes'=>['name'=>$eventName]]],'profile'=>['data'=>['type'=>'profile','attributes'=>['email'=>$email]]],'properties'=>$properties,'time'=>date('c')]]]);
    }

    public function subscribeToList(string $email, string $firstName, string $lastName): void {
        $listId=$this->s->get('KLAVIYO_LIST_ID'); if (!$listId) return;
        $profileId=$this->getOrCreateProfile($email,$firstName,$lastName);
        if ($profileId) $this->request('POST'.'/lists/'.$listId.'/relationships/profiles',['data'=>[['type'=>'profile','id'=>$profileId]]]);
    }

    private function getOrCreateProfile(string $email, string $first, string $last): ?string {
        $filter=rawurlencode('equals(email,"'.sanitize_email($email).'")');
        $r=wp_remote_request(self::BASE.'/profiles/?filter='.$filter,['timeout'=>15,'headers'=>['Authorization'=>'Klaviyo-API-Key '.$this->s->get('KLAVIYO_PRIVATE_KEY'),'revision'=>'2024-02-15']]);
        if (is_wp_error($r)) return null;
        $b=json_decode(wp_remote_retrieve_body($r),true);
        if (!empty($b['data'][0]['id'])) return $b['data'][0]['id'];
        // Create new profile
        $res=json_decode(wp_remote_retrieve_body(wp_remote_post(self::BASE.'/profiles',['timeout'=>15,'headers'=>['Authorization'=>'Klaviyo-API-Key '.$this->s->get('KLAVIYO_PRIVATE_KEY'),'Content-Type'=>'application/json','revision'=>'2024-02-15'],'body'=>wp_json_encode(['data'=>['type'=>'profile','attributes'=>['email'=>$email,'first_name'=>$first,'last_name'=>$last]]])])),true);
        return $res['data']['id']??null;
    }

    public function register(): void {
        add_action('woocommerce_payment_complete',[$this,'onOrderPaid']);
        add_action('woocommerce_cart_item_added',[$this,'onAddToCart'],10,2);
    }

    public function onOrderPaid(int $orderId): void {
        $order=wc_get_order($orderId); if (!$order) return;
        try {
            $this->subscribeToList($order->get_billing_email(),$order->get_billing_first_name(),$order->get_billing_last_name());
            $this->trackEvent('Placed Order',$order->get_billing_email(),['order_id'=>$order->get_order_number(),'value'=>(float)$order->get_total(),'currency'=>'PKR','items'=>array_values(array_map(fn($i)=>['name'=>$i->get_name(),'quantity'=>$i->get_quantity(),'price'=>(float)($i->get_total()/$i->get_quantity())],$order->get_items()))]);
        } catch (\Throwable $e) { $this->log->error('Klaviyo sync failed.',['error'=>$e->getMessage()]); }
    }

    public function onAddToCart(string $cartItemKey, array $cartItem): void {
        $user=wp_get_current_user(); if (!$user->ID) return;
        try { $this->trackEvent('Added to Cart',$user->user_email,['product_id'=>$cartItem['product_id'],'quantity'=>$cartItem['quantity']]); }
        catch (\Throwable $e) {}
    }
}
