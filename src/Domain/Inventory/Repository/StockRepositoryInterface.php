<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Repository;
use PkStore\Domain\Inventory\Entity\{StockLevel, StockMovement, MovementType};
interface StockRepositoryInterface {
    public function getStock(int $productId, int $locationId): StockLevel;
    public function updateStock(StockLevel $stock): void;
    public function recordMovement(StockMovement $movement): int;
    public function getMovements(int $productId, int $limit=20): array;
    public function hasMovementReference(int $productId, int $locationId, string $referenceType, string $referenceId, MovementType $movementType): bool;
    public function getLocationStock(int $locationId): array;
    public function getTotalStock(int $productId): int;
    public function enqueueWooCommerceSync(int $productId): void;
    public function claimWooCommerceSyncBatch(int $limit = 20): array;
    public function completeWooCommerceSync(int $id, string $generation): void;
    public function retryWooCommerceSync(int $id, string $generation, int $attempts, string $errorClass): void;
}
