<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Entity;
use DateTimeImmutable;
enum SessionStatus: string { case Open='open'; case Closed='closed'; }
final class POSSession {
    public function __construct(
        public readonly int $id, public readonly int $locationId,
        public readonly int $cashierId, public readonly int $openingCash,
        private ?int $closingCash, private int $totalSalesPkr,
        private int $totalCashPkr, private int $totalCardPkr,
        private int $totalJazzcash, private int $totalEasypaisa,
        private SessionStatus $status, public readonly DateTimeImmutable $openedAt,
        private ?DateTimeImmutable $closedAt,
    ) {}
    public function getStatus(): SessionStatus { return $this->status; }
    public function isOpen(): bool { return $this->status === SessionStatus::Open; }
    public function getTotalSalesPkr(): int { return $this->totalSalesPkr; }
    public function getTotalCashPkr(): int { return $this->totalCashPkr; }
    public function getTotalCardPkr(): int { return $this->totalCardPkr; }
    public function getTotalJazzcashPkr(): int { return $this->totalJazzcash; }
    public function getTotalEasypaisaPkr(): int { return $this->totalEasypaisa; }
    public function getClosingCash(): ?int { return $this->closingCash; }
    public function getClosedAt(): ?DateTimeImmutable { return $this->closedAt; }
    public function recordSale(int $total, string $paymentMethod, ?array $paymentSplit = null): void {
        if (!$this->isOpen()) throw new \LogicException('Cannot record sale on closed session.');
        if ($paymentSplit !== null) {
            $splitTotal = 0;
            foreach ($paymentSplit as $method => $amount) {
                $amount = (int)$amount;
                if (!in_array((string)$method, ['cash','card','jazzcash','easypaisa'], true) || $amount < 0) {
                    throw new \InvalidArgumentException('Invalid split payment entry.');
                }
                $splitTotal += $amount;
            }
            if ($splitTotal !== $total) throw new \InvalidArgumentException('Split payment amounts must equal the sale total.');
            foreach ($paymentSplit as $method => $amount) {
                $amount = (int)$amount;
                match($method) {
                    'cash' => $this->totalCashPkr += $amount,
                    'card' => $this->totalCardPkr += $amount,
                    'jazzcash' => $this->totalJazzcash += $amount,
                    'easypaisa' => $this->totalEasypaisa += $amount,
                };
            }
            $this->totalSalesPkr += $total;
            return;
        }
        match($paymentMethod) {
            'cash' => $this->totalCashPkr += $total,
            'card' => $this->totalCardPkr += $total,
            'jazzcash' => $this->totalJazzcash += $total,
            'easypaisa' => $this->totalEasypaisa += $total,
            default => throw new \InvalidArgumentException('Unsupported POS payment method: '.$paymentMethod),
        };
        $this->totalSalesPkr += $total;
    }
    public function close(int $closingCash): void {
        if ($closingCash < 0) throw new \InvalidArgumentException('Closing cash cannot be negative.');
        $this->status = SessionStatus::Closed;
        $this->closingCash = $closingCash;
        $this->closedAt = new DateTimeImmutable();
    }
    public function getCashVariance(): int { return ($this->closingCash ?? 0) - ($this->openingCash + $this->totalCashPkr); }
    public static function open(int $locationId, int $cashierId, int $openingCash): self {
        return new self(0, $locationId, $cashierId, $openingCash, null, 0, 0, 0, 0, 0, SessionStatus::Open, new DateTimeImmutable(), null);
    }
}
