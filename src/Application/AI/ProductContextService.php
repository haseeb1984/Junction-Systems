<?php
declare(strict_types=1);
namespace PkStore\Application\AI;

use PkStore\Domain\AI\{ContextSelector, ProductRepositoryInterface, PromptComposer};

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
            return $product ? [$product] : [];
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
            ));        }

        if ($keywords) {
            $products = $this->repo->findByKeywords($keywords, self::MAX, $budget);
            if ($products) return $products;
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
}
