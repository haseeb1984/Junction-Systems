<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Affiliate\Repository;
use JunctionStore\Domain\Affiliate\Entity\Commission;
interface CommissionRepositoryInterface {
    public function save(Commission $c): int; public function findByOrderId(int $orderId): ?Commission;
    public function findByAffiliate(int $affiliateId, int $limit=20): array;
    public function markApproved(int $id): void; public function markPaid(int $id): void;
}
