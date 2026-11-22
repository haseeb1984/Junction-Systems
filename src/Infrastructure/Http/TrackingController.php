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
                'permission_callback'=>[$this,'nonce'],
                'args'=>[
                    'order_key'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'],
                ],
            ]);
        });
    }
    private function hasEnabledCourier(): bool {
        return $this->features->isEnabled('courier_tcs')
            || $this->features->isEnabled('courier_leopards')
            || $this->features->isEnabled('courier_trax');
    }
    public function nonce(\WP_REST_Request $r):bool {
        return wp_verify_nonce($r->get_header('X-WP-Nonce')??'', 'wp_rest') !== false;
    }
    public function handle(\WP_REST_Request $r):\WP_REST_Response{
        $orderKey = trim((string) $r->get_param('order_key'));
        if ($orderKey === '' || strlen($orderKey) > 100) {
            return new \WP_REST_Response(['success'=>false,'code'=>'invalid_order_key','message'=>'Enter the order key from your confirmation email.'],422);
        }
        if (!function_exists('wc_get_order_id_by_order_key') || !function_exists('wc_get_order')) {
            return new \WP_REST_Response(['success'=>false,'code'=>'unavailable','message'=>'Order tracking is temporarily unavailable.'],503);
        }

        $orderId = (int) wc_get_order_id_by_order_key($orderKey);
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!($order instanceof \WC_Order) || !hash_equals((string) $order->get_order_key(), $orderKey)) {
            return new \WP_REST_Response(['success'=>false,'code'=>'not_found','message'=>'Order not found.'],404);
        }

        $customerId = (int) $order->get_customer_id();
        if (is_user_logged_in() && $customerId > 0
            && $customerId !== get_current_user_id()
            && !current_user_can('manage_woocommerce')) {
            return new \WP_REST_Response(['success'=>false,'code'=>'forbidden','message'=>'You cannot view this order.'],403);
        }

        $trackingNumber = (string) $order->get_meta('_tracking_courier');
        $courierCode = strtolower((string) $order->get_meta('_booked_courier'));
        $courierMap = [
            'tcs' => ['courier_tcs', TcsCourier::class],
            'leopards' => ['courier_leopards', LeopardsCourier::class],
            'trax' => ['courier_trax', TraxCourier::class],
        ];
        if ($trackingNumber === '' || str_starts_with($trackingNumber, 'PENDING-')
            || !isset($courierMap[$courierCode])) {
            return new \WP_REST_Response(['success'=>false,'code'=>'not_found','message'=>'Tracking details are not available yet.'],404);
        }

        [$feature, $courierClass] = $courierMap[$courierCode];
        if (!$this->features->isEnabled($feature)) {
            return new \WP_REST_Response(['success'=>false,'code'=>'not_available','message'=>'Tracking is not enabled for this installation.'],403);
        }

        try {
            $courier = $this->c->make($courierClass);
            $update = $courier->getTrackingUpdate($trackingNumber);
        } catch (\Throwable $error) {
            error_log('[PK Store] Customer tracking lookup failed (' . get_class($error) . ').');
            return new \WP_REST_Response(['success'=>false,'code'=>'provider_unavailable','message'=>'Tracking service is temporarily unavailable.'],503);
        }
        if ($update === null) {
            return new \WP_REST_Response(['success'=>false,'code'=>'not_found','message'=>'No tracking update is available yet.'],404);
        }

        return new \WP_REST_Response([
            'success'=>true,
            'trackingNumber'=>$update->trackingNumber,
            'courier'=>$courier->getDisplayName(),
            'currentStatus'=>$update->status->value,
            'events'=>[[
                'status'=>$update->status->value,
                'description'=>$update->description,
                'location'=>$update->location,
                'timestamp'=>$update->eventTimestamp->format(\DateTimeInterface::ATOM),
            ]],
        ],200);
    }
}
