<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Repository;
use PkStore\Domain\POS\Entity\POSSession;
interface POSSessionRepositoryInterface {
    public function save(POSSession $s): int;
    public function update(POSSession $s): void;
    /** Atomically increments tender totals only while the session remains open. */
    public function recordSaleAtomic(int $sessionId, int $total, string $paymentMethod, ?array $paymentSplit = null): void;
    public function findById(int $id): ?POSSession;
    public function findOpenByLocation(int $locationId): ?POSSession;
    public function findRecent(int $locationId, int $limit=10): array;
}
