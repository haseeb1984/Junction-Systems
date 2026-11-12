<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Logistics;
use JunctionStore\Domain\Logistics\DTO\ShipmentRequestDTO;
final class RateCalculator {
    private const R=['tcs'=>['A'=>['standard'=>['base'=>220,'per_kg'=>35,'threshold_kg'=>0.5],'overnight'=>['base'=>280,'per_kg'=>45,'threshold_kg'=>0.5],'economy'=>['base'=>160,'per_kg'=>25,'threshold_kg'=>0.5]],'B'=>['standard'=>['base'=>280,'per_kg'=>45,'threshold_kg'=>0.5],'overnight'=>['base'=>350,'per_kg'=>55,'threshold_kg'=>0.5],'economy'=>['base'=>200,'per_kg'=>35,'threshold_kg'=>0.5]],'C'=>['standard'=>['base'=>380,'per_kg'=>60,'threshold_kg'=>0.5],'overnight'=>['base'=>480,'per_kg'=>75,'threshold_kg'=>0.5],'economy'=>['base'=>280,'per_kg'=>50,'threshold_kg'=>0.5]]],'leopards'=>['A'=>['standard'=>['base'=>200,'per_kg'=>30,'threshold_kg'=>0.5],'economy'=>['base'=>150,'per_kg'=>20,'threshold_kg'=>0.5]],'B'=>['standard'=>['base'=>260,'per_kg'=>40,'threshold_kg'=>0.5],'economy'=>['base'=>190,'per_kg'=>30,'threshold_kg'=>0.5]],'C'=>['standard'=>['base'=>360,'per_kg'=>55,'threshold_kg'=>0.5],'economy'=>['base'=>260,'per_kg'=>45,'threshold_kg'=>0.5]]],'trax'=>['A'=>['standard'=>['base'=>190,'per_kg'=>28,'threshold_kg'=>0.5],'economy'=>['base'=>140,'per_kg'=>18,'threshold_kg'=>0.5]],'B'=>['standard'=>['base'=>250,'per_kg'=>38,'threshold_kg'=>0.5],'economy'=>['base'=>180,'per_kg'=>28,'threshold_kg'=>0.5]],'C'=>['standard'=>['base'=>340,'per_kg'=>52,'threshold_kg'=>0.5],'economy'=>['base'=>240,'per_kg'=>42,'threshold_kg'=>0.5]]]];
    public function __construct(private readonly CityMappingService $cm){}
    public function calculate(ShipmentRequestDTO $s,string $cc,?string $zone=null):int{
        $z=$zone??$this->cm->getZone($s->city);$svc=$s->serviceType;
        $rates=self::R[$cc][$z][$svc]??self::R[$cc][$z]['standard']??throw new \InvalidArgumentException("No rate for [{$cc}][{$z}][{$svc}]");
        $total=$rates['base'];$extra=max(0,$s->getChargeableWeightKg()-$rates['threshold_kg']);
        if($extra>0)$total+=(int)round(ceil($extra/0.5)*0.5*$rates['per_kg']);
        if($s->isCashOnDelivery)$total+=max(100,(int)round($s->codAmountPkr*0.015));
        if($s->isFragile)$total=(int)round($total*1.1);
        return $total;
    }
}
