<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO,TrackingUpdateDTO,TrackingStatus};
use PkStore\Domain\Logistics\Exception\CourierApiException;
final class LeopardsCourier extends AbstractCourier {
    private const L='https://merchantapi.leopardscourier.com/api';private const S='https://merchantapistaging.leopardscourier.com/api';
    private const IPS=['103.206.24.0/24','203.135.16.0/24'];
    public function getCourierCode():string{return 'leopards';}public function getDisplayName():string{return 'Leopards Courier';}
    public function calculateRate(ShipmentRequestDTO $s):?int{try{return $this->rc->calculate($s,'leopards');}catch(\Throwable){return null;}}
    public function createShipment(ShipmentRequestDTO $s):ShipmentResultDTO{
        $key=$this->cfg('LEOPARDS_API_KEY');$pass=$this->cfg('LEOPARDS_API_PASSWORD');$base=$this->cfg->get('LEOPARDS_SANDBOX_MODE','0')==='1'?self::S:self::L;
        try{$cc=$this->cm->getLeopardsCityCode($s->city);}catch(\InvalidArgumentException $e){return ShipmentResultDTO::failure('leopards',$s->internalOrderId,$e->getMessage());}
        $p=['api_key'=>$key,'api_password'=>$pass,'booked_packet_weight'=>$s->getChargeableWeightKg(),'booked_packet_no_piece'=>$s->itemCount,'booked_packet_collect_amount'=>$s->isCashOnDelivery?$s->codAmountPkr:0,'booked_packet_order_id'=>$s->internalOrderId,'booked_packet_consignee_name'=>$s->recipientName,'booked_packet_consignee_phone'=>$s->recipientPhone,'booked_packet_consignee_address'=>trim($s->addressLine1.' '.$s->addressLine2),'booked_packet_consignee_city'=>$cc,'packet_type'=>$s->isCashOnDelivery?'COD':'Paid','shipment_value'=>$s->declaredValuePkr];
        try{$r=$this->dispatch("{$base}/bookPacket/format/json",$p);if(($r['status']??0)!=1)return ShipmentResultDTO::failure('leopards',$s->internalOrderId,$r['error']??'Booking rejected.',$r);$tn=(string)($r['track_number']??'');if(empty($tn))return ShipmentResultDTO::failure('leopards',$s->internalOrderId,'HTTP error: provider response did not include a verifiable tracking number after booking submission.',$r);return ShipmentResultDTO::success($tn,'leopards',$s->internalOrderId,$this->rc->calculate($s,'leopards'),$r['slip_link']??null,null,$r);}catch(CourierApiException $e){return ShipmentResultDTO::failure('leopards',$s->internalOrderId,$e->getMessage());}
    }
    public function cancelShipment(string $id):bool{try{$base=$this->cfg->get('LEOPARDS_SANDBOX_MODE','0')==='1'?self::S:self::L;$r=$this->dispatch("{$base}/cancelBookedPacket/format/json",['api_key'=>$this->cfg('LEOPARDS_API_KEY'),'api_password'=>$this->cfg('LEOPARDS_API_PASSWORD'),'track_number'=>$id]);return($r['status']??0)==1;}catch(\Throwable){return false;}}
    public function getTrackingUpdate(string $id):?TrackingUpdateDTO{try{$base=$this->cfg->get('LEOPARDS_SANDBOX_MODE','0')==='1'?self::S:self::L;$r=$this->dispatch("{$base}/trackBookedPacket/format/json",['api_key'=>$this->cfg('LEOPARDS_API_KEY'),'api_password'=>$this->cfg('LEOPARDS_API_PASSWORD'),'track_number'=>$id]);$p=$r['packet_list'][0]??[];$raw=(string)($p['status']??'0');return new TrackingUpdateDTO($id,'leopards',TrackingStatus::fromCourierCode($raw,'leopards'),$raw,$p['city']??'',$p['status_desc']??'',new \DateTimeImmutable($p['updated_on']??'now'),null,$r);}catch(\Throwable){return null;}}
    public function verifyWebhookSignature(array $p,array $h):bool{$ip=sanitize_text_field($_SERVER['REMOTE_ADDR']??'');foreach(self::IPS as $cidr){[$sub,$bits]=explode('/',$cidr);$mask=-1<<(32-(int)$bits);if((ip2long($ip)&$mask)===(ip2long($sub)&$mask))return true;}return false;}
}
