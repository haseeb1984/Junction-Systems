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
        if (!($order instanceof \WC_Order)) { throw new \RuntimeException('WooCommerce order is unavailable for inventory ingestion.'); }
        $onlineLocation = $this->locationRepo->findOnline();
        if (!$onlineLocation) { throw new \RuntimeException('Junction online inventory location is not configured.'); }

        // Aggregate repeated product lines so one order creates one auditable
        // online-sale movement per inventory product.
        $quantities = [];
        foreach ($order->get_items() as $item) {
            $wcProductId = (int) $item->get_product_id();
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $rawQuantity = (float) $item->get_quantity();
            if ($wcProductId < 1 || $rawQuantity <= 0 || floor($rawQuantity) !== $rawQuantity) {
                throw new \RuntimeException('WooCommerce order contains an invalid inventory product or quantity.');
            }
            // Prefer an explicitly mapped variation so separately stocked
            // variants deduct from their own Junction inventory record. If no
            // variation mapping exists, fall back to the parent product mapping.
            $product = $variationId > 0
                ? $this->productRepo->findByWcProductId($variationId)
                : null;
            $product ??= $this->productRepo->findByWcProductId($wcProductId);
            if (!$product) {
                // Fail before opening the inventory transaction: never deduct only
                // the mapped subset of an order and leave the order under-counted.
                throw new \RuntimeException('WooCommerce order product is not linked to Junction inventory.');
            }
            $quantities[$product->id] = ($quantities[$product->id] ?? 0) + (int) $rawQuantity;
        }
        if (!$quantities) {
            // An order with no stock-managed product lines needs no inventory movement.
            return;
        }
        ksort($quantities, SORT_NUMERIC);

        global $wpdb;
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
            $this->log->critical('WooCommerce order inventory ledger could not start its transaction.', [
                'order_id' => $orderId,
            ]);
            throw new \RuntimeException('Unable to start WooCommerce order inventory ledger transaction.');
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
                throw new \RuntimeException('Unable to commit WooCommerce order inventory ledger.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce order inventory ledger was rolled back; event must be retried.', [
                'order_id' => $orderId,
                'error_class' => get_class($error),
            ]);
            throw $error;
        }

        // Push the aggregate snapshot only after the inventory ledger commits.
        foreach (array_keys($quantities) as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce((int) $productId);
            } catch (\Throwable $error) {
                $this->log->error('WooCommerce order committed; aggregate stock sync remains queued.', [
                    'order_id' => $orderId,
                    'product_id' => (int) $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
    }
    /**
     * Mirror a cancellation restock only after WooCommerce has actually restored
     * the individual order item. The callback's stock delta is authoritative.
     */
    public function onWooCommerceOrderItemRestored($item, int $newStock, int $oldStock, $order): void {
        if (!($order instanceof \\WC_Order) || !is_object($item)
            || !method_exists($item, 'get_id') || !method_exists($item, 'get_product_id')) {
            throw new \\InvalidArgumentException('WooCommerce restore event is malformed.');
        }
        $orderId = (int) $order->get_id();
        $itemId = (int) $item->get_id();
        $quantity = $newStock - $oldStock;
        if ($orderId < 1 || $itemId < 1 || $quantity < 1) {
            throw new \\InvalidArgumentException('WooCommerce restore quantity is invalid.');
        }

        $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
        $wcProductId = (int) $item->get_product_id();
        $product = $variationId > 0 ? $this->productRepo->findByWcProductId($variationId) : null;
        $product ??= $this->productRepo->findByWcProductId($wcProductId);
        if (!$product) {
            throw new \\RuntimeException('Restored WooCommerce item is not linked to Junction inventory.');
        }
        $onlineLocation = $this->locationRepo->findOnline();
        if (!$onlineLocation) {
            throw new \\RuntimeException('Junction online inventory location is not configured.');
        }

        $referenceId = $orderId . ':' . $itemId;
        global $wpdb;
        if (!$wpdb instanceof \\wpdb || $wpdb->query('START TRANSACTION') === false) {
            throw new \\RuntimeException('Unable to start WooCommerce return ledger transaction.');
        }
        try {
            $this->stockRepo->getStock($product->id, $onlineLocation->id);
            if (!$this->stockRepo->hasMovementReference(
                $product->id,
                $onlineLocation->id,
                'wc_order_restore',
                $referenceId,
                MovementType::OnlineReturn
            )) {
                $this->adjustStock->execute(
                    $product->id,
                    $onlineLocation->id,
                    $quantity,
                    MovementType::OnlineReturn,
                    "WooCommerce cancellation restock for order #{$orderId}, item #{$itemId}",
                    'wc_order_restore',
                    $referenceId,
                    false
                );
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \\RuntimeException('Unable to commit WooCommerce return ledger transaction.');
            }
        } catch (\\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce cancellation restock was not applied to Junction inventory.', [
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'error_class' => get_class($error),
            ]);
            throw $error;
        }

        try {
            $this->adjustStock->syncProductToWooCommerce($product->id);
        } catch (\\Throwable $error) {
            $this->log->error('WooCommerce return committed; aggregate stock sync remains queued.', [
                'order_id' => $orderId,
                'product_id' => $product->id,
                'error_class' => get_class($error),
            ]);
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
