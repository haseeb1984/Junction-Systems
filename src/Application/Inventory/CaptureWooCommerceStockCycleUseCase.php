<?php
declare(strict_types=1);

namespace PkStore\Application\Inventory;

use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\Inventory\Repository\LocationRepositoryInterface;

/**
 * Persist an immutable snapshot of one real WooCommerce stock-reduction pass.
 *
 * This service is called by the actual WooCommerce stock-reduction hook.
 * It snapshots each reduction cycle before asynchronous ledger processing.
 */
final class CaptureWooCommerceStockCycleUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly LocationRepositoryInterface $locationRepo,
    ) {}

    /**
     * @return string The persisted cycle UUID.
     */
    public function capture($order): string {
        if (!($order instanceof \WC_Order)) {
            throw new \InvalidArgumentException('WooCommerce stock-cycle capture requires an order.');
        }

        $orderId = (int) $order->get_id();
        if ($orderId < 1 || $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') {
            throw new \InvalidArgumentException('WooCommerce order is not eligible for online stock-cycle capture.');
        }

        $activeCycleUuid = (string) $order->get_meta('_pkstore_wc_active_cycle_uuid', true);
        if ($activeCycleUuid !== ''
            && $order->get_meta('_pkstore_wc_cycle_return_capture_failed_' . $activeCycleUuid, true)) {
            throw new \RuntimeException('A previous stock-cycle return capture needs operator review before another sale cycle can be captured.');
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for stock-cycle capture.');
        }

        $lockName = 'pkstore_wc_cycle_' . $orderId;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 10));
        if ((int) $acquired !== 1) {
            throw new \RuntimeException('Unable to acquire WooCommerce stock-cycle lock.');
        }

        try {
            return $this->captureLocked($order, $orderId);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public function markReturnPending(
        \WC_Order $order,
        int $itemId,
        string $kind,
        int $quantity,
        ?int $refundCumulative = null,
        ?string $cycleUuidOverride = null,
        ?int $wcProductId = null
    ): void {
        if (!in_array($kind, ['cancellation', 'refund'], true) || $itemId < 1 || $quantity < 1) {
            throw new \InvalidArgumentException('WooCommerce cycle return capture is invalid.');
        }
        $orderId = (int) $order->get_id();
        $cycleUuid = $cycleUuidOverride ?? (string) $order->get_meta('_pkstore_wc_active_cycle_uuid', true);
        if ($orderId < 1 || !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid)) {
            throw new \RuntimeException('WooCommerce order has no valid stock-cycle identity for return capture.');
        }
        if ($wcProductId !== null && $wcProductId < 1) throw new \InvalidArgumentException('WooCommerce cycle return product ID is invalid.');
        if ($kind === 'refund' && ($refundCumulative === null || $refundCumulative < $quantity)) {
            throw new \InvalidArgumentException('WooCommerce refund cumulative quantity is invalid.');
        }
        $failedMetaKey = '_pkstore_wc_cycle_return_capture_failed_' . $cycleUuid;

        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for cycle return capture.');
        $lockName = 'pkstore_wc_cycle_' . $orderId;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 10));
        if ((int) $acquired !== 1) throw new \RuntimeException('Unable to acquire WooCommerce stock-cycle return lock.');

        try {
            $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
            $itemsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
            $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('Unable to start WooCommerce cycle return capture transaction.');
            }
            try {
                $cycle = $wpdb->get_row($wpdb->prepare(
                    "SELECT state FROM {$cyclesTable} WHERE cycle_uuid=%s AND wc_order_id=%d FOR UPDATE",
                    $cycleUuid,
                    $orderId
                ), ARRAY_A);
                if ($wpdb->last_error !== '' || !is_array($cycle)) {
                    throw new \RuntimeException('WooCommerce stock-cycle header is unavailable for return capture.');
                }

                $cycleItem = $wpdb->get_row($wpdb->prepare(
                    "SELECT reduced_quantity,refund_cumulative_baseline,cancellation_restored_quantity,refund_restored_quantity
                     FROM {$itemsTable} WHERE cycle_uuid=%s AND wc_order_item_id=%d FOR UPDATE",
                    $cycleUuid,
                    $itemId
                ), ARRAY_A);
                if ($wpdb->last_error !== '' || !is_array($cycleItem)) {
                    throw new \RuntimeException('WooCommerce stock-cycle item is unavailable for return capture.');
                }

                $reducedQuantity = (int) $cycleItem['reduced_quantity'];
                $refundBaseline = (int) $cycleItem['refund_cumulative_baseline'];
                $alreadyReturned = (int) $cycleItem['cancellation_restored_quantity'] + (int) $cycleItem['refund_restored_quantity'];
                if ($kind === 'cancellation') {
                    $eventCumulative = $reducedQuantity;
                    $eventKey = 'wc_stock_cycle_cancellation:' . $cycleUuid . ':' . $itemId . ':' . $eventCumulative;
                    $sourceHook = 'woocommerce_restore_order_item_stock';
                } else {
                    $eventCumulative = (int) $refundCumulative - $refundBaseline;
                    if ($eventCumulative < 1 || $eventCumulative !== (int) $cycleItem['refund_restored_quantity'] + $quantity
                        || $alreadyReturned + $quantity > $reducedQuantity) {
                        throw new \RuntimeException('WooCommerce refund restock does not match the active stock cycle.');
                    }
                    $eventKey = 'wc_stock_cycle_refund:' . $cycleUuid . ':' . $itemId . ':' . $eventCumulative;
                    $sourceHook = 'woocommerce_restock_refunded_item';
                }

                $existingEvent = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,status FROM {$eventsTable} WHERE event_key=%s FOR UPDATE",
                    $eventKey
                ), ARRAY_A);
                if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to inspect WooCommerce cycle return event.');

                if (is_array($existingEvent) && $existingEvent['status'] === 'processed') {
                    if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to acknowledge existing cycle return event.');
                    $order->delete_meta_data($failedMetaKey);
                    if ((int) $order->save() !== $orderId) throw new \RuntimeException('Unable to clear recovered cycle return capture marker.');
                    return;
                }
                if (!in_array($cycle['state'], ['reducing', 'active', 'restoring'], true)) {
                    throw new \RuntimeException('WooCommerce stock cycle is not eligible for return capture.');
                }

                if (!is_array($existingEvent)) {
                    $openCycleCount = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$cyclesTable} WHERE wc_order_id=%d AND state<>'closed'",
                        $orderId
                    ));
                    if ($wpdb->last_error !== '' || !is_numeric($openCycleCount) || ($kind === 'refund' && (int) $openCycleCount > 1)) {
                        throw new \RuntimeException('WooCommerce return event is ambiguous because multiple stock cycles are still open.');
                    }

                    if ($kind === 'refund') {
                        // WooCommerce's cumulative refund-restock metadata does
                        // not identify which reduction cycle a late refund belongs
                        // to after a prior cancellation restored this same line.
                        // Fail closed rather than crediting the current cycle.
                        $priorCancelledCycles = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*)
                             FROM {$cyclesTable} c
                             INNER JOIN {$itemsTable} i ON i.cycle_uuid=c.cycle_uuid
                             WHERE c.wc_order_id=%d
                               AND c.cycle_number < (SELECT cycle_number FROM {$cyclesTable} WHERE cycle_uuid=%s)
                               AND i.wc_order_item_id=%d
                               AND i.cancellation_restored_quantity > 0",
                            $orderId,
                            $cycleUuid,
                            $itemId
                        ));
                        if ($wpdb->last_error !== '' || !is_numeric($priorCancelledCycles) || (int) $priorCancelledCycles > 0) {
                            throw new \RuntimeException('WooCommerce refund cannot be assigned safely after a prior cancellation stock cycle.');
                        }
                    }

                    $now = current_time('mysql');
                    $inserted = $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$eventsTable}
                         (event_uuid,event_key,cycle_uuid,wc_order_id,wc_order_item_id,event_kind,quantity,cumulative_quantity,source_hook,source_metadata,status,attempts,next_attempt_at,claimed_at,claim_token,last_error,created_at,updated_at)
                         VALUES (%s,%s,%s,%d,%d,%s,%d,%d,%s,%s,'pending',0,%s,NULL,NULL,'',%s,%s)",
                        wp_generate_uuid4(),
                        $eventKey,
                        $cycleUuid,
                        $orderId,
                        $itemId,
                        $kind,
                        $quantity,
                        $eventCumulative,
                        $sourceHook,
                        wp_json_encode(['captured_at_hook' => true, 'wc_product_id' => $wcProductId]),
                        $now,
                        $now,
                        $now
                    ));
                    if ($inserted === false) throw new \RuntimeException('Unable to persist WooCommerce cycle return event.');
                } elseif (!in_array($existingEvent['status'], ['pending', 'processing'], true)) {
                    throw new \RuntimeException('WooCommerce cycle return event is not eligible for capture retry.');
                }

                $now = current_time('mysql');
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$cyclesTable} SET state='restoring', updated_at=%s
                     WHERE cycle_uuid=%s AND state IN ('reducing','active','restoring')",
                    $now,
                    $cycleUuid
                ));
                if ($updated === false) throw new \RuntimeException('Unable to mark WooCommerce stock cycle as restoring.');

                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Unable to commit WooCommerce cycle return capture.');
                }
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }

        $order->delete_meta_data($failedMetaKey);
        if ((int) $order->save() !== $orderId) throw new \RuntimeException('Unable to clear recovered cycle return capture marker.');
    }

    private function captureLocked(\WC_Order $order, int $orderId): string {
        global $wpdb;
        $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
        $itemsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
        $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';

        $activeLocations = $this->locationRepo->findAll();
        if (!$activeLocations) {
            throw new \RuntimeException('No active Junction inventory locations are available for stock-cycle capture.');
        }
        usort($activeLocations, static fn($a, $b): int => $a->id <=> $b->id);
        // This is a temporary schema locator only. The cycle-aware sale worker
        // replaces it with the first actual allocation and stores every location
        // in allocation_snapshot before the cycle is activated.
        $snapshotLocationId = (int) $activeLocations[0]->id;

        $snapshot = [];
        foreach ($order->get_items('line_item') as $item) {
            $itemId = (int) $item->get_id();
            $parentId = (int) $item->get_product_id();
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $reduced = $item->get_meta('_reduced_stock', true);
            if ($reduced === '' || $reduced === null) {
                $reduced = $item->get_meta('_pkstore_wc_reduced_stock_snapshot', true);
            }
            if ($reduced === '' || $reduced === null) {
                continue;
            }
            if (!is_numeric($reduced)) {
                throw new \RuntimeException('WooCommerce stock-cycle line has malformed reduced-stock metadata.');
            }
            if ((float) $reduced === 0.0) {
                continue;
            }
            $orderedQuantity = $item->get_quantity();
            if ($itemId < 1 || $parentId < 1
                || (float) $reduced < 0 || floor((float) $reduced) !== (float) $reduced
                || !is_numeric($orderedQuantity) || floor((float) $orderedQuantity) !== (float) $orderedQuantity
                || (int) $orderedQuantity !== (int) $reduced) {
                throw new \RuntimeException('WooCommerce stock-cycle line snapshot is invalid or the order item quantity changed.');
            }

            $inventoryProduct = $variationId > 0
                ? $this->productRepo->findByWcProductId($variationId)
                : null;
            $inventoryProduct ??= $this->productRepo->findByWcProductId($parentId);
            if (!$inventoryProduct || !$inventoryProduct->isActive) {
                throw new \RuntimeException('Reduced WooCommerce product has no active Junction inventory mapping.');
            }

            $refundBaselineRaw = $item->get_meta('_restock_refunded_items', true);
            $refundBaseline = $refundBaselineRaw === '' || $refundBaselineRaw === null ? 0 : $refundBaselineRaw;
            if (!is_numeric($refundBaseline) || (int) $refundBaseline < 0 || floor((float) $refundBaseline) !== (float) $refundBaseline) {
                throw new \RuntimeException('WooCommerce stock-cycle refund baseline is invalid.');
            }
            $snapshot[] = [
                'item_id' => $itemId,
                'parent_id' => $parentId,
                'variation_id' => $variationId,
                'inventory_product_id' => (int) $inventoryProduct->id,
                'inventory_location_id' => $snapshotLocationId,
                'quantity' => (int) $reduced,
                'refund_cumulative_baseline' => (int) $refundBaseline,
            ];
        }

        if (!$snapshot) {
            throw new \RuntimeException('WooCommerce order has no actual stock-reduced line items to capture.');
        }
        usort($snapshot, static fn(array $a, array $b): int => $a['item_id'] <=> $b['item_id']);

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start WooCommerce stock-cycle transaction.');
        }

        try {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_uuid, cycle_number, state FROM {$cyclesTable}
                 WHERE wc_order_id=%d AND state IN ('reducing','active','restoring')
                 ORDER BY cycle_number DESC LIMIT 1 FOR UPDATE",
                $orderId
            ), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Unable to inspect existing WooCommerce stock cycles.');
            }

            // Never start a later reduction while any prior cycle is still
            // restoring. Even a cancellation event may be waiting for its
            // matching sale movement or return worker; allocating the next sale
            // against that stale ledger could choose the wrong physical location.
            $openRestoringCycle = $wpdb->get_var($wpdb->prepare(
                "SELECT cycle_uuid FROM {$cyclesTable}
                 WHERE wc_order_id=%d AND state='restoring'
                 ORDER BY cycle_number ASC LIMIT 1",
                $orderId
            ));
            if ($wpdb->last_error !== '' || $openRestoringCycle) {
                throw new \RuntimeException('A previous WooCommerce stock cycle is still restoring and must finish before another sale cycle can be captured.');
            }

            if (is_array($existing)) {
                $pendingReturn = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$eventsTable} WHERE cycle_uuid=%s AND event_kind IN ('cancellation','refund') AND status<>'processed' LIMIT 1",
                    (string) $existing['cycle_uuid']
                ));
                if ($wpdb->last_error !== '' || $pendingReturn) {
                    throw new \RuntimeException('Previous WooCommerce stock cycle has an unprocessed return event.');
                }
                $returnQueueTable = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
                $pendingQueueReturn = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$returnQueueTable} WHERE cycle_uuid=%s LIMIT 1",
                    (string) $existing['cycle_uuid']
                ));
                if ($wpdb->last_error !== '' || $pendingQueueReturn) {
                    throw new \RuntimeException('Previous WooCommerce stock cycle still has a queued return event.');
                }
                $existingItems = $wpdb->get_results($wpdb->prepare(
                    "SELECT wc_order_item_id, wc_parent_product_id, wc_variation_id,
                            inventory_product_id, inventory_location_id, reduced_quantity, refund_cumulative_baseline
                     FROM {$itemsTable} WHERE cycle_uuid=%s ORDER BY wc_order_item_id ASC",
                    (string) $existing['cycle_uuid']
                ), ARRAY_A);
                if ($wpdb->last_error !== '') {
                    throw new \RuntimeException('Unable to inspect existing WooCommerce stock-cycle lines.');
                }
                $existingSnapshot = [];
                foreach (($existingItems ?: []) as $row) {
                    $existingSnapshot[] = [
                        'item_id' => (int) $row['wc_order_item_id'],
                        'parent_id' => (int) $row['wc_parent_product_id'],
                        'variation_id' => (int) $row['wc_variation_id'],
                        'inventory_product_id' => (int) $row['inventory_product_id'],
                        'inventory_location_id' => (int) $row['inventory_location_id'],
                        'quantity' => (int) $row['reduced_quantity'],
                        'refund_cumulative_baseline' => (int) $row['refund_cumulative_baseline'],
                    ];
                }
                if ($existingSnapshot !== $snapshot) {
                    throw new \RuntimeException('An open WooCommerce stock cycle conflicts with the current reduction snapshot.');
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Unable to commit duplicate WooCommerce stock-cycle lookup.');
                }
                return (string) $existing['cycle_uuid'];
            }

            $nextNumberValue = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM {$cyclesTable} WHERE wc_order_id=%d",
                $orderId
            ));
            if ($wpdb->last_error !== '' || !is_numeric($nextNumberValue)) {
                throw new \RuntimeException('Unable to inspect WooCommerce stock-cycle sequence.');
            }
            $nextNumber = (int) $nextNumberValue;
            if ($nextNumber < 1) {
                throw new \RuntimeException('Unable to allocate WooCommerce stock-cycle number.');
            }

            $cycleUuid = wp_generate_uuid4();
            $eventKey = 'wc_stock_cycle_reduce:' . $orderId . ':' . $nextNumber;
            $now = current_time('mysql');

            $inserted = $wpdb->insert($cyclesTable, [
                'wc_order_id' => $orderId,
                'cycle_uuid' => $cycleUuid,
                'cycle_number' => $nextNumber,
                'state' => 'reducing',
                'reduction_event_key' => $eventKey,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s']);
            if ($inserted !== 1) {
                throw new \RuntimeException('Unable to persist WooCommerce stock-cycle header.');
            }

            foreach ($snapshot as $line) {
                $inserted = $wpdb->insert($itemsTable, [
                    'cycle_uuid' => $cycleUuid,
                    'wc_order_id' => $orderId,
                    'wc_order_item_id' => $line['item_id'],
                    'wc_parent_product_id' => $line['parent_id'],
                    'wc_variation_id' => $line['variation_id'],
                    'inventory_product_id' => $line['inventory_product_id'],
                    'inventory_location_id' => $line['inventory_location_id'],
                    'reduced_quantity' => $line['quantity'],
                    'refund_cumulative_baseline' => $line['refund_cumulative_baseline'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']);
                if ($inserted !== 1) {
                    throw new \RuntimeException('Unable to persist WooCommerce stock-cycle line snapshot.');
                }
            }

            $eventInserted = $wpdb->insert($eventsTable, [
                'event_uuid' => wp_generate_uuid4(),
                'event_key' => $eventKey,
                'cycle_uuid' => $cycleUuid,
                'wc_order_id' => $orderId,
                'wc_order_item_id' => null,
                'event_kind' => 'reduction',
                'quantity' => array_sum(array_column($snapshot, 'quantity')),
                'cumulative_quantity' => 0,
                'source_hook' => 'woocommerce_reduce_order_stock',
                'source_metadata' => wp_json_encode(['line_count' => count($snapshot)]),
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($eventInserted !== 1) {
                throw new \RuntimeException('Unable to persist WooCommerce stock-cycle reduction event.');
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit WooCommerce stock-cycle snapshot.');
            }
            return $cycleUuid;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
