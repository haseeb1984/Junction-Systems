<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Repository;
use PkStore\Domain\Inventory\Entity\{StockLevel, StockMovement};
interface StockRepositoryInterface {
    public function getStock(int $productId, int $locationId): StockLevel;
    public function updateStock(StockLevel $stock): void;
    public function recordMovement(StockMovement $movement): int;
    public function getMovements(int $productId, int $limit=20): array;
    public function getLocationStock(int $locationId): array;
    public function getTotalStock(int $productId): int;
}
