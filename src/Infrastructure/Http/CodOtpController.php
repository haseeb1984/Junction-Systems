<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Application\Verification\{SendCodOtpUseCase,VerifyCodOtpUseCase};
use PkStore\Container\ServiceContainer;
use PkStore\Domain\Verification\Exception\{InvalidOtpException,OtpAlreadyVerifiedException,OtpExpiredException,RateLimitExceededException,SmsProviderException};

final class CodOtpController {
    public function __construct(private readonly ServiceContainer $container) {}

    public function register(): void {
        add_action('rest_api_init', function(){
            register_rest_route('pkstore/v1','/cod/send-otp',['methods'=>'POST','callback'=>[$this,'handleSend'],'permission_callback'=>[$this,'verifyNonce'],'args'=>['phone'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'],'order_id'=>['required'=>true,'type'=>'string'],'_nonce'=>['required'=>true,'type'=>'string']]]);
            register_rest_route('pkstore/v1','/cod/verify-otp',['methods'=>'POST','callback'=>[$this,'handleVerify'],'permission_callback'=>[$this,'verifyNonce'],'args'=>['phone'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'],'order_id'=>['required'=>true,'type'=>'string'],'code'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'],'_nonce'=>['required'=>true,'type'=>'string']]]);
        });
    }

    public function verifyNonce(\WP_REST_Request $req): bool {
        return (bool)wp_verify_nonce($req->get_param('_nonce'),'pkstore_cod_otp');
    }

    public function handleSend(\WP_REST_Request $req): \WP_REST_Response {
        $orderId = (string)$req->get_param('order_id');

        // Skip OTP for digital-only orders
        if ($this->isDigitalOnlyOrder($orderId)) {
            return new \WP_REST_Response([
                'success' => true,
                'skip'    => true,
                'message' => 'Digital product order — no phone verification required.',
            ], 200);
        }

        try {
            $this->container->make(SendCodOtpUseCase::class)->execute(
                (string)$req->get_param('phone'), $orderId
            );
            return new \WP_REST_Response(['success'=>true,'message'=>'OTP sent to your mobile number.'],200);
        } catch (RateLimitExceededException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'rate_limited','message'=>$e->getMessage(),'unlocks_at'=>$e->unlocksAt->format(\DateTimeInterface::ATOM)],429);
        } catch (SmsProviderException) {
            return new \WP_REST_Response(['success'=>false,'code'=>'sms_failed','message'=>'Could not send OTP. Please try again.'],503);
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'invalid_phone','message'=>$e->getMessage()],422);
        }
    }

    public function handleVerify(\WP_REST_Request $req): \WP_REST_Response {
        $orderId = (string)$req->get_param('order_id');

        // Digital orders are pre-verified
        if ($this->isDigitalOnlyOrder($orderId)) {
            return new \WP_REST_Response(['success'=>true,'message'=>'Digital order verified.'],200);
        }

        try {
            $this->container->make(VerifyCodOtpUseCase::class)->execute(
                (string)$req->get_param('phone'), $orderId, (string)$req->get_param('code')
            );
            return new \WP_REST_Response(['success'=>true,'message'=>'Phone verified. Your CoD order is confirmed.'],200);
        } catch (OtpExpiredException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'otp_expired','message'=>$e->getMessage()],410);
        } catch (OtpAlreadyVerifiedException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'replay_rejected','message'=>'This code has already been used.'],409);
        } catch (InvalidOtpException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'invalid_otp','message'=>$e->getMessage(),'remaining_attempts'=>$e->remainingAttempts],422);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'not_found','message'=>$e->getMessage()],404);
        }
    }

    private function isDigitalOnlyOrder(string $orderId): bool
    {
        if (empty($orderId) || !function_exists('wc_get_order')) return false;
        $order = wc_get_order((int)$orderId);
        if (!($order instanceof \WC_Order)) return false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product instanceof \WC_Product && !$product->is_virtual() && !$product->is_downloadable()) {
                return false;
            }
        }
        return true;
    }
}
