<?php
declare(strict_types=1);
namespace PkStore\Domain\AI;
use PkStore\Domain\AI\ValueObject\ProductContext;
interface ProductRepositoryInterface { /** @return ProductContext[] */ public function findByKeywords(array $keywords, int $limit=5):array; /** @return ProductContext[] */ public function findByCategory(string $slug, int $limit=8):array; public function findBySku(string $sku):?ProductContext; public function getStoreMetadata():array; }
