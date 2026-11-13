<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Entity;
use DateTimeImmutable;
enum PayoutStatus: string { case Pending='pending'; case Processing='processing'; case Completed='completed'; case Failed='failed'; }
final readonly class Payout {
    public function __construct(public readonly int $id, public readonly int $affiliateId, public readonly int $amountPkr, public readonly string $paymentMethod, public readonly string $paymentAccount, public readonly PayoutStatus $status, public readonly ?string $transactionId, public readonly DateTimeImmutable $createdAt) {}
    public static function create(int $affiliateId, int $amountPkr, string $method, string $account): self {
        return new self(0, $affiliateId, $amountPkr, $method, $account, PayoutStatus::Pending, null, new DateTimeImmutable());
    }
}
