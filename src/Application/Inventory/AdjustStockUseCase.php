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
        // The inventory transaction is already committed. A projection failure must
        // never report the adjustment as failed and invite the operator to repeat it.
        $this->syncToWooCommerce($productId, $locationId);
        $this->log->info('Stock adjusted.', ['product_id'=>$productId,'location_id'=>$locationId,'delta'=>$delta,'type'=>$type->value]);
    }
    public function setAbsolute(int $productId, int $locationId, int $quantity, string $note = 'Manual count'): void {
        $this->stockRepo->setStockAndRecordMovement($productId,$locationId,$quantity,$note,get_current_user_id());
        $this->syncToWooCommerce($productId, $locationId);
    }
    private function syncToWooCommerce(int $productId, int $locationId): void {
        try {
            $product = $this->productRepo->findById($productId);
            if (!$product || !$product->wcProductId || !function_exists('wc_get_product')) return;
            $totalStock = $this->stockRepo->getTotalStock($productId);
            $wcProduct  = wc_get_product($product->wcProductId);
            if (!$wcProduct) return;
            $wcProduct->set_manage_stock(true);
            $wcProduct->set_stock_quantity($totalStock);
            $wcProduct->set_stock_status($totalStock > 0 ? 'instock' : 'outofstock');
            if ($wcProduct->save() === false) throw new \RuntimeException('WooCommerce rejected the stock projection save.');
        } catch (\Throwable $e) {
            // The hourly full reconciliation is the recovery path for this projection.
            $this->log->error('Inventory adjustment committed but WooCommerce stock projection failed.', [
                'product_id'=>$productId,'location_id'=>$locationId,'error'=>$e->getMessage(),
            ]);
        }
    }
}
