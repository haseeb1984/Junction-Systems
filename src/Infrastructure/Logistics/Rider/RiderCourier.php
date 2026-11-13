<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics\Rider;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class RiderCourier {
    private const BASE = 'https://api.rider.pk/v1';
    private const SAME_DAY_CITIES = ['karachi','lahore','islamabad','rawalpindi'];
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}
    public function bookShipment(\WC_Order $order): array {
        $apiKey=$this->s->get('RIDER_API_KEY'); if(!$apiKey) throw new \RuntimeException('Rider.pk API key not configured.');
        $city=strtolower($order->get_shipping_city());
        if(!in_array($city,self::SAME_DAY_CITIES,true)) throw new \RuntimeException("Rider.pk does not cover city: {$city}");
        $payload=['client_order_id'=>'RDR-'.$order->get_id(),'pickup'=>['address'=>$this->s->get('STORE_ADDRESS','Store Address'),'city'=>$this->s->get('STORE_CITY','Karachi'),'contact_name'=>get_bloginfo('name'),'contact_phone'=>$this->s->get('STORE_PHONE','')],'delivery'=>['address'=>$order->get_shipping_address_1().' '.$order->get_shipping_address_2(),'city'=>$order->get_shipping_city(),'contact_name'=>$order->get_shipping_first_name().' '.$order->get_shipping_last_name(),'contact_phone'=>$order->get_billing_phone()],'parcel'=>['weight'=>1,'description'=>substr(implode(', ',array_map(fn($i)=>$i->get_name(),$order->get_items())),0,150)],'cod_amount'=>$order->get_payment_method()==='cod'?(int)$order->get_total():0,'service_type'=>'same_day'];
        $r=wp_remote_post(self::BASE.'/shipments',['timeout'=>20,'headers'=>['Content-Type'=>'application/json','X-API-Key'=>$apiKey],'body'=>wp_json_encode($payload)]);
        if(is_wp_error($r)) throw new \RuntimeException('Rider API error: '.$r->get_error_message());
        $b=json_decode(wp_remote_retrieve_body($r),true);
        $this->log->info('Rider.pk booking.',['order'=>$order->get_id(),'response'=>$b]);
        if(empty($b['tracking_id'])) throw new \RuntimeException('Rider rejected: '.($b['message']??'Unknown'));
        return['tracking_number'=>$b['tracking_id'],'courier'=>'rider','estimated_delivery'=>$b['estimated_delivery']??'Same day'];
    }
    public function isCitySupported(string $city): bool { return in_array(strtolower($city),self::SAME_DAY_CITIES,true); }
}
