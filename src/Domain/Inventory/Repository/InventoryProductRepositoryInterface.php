<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Inventory\Repository;
use JunctionStore\Domain\Inventory\Entity\InventoryProduct;
interface InventoryProductRepositoryInterface {
    public function save(InventoryProduct $p): int;
    public function update(InventoryProduct $p): void;
    public function findById(int $id): ?InventoryProduct;
    public function findBySku(string $sku): ?InventoryProduct;
    public function findByBarcode(string $barcode): ?InventoryProduct;
    public function findByWcProductId(int $wcId): ?InventoryProduct;
    public function findAll(int $limit=50, int $offset=0, string $search=''): array;
    public function findBelowReorderPoint(): array;
    public function countAll(): int;
}
