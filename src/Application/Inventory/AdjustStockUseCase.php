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
        $stock = $this->stockRepo->getStock($productId, $locationId);
        $before = $stock->getQuantity();
        $stock->adjust($delta);
        $this->stockRepo->updateStock($stock);
        $movement = StockMovement::create($productId, $locationId, $type, $delta, $before, $refType, $refId, $note, get_current_user_id());
        $this->stockRepo->recordMovement($movement);

        // Persist the outbox intent alongside stock changes. POS calls this inside
        // its sale transaction, so a rollback also rolls back the sync request.
        $this->stockRepo->enqueueWooCommerceSync($productId);

        // POS defers WooCommerce side effects until after its transaction commits.
        if ($syncWooCommerce) {
            try {
                $this->syncProductToWooCommerce($productId);
            } catch (\Throwable $error) {
                $this->log->error('Stock change saved; WooCommerce sync remains queued for retry.', [
                    'product_id' => $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
        $this->log->info('Stock adjusted.', [
            'product_id' => $productId,
            'location_id' => $locationId,
            'delta' => $delta,
            'type' => $type->value,
        ]);
    }

    public function setAbsolute(int $productId, int $locationId, int $quantity, string $note = 'Manual count'): void {
        global $wpdb;
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start manual stock count transaction.');
        }

        try {
            $stock = $this->stockRepo->getStock($productId, $locationId);
            $before = $stock->getQuantity();
            $delta = $quantity - $before;
            $stock->set($quantity);
            $this->stockRepo->updateStock($stock);
            $movement = StockMovement::create($productId, $locationId, MovementType::Adjustment, $delta, $before, 'manual', '', $note, get_current_user_id());
            $this->stockRepo->recordMovement($movement);
            $this->stockRepo->enqueueWooCommerceSync($productId);

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit manual stock count transaction.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }

        // External WooCommerce writes only happen after the inventory ledger commits.
        try {
            $this->syncProductToWooCommerce($productId);
        } catch (\Throwable $error) {
            $this->log->error('Manual stock count saved; WooCommerce sync remains queued for retry.', [
                'product_id' => $productId,
                'error_class' => get_class($error),
            ]);
        }
    }

    public function syncProductToWooCommerce(int $productId): void {
        $product = $this->productRepo->findById($productId);
        if (!$product || !$product->wcProductId) return;
        $totalStock = $this->stockRepo->getTotalStock($productId);
        $wcProduct = wc_get_product($product->wcProductId);
        if (!$wcProduct) return;
        $wcProduct->set_manage_stock(true);
        $wcProduct->set_stock_quantity($totalStock);
        $wcProduct->set_stock_status($totalStock > 0 ? 'instock' : 'outofstock');
        $savedId = $wcProduct->save();
        if (!$savedId) throw new \RuntimeException('WooCommerce product stock save failed.');
    }
}
