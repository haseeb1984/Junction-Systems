<?php
declare(strict_types=1);
namespace PkStore\Application\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO};
use PkStore\Infrastructure\Logistics\CourierCircuitBreaker;
use PkStore\Infrastructure\WooCommerce\OrderMetaRepository;
use Psr\Log\LoggerInterface;
final class BookShipmentUseCase {
    public function __construct(private readonly CourierCircuitBreaker $cb, private readonly OrderMetaRepository $meta, private readonly LoggerInterface $log) {}
    public function execute(ShipmentRequestDTO $s): ShipmentResultDTO {
        $result = $this->cb->bookWithFailover($s);
        $orderId = (int) $s->internalOrderId;
        $order = $orderId > 0 && function_exists('wc_get_order') ? wc_get_order($orderId) : null;

        if ($result->success && !str_starts_with($result->trackingNumber, 'PENDING-')) {
            $this->meta->saveTrackingNumber($s->internalOrderId, $result->trackingNumber, $result->courierCode);
            if ($order instanceof \WC_Order) {
                $order->update_meta_data('_pkstore_courier_booking_state', 'confirmed');
                $order->update_meta_data('_pkstore_courier_booking_provider', $result->courierCode);
                $order->delete_meta_data('_pkstore_courier_booking_review_reason');
                $order->save();
            }
        } elseif ($order instanceof \WC_Order && !$result->success
            && str_contains($result->message, 'Booking outcome unknown')) {
            $order->update_meta_data('_pkstore_courier_booking_state', 'needs_review');
            $order->update_meta_data('_pkstore_courier_booking_provider', $result->courierCode);
            $order->update_meta_data('_pkstore_courier_booking_review_reason', 'Provider may have accepted the booking but Junction did not receive a confirmed tracking response.');
            $order->add_order_note('[PK Store] Courier booking outcome is unknown with ' . sanitize_text_field($result->courierCode) . '. Automatic failover/retry has been stopped to prevent duplicate parcels. Reconcile the provider portal before taking further action.');
            $order->save();
        } elseif ($order instanceof \WC_Order && $result->success
            && str_starts_with($result->trackingNumber, 'PENDING-')) {
            $order->update_meta_data('_pkstore_courier_booking_state', 'pending');
            $order->save();
        }

        return $result;
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
            if ($order->get_status() !== 'processing'
                || $order->get_meta('_pkstore_wc_inventory_sync_hold', true) === 'yes'
                || $order->get_meta('_pkstore_courier_booking_state', true) === 'needs_review') return;

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
            if ($result->success && !str_starts_with($result->trackingNumber, 'PENDING-')) {
                $order->add_order_note('[PK Store] Courier booking confirmed on retry. Tracking: ' . $result->trackingNumber);
            } elseif ($result->success) {
                $order->add_order_note('[PK Store] Courier booking is still pending; no provider has confirmed a shipment yet. Automatic retry remains scheduled.');
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
