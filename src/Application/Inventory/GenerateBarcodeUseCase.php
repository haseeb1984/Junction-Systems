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
        // Probe the base value and deterministic alternatives. The repository
        // reserves each barcode under a MySQL advisory lock; retry a candidate
        // if another request claimed it between this lookup and the update.
        $base = $barcode;
        for ($attempt = 0; $attempt <= 999; $attempt++) {
            $candidate = $attempt === 0
                ? $base
                : substr($base, 0, 9) . sprintf('%03d', $attempt);
            $conflict = $this->productRepo->findByBarcode($candidate);
            if ($conflict && $conflict->id !== $productId) continue;

            $product->assignBarcode($candidate);
            try {
                $this->productRepo->update($product);
                return $candidate;
            } catch (\DomainException $error) {
                // The barcode reservation lock detected a concurrent winner.
                continue;
            }
        }
        throw new \RuntimeException('Unable to generate a unique inventory barcode.');
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
