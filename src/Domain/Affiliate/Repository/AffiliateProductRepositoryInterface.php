<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Repository;
use PkStore\Domain\Affiliate\Entity\AffiliateProduct;
interface AffiliateProductRepositoryInterface {
    public function save(AffiliateProduct $p): int; public function update(AffiliateProduct $p): void;
    public function findById(int $id): ?AffiliateProduct; public function findActive(int $limit=20, int $offset=0): array;
    public function findByAffiliate(int $affiliateId): array; public function countActive(): int; public function recordClick(int $productId): void;
}
