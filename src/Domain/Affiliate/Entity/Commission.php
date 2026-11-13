<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Entity;
use DateTimeImmutable;
enum CommissionStatus: string { case Pending='pending'; case Approved='approved'; case Paid='paid'; case Cancelled='cancelled'; }
final readonly class Commission {
    public function __construct(public readonly int $id, public readonly int $affiliateId, public readonly int $orderId, public readonly int $orderTotalPkr, public readonly int $commissionPkr, public readonly float $ratePercent, public readonly CommissionStatus $status, public readonly DateTimeImmutable $createdAt) {}
    public static function create(int $affiliateId, int $orderId, int $orderTotal, int $commission, float $rate): self {
        return new self(0, $affiliateId, $orderId, $orderTotal, $commission, $rate, CommissionStatus::Pending, new DateTimeImmutable());
    }
}
