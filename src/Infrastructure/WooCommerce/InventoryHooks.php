<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
use PkStore\Application\Inventory\SyncWooCommerceStockUseCase;
use PkStore\Application\Inventory\InventoryProductLock;
use PkStore\Application\Inventory\ProcessWooCommerceOrderInventoryQueueUseCase;
use PkStore\Application\Inventory\ProcessWooCommerceReturnInventoryQueueUseCase;
use PkStore\Container\ServiceContainer;
final class InventoryHooks {
    /** @var array<string, string[]> */
    private array $lifecycleLocks = [];

    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        // MigrationRunner runs on init priority 1. Register lifecycle hooks only
        // after it has completed the cycle-aware schema upgrade.
        add_action('init', [$this, 'registerLifecycleHooks'], 20);
    }

    public function registerLifecycleHooks(): void {
        if (version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.13', '<')) {
            // Without the cycle-scoped sale/return schema, allowing online sales
            // would make the shared POS/store ledger unreliable.
            add_filter('woocommerce_is_purchasable', [$this, 'blockPurchasesDuringMigration'], 99, 2);
            add_action('woocommerce_check_cart_items', [$this, 'blockCheckoutDuringMigration']);
            add_action('admin_notices', [$this, 'migrationNotice']);
            return;
        }

        // WooCommerce's per-line restore hook is required to reconcile actual
        // cancellation/pending/failed stock restoration without guessing from
        // order status. Older versions must not sell Junction-managed products.
        if (!defined('WC_VERSION') || version_compare(WC_VERSION, '9.1', '<')) {
            add_filter('woocommerce_is_purchasable', [$this, 'blockPurchasesOnUnsupportedWooCommerce'], 99, 2);
            add_action('woocommerce_check_cart_items', [$this, 'blockCheckoutOnUnsupportedWooCommerce']);
            add_action('admin_notices', [$this, 'woocommerceVersionNotice']);
            return;
        }

        // Acquire the shared product mutex before WooCommerce mutates stock.
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'lockProductsBeforeOrderStockReduction'], 1, 2);
        add_filter('woocommerce_can_restore_order_stock', [$this, 'lockProductsBeforeOrderStockRestore'], 1, 2);
        add_filter('woocommerce_can_restock_refunded_items', [$this, 'lockProductsBeforeRefundRestock'], 1, 3);
        add_action('woocommerce_restore_order_stock', [$this, 'releaseOrderRestoreLocks'], PHP_INT_MAX, 1);
        add_action('woocommerce_order_refunded', [$this, 'releaseRefundRestockLocks'], PHP_INT_MAX, 2);
        register_shutdown_function([$this, 'releaseAllLifecycleLocks']);

        // Queue an online sale only after WooCommerce has completed its actual
        // order stock-reduction pass. Payment/status transitions alone are not proof.
        add_action('woocommerce_reduce_order_stock', [$this, 'onOrderStockReduced'], 10, 1);
        // This per-line hook fires only after WooCommerce actually restores stock.
        add_action('woocommerce_restore_order_item_stock', [$this, 'onOrderItemRestored'], 10, 4);
        // This hook fires only when the merchant explicitly restocks a refunded item.
        add_action('woocommerce_restock_refunded_item', [$this, 'onRefundItemRestocked'], 10, 5);
        if (!wp_next_scheduled('pkstore_inventory_hourly_sync')) {
            wp_schedule_event(time(), 'hourly', 'pkstore_inventory_hourly_sync');
        }
        add_action('pkstore_inventory_hourly_sync', [$this, 'hourlySyncAll']);
    }

    /**
     * Lock mapped stock owners before WooCommerce performs an order stock change.
     * @return bool Preserve WooCommerce's original decision.
     */
    public function lockProductsBeforeOrderStockReduction(bool $allowed, $order): bool {
        if (!$allowed || !($order instanceof \WC_Order) || $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') return $allowed;
        $this->acquireOrderLifecycleLocks('reduce', $order, null);
        return $allowed;
    }

    public function lockProductsBeforeOrderStockRestore(bool $allowed, $order): bool {
        if (!$allowed || !($order instanceof \WC_Order)) return $allowed;
        $this->acquireOrderLifecycleLocks('restore', $order, null);
        return $allowed;
    }

    public function lockProductsBeforeRefundRestock(bool $allowed, $order, array $refundedLineItems = []): bool {
        if (!$allowed || !($order instanceof \WC_Order)) return $allowed;
        $this->acquireOrderLifecycleLocks('refund', $order, $refundedLineItems);
        return $allowed;
    }

    private function acquireOrderLifecycleLocks(string $kind, \WC_Order $order, ?array $selectedLines): void {
        $orderId = (int) $order->get_id();
        if ($orderId < 1) throw new \RuntimeException('Cannot lock stock for an invalid WooCommerce order.');
        $key = $kind . ':' . $orderId;
        if (isset($this->lifecycleLocks[$key])) return;

        $productRepo = $this->c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class);
        $inventoryIds = [];
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if ($selectedLines !== null && (!isset($selectedLines[$itemId]['qty']) || (int) $selectedLines[$itemId]['qty'] <= 0)) continue;
            $reduced = $item->get_meta('_reduced_stock', true);
            if ($kind === 'restore' && (!is_numeric($reduced) || (float) $reduced <= 0)) continue;
            $wcProduct = $item->get_product();
            if (!$wcProduct instanceof \WC_Product || !$wcProduct->managing_stock()) continue;
            $stockOwnerId = method_exists($wcProduct, 'get_stock_managed_by_id')
                ? (int) $wcProduct->get_stock_managed_by_id()
                : (int) $wcProduct->get_id();
            if ($stockOwnerId < 1) throw new \RuntimeException('WooCommerce stock owner could not be resolved.');
            $inventoryProduct = $productRepo->findByWcProductId($stockOwnerId);
            if ($inventoryProduct) $inventoryIds[] = (int) $inventoryProduct->id;
        }

        if ($inventoryIds) $this->lifecycleLocks[$key] = InventoryProductLock::acquireMany($inventoryIds);
        else $this->lifecycleLocks[$key] = [];
    }

    private function releaseOrderLifecycleLocks(string $kind, int $orderId): void {
        $key = $kind . ':' . $orderId;
        if (!array_key_exists($key, $this->lifecycleLocks)) return;
        InventoryProductLock::release($this->lifecycleLocks[$key]);
        unset($this->lifecycleLocks[$key]);
    }

    public function releaseOrderRestoreLocks($order): void {
        if ($order instanceof \WC_Order) $this->releaseOrderLifecycleLocks('restore', (int) $order->get_id());
    }

    public function releaseRefundRestockLocks(int $orderId, int $refundId): void {
        $this->releaseOrderLifecycleLocks('refund', $orderId);
    }

    public function releaseAllLifecycleLocks(): void {
        foreach (array_keys($this->lifecycleLocks) as $key) {
            [$kind, $orderId] = explode(':', $key, 2);
            $this->releaseOrderLifecycleLocks($kind, (int) $orderId);
        }
    }

    public function blockPurchasesDuringMigration(bool $purchasable, $product): bool {
        return false;
    }

    public function blockPurchasesOnUnsupportedWooCommerce(bool $purchasable, $product): bool {
        if (!$purchasable) return false;
        return $this->hasJunctionInventoryMapping($product) ? false : $purchasable;
    }

    public function blockCheckoutOnUnsupportedWooCommerce(): void {
        if (!function_exists('WC') || !WC()->cart || !function_exists('wc_add_notice')) return;
        foreach (WC()->cart->get_cart() as $cartItem) {
            if ($this->hasJunctionInventoryMapping($cartItem['data'] ?? null)) {
                wc_add_notice('This store requires WooCommerce 9.1 or later for safe Junction online/POS stock and return synchronization. Please contact the store administrator.', 'error');
                return;
            }
        }
    }

    public function woocommerceVersionNotice(): void {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="notice notice-error"><p>Junction online inventory requires WooCommerce 9.1 or later. Junction-managed products are not purchasable until WooCommerce is updated because older versions do not expose the required per-line stock-restoration hook.</p></div>';
    }

    private function hasJunctionInventoryMapping($product): bool {
        if (!$product instanceof \WC_Product) return false;
        try {
            $repo = $this->c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class);
            if ($repo->findByWcProductId((int) $product->get_id())) return true;
            if ($product->is_type('variation')) {
                return (bool) $repo->findByWcProductId((int) $product->get_parent_id());
            }
            return false;
        } catch (\Throwable $error) {
            // Mapping lookup failures must not silently allow an online sale
            // when the lifecycle hooks required for safe stock restoration are absent.
            return true;
        }
    }


    public function blockCheckoutDuringMigration(): void {
        if (function_exists('wc_add_notice')) {
            wc_add_notice('Online checkout is temporarily paused while Junction completes a protected inventory schema upgrade. Please contact the store administrator.', 'error');
        }
    }

    public function migrationNotice(): void {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="notice notice-error"><p>Junction inventory integration is paused: database migration 2.0.13 is not complete. Confirm the required pre-migration hosting backup so the cycle-aware POS/online stock ledger can be upgraded safely.</p></div>';
    }
    public function onOrderStockReduced($order): void {
        $orderId = 0;
        try {
            if (!($order instanceof \WC_Order) || $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') return;
            $orderId = (int) $order->get_id();
            if ($orderId < 1) throw new \InvalidArgumentException('Invalid WooCommerce order ID.');

            // Preserve the actual reduction first. WooCommerce may remove its
            // _reduced_stock metadata when cancellation/refund restores stock.
            $lineItems = $order->get_items('line_item');
            $previousSnapshotVersion = (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true);
            // Mark capture as incomplete before writing any per-line fallback
            // metadata. If a database write fails midway, the worker must not
            // interpret a mixture of old/new snapshots as a valid sale.
            $order->update_meta_data('_pkstore_wc_reduction_snapshot_version', 'capturing');
            if ((int) $order->save() !== $orderId) {
                throw new \RuntimeException('Unable to mark WooCommerce stock-reduction snapshot as pending.');
            }

            $capturedLines = 0;
            foreach ($lineItems as $item) {
                $reduced = $item->get_meta('_reduced_stock', true);
                if ($reduced === '' || $reduced === null || (is_numeric($reduced) && (float) $reduced === 0.0)) {
                    // A prior cycle's fallback snapshot must never make a line
                    // look reduced in this new cycle if WooCommerce did not
                    // actually decrement it this time (e.g. stock management
                    // was disabled or the product became virtual).
                    if ($item->get_meta('_pkstore_wc_reduced_stock_snapshot', true) !== '') {
                        $item->delete_meta_data('_pkstore_wc_reduced_stock_snapshot');
                        if ((int) $item->save() !== (int) $item->get_id()) {
                            throw new \RuntimeException('Unable to clear stale WooCommerce stock-reduction snapshot.');
                        }
                    }
                    continue;
                }
                if (!is_numeric($reduced) || (float) $reduced < 0 || floor((float) $reduced) !== (float) $reduced) {
                    throw new \RuntimeException('WooCommerce stock-reduction metadata is invalid.');
                }
                $item->update_meta_data('_pkstore_wc_reduced_stock_snapshot', (int) $reduced);
                if ((int) $item->save() !== (int) $item->get_id()) {
                    throw new \RuntimeException('Unable to persist WooCommerce stock-reduction snapshot.');
                }
                $capturedLines++;
            }
            if ($capturedLines < 1) {
                if ($previousSnapshotVersion === '') {
                    $order->delete_meta_data('_pkstore_wc_reduction_snapshot_version');
                } else {
                    $order->update_meta_data('_pkstore_wc_reduction_snapshot_version', $previousSnapshotVersion);
                }
                if ((int) $order->save() !== $orderId) {
                    throw new \RuntimeException('Unable to restore WooCommerce stock-reduction snapshot state.');
                }
                return;
            }
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
                // A legacy sentinel queue row remains visible and blocks POS
                // sales until the cycle can be safely reconstructed.
                $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class)->enqueue($orderId, null);
                $this->holdOrderForInventorySync($order, null);
                error_log('[PKStore Inventory] WooCommerce stock-cycle capture failed; order held pending ledger reconciliation ('.get_class($captureError).').');
                return;
            }

            $orderQueue = $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class);
            $orderQueue->enqueue($orderId, $cycleUuid);

            // Commit the shared ledger before this checkout request finishes.
            // The durable queue remains the crash/retry fallback; an idempotent
            // worker can safely replay the event if this synchronous pass fails.
            try {
                $this->c->make(SyncWooCommerceStockUseCase::class)->onWooCommerceSale($orderId, $cycleUuid);
                $orderQueue->bindInventorySyncHoldCycle($order, $cycleUuid);
                $orderQueue->releaseInventorySyncHold($order, $cycleUuid);
                $orderQueue->acknowledgeCompleted($orderId, $cycleUuid);
            } catch (\Throwable $syncError) {
                $this->holdOrderForInventorySync($order, $cycleUuid);
                error_log('[PKStore Inventory] Synchronous online stock ingestion deferred to durable queue; order held ('.get_class($syncError).').');
            }
        } catch (\Throwable $error) {
            if ($orderId > 0) {
                try {
                    $fallbackCycleUuid = isset($cycleUuid) && is_string($cycleUuid) ? $cycleUuid : null;
                    $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class)->enqueue($orderId, $fallbackCycleUuid);
                } catch (\Throwable $queueError) {
                    error_log('[PKStore Inventory] WooCommerce stock reduction fallback queue also failed ('.get_class($queueError).').');
                }
                if (isset($order) && $order instanceof \WC_Order) {
                    $this->holdOrderForInventorySync($order, isset($cycleUuid) && is_string($cycleUuid) ? $cycleUuid : null);
                }
            }
            error_log('[PKStore Inventory] WooCommerce stock reduction hook could not complete ingestion ('.get_class($error).').');
        } finally {
            $this->releaseOrderLifecycleLocks('reduce', $orderId);
        }
    }

    private function cycleUuidForReturn(\WC_Order $order): ?string {
        $cycleUuid = (string) $order->get_meta('_pkstore_wc_active_cycle_uuid', true);
        $snapshotVersion = (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true);
        if ($snapshotVersion === '') {
            return $cycleUuid !== null && $cycleUuid !== '' ? $cycleUuid : null; // pre-cycle legacy order
        }
        if ($snapshotVersion !== '1') {
            return null; // incomplete snapshot must never bind a return to a prior cycle
        }

        // For cycle-aware orders, verify the marker against durable cycle
        // storage instead of trusting possibly stale order metadata.
        try {
            $cycleUuid = $this->c->make(\PkStore\Application\Inventory\CaptureWooCommerceStockCycleUseCase::class)
                ->resolveCycleUuidForReturn($order);
            $order->update_meta_data('_pkstore_wc_active_cycle_uuid', $cycleUuid);
            if ((int) $order->save() !== (int) $order->get_id()) {
                throw new \RuntimeException('Unable to persist recovered WooCommerce return-cycle identity.');
            }
            return $cycleUuid;
        } catch (\Throwable $error) {
            error_log('[PKStore Inventory] WooCommerce return cycle identity could not be recovered; durable event remains unbound for retry (' . get_class($error) . ').');
            return null;
        }
    }

    private function holdOrderForInventorySync(\WC_Order $order, ?string $cycleUuid): void {
        $orderId = (int) $order->get_id();
        if ($orderId < 1) return;
        $freshOrder = wc_get_order($orderId);
        if (!($freshOrder instanceof \WC_Order)) return;

        $alreadyMarked = $freshOrder->get_meta('_pkstore_wc_inventory_sync_hold', true) === 'yes';
        if (!$alreadyMarked) {
            if (in_array($freshOrder->get_status(), ['cancelled', 'refunded', 'failed'], true)) return;
            $freshOrder->update_meta_data('_pkstore_wc_inventory_sync_hold', 'yes');
            $freshOrder->update_meta_data('_pkstore_wc_inventory_sync_previous_status', $freshOrder->get_status());
            if ($cycleUuid !== null) {
                $freshOrder->update_meta_data('_pkstore_wc_inventory_sync_cycle_uuid', $cycleUuid);
            }
            if ((int) $freshOrder->save() !== $orderId) {
                throw new \RuntimeException('Unable to persist WooCommerce inventory fulfilment hold.');
            }
        } else {
            // Retry a failed status transition only while the order still has
            // the exact status captured when the hold was first requested.
            // A different current status is a newer operator/WooCommerce decision.
            $previousStatus = (string) $freshOrder->get_meta('_pkstore_wc_inventory_sync_previous_status', true);
            if ($freshOrder->get_status() !== $previousStatus) return;
        }

        if ($freshOrder->get_status() !== 'on-hold') {
            $freshOrder->update_status('on-hold', 'Junction is reconciling the shared inventory ledger before this order can be fulfilled.');
            $check = wc_get_order($orderId);
            if (!($check instanceof \WC_Order) || $check->get_status() !== 'on-hold') {
                throw new \RuntimeException('WooCommerce did not confirm the inventory fulfilment hold.');
            }
        }
    }

    private function markReturnCaptureFailed(\WC_Order $order): void {
        $orderId = (int) $order->get_id();
        if ($orderId < 1) throw new \RuntimeException('Unable to persist a return safety marker without a valid order ID.');
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for return safety marker.');
        $lockName = 'pkstore_wc_order_' . $orderId;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 10));
        if ((int) $acquired !== 1) throw new \RuntimeException('Unable to acquire WooCommerce order lock for return safety marker.');
        try {
            $freshOrder = wc_get_order($orderId);
            if (!($freshOrder instanceof \WC_Order)) throw new \RuntimeException('WooCommerce order is unavailable for return safety marker.');
            $freshOrder->update_meta_data('_pkstore_wc_return_capture_failed', 'yes');
            if ((int) $freshOrder->save() !== $orderId) {
                throw new \RuntimeException('Unable to persist the WooCommerce return reconciliation safety marker.');
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public function onOrderItemRestored($item, $newStock, $oldStock, $order): void {
        try {
            if (!($order instanceof \WC_Order) || !is_object($item) || !method_exists($item, 'get_id')) {
                throw new \InvalidArgumentException('Malformed WooCommerce cancellation restock event.');
            }
            if (!is_numeric($newStock) || !is_numeric($oldStock)
                || floor((float) $newStock) !== (float) $newStock
                || floor((float) $oldStock) !== (float) $oldStock
                || (int) $newStock <= (int) $oldStock) {
                throw new \InvalidArgumentException('WooCommerce cancellation restock quantity must be a positive whole number.');
            }
            $quantity = (int) $newStock - (int) $oldStock;
            $productId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ($productId < 1) $productId = (int) $item->get_product_id();
            $cycleUuid = $this->cycleUuidForReturn($order);
            if ($cycleUuid !== null && $cycleUuid !== '') {
                try {
                    $this->c->make(\PkStore\Application\Inventory\CaptureWooCommerceStockCycleUseCase::class)
                        ->markReturnPending($order, (int) $item->get_id(), 'cancellation', $quantity, null, $cycleUuid, $productId);
                } catch (\Throwable $captureError) {
                    $order->update_meta_data('_pkstore_wc_cycle_return_capture_failed_' . $cycleUuid, '1');
                    $order->save();
                    $this->markReturnCaptureFailed($order);
                    error_log('[PKStore Inventory] WooCommerce cancellation cycle capture failed; queued return needs review ('.get_class($captureError).').');
                }
            }
            $returnQueue = $this->c->make(ProcessWooCommerceReturnInventoryQueueUseCase::class);
            $eventKey = $returnQueue->enqueueCancellation(
                (int) $order->get_id(), (int) $item->get_id(), $productId, $quantity, $cycleUuid !== null && $cycleUuid !== '' ? $cycleUuid : null
            );
            try {
                $applied = $this->c->make(SyncWooCommerceStockUseCase::class)->onWooCommerceOrderItemRestored(
                    $item, (int) $newStock, (int) $oldStock, $order, $cycleUuid !== null && $cycleUuid !== '' ? $cycleUuid : null
                );
                if ($applied) $returnQueue->acknowledgeCompleted($eventKey);
            } catch (\Throwable $syncError) {
                error_log('[PKStore Inventory] Synchronous cancellation restock deferred to durable queue ('.get_class($syncError).').');
            }
        } catch (\Throwable $e) {
            if (isset($order) && $order instanceof \WC_Order) {
                try { $this->markReturnCaptureFailed($order); }
                catch (\Throwable $markerError) { error_log('[PKStore Inventory] Cancellation safety marker could not be persisted ('.get_class($markerError).').'); }
            }
            error_log('[PKStore Inventory] Unable to persist cancellation restock event ('.get_class($e).').');
        }
    }
    public function onRefundItemRestocked(int $productId, $oldStock, $newStock, $order, $product): void {
        try {
            if (!($order instanceof \WC_Order)) throw new \InvalidArgumentException('Malformed WooCommerce refund event.');
            if (!is_numeric($newStock) || !is_numeric($oldStock)
                || floor((float) $newStock) !== (float) $newStock
                || floor((float) $oldStock) !== (float) $oldStock) {
                throw new \InvalidArgumentException('WooCommerce refund restock quantities must be whole numbers.');
            }
            $delta = (int) $newStock - (int) $oldStock;
            $itemId = null;
            $cumulative = 0;
            foreach ($order->get_items('line_item') as $item) {
                $parentId = (int) $item->get_product_id();
                $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
                if ($productId !== $parentId && $productId !== $variationId) continue;
                $rawCumulative = $item->get_meta('_restock_refunded_items', true);
                if ($rawCumulative === '' || $rawCumulative === null) $rawCumulative = 0;
                if (!is_numeric($rawCumulative) || (int) $rawCumulative < 0
                    || floor((float) $rawCumulative) !== (float) $rawCumulative) {
                    throw new \InvalidArgumentException('WooCommerce refund cumulative restock metadata is invalid.');
                }
                $cumulative += (int) $rawCumulative;
                if ($itemId === null) $itemId = (int) $item->get_id();
                else $itemId = 0;
            }
            if ($itemId === 0) $itemId = null; // ambiguous duplicate product lines remain queued for reconciliation
            $cycleUuid = $this->cycleUuidForReturn($order);
            if ($cycleUuid !== null && $cycleUuid !== '') {
                try {
                    $this->c->make(\PkStore\Application\Inventory\CaptureWooCommerceStockCycleUseCase::class)
                        ->markReturnPending($order, (int) $itemId, 'refund', $delta, $cumulative, $cycleUuid, $productId);
                } catch (\Throwable $captureError) {
                    $order->update_meta_data('_pkstore_wc_cycle_return_capture_failed_' . $cycleUuid, '1');
                    $order->save();
                    error_log('[PKStore Inventory] WooCommerce refund cycle capture failed; queued return needs review ('.get_class($captureError).').');
                }
            }
            $returnQueue = $this->c->make(ProcessWooCommerceReturnInventoryQueueUseCase::class);
            $eventKey = $returnQueue->enqueueRefund(
                (int) $order->get_id(), $productId, $delta, $cumulative, $itemId, $cycleUuid !== null && $cycleUuid !== '' ? $cycleUuid : null
            );
            if ($itemId !== null) {
                try {
                    $applied = $this->c->make(SyncWooCommerceStockUseCase::class)->onWooCommerceRefundItemRestocked(
                        $productId, (int) $newStock, (int) $oldStock, $order, $product, $itemId, $cumulative, $cycleUuid !== null && $cycleUuid !== '' ? $cycleUuid : null
                    );
                    if ($applied) $returnQueue->acknowledgeCompleted($eventKey);
                } catch (\Throwable $syncError) {
                    error_log('[PKStore Inventory] Synchronous refund restock deferred to durable queue ('.get_class($syncError).').');
                }
            }
        } catch (\Throwable $e) {
            if (isset($order) && $order instanceof \WC_Order) {
                try { $this->markReturnCaptureFailed($order); }
                catch (\Throwable $markerError) { error_log('[PKStore Inventory] Refund safety marker could not be persisted ('.get_class($markerError).').'); }
            }
            error_log('[PKStore Inventory] Unable to persist refund restock event ('.get_class($e).').');
        }
    }
    public function hourlySyncAll(): void {
        try { $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce(); }
        catch (\Throwable $e) { error_log('[PKStore Inventory] Hourly sync failed ('.get_class($e).').'); }
    }
}
