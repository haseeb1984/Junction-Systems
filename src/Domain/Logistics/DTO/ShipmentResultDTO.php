<?php
declare(strict_types=1);
namespace PkStore\Domain\Logistics\DTO;
final readonly class ShipmentResultDTO {
    public function __construct(public readonly bool $success, public readonly string $trackingNumber, public readonly string $courierCode, public readonly string $internalOrderId, public readonly ?string $labelUrl, public readonly ?string $estimatedDelivery, public readonly int $shippingCostPkr, public readonly string $message, public readonly \DateTimeImmutable $bookedAt, public readonly array $rawResponse) {}
    public static function success(string $tn, string $cc, string $oid, int $cost, ?string $label=null, ?string $eta=null, array $raw=[]):self { return new self(true,$tn,$cc,$oid,$label,$eta,$cost,'Booked.',new \DateTimeImmutable(),$raw); }
    public static function failure(string $cc, string $oid, string $reason, array $raw=[]):self { return new self(false,'',$cc,$oid,null,null,0,$reason,new \DateTimeImmutable(),$raw); }
}
