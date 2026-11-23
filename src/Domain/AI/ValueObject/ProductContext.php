<?php
declare(strict_types=1);
namespace PkStore\Domain\AI\ValueObject;

final readonly class ProductContext {
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $price,
        public readonly ?string $salePrice,
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
        if (function_exists('mb_substr')) {
            $description = mb_substr($this->shortDescription, 0, 200);
        } elseif (function_exists('iconv_substr')) {
            $description = iconv_substr($this->shortDescription, 0, 200, 'UTF-8');
            if ($description === false) $description = substr($this->shortDescription, 0, 200);
        } else {
            $description = substr($this->shortDescription, 0, 200);
        }

        $data = [
            'id' => $this->productId,
            'sku' => $this->sku,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'stock' => $this->stockStatus,
            'url' => $this->productUrl,
            'category' => $this->categoryNames,
            'description' => $description,
        ];

        if ($this->salePrice !== null) $data['sale_price'] = $this->salePrice;
        if ($this->stockQuantity !== null) $data['quantity'] = $this->stockQuantity;
        if (!empty($this->attributes)) $data['attributes'] = $this->attributes;
        if ($this->brand !== null) $data['brand'] = $this->brand;
        if ($this->isVariation) $data['is_variation'] = true;

        return $data;
    }
}
