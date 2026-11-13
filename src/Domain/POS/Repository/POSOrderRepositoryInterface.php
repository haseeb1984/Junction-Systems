<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Repository;
use PkStore\Domain\POS\Entity\POSOrder;
interface POSOrderRepositoryInterface {
    public function save(POSOrder $o): int;
    public function update(POSOrder $o): void;
    public function findById(int $id): ?POSOrder;
    public function findByRef(string $ref): ?POSOrder;
    public function findPending(): array;
    public function findBySession(int $sessionId): array;
    public function saveItems(int $orderId, array $items): void;
}
