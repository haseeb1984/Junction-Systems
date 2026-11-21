<?php
declare(strict_types=1);

namespace PkStore\Application\Inventory;

use Psr\Log\LoggerInterface;

/**
 * Durable retry queue for WooCommerce order-to-inventory ledger ingestion.
 *
 * Queue rows contain only the WooCommerce order ID and operational state. The
 * inventory use case remains responsible for transactional, idempotent ledger
 * updates; this queue makes failed attempts recoverable.
 */
final class ProcessWooCommerceOrderInventoryQueueUseCase {
    private const MAX_AUTOMATIC_ATTEMPTS = 12;

    public function __construct(
        private readonly SyncWooCommerceStockUseCase $syncInventory,
        private readonly LoggerInterface $log,
    ) {}

    public function enqueue(int $orderId): void {
        if ($orderId < 1) {
            throw new \InvalidArgumentException('A valid WooCommerce order ID is required.');
        }
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for order inventory queue.');
        }

        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (wc_order_id, generation, status, attempts, next_attempt_at, claimed_at, last_error, created_at, updated_at)
             VALUES (%d, %s, 'pending', 0, %s, NULL, '', %s, %s)
             ON DUPLICATE KEY UPDATE
                generation=VALUES(generation),
                status=IF(claimed_at IS NOT NULL,'processing','pending'),
                attempts=IF(claimed_at IS NOT NULL,attempts,0),
                next_attempt_at=IF(claimed_at IS NOT NULL,next_attempt_at,VALUES(next_attempt_at)),
                claimed_at=claimed_at,
                last_error='', updated_at=VALUES(updated_at)",
            $orderId, wp_generate_uuid4(), $now, $now, $now
        ));
        if ($result === false) {
            throw new \RuntimeException('Unable to persist WooCommerce order inventory event.');
        }
    }

    public function execute(int $limit = 20): array {
        $jobs = $this->claimBatch($limit);
        $result = ['processed' => 0, 'failed' => 0];

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $orderId = (int) ($job['wc_order_id'] ?? 0);
            $attempts = (int) ($job['attempts'] ?? 1);
            $generation = (string) ($job['generation'] ?? '');
            if ($id < 1 || $orderId < 1 || $generation === '') continue;

            try {
                $this->syncInventory->onWooCommerceSale($orderId);
                $this->complete($id, $generation);
                $result['processed']++;
            } catch (\Throwable $error) {
                $result['failed']++;
                try {
                    $this->retry($id, $generation, $attempts, get_class($error));
                } catch (\Throwable $queueError) {
                    $this->log->critical('WooCommerce order inventory retry could not be persisted.', [
                        'order_id' => $orderId,
                        'error_class' => get_class($queueError),
                    ]);
                }
                $this->log->error($attempts >= self::MAX_AUTOMATIC_ATTEMPTS
                    ? 'WooCommerce order inventory event moved to needs_review after exhausting automatic retries.'
                    : 'WooCommerce order inventory event will be retried.', [
                    'order_id' => $orderId,
                    'attempt' => $attempts,
                    'error_class' => get_class($error),
                ]);
            }
        }

        return $result;
    }

    private function claimBatch(int $limit): array {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for order inventory queue.');
        }
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $limit = max(1, min(100, $limit));
        $recovered = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='pending', claimed_at=NULL
             WHERE status='processing' AND claimed_at < DATE_SUB(%s, INTERVAL 10 MINUTE)",
            current_time('mysql')
        ));
        if ($recovered === false) throw new \RuntimeException('Unable to recover stale order inventory claims.');

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start order inventory queue claim.');
        }
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, wc_order_id, attempts, generation FROM {$table}
                 WHERE status='pending' AND next_attempt_at <= %s
                 ORDER BY next_attempt_at ASC, id ASC LIMIT %d FOR UPDATE",
                current_time('mysql'), $limit
            ), ARRAY_A);
            if ($rows === null && $wpdb->last_error !== '') {
                throw new \RuntimeException('Unable to read order inventory queue.');
            }
            $rows = $rows ?: [];
            foreach ($rows as &$row) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status='processing', claimed_at=%s, attempts=attempts+1
                     WHERE id=%d AND status='pending'",
                    current_time('mysql'), (int) $row['id']
                ));
                if ($updated === false) throw new \RuntimeException('Unable to claim order inventory event.');
                $row['attempts'] = (int) $row['attempts'] + 1;
            }
            unset($row);
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit order inventory queue claim.');
            }
            return $rows;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    private function complete(int $id, string $generation): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $deleted = $wpdb->delete($table, [
            'id' => $id,
            'generation' => $generation,
            'status' => 'processing',
        ], ['%d', '%s', '%s']);
        if ($deleted === false) throw new \RuntimeException('Unable to complete order inventory event.');
        if ($deleted === 0) {
            // A duplicate lifecycle hook refreshed this event while it ran.
            // Release the newer generation; ledger movement references prevent
            // a second stock deduction on the subsequent attempt.
            $released = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='pending', claimed_at=NULL, attempts=0, next_attempt_at=%s
                 WHERE id=%d AND status='processing' AND generation<>%s",
                current_time('mysql'), $id, $generation
            ));
            if ($released === false) throw new \RuntimeException('Unable to release refreshed order inventory event.');
        }
    }

    private function retry(int $id, string $generation, int $attempts, string $errorClass): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $needsReview = $attempts >= self::MAX_AUTOMATIC_ATTEMPTS;
        $delay = min(21600, 30 * (2 ** min(10, max(0, $attempts - 1))));
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status=%s, claimed_at=NULL,
                 next_attempt_at=IF(%s='needs_review',%s,DATE_ADD(%s, INTERVAL %d SECOND)),
                 last_error=%s
             WHERE id=%d AND generation=%s AND status='processing'",
            $needsReview ? 'needs_review' : 'pending',
            $needsReview ? 'needs_review' : 'pending',
            $now, $now, $delay, substr(sanitize_key($errorClass), 0, 100), $id, $generation
        ));
        if ($updated === false) throw new \RuntimeException('Unable to reschedule order inventory event.');
        if ($updated === 0) {
            $released = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='pending', claimed_at=NULL, attempts=0, next_attempt_at=%s
                 WHERE id=%d AND status='processing' AND generation<>%s",
                current_time('mysql'), $id, $generation
            ));
            if ($released === false) throw new \RuntimeException('Unable to release refreshed order inventory event after failure.');
        }
    }
}
