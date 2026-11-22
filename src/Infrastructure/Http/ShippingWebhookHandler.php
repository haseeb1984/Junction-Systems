<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Domain\Logistics\DTO\TrackingStatus;
use PkStore\Infrastructure\Logistics\{TcsCourier,LeopardsCourier,TraxCourier};
final class ShippingWebhookHandler {
    public function __construct(private readonly ServiceContainer $c, private readonly FeatureManager $features){}
    public function register():void{
        add_action('rest_api_init',function(){
            $featureMap=['tcs'=>'courier_tcs','leopards'=>'courier_leopards','trax'=>'courier_trax'];
            foreach($featureMap as $cc=>$feature){
                if(!$this->features->isEnabled($feature)) continue;
                register_rest_route('pkstore/v1',"/webhooks/{$cc}",[
                    'methods'=>'POST',
                    'callback'=>fn(\WP_REST_Request $r)=>$this->handle($r,$cc),
                    'permission_callback'=>'__return_true'
                ]);
            }
        });
    }
    public function handle(\WP_REST_Request $r,string $cc):\WP_REST_Response{
        $featureMap=['tcs'=>'courier_tcs','leopards'=>'courier_leopards','trax'=>'courier_trax'];
        if(!isset($featureMap[$cc]) || !$this->features->isEnabled($featureMap[$cc])) {
            return new \WP_REST_Response(['status'=>'disabled'],403);
        }
        $payload=$r->get_json_params()??[];$headers=array_map(fn($v)=>is_array($v)?$v[0]:$v,$r->get_headers());
        $map=['tcs'=>TcsCourier::class,'leopards'=>LeopardsCourier::class,'trax'=>TraxCourier::class];
        $courier=$this->c->make($map[$cc]);
        if(!$courier->verifyWebhookSignature($payload,$headers)){error_log('[PK Store] Rejected courier webhook with invalid signature ('.$cc.').');return new \WP_REST_Response(['status'=>'invalid_signature'],401);}
        $tn=match($cc){'tcs'=>sanitize_text_field($payload['cn_number']??$payload['tracking_number']??''),'leopards'=>sanitize_text_field($payload['track_number']??''),'trax'=>sanitize_text_field($payload['tracking_number']??''),default=>''};
        if(empty($tn))return new \WP_REST_Response(['status'=>'missing_tracking_number'],422);
        if (!function_exists('wc_get_orders')) {
            return new \WP_REST_Response(['status'=>'unavailable'],503);
        }
        // Query through WooCommerce's order datastore so the same callback
        // lookup works with both legacy postmeta and HPOS order metadata.
        $orderQuery = [
            'limit' => 2,
            'return' => 'objects',
        ];
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $orderQuery['meta_query'] = [[
                'key' => '_tracking_' . $cc,
                'value' => $tn,
                'compare' => '=',
            ]];
        } else {
            $orderQuery['meta_key'] = '_tracking_' . $cc;
            $orderQuery['meta_value'] = $tn;
        }
        $matches = wc_get_orders($orderQuery);
        if (!is_array($matches)) {
            return new \WP_REST_Response(['status'=>'lookup_unavailable'],503);
        }
        if (count($matches) === 0) {
            error_log('[PK Store] Courier webhook could not match an order ('.$cc.').');
            return new \WP_REST_Response(['status'=>'order_not_found'],404);
        }
        if (count($matches) !== 1) {
            error_log('[PK Store] Courier webhook tracking reference is ambiguous ('.$cc.').');
            return new \WP_REST_Response(['status'=>'ambiguous_order'],409);
        }
        $order = $matches[0];
        if (!($order instanceof \WC_Order)) {
            return new \WP_REST_Response(['status'=>'ok'],200);
        }
        $eventHash = hash('sha256', $cc . '|' . $tn . '|' . (string) wp_json_encode($payload));
        $seenEvents = $order->get_meta('_pkstore_courier_webhook_hashes', true);
        if (!is_array($seenEvents)) $seenEvents = [];
        if (in_array($eventHash, $seenEvents, true)) {
            return new \WP_REST_Response(['status'=>'duplicate'],200);
        }
        $raw=match($cc){'tcs'=>(string)($payload['status_code']??$payload['status']??'UNKNOWN'),'leopards'=>(string)($payload['packet_status']??'0'),'trax'=>(string)($payload['status']??'unknown'),default=>'unknown'};
        $status=TrackingStatus::fromCourierCode($raw,$cc);
        $targetStatus = $status->toWooCommerceStatus();
        $normalizedTargetStatus = str_starts_with($targetStatus, 'wc-')
            ? substr($targetStatus, 3)
            : $targetStatus;
        if ($status->canAdvanceFrom($order->get_status())
            && $order->get_status() !== $normalizedTargetStatus) {
            $loc=sanitize_text_field($payload['location']??$payload['city']??$payload['current_city']??'');
            $order->update_status($targetStatus,"[{$cc}] ".ucwords(str_replace('_',' ',$status->value)).($loc?" ({$loc})":""));
        }
        if($status===TrackingStatus::Delivered&&str_contains($order->get_payment_method(),'cod')&&!$order->get_meta('_cod_delivered_at')){
            $order->update_meta_data('_cod_delivered_at',current_time('mysql'));
            $order->update_meta_data('_cod_delivered_by',$cc);
            $order->save_meta_data();
            do_action('pkstore_cod_delivered',$order->get_id(),$cc,$tn);
        }
        $seenEvents[] = $eventHash;
        $order->update_meta_data('_pkstore_courier_webhook_hashes', array_slice($seenEvents, -20));
        $order->save_meta_data();
        return new \WP_REST_Response(['status'=>'ok'],200);
    }
}
