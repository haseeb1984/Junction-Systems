<?php
declare(strict_types=1);
namespace PkStore\Domain\AI;

use PkStore\Domain\AI\ValueObject\ProductContext;

interface ProductRepositoryInterface {
    /** @return ProductContext[] Search the full published WooCommerce catalogue. */
    public function findByKeywords(array $keywords, int $limit = 12, ?float $maxPrice = null): array;

    /** @return ProductContext[] */
    public function findByCategory(string $slug, int $limit = 12, ?float $maxPrice = null): array;

    /** @return ProductContext[] Popular, currently available catalogue items. */
    public function findPopular(int $limit = 12, ?float $maxPrice = null): array;

    public function findBySku(string $sku, ?float $maxPrice = null): ?ProductContext;

    public function getStoreMetadata(): array;
}
