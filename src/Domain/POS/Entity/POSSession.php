<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Entity;
use DateTimeImmutable;
final class POSSession {
    public function __construct(
        public readonly int $id, public readonly int $locationId,
        public readonly int $cashierId, public readonly int $openingCash,
        private ?int $closingCash, private int $totalSalesPkr,
        private int $totalCashPkr, private int $totalCardPkr,
        private int $totalJazzcash, private int $totalEasypaisa,
        private SessionStatus $status,
        public readonly DateTimeImmutable $openedAt, private ?DateTimeImmutable $closedAt,
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
        if ($total < 0) throw new \InvalidArgumentException('Sale total cannot be negative.');

        $amounts = ['cash' => 0, 'card' => 0, 'jazzcash' => 0, 'easypaisa' => 0];
        if ($paymentSplit !== null) {
            foreach ($paymentSplit as $method => $amount) {
                if (!array_key_exists((string) $method, $amounts)) {
                    throw new \InvalidArgumentException('Unsupported split payment method.');
                }
                $validatedAmount = filter_var($amount, FILTER_VALIDATE_INT);
                if ($validatedAmount === false || $validatedAmount < 0) {
                    throw new \InvalidArgumentException('Split payment amounts must be non-negative integers.');
                }
                $amounts[$method] += $validatedAmount;
            }
            if (array_sum($amounts) !== $total) {
                throw new \InvalidArgumentException('Split payment amounts must equal the sale total.');
            }
        } else {
            if (!array_key_exists($paymentMethod, $amounts)) {
                throw new \InvalidArgumentException('Unsupported POS payment method.');
            }
            $amounts[$paymentMethod] = $total;
        }

        // Apply all validated tender buckets only after the complete split is valid.
        $this->totalCashPkr += $amounts['cash'];
        $this->totalCardPkr += $amounts['card'];
        $this->totalJazzcash += $amounts['jazzcash'];
        $this->totalEasypaisa += $amounts['easypaisa'];
        $this->totalSalesPkr += $total;
    }
    public function close(int $closingCash): void {
        if (!$this->isOpen()) throw new \LogicException('POS session is already closed.');
        if ($closingCash < 0) throw new \InvalidArgumentException('Closing cash cannot be negative.');
        $this->status = SessionStatus::Closed; $this->closingCash = $closingCash;
        $this->closedAt = new DateTimeImmutable();
    }
    public function getCashVariance(): int { return ($this->closingCash ?? 0) - ($this->openingCash + $this->totalCashPkr); }
    public static function open(int $locationId, int $cashierId, int $openingCash): self {
        if ($locationId < 1 || $cashierId < 1 || $openingCash < 0) {
            throw new \InvalidArgumentException('POS location, cashier, and opening cash must be valid non-negative values.');
        }
        return new self(0, $locationId, $cashierId, $openingCash, null, 0, 0, 0, 0, 0, SessionStatus::Open, new DateTimeImmutable(), null);
    }
}
