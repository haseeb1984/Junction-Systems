<?php
declare(strict_types=1);
namespace PkStore\Domain\Logistics\DTO;
final readonly class ShipmentRequestDTO {
    public function __construct(public readonly string $internalOrderId, public readonly string $orderReference, public readonly string $recipientName, public readonly string $recipientPhone, public readonly string $recipientEmail, public readonly string $addressLine1, public readonly string $addressLine2, public readonly string $city, public readonly string $province, public readonly string $postalCode, public readonly string $country, public readonly float $weightKg, public readonly int $itemCount, public readonly array $dimensions, public readonly int $declaredValuePkr, public readonly bool $isCashOnDelivery, public readonly int $codAmountPkr, public readonly string $specialInstructions, public readonly bool $isFragile, public readonly string $serviceType) {
        if($this->isCashOnDelivery&&$this->codAmountPkr<=0)throw new \InvalidArgumentException('CoD amount must be positive.');
        if($this->weightKg<=0||$this->weightKg>70)throw new \InvalidArgumentException("Invalid weight [{$this->weightKg}kg].");
    }
    public function getVolumetricWeightKg():float { if(empty($this->dimensions))return 0.0; return(($this->dimensions['l']??0)*($this->dimensions['w']??0)*($this->dimensions['h']??0))/5000; }
    public function getChargeableWeightKg():float{return max($this->weightKg,$this->getVolumetricWeightKg());}
}
