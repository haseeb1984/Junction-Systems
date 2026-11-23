<?php
declare(strict_types=1);
namespace PkStore\Application\AI;

use PkStore\Domain\AI\{ContextSelector, ProductRepositoryInterface, PromptComposer};
use PkStore\Domain\AI\ValueObject\ProductContext;

final class ProductContextService {
    private const MAX = 12;

    public function __construct(
        private readonly ProductRepositoryInterface $repo,
        private readonly ContextSelector $selector,
        private readonly PromptComposer $composer
    ) {}

    public function buildSystemPrompt(string $message): string {
        $metadata = $this->repo->getStoreMetadata();
        $products = $this->resolve($message);
        return $this->composer->build($products, $metadata);
    }

    private function resolve(string $message): array {
        $budget = $this->selector->extractBudget($message);
        $sku = $this->selector->extractSku($message);

        if ($sku !== null) {
            $product = $this->repo->findBySku($sku, $budget);
            return $product ? $this->includeAvailableAlternatives([$product], $message, $budget) : [];
        }

        $keywords = $this->selector->extract($message);
        if ($budget !== null) {
            $keywords = array_values(array_filter(
                $keywords,
                static function (string $keyword) use ($budget): bool {
                    $numeric = str_replace(',', '', $keyword);
                    if (is_numeric($numeric) && (float) $numeric === $budget) return false;
                    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)k$/i', $keyword, $match) === 1
                        && (float) $match[1] * 1000 === $budget) return false;
                    return true;
                }
            ));
        }

        if ($keywords) {
            $products = $this->repo->findByKeywords($keywords, self::MAX, $budget);
            if ($products) return $this->includeAvailableAlternatives($products, $message, $budget);
        }

        $category = $this->selector->detectCategoryIntent($message);
        if ($category !== null) {
            return $this->repo->findByCategory($category, self::MAX, $budget);
        }

        // Only broad discovery prompts may fall back to popular items. A
        // specific query with no verified matches must not receive unrelated products.
        if (!$keywords) {
            return $this->repo->findPopular(self::MAX, $budget);
        }

        return [];
    }

    /**
     * Keep the requested out-of-stock item in context, then add verified
     * available products from the same category. Never replace the requested
     * item silently or exceed the customer's stated budget.
     *
     * @param ProductContext[] $products
     * @return ProductContext[]
     */
    private function includeAvailableAlternatives(array $products, string $message, ?float $budget): array {
        $availableCount = count(array_filter(
            $products,
            static fn(ProductContext $product): bool => $product->stockStatus !== 'out_of_stock'
        ));
        if ($availableCount >= 2) return array_slice($products, 0, self::MAX);

        $hasOutOfStock = count(array_filter(
            $products,
            static fn(ProductContext $product): bool => $product->stockStatus === 'out_of_stock'
        )) > 0;
        if (!$hasOutOfStock) return array_slice($products, 0, self::MAX);

        $category = $this->selector->detectCategoryIntent($message);
        if ($category === null && !empty($products[0]->categoryNames)) {
            $firstCategory = trim(explode(',', $products[0]->categoryNames)[0]);
            $category = function_exists('sanitize_title')
                ? sanitize_title($firstCategory)
                : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $firstCategory) ?? '', '-'));
        }
        if (!$category) return array_slice($products, 0, self::MAX);

        $alternatives = $this->repo->findByCategory($category, self::MAX, $budget);
        $merged = $products;
        $seen = [];
        foreach ($products as $product) $seen[$product->productId] = true;

        foreach ($alternatives as $alternative) {
            if ($alternative->stockStatus === 'out_of_stock' || isset($seen[$alternative->productId])) continue;
            $seen[$alternative->productId] = true;
            $merged[] = $alternative;
            if (count($merged) >= self::MAX) break;
        }

        return array_slice($merged, 0, self::MAX);
    }
}
