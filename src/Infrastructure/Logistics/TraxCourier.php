<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO,TrackingUpdateDTO,TrackingStatus};
use PkStore\Domain\Logistics\Exception\CourierApiException;
final class TraxCourier extends AbstractCourier {
    private const L='https://sonic.pk/api/v2';private const S='https://staging.sonic.pk/api/v2';
    public function getCourierCode():string{return 'trax';}public function getDisplayName():string{return 'Trax (Sonic)';}
    public function calculateRate(ShipmentRequestDTO $s):?int{try{return $this->rc->calculate($s,'trax');}catch(\Throwable){return null;}}
    public function createShipment(ShipmentRequestDTO $s):ShipmentResultDTO{
        $tok=$this->cfg('TRAX_API_TOKEN');$sid=$this->cfg('TRAX_SENDER_ID');$base=$this->cfg->get('TRAX_SANDBOX_MODE','0')==='1'?self::S:self::L;
        try{$cn=$this->cm->getTraxCityName($s->city);}catch(\InvalidArgumentException $e){return ShipmentResultDTO::failure('trax',$s->internalOrderId,$e->getMessage());}
        $p=['service'=>$s->serviceType,'consignee_name'=>$s->recipientName,'consignee_phone'=>$s->recipientPhone,'destination_city'=>$cn,'consignee_address'=>trim($s->addressLine1.' '.$s->addressLine2),'order_id'=>$s->internalOrderId,'sender_id'=>$sid,'items'=>$s->itemCount,'weight'=>$s->getChargeableWeightKg(),'amount'=>$s->isCashOnDelivery?$s->codAmountPkr:0,'payment_type'=>$s->isCashOnDelivery?'cod':'prepaid'];
        try{$r=$this->dispatch("{$base}/orders",$p,['Authorization'=>"Bearer {$tok}"]);$tn=$r['tracking_number']??$r['reference_number']??'';if(empty($tn))return ShipmentResultDTO::failure('trax',$s->internalOrderId,'No tracking number.',$r);return ShipmentResultDTO::success($tn,'trax',$s->internalOrderId,$this->rc->calculate($s,'trax'),$r['slip_url']??null,$r['estimated_delivery_date']??null,$r);}catch(CourierApiException $e){return ShipmentResultDTO::failure('trax',$s->internalOrderId,$e->getMessage());}
    }
    public function cancelShipment(string $id):bool{try{$base=$this->cfg->get('TRAX_SANDBOX_MODE','0')==='1'?self::S:self::L;$this->dispatch("{$base}/orders/{$id}",[],['Authorization'=>'Bearer '.$this->cfg('TRAX_API_TOKEN')],'DELETE');return true;}catch(\Throwable){return false;}}
    public function getTrackingUpdate(string $id):?TrackingUpdateDTO{try{$base=$this->cfg->get('TRAX_SANDBOX_MODE','0')==='1'?self::S:self::L;$r=$this->dispatch("{$base}/orders/{$id}",[],['Authorization'=>'Bearer '.$this->cfg('TRAX_API_TOKEN')],'GET');$raw=$r['status']??'unknown';return new TrackingUpdateDTO($id,'trax',TrackingStatus::fromCourierCode($raw,'trax'),$raw,$r['current_location']??'',$r['status_description']??'',new \DateTimeImmutable($r['last_updated']??'now'),null,$r);}catch(\Throwable){return null;}}
    public function verifyWebhookSignature(array $p,array $h):bool{$sec=$this->cfg->get('TRAX_WEBHOOK_SECRET');$recv=$h['x-trax-hmac-sha256']??$h['X-Trax-HMAC-SHA256']??'';if(!$sec||!$recv)return false;return hash_equals(base64_encode(hash_hmac('sha256',wp_json_encode($p,JSON_UNESCAPED_UNICODE)??'',$sec,true)),$recv);}
}
