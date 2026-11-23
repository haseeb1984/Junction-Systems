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
            $budgetToken = (string) (int) $budget;
            $keywords = array_values(array_filter(
                $keywords,
                static fn(string $keyword): bool => !ctype_digit($keyword) || (string) (int) $keyword !== $budgetToken
            ));
        }

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
