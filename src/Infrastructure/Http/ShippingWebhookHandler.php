<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Container\ServiceContainer;
use PkStore\Domain\Logistics\DTO\TrackingStatus;
use PkStore\Infrastructure\Logistics\{TcsCourier,LeopardsCourier,TraxCourier};
final class ShippingWebhookHandler {
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_action('rest_api_init',function(){foreach(['tcs','leopards','trax']as $cc){register_rest_route('pkstore/v1',"/webhooks/{$cc}",['methods'=>'POST','callback'=>fn(\WP_REST_Request $r)=>$this->handle($r,$cc),'permission_callback'=>'__return_true']);}});}
    public function handle(\WP_REST_Request $r,string $cc):\WP_REST_Response{
        $payload=$r->get_json_params()??[];$headers=array_map(fn($v)=>is_array($v)?$v[0]:$v,$r->get_headers());
        $map=['tcs'=>TcsCourier::class,'leopards'=>LeopardsCourier::class,'trax'=>TraxCourier::class];
        $courier=$this->c->make($map[$cc]);
        if(!$courier->verifyWebhookSignature($payload,$headers))return new \WP_REST_Response(['status'=>'ok'],200);
        $tn=match($cc){'tcs'=>sanitize_text_field($payload['cn_number']??$payload['tracking_number']??''),'leopards'=>sanitize_text_field($payload['track_number']??''),'trax'=>sanitize_text_field($payload['tracking_number']??''),default=>''};
        if(empty($tn))return new \WP_REST_Response(['status'=>'ok'],200);
        global $wpdb;$oid=$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1","_tracking_{$cc}",$tn));
        if(!$oid)return new \WP_REST_Response(['status'=>'ok'],200);
        $raw=match($cc){'tcs'=>(string)($payload['status_code']??$payload['status']??'UNKNOWN'),'leopards'=>(string)($payload['packet_status']??'0'),'trax'=>(string)($payload['status']??'unknown'),default=>'unknown'};
        $status=TrackingStatus::fromCourierCode($raw,$cc);
        $order=wc_get_order((int)$oid);if(!($order instanceof \WC_Order))return new \WP_REST_Response(['status'=>'ok'],200);
        if(in_array($order->get_status(),['completed','processing'],true)&&$status===TrackingStatus::Delivered)return new \WP_REST_Response(['status'=>'ok'],200);
        $loc=sanitize_text_field($payload['location']??$payload['city']??$payload['current_city']??'');
        $order->update_status($status->toWooCommerceStatus(),"[{$cc}] ".ucwords(str_replace('_',' ',$status->value)).($loc?" ({$loc})":""));
        if($status===TrackingStatus::Delivered&&str_contains($order->get_payment_method(),'cod')){$order->update_meta_data('_cod_delivered_at',current_time('mysql'));$order->update_meta_data('_cod_delivered_by',$cc);$order->save_meta_data();do_action('pkstore_cod_delivered',$order->get_id(),$cc,$tn);}
        return new \WP_REST_Response(['status'=>'ok'],200);
    }
}
