<?php
declare(strict_types=1);
namespace PkStore\Application\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO};
use PkStore\Infrastructure\Logistics\CourierCircuitBreaker;
use PkStore\Infrastructure\WooCommerce\OrderMetaRepository;
use Psr\Log\LoggerInterface;
final class BookShipmentUseCase {
    public function __construct(private readonly CourierCircuitBreaker $cb, private readonly OrderMetaRepository $meta, private readonly LoggerInterface $log) {}
    public function execute(ShipmentRequestDTO $s):ShipmentResultDTO {
        $r=$this->cb->bookWithFailover($s);
        if($r->success&&!str_starts_with($r->trackingNumber,'PENDING-'))
            $this->meta->saveTrackingNumber($s->internalOrderId,$r->trackingNumber,$r->courierCode);
        return $r;
    }
    public function retryForOrder(int $orderId):void {
        $order=wc_get_order($orderId); if(!($order instanceof \WC_Order))return;
        if (!$this->cb->hasCouriers()) {
            $order->add_order_note('[PK Store] Courier booking was not attempted because no courier is enabled by the current package and store settings.');
            return;
        }
        $s=$this->buildDto($order); if($s===null)return;
        $r=$this->execute($s);
        if($r->success) {
            $order->add_order_note('[PK Store] Courier booked on retry. Tracking: '.$r->trackingNumber);
        } else {
            $order->add_order_note('[PK Store] Courier booking failed at all enabled providers. Check courier configuration, city mapping and provider logs before retrying.');
        }
    }
    private function buildDto(\WC_Order $o):?ShipmentRequestDTO {
        try { return new ShipmentRequestDTO(internalOrderId:(string)$o->get_id(),orderReference:$o->get_order_number(),recipientName:$o->get_shipping_first_name().' '.$o->get_shipping_last_name(),recipientPhone:$o->get_billing_phone(),recipientEmail:$o->get_billing_email(),addressLine1:$o->get_shipping_address_1(),addressLine2:$o->get_shipping_address_2(),city:strtolower(trim($o->get_shipping_city())),province:$o->get_shipping_state(),postalCode:$o->get_shipping_postcode(),country:'PK',weightKg:(float)($o->get_meta('_total_weight')?: 0.5),itemCount:$o->get_item_count(),dimensions:[],declaredValuePkr:(int)$o->get_total(),isCashOnDelivery:$o->get_payment_method()==='cod',codAmountPkr:(int)$o->get_total(),specialInstructions:'',isFragile:false,serviceType:'standard'); }
        catch(\Throwable $e){$this->log->error('DTO build failed',['order_id'=>$o->get_id(),'err'=>$e->getMessage()]);return null;}
    }
}
