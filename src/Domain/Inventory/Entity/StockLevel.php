<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Entity;
use DateTimeImmutable;
final class StockLevel {
    public function __construct(
        public readonly int $id, public readonly int $productId,
        public readonly int $locationId, private int $quantity,
        public readonly DateTimeImmutable $updatedAt,
    ) {}
    public function getQuantity(): int { return $this->quantity; }
    public function isBelowReorderPoint(int $reorderPoint): bool { return $this->quantity <= $reorderPoint; }
    public function adjust(int $delta): void {
        $new = $this->quantity + $delta;
        if ($new < 0) throw new \DomainException("Stock cannot go below 0. Current: {$this->quantity}, Delta: {$delta}");
        $this->quantity = $new;
    }
    public function set(int $quantity): void {
        if ($quantity < 0) throw new \InvalidArgumentException("Quantity cannot be negative.");
        $this->quantity = $quantity;
    }
}
