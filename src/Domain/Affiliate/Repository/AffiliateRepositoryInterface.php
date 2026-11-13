<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Repository;
use PkStore\Domain\Affiliate\Entity\Affiliate;
use PkStore\Domain\Affiliate\ValueObject\AffiliateCode;
interface AffiliateRepositoryInterface {
    public function save(Affiliate $a): int; public function update(Affiliate $a): void;
    public function findById(int $id): ?Affiliate; public function findByCode(AffiliateCode $code): ?Affiliate;
    public function findByUserId(int $userId): ?Affiliate; public function findEligibleForPayout(): array;
    public function findAll(int $limit=50, int $offset=0): array; public function countAll(): int;
}
