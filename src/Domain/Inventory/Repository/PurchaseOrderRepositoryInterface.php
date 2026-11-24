<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Repository;
use PkStore\Domain\Inventory\Entity\PurchaseOrder;
interface PurchaseOrderRepositoryInterface {
    /** @param array<int,array{product_id:int,quantity:int,unit_cost_pkr:int}> $items */
    public function create(PurchaseOrder $order, array $items): int;
    public function findById(int $id): ?PurchaseOrder;
    public function findAll(int $limit=100): array;
    /** Applies receipt quantities, stock, movement ledger and PO status in one transaction. */
    public function receive(int $purchaseOrderId, array $items, string $receiptReference, int $userId): void;
    public function supplierExists(int $supplierId): bool;
}
