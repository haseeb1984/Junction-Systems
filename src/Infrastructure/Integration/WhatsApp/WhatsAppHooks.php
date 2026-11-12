<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Integration\WhatsApp;
use JunctionStore\Container\ServiceContainer;

final class WhatsAppHooks {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('woocommerce_order_status_processing', [$this,'onOrderConfirmed'],10,1);
        add_action('woocommerce_order_status_wc-shipped', [$this,'onOrderShipped'],10,1);
        add_action('woocommerce_order_status_completed',  [$this,'onOrderDelivered'],10,1);
        add_action('pkstore_low_stock_alert',             [$this,'onLowStock'],10,2);
        add_action('rest_api_init', function(){
            register_rest_route('pkstore/v1','/webhooks/whatsapp',['methods'=>'POST','callback'=>[$this,'handleWebhook'],'permission_callback'=>'__return_true']);
        });
    }
    public function onOrderConfirmed(int $orderId): void {
        $order=wc_get_order($orderId); if(!$order) return;
        try{$this->c->make(WhatsAppService::class)->sendOrderConfirmation($order);}catch(\Throwable $e){error_log('[JunctionStore WA] '.$e->getMessage());}
    }
    public function onOrderShipped(int $orderId): void {
        $order=wc_get_order($orderId); if(!$order) return;
        $tracking=get_post_meta($orderId,'_pkstore_tracking_number',true);
        $courier=get_post_meta($orderId,'_pkstore_courier',true);
        if(!$tracking) return;
        try{$this->c->make(WhatsAppService::class)->sendShippingUpdate($order,(string)$tracking,(string)$courier);}catch(\Throwable $e){error_log('[JunctionStore WA] '.$e->getMessage());}
    }
    public function onOrderDelivered(int $orderId): void {
        $order=wc_get_order($orderId); if(!$order) return;
        try{$this->c->make(WhatsAppService::class)->sendDeliveryConfirmation($order);}catch(\Throwable $e){error_log('[JunctionStore WA] '.$e->getMessage());}
    }
    public function onLowStock(string $productName, int $stock): void {
        $adminPhone=\JunctionStore\Settings\SettingsManager::getInstance()->get('WHATSAPP_ADMIN_PHONE');
        if(!$adminPhone) return;
        try{$this->c->make(WhatsAppService::class)->sendLowStockAlert($adminPhone,$productName,$stock);}catch(\Throwable $e){}
    }
    public function handleWebhook(\WP_REST_Request $req): \WP_REST_Response {
        // Meta webhook verification
        if($req->get_method()==='GET'){
            $challenge=$req->get_param('hub_challenge'); $token=$req->get_param('hub_verify_token');
            $expected=\JunctionStore\Settings\SettingsManager::getInstance()->get('WHATSAPP_VERIFY_TOKEN');
            if($token===$expected) return new \WP_REST_Response((int)$challenge,200);
            return new \WP_REST_Response('Forbidden',403);
        }
        // Incoming messages — log for now
        error_log('[JunctionStore WA Webhook] '.wp_json_encode($req->get_json_params()));
        return new \WP_REST_Response(['status'=>'ok'],200);
    }
}
