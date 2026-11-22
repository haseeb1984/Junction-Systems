<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Integration\Analytics;
use PkStore\Settings\SettingsManager;

final class FacebookPixelService {
    public function __construct(private readonly SettingsManager $s) {}

    public function register(): void {
        add_action('wp_head',[$this,'injectPixel'],1);
        add_action('wp_footer',[$this,'injectEvents']);
        add_action('woocommerce_payment_complete',[$this,'serverSideConversion']);
    }

    public function injectPixel(): void {
        $pixelId=$this->s->get('FACEBOOK_PIXEL_ID'); if (!$pixelId) return;
        echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','".esc_js($pixelId)."');fbq('track','PageView');</script>
";
    }

    public function injectEvents(): void {
        if (!$this->s->get('FACEBOOK_PIXEL_ID')) return;
        if (is_product())            { $this->fireViewContent(); }
        if (is_checkout())           { $this->fireInitiateCheckout(); }
        if (is_order_received_page()){ $this->firePurchase(); }
    }

    private function fireViewContent(): void {
        global $product; if (!$product) return;
        echo "<script>fbq('track','ViewContent',{content_ids:['".esc_js($product->get_sku())."'],content_type:'product',value:".esc_js($product->get_price()).",currency:'PKR'});</script>
";
    }

    private function fireInitiateCheckout(): void {
        $cart=WC()->cart; if (!$cart) return;
        $ids=array_values(array_map(fn($i)=>$i['data']->get_sku(),$cart->get_cart()));
        echo "<script>fbq('track','InitiateCheckout',{content_ids:".wp_json_encode($ids).",currency:'PKR',value:".esc_js($cart->get_total('edit')).",num_items:".(int)$cart->get_cart_contents_count()."});</script>
";
    }

    private function firePurchase(): void {
        $orderId=absint(get_query_var('order-received')); if (!$orderId) return;
        $order=wc_get_order($orderId); if (!$order) return;
        if (get_post_meta($orderId,'_fbpixel_purchase_fired',true)) return;
        update_post_meta($orderId,'_fbpixel_purchase_fired','1');
        $ids=array_values(array_map(fn($i)=>$i->get_product()->get_sku(),$order->get_items()));
        echo "<script>fbq('track','Purchase',{content_ids:".wp_json_encode($ids).",content_type:'product',currency:'PKR',value:".esc_js($order->get_total())."});</script>
";
    }

    public function serverSideConversion(int $orderId): void {
        $token=$this->s->get('FACEBOOK_CAPI_TOKEN'); $pixelId=$this->s->get('FACEBOOK_PIXEL_ID');
        if (!$token||!$pixelId) return;
        $order=wc_get_order($orderId); if (!$order) return;
        $email=$order->get_billing_email(); $phone=$order->get_billing_phone();
        $userData=['em'=>hash('sha256',strtolower(trim($email)))];
        if ($phone) $userData['ph']=hash('sha256',preg_replace('/[^\d]/','',$phone));
        $payload=['data'=>[['event_name'=>'Purchase','event_time'=>time(),'action_source'=>'website','event_source_url'=>get_permalink($orderId),'user_data'=>$userData,'custom_data'=>['currency'=>'PKR','value'=>(float)$order->get_total(),'content_ids'=>array_values(array_map(fn($i)=>$i->get_product()->get_sku(),$order->get_items())),'content_type'=>'product','order_id'=>$order->get_order_number()]]]];
        wp_remote_post("https://graph.facebook.com/v18.0/{$pixelId}/events?access_token=".urlencode($token),['timeout'=>10,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($payload)]);
    }
}
