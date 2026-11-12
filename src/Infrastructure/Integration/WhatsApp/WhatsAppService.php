<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Integration\WhatsApp;
use JunctionStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class WhatsAppService {
    private const BASE = 'https://graph.facebook.com/v18.0';
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}
    private function send(string $phone, array $message): bool {
        $token=$this->s->get('WHATSAPP_TOKEN'); $phoneId=$this->s->get('WHATSAPP_PHONE_ID');
        if(!$token||!$phoneId) return false;
        $clean=preg_replace('/[^\d]/','', $phone);
        if(str_starts_with($clean,'0')) $clean='92'.substr($clean,1);
        $r=wp_remote_post(self::BASE.'/'.$phoneId.'/messages',['timeout'=>15,'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$token],'body'=>wp_json_encode(['messaging_product'=>'whatsapp','to'=>$clean,'type'=>'text','text'=>['body'=>$message['text']]])]);
        $ok=!is_wp_error($r)&&wp_remote_retrieve_response_code($r)===200;
        if(!$ok) $this->log->error('WhatsApp send failed.',['phone'=>$clean,'error'=>is_wp_error($r)?$r->get_error_message():wp_remote_retrieve_body($r)]);
        return $ok;
    }
    public function sendOrderConfirmation(\WC_Order $order): bool {
        $name=$order->get_billing_first_name(); $ref=$order->get_order_number(); $total=number_format((float)$order->get_total());
        $items=implode(', ',array_map(fn($i)=>$i->get_name().' x'.$i->get_quantity(),$order->get_items()));
        $storeName=\JunctionStore\Core\Branding\BrandingManager::getInstance()->getStoreName();
        $text="*{$storeName}*

Aapka shukriya, {$name}!

✅ Order #{$ref} confirm ho gaya.

Items: {$items}
Total: PKR {$total}

Hum aapko delivery update dete rahenge.";
        return $this->send($order->get_billing_phone(),['text'=>$text]);
    }
    public function sendShippingUpdate(\WC_Order $order, string $trackingNumber, string $courier): bool {
        $name=$order->get_billing_first_name(); $ref=$order->get_order_number();
        $storeName=\JunctionStore\Core\Branding\BrandingManager::getInstance()->getStoreName();
        $text="*{$storeName}*

📦 Order #{$ref} ship ho gaya!

Tracking: {$trackingNumber}
Courier: ".strtoupper($courier)."

Estimated delivery: 2-3 business days.";
        return $this->send($order->get_billing_phone(),['text'=>$text]);
    }
    public function sendDeliveryConfirmation(\WC_Order $order): bool {
        $name=$order->get_billing_first_name(); $ref=$order->get_order_number();
        $storeName=\JunctionStore\Core\Branding\BrandingManager::getInstance()->getStoreName();
        $text="*{$storeName}*

🎉 Order #{$ref} deliver ho gaya!

Shukriya {$name}! Umeed hai aapko pasand aya hoga.

Koi masla ho to hamen reply karein.";
        return $this->send($order->get_billing_phone(),['text'=>$text]);
    }
    public function sendLowStockAlert(string $adminPhone, string $productName, int $stock): bool {
        $text="⚠ *Stock Alert*

{$productName} ka stock low hai!
Current stock: {$stock} units

Please reorder karein.";
        return $this->send($adminPhone,['text'=>$text]);
    }
    public static function getWebhookUrl(): string { return rest_url('pkstore/v1/webhooks/whatsapp'); }
}
