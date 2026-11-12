<?php
declare(strict_types=1);
namespace JunctionStore\Domain\POS\Repository;
use JunctionStore\Domain\POS\Entity\POSSession;
interface POSSessionRepositoryInterface {
    public function save(POSSession $s): int;
    public function update(POSSession $s): void;
    public function findById(int $id): ?POSSession;
    public function findOpenByLocation(int $locationId): ?POSSession;
    public function findRecent(int $locationId, int $limit=10): array;
}
