<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Entity;
use PkStore\Domain\Affiliate\ValueObject\{AffiliateCode, CommissionRate};
use DateTimeImmutable;
final class Affiliate {
    private function __construct(
        public readonly int $id, public readonly int $userId, public readonly string $name,
        public readonly string $email, public readonly AffiliateCode $code,
        public readonly CommissionRate $commissionRate, private int $totalEarnedPkr,
        private int $totalPaidPkr, private int $pendingPayoutPkr,
        public readonly string $paymentMethod, public readonly string $paymentAccount,
        public readonly string $status, public readonly DateTimeImmutable $createdAt,
    ) {}
    public static function create(int $userId, string $name, string $email, string $paymentMethod, string $paymentAccount, ?CommissionRate $rate = null): self {
        return new self(0, $userId, $name, $email, AffiliateCode::generate(), $rate ?? CommissionRate::default(), 0, 0, 0, $paymentMethod, $paymentAccount, 'active', new DateTimeImmutable());
    }
    public static function reconstitute(int $id, int $userId, string $name, string $email, AffiliateCode $code, CommissionRate $rate, int $totalEarned, int $totalPaid, int $pendingPayout, string $paymentMethod, string $paymentAccount, string $status, DateTimeImmutable $createdAt): self {
        return new self($id, $userId, $name, $email, $code, $rate, $totalEarned, $totalPaid, $pendingPayout, $paymentMethod, $paymentAccount, $status, $createdAt);
    }
    public function getTotalEarnedPkr(): int { return $this->totalEarnedPkr; }
    public function getTotalPaidPkr(): int { return $this->totalPaidPkr; }
    public function getPendingPayoutPkr(): int { return $this->pendingPayoutPkr; }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isEligibleForPayout(): bool { return $this->pendingPayoutPkr >= 1000; }
    public function creditCommission(int $amountPkr): void { if ($amountPkr <= 0) return; $this->totalEarnedPkr += $amountPkr; $this->pendingPayoutPkr += $amountPkr; }
    public function recordPayout(int $amountPkr): void { if ($amountPkr > $this->pendingPayoutPkr) throw new \LogicException("Payout amount exceeds pending balance."); $this->totalPaidPkr += $amountPkr; $this->pendingPayoutPkr -= $amountPkr; }
    public function getTrackingUrl(string $storeUrl, int $productId = 0): string { $base = rtrim($storeUrl, '/'); return $productId > 0 ? "{$base}/?ref={$this->code}&p={$productId}" : "{$base}/?ref={$this->code}"; }
}
