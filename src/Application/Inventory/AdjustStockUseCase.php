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
    public function execute(int $productId, int $locationId, int $delta, MovementType $type, string $note = '', string $refType = '', string $refId = '', bool $syncWooCommerce = true): void {
        $stock  = $this->stockRepo->getStock($productId, $locationId);
        $before = $stock->getQuantity();
        $stock->adjust($delta);
        $this->stockRepo->updateStock($stock);
        $movement = StockMovement::create($productId, $locationId, $type, $delta, $before, $refType, $refId, $note, get_current_user_id());
        $this->stockRepo->recordMovement($movement);
        // Persist the sync intent in the same transaction as stock changes. The worker
        // can recover it after a PHP crash or a failed WooCommerce product save.
        $this->stockRepo->enqueueWooCommerceSync($productId);
        // POS sales defer external WooCommerce side effects until their DB transaction commits.
        if ($syncWooCommerce) $this->syncProductToWooCommerce($productId);
        $this->log->info('Stock adjusted.', ['product_id'=>$productId,'location_id'=>$locationId,'delta'=>$delta,'type'=>$type->value]);
    }
    public function setAbsolute(int $productId, int $locationId, int $quantity, string $note = 'Manual count'): void {
        $stock  = $this->stockRepo->getStock($productId, $locationId);
        $before = $stock->getQuantity();
        $delta  = $quantity - $before;
        $stock->set($quantity);
        $this->stockRepo->updateStock($stock);
        $movement = StockMovement::create($productId, $locationId, MovementType::Adjustment, $delta, $before, 'manual', '', $note, get_current_user_id());
        $this->stockRepo->recordMovement($movement);
        $this->stockRepo->enqueueWooCommerceSync($productId);
        $this->syncProductToWooCommerce($productId, $locationId);
    }
    public function syncProductToWooCommerce(int $productId): void {
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
