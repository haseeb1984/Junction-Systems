<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Inventory\Entity;
use DateTimeImmutable;
final class InventoryProduct {
    public function __construct(
        public readonly int $id, public readonly ?int $wcProductId,
        public readonly string $sku, private string $barcode,
        public readonly string $barcodeType, public readonly string $name,
        public readonly string $category, public readonly int $costPricePkr,
        public readonly int $salePricePkr, public readonly int $reorderPoint,
        public readonly string $unit, public readonly bool $isActive,
        public readonly DateTimeImmutable $createdAt,
    ) {}
    public function getBarcode(): string { return $this->barcode; }
    public function hasBarcode(): bool { return !empty($this->barcode); }
    public function assignBarcode(string $barcode): void { $this->barcode = $barcode; }
    public static function create(string $sku, string $name, int $costPrice, int $salePrice, string $category = '', ?int $wcProductId = null, string $barcode = '', string $unit = 'piece', int $reorderPoint = 5): self {
        return new self(0, $wcProductId, $sku, $barcode, 'CODE128', $name, $category, $costPrice, $salePrice, $reorderPoint, $unit, true, new DateTimeImmutable());
    }
}
