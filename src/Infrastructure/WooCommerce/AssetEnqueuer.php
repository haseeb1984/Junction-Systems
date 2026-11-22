<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
final class AssetEnqueuer {
    public function register():void{add_action('wp_enqueue_scripts',[$this,'enqueue']);add_action('wp_head',[$this,'injectConfig'],1);add_action('wp_head',[$this,'inlineCriticalCss'],2);add_action('wp_enqueue_scripts',[$this,'deregisterJQuery'],999);}
    public function deregisterJQuery():void{if(!is_admin()){wp_deregister_script('jquery');wp_deregister_script('jquery-core');wp_deregister_script('jquery-migrate');}}
    public function enqueue():void{
        $v=filemtime(PKSTORE_DIR.'/assets/js/boot.mjs')?:PKSTORE_VERSION;
        wp_enqueue_script('pkstore-boot',PKSTORE_ASSETS.'/js/boot.mjs',[],strval($v),['strategy'=>'defer','in_footer'=>true]);
        add_filter('script_loader_tag',function($tag,$handle){if($handle==='pkstore-boot')$tag=str_replace('<script ','<script type="module" ',$tag);return $tag;},10,2);
        $cv=filemtime(PKSTORE_DIR.'/assets/css/shop.css')?:PKSTORE_VERSION;
        wp_enqueue_style('pkstore-shop',PKSTORE_ASSETS.'/css/shop.css',[],strval($cv));
        if(is_checkout()){$cv2=filemtime(PKSTORE_DIR.'/assets/css/checkout.css')?:PKSTORE_VERSION;wp_enqueue_style('pkstore-checkout',PKSTORE_ASSETS.'/css/checkout.css',[],strval($cv2));}
    }
    public function injectConfig():void{printf('<meta name="pkstore-config" data-nonce="%s" data-cod-nonce="%s" data-chat-nonce="%s" data-rest-url="%s">',esc_attr(wp_create_nonce('pkstore_nonce')),esc_attr(wp_create_nonce('pkstore_cod_otp')),esc_attr(wp_create_nonce('pkstore_ai_chat')),esc_url(rest_url('pkstore/v1')));}
    public function inlineCriticalCss():void{$p=PKSTORE_DIR.'/assets/css/critical.css';if(!file_exists($p)||filesize($p)>14000)return;$css=file_get_contents($p);if($css===false)return;echo '<style id="pkstore-critical">'.$css.'</style>';}
}
