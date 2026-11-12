<?php
declare(strict_types=1);
namespace JunctionStore\Domain\POS\Entity;
use DateTimeImmutable;
enum SessionStatus: string { case Open='open'; case Closed='closed'; }
final class POSSession {
    public function __construct(
        public readonly int $id, public readonly int $locationId,
        public readonly int $cashierId, public readonly int $openingCash,
        private ?int $closingCash, private int $totalSalesPkr,
        private int $totalCashPkr, private int $totalJazzcash,
        private int $totalEasypaisa, private SessionStatus $status,
        public readonly DateTimeImmutable $openedAt, private ?DateTimeImmutable $closedAt,
    ) {}
    public function getStatus(): SessionStatus { return $this->status; }
    public function isOpen(): bool { return $this->status === SessionStatus::Open; }
    public function getTotalSalesPkr(): int { return $this->totalSalesPkr; }
    public function recordSale(int $total, string $paymentMethod): void {
        if (!$this->isOpen()) throw new \LogicException('Cannot record sale on closed session.');
        $this->totalSalesPkr += $total;
        match($paymentMethod) {
            'jazzcash'  => $this->totalJazzcash  += $total,
            'easypaisa' => $this->totalEasypaisa += $total,
            default      => $this->totalCashPkr   += $total,
        };
    }
    public function close(int $closingCash): void {
        $this->status = SessionStatus::Closed; $this->closingCash = $closingCash;
        $this->closedAt = new DateTimeImmutable();
    }
    public function getCashVariance(): int { return ($this->closingCash ?? 0) - ($this->openingCash + $this->totalCashPkr); }
    public static function open(int $locationId, int $cashierId, int $openingCash): self {
        return new self(0, $locationId, $cashierId, $openingCash, null, 0, 0, 0, 0, SessionStatus::Open, new DateTimeImmutable(), null);
    }
}
