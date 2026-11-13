<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Integration\Analytics;
use PkStore\Settings\SettingsManager;

final class GA4Service {
    public function __construct(private readonly SettingsManager $s) {}

    public function register(): void {
        add_action('wp_head',          [$this, 'injectGtag'], 1);
        add_action('wp_footer',        [$this, 'injectEvents']);
        add_action('woocommerce_payment_complete', [$this, 'serverSidePurchase']);
    }

    public function injectGtag(): void {
        $measurementId = $this->s->get('GA4_MEASUREMENT_ID');
        if (!$measurementId) return;
        echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=".esc_attr($measurementId)."\"></script>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments);}
gtag('js',new Date());
gtag('config','".esc_js($measurementId)."',{'send_page_view':true});
var pkGA4=".esc_js($measurementId).";
</script>
";
    }

    public function injectEvents(): void {
        if (!$this->s->get('GA4_MEASUREMENT_ID')) return;
        if (is_product()) { $this->injectViewItem(); }
        if (is_cart())    { $this->injectViewCart(); }
        if (is_checkout()){ $this->injectBeginCheckout(); }
        if (is_order_received_page()){ $this->injectPurchase(); }
    }

    private function injectViewItem(): void {
        global $product;
        if (!$product) return;
        echo "<script>gtag('event','view_item',{currency:'PKR',value:".esc_js($product->get_price()).",items:[{item_id:'".esc_js($product->get_sku())."',item_name:'".esc_js($product->get_name())."',price:".esc_js($product->get_price())."}]});</script>
";
    }

    private function injectViewCart(): void {
        $cart = WC()->cart; if (!$cart) return;
        $items = array_values(array_map(fn($i) => ['item_id'=>$i['data']->get_sku(),'item_name'=>$i['data']->get_name(),'quantity'=>$i['quantity'],'price'=>$i['data']->get_price()], $cart->get_cart()));
        echo "<script>gtag('event','view_cart',{currency:'PKR',value:".esc_js($cart->get_total('edit'))."',items:".wp_json_encode($items)."});</script>
";
    }

    private function injectBeginCheckout(): void {
        $cart = WC()->cart; if (!$cart) return;
        $items = array_values(array_map(fn($i) => ['item_id'=>$i['data']->get_sku(),'item_name'=>$i['data']->get_name(),'quantity'=>$i['quantity'],'price'=>$i['data']->get_price()], $cart->get_cart()));
        echo "<script>gtag('event','begin_checkout',{currency:'PKR',value:".esc_js($cart->get_total('edit')).",items:".wp_json_encode($items)."});</script>
";
    }

    private function injectPurchase(): void {
        $orderId = absint(get_query_var('order-received')); if (!$orderId) return;
        $order = wc_get_order($orderId); if (!$order) return;
        // Only fire once
        if (get_post_meta($orderId,'_ga4_purchase_fired',true)) return;
        update_post_meta($orderId,'_ga4_purchase_fired','1');
        $items = array_values(array_map(fn($i) => ['item_id'=>$i->get_product()->get_sku(),'item_name'=>$i->get_name(),'quantity'=>$i->get_quantity(),'price'=>$i->get_total()/$i->get_quantity()], $order->get_items()));
        echo "<script>gtag('event','purchase',{transaction_id:'".esc_js($order->get_order_number())."',currency:'PKR',value:".esc_js($order->get_total()).",tax:".esc_js($order->get_total_tax()).",items:".wp_json_encode($items)."});</script>
";
    }

    public function serverSidePurchase(int $orderId): void {
        $apiSecret=$this->s->get('GA4_API_SECRET'); $measurementId=$this->s->get('GA4_MEASUREMENT_ID');
        if (!$apiSecret||!$measurementId) return;
        $order=wc_get_order($orderId); if (!$order) return;
        $clientId=get_post_meta($orderId,'_ga4_client_id',true)?:'server-side';
        $items=array_values(array_map(fn($i)=>['item_id'=>$i->get_product()->get_sku(),'item_name'=>$i->get_name(),'quantity'=>$i->get_quantity(),'price'=>(float)($i->get_total()/$i->get_quantity())],$order->get_items()));
        wp_remote_post("https://www.google-analytics.com/mp/collect?measurement_id=".urlencode($measurementId)."&api_secret=".urlencode($apiSecret),['timeout'=>10,'body'=>wp_json_encode(['client_id'=>$clientId,'events'=>[['name'=>'purchase','params'=>['transaction_id'=>$order->get_order_number(),'currency'=>'PKR','value'=>(float)$order->get_total(),'items'=>$items]]]])]);
    }
}
