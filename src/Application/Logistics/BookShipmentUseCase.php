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
        if ($orderId < 1) return;
        global $wpdb;
        if (!$wpdb instanceof \wpdb) return;

        $lockName = 'pkstore_courier_booking_' . $orderId;
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
        if ($wpdb->last_error !== '' || (int) $locked !== 1) return;

        try {
            $order = wc_get_order($orderId);
            if (!($order instanceof \WC_Order)) return;
            if ($order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') return;

            $existingTracking = (string) $order->get_meta('_tracking_courier', true);
            if ($existingTracking !== '' && !str_starts_with($existingTracking, 'PENDING-')) return;
            if ($this->isDigitalOnlyOrder($order)) return;

            if (!$this->cb->hasCouriers()) {
                $order->add_order_note('[PK Store] Courier booking was not attempted because no courier is enabled by the current package and store settings.');
                return;
            }

            $shipment = $this->buildDto($order);
            if ($shipment === null) return;
            $result = $this->execute($shipment);
            if ($result->success) {
                $order->add_order_note('[PK Store] Courier booked on retry. Tracking: ' . $result->trackingNumber);
            } else {
                $order->add_order_note('[PK Store] Courier booking failed at all enabled providers. Check courier configuration, city mapping and provider logs before retrying.');
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private function isDigitalOnlyOrder(\WC_Order $order): bool {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product instanceof \WC_Product && !$product->is_virtual() && !$product->is_downloadable()) {
                return false;
            }
        }
        return true;
    }

    private function buildDto(\WC_Order $o):?ShipmentRequestDTO {
        try { return new ShipmentRequestDTO(internalOrderId:(string)$o->get_id(),orderReference:$o->get_order_number(),recipientName:$o->get_shipping_first_name().' '.$o->get_shipping_last_name(),recipientPhone:$o->get_billing_phone(),recipientEmail:$o->get_billing_email(),addressLine1:$o->get_shipping_address_1(),addressLine2:$o->get_shipping_address_2(),city:strtolower(trim($o->get_shipping_city())),province:$o->get_shipping_state(),postalCode:$o->get_shipping_postcode(),country:'PK',weightKg:(float)($o->get_meta('_total_weight')?: 0.5),itemCount:$o->get_item_count(),dimensions:[],declaredValuePkr:(int)$o->get_total(),isCashOnDelivery:$o->get_payment_method()==='cod',codAmountPkr:(int)$o->get_total(),specialInstructions:'',isFragile:false,serviceType:'standard'); }
        catch(\Throwable $e){$this->log->error('DTO build failed',['order_id'=>$o->get_id(),'err'=>$e->getMessage()]);return null;}
    }
}
