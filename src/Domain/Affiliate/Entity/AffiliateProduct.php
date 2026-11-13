<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Entity;
use DateTimeImmutable;
final class AffiliateProduct {
    public function __construct(
        public readonly int $id, public readonly int $affiliateId, public readonly string $title,
        public readonly string $description, public readonly int $displayPricePkr,
        public readonly string $externalUrl, public readonly string $imageUrl,
        public readonly string $platform, public readonly string $category,
        private int $clickCount, public readonly string $status, public readonly DateTimeImmutable $createdAt,
    ) {}
    public static function create(int $affiliateId, string $title, string $description, int $displayPricePkr, string $externalUrl, string $imageUrl, string $platform, string $category): self {
        return new self(0, $affiliateId, $title, $description, $displayPricePkr, $externalUrl, $imageUrl, $platform, $category, 0, 'active', new DateTimeImmutable());
    }
    public function getClickCount(): int { return $this->clickCount; }
    public function incrementClicks(): void { $this->clickCount++; }
    public function isActive(): bool { return $this->status === 'active'; }
    public function getTrackedRedirectUrl(string $siteUrl): string { return rtrim($siteUrl, '/') . "/affiliate-product/{$this->id}/go/"; }
}
