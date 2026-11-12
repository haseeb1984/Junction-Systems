<?php
declare(strict_types=1);
namespace JunctionStore\Application\Inventory;
use JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
final class GenerateBarcodeUseCase {
    public function __construct(private readonly InventoryProductRepositoryInterface $productRepo) {}
    public function execute(int $productId): string {
        $product = $this->productRepo->findById($productId);
        if (!$product) throw new \RuntimeException("Product [{$productId}] not found.");
        if ($product->hasBarcode()) return $product->getBarcode();
        // Generate unique barcode from SKU (Code 128 compatible)
        $barcode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $product->sku));
        if (strlen($barcode) < 6) $barcode = str_pad($barcode, 8, '0', STR_PAD_LEFT);
        $barcode = 'PKS' . substr($barcode, 0, 9);
        // Ensure uniqueness
        $existing = $this->productRepo->findByBarcode($barcode);
        if ($existing && $existing->id !== $productId) {
            $barcode .= sprintf('%03d', $productId % 1000);
        }
        $product->assignBarcode($barcode);
        $this->productRepo->update($product);
        return $barcode;
    }
    public function generateForAll(): int {
        $products = $this->productRepo->findAll(1000, 0);
        $count    = 0;
        foreach ($products as $product) {
            if (!$product->hasBarcode()) {
                $this->execute($product->id);
                $count++;
            }
        }
        return $count;
    }
}
