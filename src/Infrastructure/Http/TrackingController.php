<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Http;
use JunctionStore\Container\ServiceContainer;
use JunctionStore\Infrastructure\Logistics\{TcsCourier,LeopardsCourier,TraxCourier};
final class TrackingController {
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_action('rest_api_init',function(){register_rest_route('pkstore/v1','/tracking/status',['methods'=>'POST','callback'=>[$this,'handle'],'permission_callback'=>[$this,'nonce']]);});}
    public function nonce(\WP_REST_Request $r):bool{return(bool)wp_verify_nonce($r->get_param('_nonce'),'pkstore_nonce');}
    public function handle(\WP_REST_Request $r):\WP_REST_Response{
        $tn=sanitize_text_field($r->get_param('tracking_number')??'');if(empty($tn))return new \WP_REST_Response(['success'=>false,'message'=>'Tracking number required.'],422);
        $couriers=[$this->c->make(TcsCourier::class),$this->c->make(LeopardsCourier::class),$this->c->make(TraxCourier::class)];
        foreach($couriers as $c){$u=$c->getTrackingUpdate($tn);if($u!==null){return new \WP_REST_Response(['success'=>true,'trackingNumber'=>$u->trackingNumber,'courier'=>$c->getDisplayName(),'currentStatus'=>$u->status->value,'events'=>[['status'=>$u->status->value,'description'=>$u->description,'location'=>$u->location,'timestamp'=>$u->eventTimestamp->format(\DateTimeInterface::ATOM)]]],200);}}
        return new \WP_REST_Response(['success'=>false,'code'=>'not_found','message'=>'Tracking number not found.'],404);
    }
}
