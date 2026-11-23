<?php
declare(strict_types=1);
namespace PkStore\Domain\AI\ValueObject;

final readonly class ProductContext {
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $pricePkr,
        public readonly ?int $salePricePkr,
        public readonly string $stockStatus,
        public readonly ?int $stockQuantity,
        public readonly string $shortDescription,
        public readonly string $categoryNames,
        public readonly array $attributes,
        public readonly bool $isFeatured,
        public readonly ?string $brand,
        public readonly int $productId = 0,
        public readonly string $productUrl = '',
        public readonly string $currency = 'PKR',
        public readonly bool $isVariation = false
    ) {}

    public function toMinifiedArray(): array {
        $description = function_exists('mb_substr')
            ? mb_substr($this->shortDescription, 0, 200)
            : substr($this->shortDescription, 0, 200);

        $data = [
            'id' => $this->productId,
            'sku' => $this->sku,
            'name' => $this->name,
            'px' => $this->pricePkr,
            'currency' => $this->currency,
            'stock' => $this->stockStatus,
            'url' => $this->productUrl,
            'cat' => $this->categoryNames,
            'desc' => $description,
        ];

        if ($this->salePricePkr !== null) $data['sale'] = $this->salePricePkr;
        if ($this->stockQuantity !== null) $data['qty'] = $this->stockQuantity;
        if (!empty($this->attributes)) $data['attrs'] = $this->attributes;
        if ($this->brand !== null) $data['brand'] = $this->brand;
        if ($this->isVariation) $data['variation'] = true;

        return $data;
    }
}
