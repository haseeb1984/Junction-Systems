<?php defined('ABSPATH')||exit; do_action('woocommerce_before_checkout_form',$checkout); ?>
<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url(wc_get_checkout_url());?>" enctype="multipart/form-data" data-checkout-order data-order-id="">
<?php if($checkout->get_checkout_fields()):?>
<div class="col2-set" id="customer_details">
<div class="col-1"><?php do_action('woocommerce_checkout_billing');?></div>
<div class="col-2"><?php do_action('woocommerce_checkout_shipping');?></div>
</div>
<?php endif;?>
<h3><?php esc_html_e('Your order','woocommerce');?></h3>
<div id="order_review" class="woocommerce-checkout-review-order"><?php do_action('woocommerce_checkout_order_review');?></div>
<div data-cod-section class="pk-cod-section" aria-hidden="true">
<h3>📱 Phone Verification Required for Cash on Delivery</h3>
<p>We need to verify your phone number before processing your CoD order.</p>
<div data-cod-phone-section class="pk-cod__section" style="display:block">
<label for="cod_phone"><strong>Your Mobile Number:</strong></label>
<input type="tel" id="cod_phone" data-cod-phone class="pk-cod__phone-input" placeholder="03001234567" maxlength="13">
<button type="button" data-cod-send-btn class="button pk-cod__send-btn">Send OTP Code</button>
</div>
<div data-cod-otp-form class="pk-cod__section" aria-hidden="true">
<label for="cod_otp_input"><strong>Enter 6-Digit Code:</strong></label>
<input type="text" id="cod_otp_input" data-cod-otp-input class="pk-cod__otp-input" maxlength="6" placeholder="● ● ● ● ● ●" inputmode="numeric" autocomplete="one-time-code">
<div class="pk-cod__actions">
<button type="button" data-cod-verify-btn class="button button-primary pk-cod__verify-btn">Verify Code</button>
<button type="button" data-cod-resend-btn class="button pk-cod__resend-btn" disabled>Resend <span data-cod-timer></span></button>
</div>
</div>
<div data-cod-success class="pk-cod__success" aria-hidden="true">
<span style="color:#00a32a;font-size:16px;font-weight:600;">✅ Phone Verified — You can now place your order!</span>
</div>
</div>
</form>
<?php do_action('woocommerce_after_checkout_form',$checkout);?>
