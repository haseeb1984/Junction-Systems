<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Repository;
use PkStore\Domain\Affiliate\Entity\Payout;
interface PayoutRepositoryInterface {
    public function save(Payout $p): int; public function updateStatus(int $id, string $status, ?string $txId=null): void;
    public function findByAffiliate(int $affiliateId, int $limit=20): array; public function findPending(): array;
}
