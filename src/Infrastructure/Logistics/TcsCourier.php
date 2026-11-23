<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO,TrackingUpdateDTO,TrackingStatus};
use PkStore\Domain\Logistics\Exception\CourierApiException;
final class TcsCourier extends AbstractCourier {
    private const L='https://api.tcsexpress.com/api/v3';private const S='https://sandbox.tcsexpress.com/api/v3';
    public function getCourierCode():string{return 'tcs';}public function getDisplayName():string{return 'TCS Express';}
    public function calculateRate(ShipmentRequestDTO $s):?int{try{return $this->rc->calculate($s,'tcs');}catch(\Throwable){return null;}}
    public function createShipment(ShipmentRequestDTO $s):ShipmentResultDTO{
        $key=$this->cfg('TCS_API_KEY');$acct=$this->cfg('TCS_ACCOUNT_NUMBER');$base=$this->cfg->get('TCS_SANDBOX_MODE','0')==='1'?self::S:self::L;
        try{$cid=$this->cm->getTcsCityId($s->city);}catch(\InvalidArgumentException $e){return ShipmentResultDTO::failure('tcs',$s->internalOrderId,$e->getMessage());}
        $p=['account_number'=>$acct,'reference_number'=>$s->internalOrderId,'service_type'=>match($s->serviceType){'overnight'=>'ON','economy'=>'EC',default=>'ST'},'consignee'=>['name'=>$s->recipientName,'phone'=>$s->recipientPhone,'address'=>trim($s->addressLine1.' '.$s->addressLine2),'city_id'=>$cid,'postal_code'=>$s->postalCode],'parcel'=>['weight'=>$s->getChargeableWeightKg(),'pieces'=>$s->itemCount,'declared_value'=>$s->declaredValuePkr,'is_fragile'=>$s->isFragile],'payment'=>['type'=>$s->isCashOnDelivery?'COD':'PREPAID','cod_amount'=>$s->isCashOnDelivery?$s->codAmountPkr:0]];
        try{$r=$this->dispatch("{$base}/bookings",$p,['X-API-KEY'=>$key]);$tn=$r['cn_number']??$r['tracking_number']??'';if(empty($tn))return ShipmentResultDTO::failure('tcs',$s->internalOrderId,'HTTP error: provider response did not include a verifiable tracking number after booking submission.',$r);return ShipmentResultDTO::success($tn,'tcs',$s->internalOrderId,$this->rc->calculate($s,'tcs'),$r['label_url']??null,$r['estimated_delivery']??null,$r);}catch(CourierApiException $e){return ShipmentResultDTO::failure('tcs',$s->internalOrderId,$e->getMessage());}
    }
    public function cancelShipment(string $id):bool{try{$base=$this->cfg->get('TCS_SANDBOX_MODE','0')==='1'?self::S:self::L;$this->dispatch("{$base}/bookings/{$id}/cancel",['reason'=>'Cancelled'],['X-API-KEY'=>$this->cfg('TCS_API_KEY')],'PUT');return true;}catch(\Throwable){return false;}}
    public function getTrackingUpdate(string $id):?TrackingUpdateDTO{try{$base=$this->cfg->get('TCS_SANDBOX_MODE','0')==='1'?self::S:self::L;$r=$this->dispatch("{$base}/tracking/{$id}",[],['X-API-KEY'=>$this->cfg('TCS_API_KEY')],'GET');$e=$r['events'][0]??[];$raw=$e['status_code']??'UNKNOWN';return new TrackingUpdateDTO($id,'tcs',TrackingStatus::fromCourierCode($raw,'tcs'),$raw,$e['location']??'',$e['description']??'',new \DateTimeImmutable($e['timestamp']??'now'),null,$r);}catch(\Throwable){return null;}}
    public function verifyWebhookSignature(array $p,array $h):bool{$sec=$this->cfg->get('TCS_WEBHOOK_SECRET');$recv=$h['x-tcs-signature']??$h['X-TCS-Signature']??'';if(!$sec||!$recv)return false;return hash_equals(hash_hmac('sha256',wp_json_encode($p,JSON_UNESCAPED_UNICODE)??'',$sec),strtolower($recv));}
}
