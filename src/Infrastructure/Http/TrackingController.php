<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Infrastructure\Logistics\{TcsCourier,LeopardsCourier,TraxCourier};
final class TrackingController {
    public function __construct(private readonly ServiceContainer $c, private readonly FeatureManager $features){}
    public function register():void{
        if (!$this->hasEnabledCourier()) return;
        add_action('rest_api_init',function(){
            register_rest_route('pkstore/v1','/tracking/status',[
                'methods'=>'POST',
                'callback'=>[$this,'handle'],
                'permission_callback'=>[$this,'nonce']
            ]);
        });
    }
    private function hasEnabledCourier(): bool {
        return $this->features->isEnabled('courier_tcs')
            || $this->features->isEnabled('courier_leopards')
            || $this->features->isEnabled('courier_trax');
    }
    public function nonce(\WP_REST_Request $r):bool{return(bool)wp_verify_nonce($r->get_param('_nonce'),'pkstore_nonce');}
    public function handle(\WP_REST_Request $r):\WP_REST_Response{
        $tn=sanitize_text_field($r->get_param('tracking_number')??'');
        if(empty($tn))return new \WP_REST_Response(['success'=>false,'message'=>'Tracking number required.'],422);
        $couriers=[
            'courier_tcs'=>TcsCourier::class,
            'courier_leopards'=>LeopardsCourier::class,
            'courier_trax'=>TraxCourier::class,
        ];
        $attempted=false;
        foreach($couriers as $feature=>$courierClass){
            if(!$this->features->isEnabled($feature)) continue;
            $attempted=true;
            $courier=$this->c->make($courierClass);
            $u=$courier->getTrackingUpdate($tn);
            if($u!==null){
                return new \WP_REST_Response([
                    'success'=>true,
                    'trackingNumber'=>$u->trackingNumber,
                    'courier'=>$courier->getDisplayName(),
                    'currentStatus'=>$u->status->value,
                    'events'=>[[
                        'status'=>$u->status->value,
                        'description'=>$u->description,
                        'location'=>$u->location,
                        'timestamp'=>$u->eventTimestamp->format(\DateTimeInterface::ATOM)
                    ]]
                ],200);
            }
        }
        if(!$attempted) return new \WP_REST_Response(['success'=>false,'code'=>'not_available','message'=>'Tracking is not enabled for this installation.'],403);
        return new \WP_REST_Response(['success'=>false,'code'=>'not_found','message'=>'Tracking number not found.'],404);
    }
}
