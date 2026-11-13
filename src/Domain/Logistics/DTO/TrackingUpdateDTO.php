<?php
declare(strict_types=1);
namespace PkStore\Domain\Logistics\DTO;
final readonly class TrackingUpdateDTO { public function __construct(public readonly string $trackingNumber, public readonly string $courierCode, public readonly TrackingStatus $status, public readonly string $rawStatusCode, public readonly string $location, public readonly string $description, public readonly \DateTimeImmutable $eventTimestamp, public readonly ?string $internalOrderId=null, public readonly array $rawPayload=[]) {} }
