<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
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
        // Probe deterministic alternatives instead of assuming one suffix is unique.
        // The repository currently has no database-level unique constraint for non-empty barcodes.
        $base = $barcode;
        $existing = $this->productRepo->findByBarcode($barcode);
        if ($existing && $existing->id !== $productId) {
            $barcode = '';
            for ($attempt = 1; $attempt <= 999; $attempt++) {
                $candidate = substr($base, 0, 9) . sprintf('%03d', $attempt);
                $conflict = $this->productRepo->findByBarcode($candidate);
                if (!$conflict || $conflict->id === $productId) {
                    $barcode = $candidate;
                    break;
                }
            }
            if ($barcode === '') {
                throw new \RuntimeException('Unable to generate a unique inventory barcode.');
            }
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
