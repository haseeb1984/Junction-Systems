<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;

use Psr\Log\LoggerInterface;

/** Durable retry queue for WooCommerce cancellation and explicit refund restocks. */
final class ProcessWooCommerceReturnInventoryQueueUseCase {
    private const MAX_AUTOMATIC_ATTEMPTS = 12;

    public function __construct(
        private readonly SyncWooCommerceStockUseCase $syncInventory,
        private readonly CaptureWooCommerceStockCycleUseCase $cycleCapture,
        private readonly LoggerInterface $log,
    ) {}

    public function enqueueCancellation(int $orderId, int $itemId, int $productId, int $quantity, ?string $cycleUuid = null): string {
        if ($orderId < 1 || $itemId < 1 || $productId < 1 || $quantity < 1
            || ($cycleUuid !== null && !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid))) {
            throw new \InvalidArgumentException('Invalid WooCommerce cancellation restock event.');
        }
        return $this->enqueue('cancel:' . ($cycleUuid ?? 'legacy') . ':' . $orderId . ':' . $itemId . ':' . $quantity, 'cancellation', $orderId, $itemId, $productId, $quantity, 0, $cycleUuid);
    }

    public function enqueueRefund(int $orderId, int $productId, int $quantity, int $cumulativeQuantity, ?int $itemId = null, ?string $cycleUuid = null): string {
        if ($orderId < 1 || $productId < 1 || $quantity < 1 || $cumulativeQuantity < $quantity
            || ($cycleUuid !== null && !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid))) {
            throw new \InvalidArgumentException('Invalid WooCommerce refund restock event.');
        }
        return $this->enqueue('refund:' . ($cycleUuid ?? 'legacy') . ':' . $orderId . ':' . $productId . ':' . ($itemId ?? 0) . ':' . $cumulativeQuantity, 'refund', $orderId, $itemId, $productId, $quantity, $cumulativeQuantity, $cycleUuid);
    }

    private function enqueue(string $key, string $kind, int $orderId, ?int $itemId, int $productId, int $quantity, int $cumulative, ?string $cycleUuid): string {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for WooCommerce return queue.');
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (event_key,event_kind,wc_order_id,wc_order_item_id,wc_product_id,cycle_uuid,quantity,cumulative_quantity,status,attempts,next_attempt_at,claimed_at,last_error,created_at,updated_at)
             VALUES (%s,%s,%d,NULLIF(%d,0),%d,NULLIF(%s,''),%d,%d,'pending',0,%s,NULL,'',%s,%s)",
            $key, $kind, $orderId, $itemId ?? 0, $productId, $cycleUuid ?? '', $quantity, $cumulative, $now, $now, $now
        ));
        if ($result === false) throw new \\RuntimeException('Unable to persist WooCommerce return inventory event.');

        // INSERT IGNORE is idempotent only when a duplicate key carries the
        // same immutable event payload. Verify it instead of trusting the key.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT event_kind,wc_order_id,wc_order_item_id,wc_product_id,cycle_uuid,quantity,cumulative_quantity
             FROM {$table} WHERE event_key=%s",
            $key
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($existing)) {
            throw new \\RuntimeException('Unable to verify persisted WooCommerce return inventory event.');
        }
        $storedItemId = $existing['wc_order_item_id'] !== null ? (int) $existing['wc_order_item_id'] : null;
        $storedCycleUuid = $existing['cycle_uuid'] !== null ? (string) $existing['cycle_uuid'] : null;
        if ((string) $existing['event_kind'] !== $kind
            || (int) $existing['wc_order_id'] !== $orderId
            || $storedItemId !== $itemId
            || (int) $existing['wc_product_id'] !== $productId
            || $storedCycleUuid !== $cycleUuid
            || (int) $existing['quantity'] !== $quantity
            || (int) $existing['cumulative_quantity'] !== $cumulative) {
            throw new \\RuntimeException('WooCommerce return event key conflicts with a different persisted payload.');
        }
        return $key;
    }

    private function clearReturnFailureMarkerIfReconciled(int $orderId): void {
        if ($orderId < 1) throw new \InvalidArgumentException('A valid WooCommerce order is required for return marker recovery.');
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) throw new \RuntimeException('WooCommerce order is unavailable while recovering its return marker.');

        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable while recovering return marker.');
        $queueTable = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
        $pendingQueue = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queueTable} WHERE wc_order_id=%d AND status IN ('pending','processing','needs_review')",
            $orderId
        ));
        if ($wpdb->last_error !== '' || !is_numeric($pendingQueue)) throw new \RuntimeException('Unable to verify outstanding return queue work.');
        $pendingEvents = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE wc_order_id=%d AND event_kind IN ('cancellation','refund') AND status IN ('pending','processing','needs_review','failed','review')",
            $orderId
        ));
        if ($wpdb->last_error !== '' || !is_numeric($pendingEvents)) throw new \RuntimeException('Unable to verify outstanding return cycle events.');
        if ((int) $pendingQueue > 0 || (int) $pendingEvents > 0) return;

        foreach ($order->get_meta_data() as $meta) {
            $key = is_object($meta) && isset($meta->key) ? (string) $meta->key : '';
            if (strpos($key, '_pkstore_wc_cycle_return_capture_failed_') === 0) return;
        }
        if ($order->get_meta('_pkstore_wc_return_capture_failed', true) === 'yes') {
            $order->delete_meta_data('_pkstore_wc_return_capture_failed');
            if ((int) $order->save() !== $orderId) throw new \RuntimeException('Unable to clear reconciled WooCommerce return safety marker.');
        }
    }

    public function acknowledgeCompleted(string $eventKey): void {
        if ($eventKey === '' || strlen($eventKey) > 191) {
            throw new \InvalidArgumentException('A valid WooCommerce return event key is required.');
        }
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for WooCommerce return queue.');
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $orderId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wc_order_id FROM {$table} WHERE event_key=%s AND status IN ('pending','needs_review') AND claimed_at IS NULL",
            $eventKey
        ));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to resolve synchronously completed WooCommerce return order.');
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE event_key=%s AND status IN ('pending','needs_review') AND claimed_at IS NULL",
            $eventKey
        ));
        if ($deleted === false) throw new \RuntimeException('Unable to acknowledge synchronously completed WooCommerce return event.');
        if ($deleted === 1 && $orderId > 0) $this->clearReturnFailureMarkerIfReconciled($orderId);
    }

    private function recoverUnqueuedCycleEvents(int $limit): void {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for cycle event recovery.');

        $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
        $itemsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
        $queueTable = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.cycle_uuid,e.wc_order_id,e.wc_order_item_id,e.event_kind,e.quantity,
                    e.cumulative_quantity,i.refund_cumulative_baseline,
                    COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(e.source_metadata) THEN e.source_metadata ELSE '{}' END,'$.wc_product_id')) AS UNSIGNED),0),NULLIF(i.wc_variation_id,0),i.wc_parent_product_id) AS wc_product_id
             FROM {$eventsTable} e
             INNER JOIN {$itemsTable} i
                ON i.cycle_uuid=e.cycle_uuid AND i.wc_order_item_id=e.wc_order_item_id
             LEFT JOIN {$queueTable} q
                ON q.cycle_uuid=e.cycle_uuid
               AND q.wc_order_id=e.wc_order_id
               AND q.wc_order_item_id=e.wc_order_item_id
               AND q.event_kind=e.event_kind
               AND q.quantity=e.quantity
               AND (
                    (e.event_kind='cancellation' AND q.cumulative_quantity=0)
                    OR (e.event_kind='refund' AND q.cumulative_quantity=e.cumulative_quantity+i.refund_cumulative_baseline)
               )
             WHERE e.event_kind IN ('cancellation','refund')
               AND e.status='pending' AND e.next_attempt_at<=%s AND q.id IS NULL
             ORDER BY e.created_at ASC LIMIT %d",
            current_time('mysql'),
            max(1, min(100, $limit))
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to inspect pending WooCommerce stock-cycle events.');

        foreach ($rows as $row) {
            $orderId = (int) $row['wc_order_id'];
            $itemId = (int) $row['wc_order_item_id'];
            $productId = (int) $row['wc_product_id'];
            $quantity = (int) $row['quantity'];
            $cycleUuid = (string) $row['cycle_uuid'];
            if ($orderId < 1 || $itemId < 1 || $productId < 1 || $quantity < 1) {
                throw new \RuntimeException('Pending WooCommerce cycle event cannot be reconstructed safely.');
            }
            if ($row['event_kind'] === 'cancellation') {
                $this->enqueueCancellation($orderId, $itemId, $productId, $quantity, $cycleUuid);
            } else {
                $cumulative = (int) $row['cumulative_quantity'] + (int) $row['refund_cumulative_baseline'];
                $this->enqueueRefund($orderId, $productId, $quantity, $cumulative, $itemId, $cycleUuid);
            }
        }
    }

    public function execute(int $limit = 20): array {
        try {
            $this->recoverUnqueuedCycleEvents($limit);
        } catch (\Throwable $recoveryError) {
            $this->log->critical('WooCommerce cycle return queue recovery failed; pending cycle events remain durable.', [
                'error_class' => get_class($recoveryError),
            ]);
        }
        $jobs = $this->claimBatch($limit);
        $result = ['processed' => 0, 'failed' => 0];
        foreach ($jobs as $job) {
            $id = (int) $job['id'];
            $attempt = (int) $job['attempts'];
            try {
                $order = wc_get_order((int) $job['wc_order_id']);
                if (!($order instanceof \WC_Order)) throw new \RuntimeException('WooCommerce order is unavailable.');
                $kind = (string) $job['event_kind'];
                $productId = (int) $job['wc_product_id'];
                $quantity = (int) $job['quantity'];
                $cycleUuid = $job['cycle_uuid'] !== null ? (string) $job['cycle_uuid'] : null;
                if ($cycleUuid === null
                    && (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true) === '1') {
                    $cycleUuid = $this->cycleCapture->resolveCycleUuidForReturn($order);
                    $order->update_meta_data('_pkstore_wc_active_cycle_uuid', $cycleUuid);
                    if ((int) $order->save() !== (int) $order->get_id()) {
                        throw new \RuntimeException('Unable to persist recovered WooCommerce return-cycle identity.');
                    }
                }
                if ($cycleUuid !== null) {
                    $itemId = $job['wc_order_item_id'] !== null ? (int) $job['wc_order_item_id'] : 0;
                    if ($itemId < 1) throw new \RuntimeException('Cycle-bound return event has no WooCommerce order item.');
                    $this->cycleCapture->markReturnPending(
                        $order,
                        $itemId,
                        $kind,
                        $quantity,
                        $kind === 'refund' ? (int) $job['cumulative_quantity'] : null,
                        $cycleUuid,
                        $productId
                    );
                }
                if ($kind === 'cancellation') {
                    $item = $order->get_item((int) $job['wc_order_item_id']);
                    if (!$item) throw new \RuntimeException('WooCommerce order item is unavailable.');
                    $applied = $this->syncInventory->onWooCommerceOrderItemRestored($item, $quantity, 0, $order, $cycleUuid);
                    if (!$applied) {
                        throw new \RuntimeException('Cancellation return is waiting for its matching sale movement.');
                    }
                } elseif ($kind === 'refund') {
                    $product = wc_get_product($productId);
                    if (!$product) throw new \RuntimeException('WooCommerce refund product is unavailable.');
                    $applied = $this->syncInventory->onWooCommerceRefundItemRestocked(
                        $productId, $quantity, 0, $order, $product, $job['wc_order_item_id'] !== null ? (int) $job['wc_order_item_id'] : null, (int) $job['cumulative_quantity'], $cycleUuid
                    );
                    if (!$applied) {
                        // A refunded payment does not prove WooCommerce restored stock.
                        // Keep the return pending until its sale movement exists.
                        throw new \RuntimeException('Refund return is waiting for its matching sale movement.');
                    }
                } else {
                    throw new \RuntimeException('Unknown WooCommerce return event kind.');
                }
                $this->complete($id, (string) ($job['claim_token'] ?? ''));
                $result['processed']++;
            } catch (\Throwable $error) {
                $result['failed']++;
                try { $this->retry($id, (string) ($job['claim_token'] ?? ''), $attempt, get_class($error)); }
                catch (\Throwable $queueError) {
                    $this->log->critical('WooCommerce return retry could not be persisted.', ['event_id' => $id, 'error_class' => get_class($queueError)]);
                }
                $this->log->error($attempt >= self::MAX_AUTOMATIC_ATTEMPTS
                    ? 'WooCommerce return event moved to needs_review after exhausting automatic retries.'
                    : 'WooCommerce return event remains queued for retry.', ['event_id' => $id, 'attempt' => $attempt, 'error_class' => get_class($error)]);
            }
        }
        return $result;
    }

    private function claimBatch(int $limit): array {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for return queue.');
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $limit = max(1, min(100, $limit));
        $recovered = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='pending',claimed_at=NULL,claim_token=NULL WHERE status='processing' AND claimed_at < DATE_SUB(%s, INTERVAL 10 MINUTE)",
            current_time('mysql')
        ));
        if ($recovered === false) throw new \RuntimeException('Unable to recover stale WooCommerce return claims.');
        if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Unable to start return queue claim.');
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,event_kind,wc_order_id,wc_order_item_id,wc_product_id,cycle_uuid,quantity,cumulative_quantity,attempts
                 FROM {$table} WHERE status='pending' AND next_attempt_at<=%s ORDER BY next_attempt_at,id LIMIT %d FOR UPDATE",
                current_time('mysql'), $limit
            ), ARRAY_A);
            if ($rows === null && $wpdb->last_error !== '') throw new \RuntimeException('Unable to read WooCommerce return queue.');
            $rows = $rows ?: [];
            foreach ($rows as &$row) {
                $claimToken = wp_generate_uuid4();
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status='processing',claimed_at=%s,claim_token=%s,attempts=attempts+1 WHERE id=%d AND status='pending'",
                    current_time('mysql'), $claimToken, (int) $row['id']
                ));
                if ($updated !== 1) throw new \RuntimeException('Unable to claim WooCommerce return event.');
                $row['attempts'] = (int) $row['attempts'] + 1;
                $row['claim_token'] = $claimToken;
            }
            unset($row);
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to commit return queue claim.');
            return $rows;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    private function complete(int $id, string $claimToken): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $orderId = (int) $wpdb->get_var($wpdb->prepare("SELECT wc_order_id FROM {$table} WHERE id=%d AND status='processing' AND claim_token=%s", $id, $claimToken));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to resolve WooCommerce return order before acknowledgement.');
        $deleted = $wpdb->delete($table, ['id' => $id, 'status' => 'processing', 'claim_token' => $claimToken], ['%d', '%s', '%s']);
        if ($deleted === false) throw new \RuntimeException('Unable to acknowledge WooCommerce return event.');
        // A stale worker must never acknowledge a job reclaimed by another worker.
        if ($deleted === 1 && $orderId > 0) $this->clearReturnFailureMarkerIfReconciled($orderId);
    }

    private function retry(int $id, string $claimToken, int $attempt, string $errorClass): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $needsReview = $attempt >= self::MAX_AUTOMATIC_ATTEMPTS;
        $delay = min(21600, 30 * (2 ** min(10, max(0, $attempt - 1))));
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status=%s,claimed_at=NULL,
             next_attempt_at=IF(%s='needs_review',%s,DATE_ADD(%s,INTERVAL %d SECOND)),last_error=%s
             WHERE id=%d AND status='processing' AND claim_token=%s",
            $needsReview ? 'needs_review' : 'pending', $needsReview ? 'needs_review' : 'pending',
            $now, $now, $delay, substr(sanitize_key($errorClass), 0, 100), $id, $claimToken
        ));
        if ($updated === false) throw new \RuntimeException('Unable to reschedule WooCommerce return event.');
        // A zero-row update means this worker lost its lease; leave the newer claim untouched.
    }
}
