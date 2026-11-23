<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Http;

use PkStore\Application\Verification\SendCodOtpUseCase;
use PkStore\Application\Verification\VerifyCodOtpUseCase;
use PkStore\Container\ServiceContainer;
use PkStore\Domain\Verification\Exception\{
    InvalidOtpException,
    OtpAlreadyVerifiedException,
    OtpExpiredException,
    RateLimitExceededException,
    SmsProviderException
};
use PkStore\Domain\Verification\Repository\OtpRepositoryInterface;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;

final class CodOtpController {
    private const SESSION_PROOF_KEY = 'pkstore_cod_otp_proof';

    public function __construct(private readonly ServiceContainer $container) {}

    public function register(): void {
        add_action('rest_api_init', function (): void {
            register_rest_route('pkstore/v1', '/cod/send-otp', [
                'methods' => 'POST',
                'callback' => [$this, 'handleSend'],
                'permission_callback' => [$this, 'verifyNonce'],
                'args' => [
                    'phone' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    '_nonce' => ['required' => true, 'type' => 'string'],
                ],
            ]);
            register_rest_route('pkstore/v1', '/cod/verify-otp', [
                'methods' => 'POST',
                'callback' => [$this, 'handleVerify'],
                'permission_callback' => [$this, 'verifyNonce'],
                'args' => [
                    'phone' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'code' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    '_nonce' => ['required' => true, 'type' => 'string'],
                ],
            ]);
        });

        // The browser check is only UX. This checkout validation is authoritative.
        add_action('woocommerce_after_checkout_validation', [$this, 'validateCheckout'], 100, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'attachProofToOrder'], 100, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'bindProofToOrder'], 100, 3);
    }

    public function verifyNonce(\WP_REST_Request $request): bool {
        return (bool) wp_verify_nonce($request->get_param('_nonce'), 'pkstore_cod_otp');
    }

    public function handleSend(\WP_REST_Request $request): \WP_REST_Response {
        if ($this->isDigitalOnlyCart()) {
            return new \WP_REST_Response([
                'success' => true,
                'skip' => true,
                'message' => 'Digital product cart — no phone verification required.',
            ], 200);
        }

        try {
            $sessionRef = $this->checkoutSessionReference();
            $this->container->make(SendCodOtpUseCase::class)->execute(
                (string) $request->get_param('phone'),
                $sessionRef
            );
            $this->clearSessionProof();
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'OTP sent to your mobile number.',
            ], 200);
        } catch (RateLimitExceededException $error) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'rate_limited',
                'message' => $error->getMessage(),
                'unlocks_at' => $error->unlocksAt->format(\DateTimeInterface::ATOM),
            ], 429);
        } catch (SmsProviderException) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'sms_failed',
                'message' => 'Could not send OTP. Please try again.',
            ], 503);
        } catch (\InvalidArgumentException $error) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'invalid_phone',
                'message' => $error->getMessage(),
            ], 422);
        } catch (\RuntimeException) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'checkout_session_unavailable',
                'message' => 'Checkout session could not be verified. Refresh the page and try again.',
            ], 503);
        }
    }

    public function handleVerify(\WP_REST_Request $request): \WP_REST_Response {
        if ($this->isDigitalOnlyCart()) {
            return new \WP_REST_Response([
                'success' => true,
                'skip' => true,
                'message' => 'Digital product cart — no phone verification required.',
            ], 200);
        }

        try {
            $sessionRef = $this->checkoutSessionReference();
            $phone = new PakistanPhoneNumber((string) $request->get_param('phone'));
            $attemptId = $this->container->make(VerifyCodOtpUseCase::class)->execute(
                $phone->e164,
                $sessionRef,
                (string) $request->get_param('code')
            );

            $session = $this->wooSession();
            $session->set(self::SESSION_PROOF_KEY, [
                'attempt_id' => $attemptId,
                'phone' => $phone->e164,
                'session_ref' => $sessionRef,
                'expires_at' => time() + 300,
                'consumed' => false,
            ]);

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Phone verified. Complete checkout using this same billing phone number.',
            ], 200);
        } catch (OtpExpiredException $error) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'otp_expired',
                'message' => $error->getMessage(),
            ], 410);
        } catch (OtpAlreadyVerifiedException) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'replay_rejected',
                'message' => 'This code has already been used.',
            ], 409);
        } catch (InvalidOtpException $error) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'invalid_otp',
                'message' => $error->getMessage(),
                'remaining_attempts' => $error->remainingAttempts,
            ], 422);
        } catch (\InvalidArgumentException $error) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'invalid_phone',
                'message' => $error->getMessage(),
            ], 422);
        } catch (\RuntimeException $error) {
            return new \WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => $error->getMessage(),
            ], 404);
        }
    }

    public function validateCheckout(array $data, \WP_Error $errors): void {
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if ($paymentMethod === '') {
            try {
                $paymentMethod = (string) $this->wooSession()->get('chosen_payment_method');
            } catch (\Throwable) {
                $errors->add('pkstore_cod_otp_session', 'Checkout session is unavailable. Refresh checkout and try again.');
                return;
            }
        }
        if ($paymentMethod !== 'cod' || $this->isDigitalOnlyCart()) return;
        if ($errors->has_errors()) return;

        $proof = $this->wooSession()->get(self::SESSION_PROOF_KEY);
        if (!is_array($proof) || !empty($proof['consumed'])) {
            $errors->add('pkstore_cod_otp_required', 'Please verify your mobile number before placing a Cash on Delivery order.');
            return;
        }

        try {
            $billingPhone = new PakistanPhoneNumber((string) ($data['billing_phone'] ?? ''));
            $sessionRef = $this->checkoutSessionReference();
        } catch (\Throwable) {
            $errors->add('pkstore_cod_otp_phone', 'Enter a valid Pakistani mobile number and verify that same number for Cash on Delivery.');
            return;
        }

        if (($proof['expires_at'] ?? 0) < time()
            || !hash_equals((string) ($proof['session_ref'] ?? ''), $sessionRef)
            || !hash_equals((string) ($proof['phone'] ?? ''), $billingPhone->e164)
            || empty($proof['attempt_id'])) {
            $this->clearSessionProof();
            $errors->add('pkstore_cod_otp_mismatch', 'Your verified phone number does not match the billing phone, or verification has expired. Please verify again.');
            return;
        }

        try {
            $consumed = $this->container->make(OtpRepositoryInterface::class)->consumeVerifiedAttempt(
                (string) $proof['attempt_id'],
                $billingPhone,
                $sessionRef
            );
        } catch (\Throwable $error) {
            error_log('[PK Store] COD OTP proof consumption failed (' . get_class($error) . ').');
            $errors->add('pkstore_cod_otp_unavailable', 'Phone verification could not be confirmed. Please try again.');
            return;
        }
        if (!$consumed) {
            $this->clearSessionProof();
            $errors->add('pkstore_cod_otp_replay', 'This phone verification has already been used or expired. Please request a new code.');
            return;
        }

        $proof['consumed'] = true;
        $this->wooSession()->set(self::SESSION_PROOF_KEY, $proof);
    }

    public function attachProofToOrder(\WC_Order $order, array $data): void {
        if ($order->get_payment_method() !== 'cod' || $this->isDigitalOnlyCart()) return;

        $proof = $this->wooSession()->get(self::SESSION_PROOF_KEY);
        if (!is_array($proof) || empty($proof['consumed']) || empty($proof['attempt_id'])) return;

        try {
            $billingPhone = new PakistanPhoneNumber((string) $order->get_billing_phone());
        } catch (\Throwable) {
            $this->holdCodOrderForOtpReview($order, 'The order billing phone could not be matched to the verified COD phone.');
            return;
        }
        if (!hash_equals((string) ($proof['phone'] ?? ''), $billingPhone->e164)) {
            $this->holdCodOrderForOtpReview($order, 'The order billing phone differs from the phone verified for COD.');
            return;
        }

        $order->update_meta_data('_pkstore_cod_otp_verified', 'yes');
        $order->update_meta_data('_pkstore_cod_otp_attempt_id', sanitize_text_field((string) $proof['attempt_id']));
        $order->update_meta_data('_pkstore_cod_otp_verified_at', current_time('mysql'));
        $order->update_meta_data(
            '_pkstore_cod_otp_phone_hash',
            hash_hmac('sha256', (string) $proof['phone'], wp_salt('auth'))
        );
    }

    public function bindProofToOrder(int $orderId, array $postedData = [], $order = null): void {
        if (!($order instanceof \WC_Order)) {
            $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        }
        if (!($order instanceof \WC_Order)
            || $order->get_payment_method() !== 'cod'
            || $this->isDigitalOnlyOrder($order)) return;

        $proof = $this->wooSession()->get(self::SESSION_PROOF_KEY);
        if (!is_array($proof) || empty($proof['consumed']) || empty($proof['attempt_id'])) {
            $this->holdCodOrderForOtpReview($order, 'No consumed server-side phone verification proof was present when the COD order was finalized.');
            return;
        }

        try {
            $billingPhone = new PakistanPhoneNumber((string) $order->get_billing_phone());
            if (!hash_equals((string) ($proof['phone'] ?? ''), $billingPhone->e164)) {
                $this->holdCodOrderForOtpReview($order, 'The final COD order billing phone differs from the verified phone.');
                $this->clearSessionProof();
                return;
            }
            $bound = $this->container->make(OtpRepositoryInterface::class)->bindConsumedAttemptToOrder(
                (string) $proof['attempt_id'],
                (string) $proof['session_ref'],
                (string) $orderId
            );
            if (!$bound) {
                $this->holdCodOrderForOtpReview($order, 'COD phone verification was consumed but its OTP audit record could not be linked to this order.');
                error_log('[PK Store] COD OTP consumed-proof order binding failed for order ' . $orderId . '.');
            }
        } catch (\Throwable $error) {
            $this->holdCodOrderForOtpReview($order, 'COD phone verification audit binding failed; manual review is required.');
            error_log('[PK Store] COD OTP order binding failed for order ' . $orderId . ' (' . get_class($error) . ').');
        }

        $this->clearSessionProof();
    }

    private function holdCodOrderForOtpReview(\WC_Order $order, string $reason): void {
        $order->update_meta_data('_pkstore_cod_otp_verified', 'no');
        $order->update_meta_data('_pkstore_cod_otp_review_required', 'yes');
        $order->add_order_note($reason . ' Dispatch is paused pending Junction support review.');
        if (!in_array($order->get_status(), ['cancelled', 'refunded', 'failed', 'on-hold'], true)) {
            $order->update_status('on-hold', 'Junction requires COD phone-verification review before fulfilment.');
        } else {
            $order->save();
        }
    }

    private function isDigitalOnlyCart(): bool {
        $wc = function_exists('WC') ? WC() : null;
        if (!$wc || !$wc->cart || $wc->cart->is_empty()) return false;

        foreach ($wc->cart->get_cart() as $cartItem) {
            $product = $cartItem['data'] ?? null;
            if (!($product instanceof \WC_Product)) return false;
            if (!$product->is_virtual()) return false;
        }
        return true;
    }

    private function isDigitalOnlyOrder(\WC_Order $order): bool {
        $items = $order->get_items();
        if (!$items) return false;
        foreach ($items as $item) {
            $product = $item->get_product();
            if (!($product instanceof \WC_Product)) return false;
            if (!$product->is_virtual()) return false;
        }
        return true;
    }

    private function checkoutSessionReference(): string {
        $session = $this->wooSession();
        $customerId = (string) $session->get_customer_id();
        if ($customerId === '') {
            throw new \RuntimeException('WooCommerce checkout session is unavailable.');
        }
        return 's:' . substr(hash_hmac('sha256', $customerId, wp_salt('auth')), 0, 30);
    }

    private function wooSession() {
        $wc = function_exists('WC') ? WC() : null;
        if (!$wc || !$wc->session) {
            throw new \RuntimeException('WooCommerce session is unavailable.');
        }
        return $wc->session;
    }

    private function clearSessionProof(): void {
        $session = $this->wooSession();
        $session->__unset(self::SESSION_PROOF_KEY);
    }
}
