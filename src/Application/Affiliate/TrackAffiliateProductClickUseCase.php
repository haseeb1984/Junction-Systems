<?php
declare(strict_types=1);
namespace PkStore\Application\Affiliate;
use PkStore\Domain\Affiliate\Repository\AffiliateProductRepositoryInterface;
final class TrackAffiliateProductClickUseCase {
    public function __construct(private readonly AffiliateProductRepositoryInterface $repo) {}
    public function execute(int $productId): ?string {
        $p = $this->repo->findById($productId);
        if ($p === null || !$p->isActive()) return null;
        $this->repo->recordClick($productId);
        return $p->externalUrl;
    }
}
