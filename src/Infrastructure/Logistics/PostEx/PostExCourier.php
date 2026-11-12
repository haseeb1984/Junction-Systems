<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Logistics\PostEx;
use JunctionStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class PostExCourier {
    private const BASE_LIVE    = 'https://api.postex.pk/services/integration/api/order/v3/create-order';
    private const BASE_SANDBOX = 'https://sandbox.postex.pk/services/integration/api/order/v3/create-order';
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}
    public function bookShipment(\WC_Order $order): array {
        $token=$this->s->get('POSTEX_TOKEN');
        if(!$token) throw new \RuntimeException('PostEx token not configured.');
        $isSandbox=$this->s->get('POSTEX_SANDBOX','1')==='1';
        $payload=['orderRefNumber'=>'PEX-'.$order->get_id(),'orderTypeCode'=>'Normal','paymentTypeCode'=>$order->get_payment_method()==='cod'?'COD':'Prepaid','orderDetail'=>substr($this->getItemsSummary($order),0,200),'merchantOrderId'=>(string)$order->get_id(),'orderAmount'=>(int)$order->get_total(),'customerName'=>$order->get_shipping_first_name().' '.$order->get_shipping_last_name(),'customerPhone'=>$order->get_billing_phone(),'deliveryAddress'=>$order->get_shipping_address_1().' '.$order->get_shipping_address_2(),'cityName'=>$order->get_shipping_city(),'storeId'=>$this->s->get('POSTEX_STORE_ID','1')];
        $r=wp_remote_post($isSandbox?self::BASE_SANDBOX:self::BASE_LIVE,['timeout'=>20,'headers'=>['Content-Type'=>'application/json','token'=>$token],'body'=>wp_json_encode($payload)]);
        if(is_wp_error($r)) throw new \RuntimeException('PostEx API error: '.$r->get_error_message());
        $b=json_decode(wp_remote_retrieve_body($r),true);
        $this->log->info('PostEx booking.',['order'=>$order->get_id(),'status'=>$b['dist']['statusCode']??'unknown']);
        if(($b['dist']['statusCode']??'')!=='200') throw new \RuntimeException('PostEx rejected: '.($b['dist']['statusMessage']??'Unknown error'));
        return['tracking_number'=>$b['dist']['data']['trackingNumber']??'','courier'=>'postex'];
    }
    public function getRemittance(): array {
        $token=$this->s->get('POSTEX_TOKEN'); if(!$token) return[];
        $r=wp_remote_get('https://api.postex.pk/services/integration/api/order/v3/get-rider-cod-remittances',['timeout'=>15,'headers'=>['token'=>$token]]);
        if(is_wp_error($r)) return[];
        return json_decode(wp_remote_retrieve_body($r),true)['dist']['data']??[];
    }
    private function getItemsSummary(\WC_Order $order): string {
        return implode(', ',array_map(fn($i)=>$i->get_name().' x'.$i->get_quantity(),$order->get_items()));
    }
}
