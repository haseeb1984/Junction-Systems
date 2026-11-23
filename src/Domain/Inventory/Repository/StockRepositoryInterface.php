<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Repository;
use PkStore\Domain\Inventory\Entity\{StockLevel, StockMovement};
interface StockRepositoryInterface {
    public function getStock(int $productId, int $locationId): StockLevel;
    public function updateStock(StockLevel $stock): void;
    /** Atomically applies a delta and writes its matching ledger entry. */
    public function applyMovement(StockMovement $movement): ?StockMovement;
    /** Moves stock between two locations atomically with paired ledger entries. */
    /** Atomically sets a count and writes the resulting adjustment to the ledger. */
    public function transferStock(int $productId, int $fromLocationId, int $toLocationId, int $quantity, string $referenceId, string $note, ?int $userId): void;
    public function setStockAndRecordMovement(int $productId, int $locationId, int $quantity, string $note, ?int $userId): ?StockMovement;
    public function recordMovement(StockMovement $movement): int;
    public function getMovements(int $productId, int $limit=20): array;
    public function getLocationStock(int $locationId): array;
    public function getTotalStock(int $productId): int;
}
