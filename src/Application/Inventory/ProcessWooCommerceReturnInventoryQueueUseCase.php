<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;

use Psr\Log\LoggerInterface;

/** Durable retry queue for WooCommerce cancellation and explicit refund restocks. */
final class ProcessWooCommerceReturnInventoryQueueUseCase {
    public function __construct(
        private readonly SyncWooCommerceStockUseCase $syncInventory,
        private readonly LoggerInterface $log,
    ) {}

    public function enqueueCancellation(int $orderId, int $itemId, int $productId, int $quantity): void {
        if ($orderId < 1 || $itemId < 1 || $productId < 1 || $quantity < 1) {
            throw new \InvalidArgumentException('Invalid WooCommerce cancellation restock event.');
        }
        $this->enqueue('cancel:' . $orderId . ':' . $itemId . ':' . $quantity, 'cancellation', $orderId, $itemId, $productId, $quantity, 0);
    }

    public function enqueueRefund(int $orderId, int $productId, int $quantity, int $cumulativeQuantity, ?int $itemId = null): void {
        if ($orderId < 1 || $productId < 1 || $quantity < 1 || $cumulativeQuantity < $quantity) {
            throw new \InvalidArgumentException('Invalid WooCommerce refund restock event.');
        }
        $this->enqueue('refund:' . $orderId . ':' . $productId . ':' . ($itemId ?? 0) . ':' . $cumulativeQuantity, 'refund', $orderId, $itemId, $productId, $quantity, $cumulativeQuantity);
    }

    private function enqueue(string $key, string $kind, int $orderId, ?int $itemId, int $productId, int $quantity, int $cumulative): void {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for WooCommerce return queue.');
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (event_key,event_kind,wc_order_id,wc_order_item_id,wc_product_id,quantity,cumulative_quantity,status,attempts,next_attempt_at,claimed_at,last_error,created_at,updated_at)
             VALUES (%s,%s,%d,NULLIF(%d,0),%d,%d,%d,'pending',0,%s,NULL,'',%s,%s)",
            $key, $kind, $orderId, $itemId ?? 0, $productId, $quantity, $cumulative, $now, $now, $now
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
                    $applied = $this->syncInventory->onWooCommerceOrderItemRestored($item, $quantity, 0, $order);
                    if (!$applied && !in_array((string) $order->get_status(), ['cancelled', 'failed', 'pending'], true)) {
                        throw new \RuntimeException('Cancellation return is waiting for its matching sale movement.');
                    }
                } elseif ($kind === 'refund') {
                    $product = wc_get_product($productId);
                    if (!$product) throw new \RuntimeException('WooCommerce refund product is unavailable.');
                    $applied = $this->syncInventory->onWooCommerceRefundItemRestocked(
                        $productId, $quantity, 0, $order, $product, $job['wc_order_item_id'] !== null ? (int) $job['wc_order_item_id'] : null, (int) $job['cumulative_quantity']
                    );
                    if (!$applied && (string) $order->get_status() !== 'refunded') {
                        throw new \RuntimeException('Refund return is waiting for its matching sale movement.');
                    }
                } else {
                    throw new \RuntimeException('Unknown WooCommerce return event kind.');
                }
                $this->complete($id);
                $result['processed']++;
            } catch (\Throwable $error) {
                $result['failed']++;
                try { $this->retry($id, $attempt, get_class($error)); }
                catch (\Throwable $queueError) {
                    $this->log->critical('WooCommerce return retry could not be persisted.', ['event_id' => $id, 'error_class' => get_class($queueError)]);
                }
                $this->log->error('WooCommerce return event remains queued for retry.', ['event_id' => $id, 'attempt' => $attempt, 'error_class' => get_class($error)]);
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
            "UPDATE {$table} SET status='pending',claimed_at=NULL WHERE status='processing' AND claimed_at < DATE_SUB(%s, INTERVAL 10 MINUTE)",
            current_time('mysql')
        ));
        if ($recovered === false) throw new \RuntimeException('Unable to recover stale WooCommerce return claims.');
        if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Unable to start return queue claim.');
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,event_kind,wc_order_id,wc_order_item_id,wc_product_id,quantity,cumulative_quantity,attempts
                 FROM {$table} WHERE status='pending' AND next_attempt_at<=%s ORDER BY next_attempt_at,id LIMIT %d FOR UPDATE",
                current_time('mysql'), $limit
            ), ARRAY_A);
            if ($rows === null && $wpdb->last_error !== '') throw new \RuntimeException('Unable to read WooCommerce return queue.');
            $rows = $rows ?: [];
            foreach ($rows as &$row) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status='processing',claimed_at=%s,attempts=attempts+1 WHERE id=%d AND status='pending'",
                    current_time('mysql'), (int) $row['id']
                ));
                if ($updated === false) throw new \RuntimeException('Unable to claim WooCommerce return event.');
                $row['attempts'] = (int) $row['attempts'] + 1;
            }
            unset($row);
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to commit return queue claim.');
            return $rows;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    private function complete(int $id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $deleted = $wpdb->delete($table, ['id' => $id, 'status' => 'processing'], ['%d', '%s']);
        if ($deleted !== 1) throw new \RuntimeException('Unable to acknowledge WooCommerce return event.');
    }

    private function retry(int $id, int $attempt, string $errorClass): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
        $delay = min(21600, 30 * (2 ** min(10, max(0, $attempt - 1))));
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='pending',claimed_at=NULL,next_attempt_at=DATE_ADD(%s,INTERVAL %d SECOND),last_error=%s
             WHERE id=%d AND status='processing'",
            current_time('mysql'), $delay, substr(sanitize_key($errorClass), 0, 100), $id
        ));
        if ($updated !== 1) throw new \RuntimeException('Unable to reschedule WooCommerce return event.');
    }
}
