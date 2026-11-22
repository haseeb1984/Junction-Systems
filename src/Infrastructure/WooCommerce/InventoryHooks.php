<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
use PkStore\Application\Inventory\SyncWooCommerceStockUseCase;
use PkStore\Application\Inventory\ProcessWooCommerceOrderInventoryQueueUseCase;
use PkStore\Application\Inventory\ProcessWooCommerceReturnInventoryQueueUseCase;
use PkStore\Container\ServiceContainer;
final class InventoryHooks {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        // Queue an online sale only after WooCommerce has completed its actual
        // order stock-reduction pass. Payment/status transitions alone are not proof.
        add_action('woocommerce_reduce_order_stock', [$this, 'onOrderStockReduced'], 10, 1);
        // This per-line hook fires only after WooCommerce actually restores stock.
        add_action('woocommerce_restore_order_item_stock', [$this, 'onOrderItemRestored'], 10, 4);
        // This hook fires only when the merchant explicitly restocks a refunded item.
        add_action('woocommerce_restock_refunded_item', [$this, 'onRefundItemRestocked'], 10, 5);
        // Scheduled full sync every hour
        if (!wp_next_scheduled('pkstore_inventory_hourly_sync'))
            wp_schedule_event(time(), 'hourly', 'pkstore_inventory_hourly_sync');
        add_action('pkstore_inventory_hourly_sync', [$this, 'hourlySyncAll']);
    }
    public function onOrderStockReduced($order): void {
        try {
            if (!($order instanceof \\WC_Order)) {
                throw new \\InvalidArgumentException('Malformed WooCommerce stock-reduction event.');
            }
            // Junction POS already deducted shared inventory; do not ingest its mirror.
            if ($order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') return;
            $orderId = (int) $order->get_id();
            if ($orderId < 1) throw new \\InvalidArgumentException('Invalid WooCommerce order ID.');
            $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class)->enqueue($orderId);
        } catch (\\Throwable $e) {
            error_log('[PKStore Inventory] Unable to enqueue actual WooCommerce stock reduction ('.get_class($e).').');
        }
    }
    public function onOrderItemRestored($item, int $newStock, int $oldStock, $order): void {
        try {
            if (!($order instanceof \WC_Order) || !is_object($item) || !method_exists($item, 'get_id')) {
                throw new \InvalidArgumentException('Malformed WooCommerce cancellation restock event.');
            }
            $productId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ($productId < 1) $productId = (int) $item->get_product_id();
            $this->c->make(ProcessWooCommerceReturnInventoryQueueUseCase::class)->enqueueCancellation(
                (int) $order->get_id(), (int) $item->get_id(), $productId, $newStock - $oldStock
            );
        } catch (\Throwable $e) {
            error_log('[PKStore Inventory] Unable to persist cancellation restock event ('.get_class($e).').');
        }
    }
    public function onRefundItemRestocked(int $productId, $oldStock, $newStock, $order, $product): void {
        try {
            if (!($order instanceof \WC_Order)) throw new \InvalidArgumentException('Malformed WooCommerce refund event.');
            $delta = (int) $newStock - (int) $oldStock;
            $itemId = null;
            $cumulative = 0;
            foreach ($order->get_items('line_item') as $item) {
                $parentId = (int) $item->get_product_id();
                $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
                if ($productId !== $parentId && $productId !== $variationId) continue;
                $cumulative += (int) $item->get_meta('_restock_refunded_items', true);
                if ($itemId === null) $itemId = (int) $item->get_id();
                else $itemId = 0;
            }
            if ($itemId === 0) $itemId = null; // ambiguous duplicate product lines remain queued for reconciliation
            $this->c->make(ProcessWooCommerceReturnInventoryQueueUseCase::class)->enqueueRefund(
                (int) $order->get_id(), $productId, $delta, $cumulative, $itemId
            );
        } catch (\Throwable $e) {
            error_log('[PKStore Inventory] Unable to persist refund restock event ('.get_class($e).').');
        }
    }
    public function hourlySyncAll(): void {
        try { $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce(); }
        catch (\Throwable $e) { error_log('[PKStore Inventory] Hourly sync failed ('.get_class($e).').'); }
    }
}
