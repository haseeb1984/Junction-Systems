<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;

use Psr\Log\LoggerInterface;

/** Durable retry queue for WooCommerce cancellation and explicit refund restocks. */
final class ProcessWooCommerceReturnInventoryQueueUseCase {
    private const MAX_AUTOMATIC_ATTEMPTS = 12;

    public function __construct(
        private readonly SyncWooCommerceStockUseCase $syncInventory,
        private readonly LoggerInterface $log,
    ) {}

    public function enqueueCancellation(int $orderId, int $itemId, int $productId, int $quantity, ?string $cycleUuid = null): void {
        if ($orderId < 1 || $itemId < 1 || $productId < 1 || $quantity < 1
            || ($cycleUuid !== null && !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid))) {
            throw new \InvalidArgumentException('Invalid WooCommerce cancellation restock event.');
        }
        $this->enqueue('cancel:' . ($cycleUuid ?? 'legacy') . ':' . $orderId . ':' . $itemId . ':' . $quantity, 'cancellation', $orderId, $itemId, $productId, $quantity, 0, $cycleUuid);
    }

    public function enqueueRefund(int $orderId, int $productId, int $quantity, int $cumulativeQuantity, ?int $itemId = null, ?string $cycleUuid = null): void {
        if ($orderId < 1 || $productId < 1 || $quantity < 1 || $cumulativeQuantity < $quantity
            || ($cycleUuid !== null && !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid))) {
            throw new \InvalidArgumentException('Invalid WooCommerce refund restock event.');
        }
        $this->enqueue('refund:' . ($cycleUuid ?? 'legacy') . ':' . $orderId . ':' . $productId . ':' . ($itemId ?? 0) . ':' . $cumulativeQuantity, 'refund', $orderId, $itemId, $productId, $quantity, $cumulativeQuantity, $cycleUuid);
    }

    private function enqueue(string $key, string $kind, int $orderId, ?int $itemId, int $productId, int $quantity, int $cumulative, ?string $cycleUuid): void {
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
        if ($result === false) throw new \RuntimeException('Unable to persist WooCommerce return inventory event.');
    }

    public function execute(int $limit = 20): array {
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
                if ($kind === 'cancellation') {
                    $item = $order->get_item((int) $job['wc_order_item_id']);
                    if (!$item) throw new \RuntimeException('WooCommerce order item is unavailable.');
                    $applied = $this->syncInventory->onWooCommerceOrderItemRestored($item, $quantity, 0, $order, $job['cycle_uuid'] !== null ? (string) $job['cycle_uuid'] : null);
                    if (!$applied) {
                        throw new \RuntimeException('Cancellation return is waiting for its matching sale movement.');
                    }
                } elseif ($kind === 'refund') {
                    $product = wc_get_product($productId);
                    if (!$product) throw new \RuntimeException('WooCommerce refund product is unavailable.');
                    $applied = $this->syncInventory->onWooCommerceRefundItemRestocked(
                        $productId, $quantity, 0, $order, $product, $job['wc_order_item_id'] !== null ? (int) $job['wc_order_item_id'] : null, (int) $job['cumulative_quantity'], $job['cycle_uuid'] !== null ? (string) $job['cycle_uuid'] : null
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
        $deleted = $wpdb->delete($table, ['id' => $id, 'status' => 'processing', 'claim_token' => $claimToken], ['%d', '%s', '%s']);
        if ($deleted === false) throw new \RuntimeException('Unable to acknowledge WooCommerce return event.');
        // A stale worker must never acknowledge a job reclaimed by another worker.
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
