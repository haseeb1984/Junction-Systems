<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO};
final class CourierCircuitBreaker {
    private const THRESH=5;private const COOL=300;private const PFX='pkstore_cb_';
    public function __construct(private readonly array $couriers){}
    public function hasCouriers(): bool { return $this->couriers !== []; }
    public function bookWithFailover(ShipmentRequestDTO $s):ShipmentResultDTO{
        foreach($this->couriers as $c){
            $code=$c->getCourierCode();if($this->open($code))continue;
            try{$r=$c->createShipment($s);if($r->success){$this->reset($code);return $r;}return $r;}
            catch(\Throwable){$this->fail($code);if($this->fails($code)>=self::THRESH)$this->openCircuit($code);}
        }
        return $this->optimistic($s);
    }
    private function optimistic(ShipmentRequestDTO $s):ShipmentResultDTO{
        $orderId = (int) $s->internalOrderId;
        if(function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time()+300,'pkstore_retry_courier_booking',['order_id'=>$orderId],'pkstore-logistics');
        } elseif (!wp_next_scheduled('pkstore_retry_courier_booking', [$orderId])) {
            wp_schedule_single_event(time()+300,'pkstore_retry_courier_booking',[$orderId]);
        }
        wp_mail(get_option('admin_email'),'[PK Store] Couriers Down — Order #'.$s->internalOrderId,"All couriers failed. Order accepted, booking queued for retry in 5 minutes.");
        return new ShipmentResultDTO(true,'PENDING-'.$s->internalOrderId,'pending',$s->internalOrderId,null,null,0,'Booking queued.',new \DateTimeImmutable(),[]);
    }
    private function open(string $c):bool{return get_transient(self::PFX.$c.'_state')==='open';}
    private function openCircuit(string $c):void{set_transient(self::PFX.$c.'_state','open',self::COOL);}
    private function reset(string $c):void{delete_transient(self::PFX.$c.'_state');delete_transient(self::PFX.$c.'_fails');}
    private function fail(string $c):void{$k=self::PFX.$c.'_fails';set_transient($k,(int)get_transient($k)+1,self::COOL*2);}
    private function fails(string $c):int{return(int)get_transient(self::PFX.$c.'_fails');}
}
