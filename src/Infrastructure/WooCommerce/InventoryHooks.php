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
            if (!($order instanceof \WC_Order) || $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') return;
            $orderId = (int) $order->get_id();
            if ($orderId < 1) throw new \InvalidArgumentException('Invalid WooCommerce order ID.');

            // Preserve the actual reduction first. WooCommerce may remove its
            // _reduced_stock metadata when cancellation/refund restores stock.
            $capturedLines = 0;
            foreach ($order->get_items('line_item') as $item) {
                $reduced = $item->get_meta('_reduced_stock', true);
                if ($reduced === '' || $reduced === null || (float) $reduced === 0.0) continue;
                if (!is_numeric($reduced) || (float) $reduced < 0 || floor((float) $reduced) !== (float) $reduced) {
                    throw new \RuntimeException('WooCommerce stock-reduction metadata is invalid.');
                }
                $item->update_meta_data('_pkstore_wc_reduced_stock_snapshot', (int) $reduced);
                if ((int) $item->save() !== (int) $item->get_id()) {
                    throw new \RuntimeException('Unable to persist WooCommerce stock-reduction snapshot.');
                }
                $capturedLines++;
            }
            if ($capturedLines < 1) return;
            $order->update_meta_data('_pkstore_wc_reduction_snapshot_version', '1');
            if ((int) $order->save() !== $orderId) {
                throw new \RuntimeException('Unable to persist WooCommerce stock-reduction snapshot version.');
            }

            try {
                // Capture a durable per-order reduction cycle. The cycle UUID
                // distinguishes a genuine re-sale after a fully restored cycle.
                $cycleUuid = $this->c->make(\PkStore\Application\Inventory\CaptureWooCommerceStockCycleUseCase::class)->capture($order);
                $order->update_meta_data('_pkstore_wc_active_cycle_uuid', $cycleUuid);
                if ((int) $order->save() !== $orderId) {
                    throw new \RuntimeException('Unable to persist WooCommerce stock-cycle identity.');
                }
            } catch (\Throwable $captureError) {
                // Still enqueue the captured quantity so a failed cycle capture
                // becomes visible in Sync Health instead of silently losing stock.
                // If an older active cycle exists, its UUID remains on the order;
                // the worker will fail closed on a conflicting snapshot.
                $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class)
                    ->enqueue($orderId, null);
                $this->log->critical('WooCommerce stock-cycle capture failed; order was queued for safe reconciliation.', [
                    'order_id' => $orderId,
                    'error_class' => get_class($captureError),
                ]);
                return;
            }

            $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class)->enqueue($orderId, $cycleUuid);
        } catch (\Throwable $error) {
            $this->log->error('WooCommerce stock reduction hook could not enqueue inventory processing.', [
                'error_class' => get_class($error),
            ]);
        }
    }

    public function onOrderItemRestored($item, int $newStock, int $oldStock, $order): void {
        try {
            if (!($order instanceof \WC_Order) || !is_object($item) || !method_exists($item, 'get_id')) {
                throw new \InvalidArgumentException('Malformed WooCommerce cancellation restock event.');
            }
            $productId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ($productId < 1) $productId = (int) $item->get_product_id();
            $cycleUuid = (string) $order->get_meta('_pkstore_wc_active_cycle_uuid', true);
            if ($cycleUuid !== '') {
                try {
                    $this->c->make(\PkStore\Application\Inventory\CaptureWooCommerceStockCycleUseCase::class)
                        ->markReturnPending($order, (int) $item->get_id(), 'cancellation', $newStock - $oldStock);
                } catch (\Throwable $captureError) {
                    $order->update_meta_data('_pkstore_wc_cycle_return_capture_failed_' . $cycleUuid, '1');
                    $order->save();
                    $this->log->critical('WooCommerce cancellation cycle capture failed; queued return will require review.', [
                        'order_id' => (int) $order->get_id(),
                        'order_item_id' => (int) $item->get_id(),
                        'error_class' => get_class($captureError),
                    ]);
                }
            }
            $this->c->make(ProcessWooCommerceReturnInventoryQueueUseCase::class)->enqueueCancellation(
                (int) $order->get_id(), (int) $item->get_id(), $productId, $newStock - $oldStock, $cycleUuid !== '' ? $cycleUuid : null
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
            $cycleUuid = (string) $order->get_meta('_pkstore_wc_active_cycle_uuid', true);
            if ($cycleUuid !== '') {
                try {
                    $this->c->make(\PkStore\Application\Inventory\CaptureWooCommerceStockCycleUseCase::class)
                        ->markReturnPending($order, (int) $itemId, 'refund', $delta, $cumulative);
                } catch (\Throwable $captureError) {
                    $order->update_meta_data('_pkstore_wc_cycle_return_capture_failed_' . $cycleUuid, '1');
                    $order->save();
                    $this->log->critical('WooCommerce refund cycle capture failed; queued return will require review.', [
                        'order_id' => (int) $order->get_id(),
                        'order_item_id' => (int) $itemId,
                        'error_class' => get_class($captureError),
                    ]);
                }
            }
            $this->c->make(ProcessWooCommerceReturnInventoryQueueUseCase::class)->enqueueRefund(
                (int) $order->get_id(), $productId, $delta, $cumulative, $itemId, $cycleUuid !== '' ? $cycleUuid : null
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
