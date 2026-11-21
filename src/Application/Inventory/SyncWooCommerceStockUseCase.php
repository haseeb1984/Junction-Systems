<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface, LocationRepositoryInterface};
use Psr\Log\LoggerInterface;
final class SyncWooCommerceStockUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly StockRepositoryInterface $stockRepo,
        private readonly LocationRepositoryInterface $locationRepo,
        private readonly AdjustStockUseCase $adjustStock,
        private readonly LoggerInterface $log,
    ) {}
    public function onWooCommerceSale(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \\WC_Order)) return;
        $onlineLocation = $this->locationRepo->findOnline();
        if (!$onlineLocation) return;

        // Aggregate repeated product lines so one order creates one auditable
        // online-sale movement per inventory product.
        $quantities = [];
        foreach ($order->get_items() as $item) {
            $wcProductId = (int) $item->get_product_id();
            $quantity = (int) $item->get_quantity();
            if ($wcProductId < 1 || $quantity < 1) continue;
            $product = $this->productRepo->findByWcProductId($wcProductId);
            if (!$product) continue;
            $quantities[$product->id] = ($quantities[$product->id] ?? 0) + $quantity;
        }
        if (!$quantities) return;

        global $wpdb;
        if (!$wpdb instanceof \\wpdb || $wpdb->query('START TRANSACTION') === false) {
            $this->log->critical('WooCommerce order inventory ledger could not start its transaction.', [
                'order_id' => $orderId,
            ]);
            return;
        }

        try {
            foreach ($quantities as $productId => $quantity) {
                // Lock the location/product row before checking the reference.
                // Concurrent duplicate WooCommerce hooks then observe the first
                // committed movement and cannot deduct the same order twice.
                $this->stockRepo->getStock((int) $productId, $onlineLocation->id);
                if ($this->stockRepo->hasMovementReference(
                    (int) $productId,
                    $onlineLocation->id,
                    'wc_order',
                    (string) $orderId,
                    MovementType::OnlineSale
                )) {
                    continue;
                }

                $this->adjustStock->execute(
                    (int) $productId,
                    $onlineLocation->id,
                    -$quantity,
                    MovementType::OnlineSale,
                    "WooCommerce order #{$orderId}",
                    'wc_order',
                    (string) $orderId,
                    false
                );
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \\RuntimeException('Unable to commit WooCommerce order inventory ledger.');
            }
        } catch (\\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce order inventory ledger was rolled back.', [
                'order_id' => $orderId,
                'error_class' => get_class($error),
            ]);
            return;
        }

        // Push the aggregate snapshot only after the inventory ledger commits.
        foreach (array_keys($quantities) as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce((int) $productId);
            } catch (\\Throwable $error) {
                $this->log->error('WooCommerce order committed; aggregate stock sync remains queued.', [
                    'order_id' => $orderId,
                    'product_id' => (int) $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
    }
    public function syncAllToWooCommerce(): int {
        $products = $this->productRepo->findAll(500, 0);
        $synced = 0;
        foreach ($products as $product) {
            if (!$product->wcProductId) continue;
            // Keep the reconciliation retryable and use the same per-product lock
            // as POS, manual adjustments and the background queue worker.
            $this->stockRepo->enqueueWooCommerceSync($product->id);
            try {
                $this->adjustStock->syncProductToWooCommerce($product->id);
                $synced++;
            } catch (\Throwable $error) {
                $this->log->error('Hourly WooCommerce stock reconciliation remains queued.', [
                    'product_id' => $product->id,
                    'error_class' => get_class($error),
                ]);
            }
        }
        return $synced;
    }
}
