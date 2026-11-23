<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Entity\{MovementType, StockMovement};
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface};
use Psr\Log\LoggerInterface;
final class AdjustStockUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly StockRepositoryInterface $stockRepo,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(int $productId, int $locationId, int $delta, MovementType $type, string $note = '', string $refType = '', string $refId = ''): void {
        $movement = StockMovement::create($productId,$locationId,$type,$delta,0,$refType,$refId,$note,get_current_user_id());
        $this->stockRepo->applyMovement($movement);
        // Sync only after the stock quantity and its ledger entry have committed.
        $this->syncToWooCommerce($productId, $locationId);
        $this->log->info('Stock adjusted.', ['product_id'=>$productId,'location_id'=>$locationId,'delta'=>$delta,'type'=>$type->value]);
    }
    public function setAbsolute(int $productId, int $locationId, int $quantity, string $note = 'Manual count'): void {
        $this->stockRepo->setStockAndRecordMovement($productId,$locationId,$quantity,$note,get_current_user_id());
        $this->syncToWooCommerce($productId, $locationId);
    }
    private function syncToWooCommerce(int $productId, int $locationId): void {
        $product = $this->productRepo->findById($productId);
        if (!$product || !$product->wcProductId) return;
        $totalStock = $this->stockRepo->getTotalStock($productId);
        $wcProduct  = wc_get_product($product->wcProductId);
        if (!$wcProduct) return;
        $wcProduct->set_manage_stock(true);
        $wcProduct->set_stock_quantity($totalStock);
        $wcProduct->set_stock_status($totalStock > 0 ? 'instock' : 'outofstock');
        $wcProduct->save();
    }
}
