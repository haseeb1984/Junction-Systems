<?php
declare(strict_types=1);
namespace JunctionStore\Domain\AI;
use JunctionStore\Domain\AI\ValueObject\ProductContext;
interface ProductRepositoryInterface { /** @return ProductContext[] */ public function findByKeywords(array $keywords, int $limit=5):array; /** @return ProductContext[] */ public function findByCategory(string $slug, int $limit=8):array; public function findBySku(string $sku):?ProductContext; public function getStoreMetadata():array; }
