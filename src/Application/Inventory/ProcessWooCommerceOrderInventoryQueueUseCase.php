<?php
declare(strict_types=1);

namespace PkStore\Application\Inventory;

use Psr\Log\LoggerInterface;

/**
 * Durable retry queue for WooCommerce order-to-inventory ledger ingestion.
 *
 * Each row is scoped to a WooCommerce order + stock-cycle UUID (or the legacy
 * sentinel). The inventory use case remains responsible for transactional,
 * idempotent ledger updates; this queue makes failed attempts recoverable.
 */
final class ProcessWooCommerceOrderInventoryQueueUseCase {
    private const MAX_AUTOMATIC_ATTEMPTS = 12;
    private const LEGACY_CYCLE_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly SyncWooCommerceStockUseCase $syncInventory,
        private readonly CaptureWooCommerceStockCycleUseCase $cycleCapture,
        private readonly LoggerInterface $log,
    ) {}

    public function enqueue(int $orderId, ?string $cycleUuid = null): void {
        if ($orderId < 1 || ($cycleUuid !== null && !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid))) {
            throw new \InvalidArgumentException('A valid WooCommerce order ID and stock-cycle identity are required.');
        }
        $queueCycleUuid = $cycleUuid ?? self::LEGACY_CYCLE_UUID;
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for order inventory queue.');
        }

        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (wc_order_id, cycle_uuid, generation, status, attempts, next_attempt_at, claimed_at, last_error, created_at, updated_at)
             VALUES (%d, %s, %s, 'pending', 0, %s, NULL, '', %s, %s)
             ON DUPLICATE KEY UPDATE
                generation=VALUES(generation),
                status=IF(claimed_at IS NOT NULL,'processing','pending'),
                attempts=IF(claimed_at IS NOT NULL,attempts,0),
                next_attempt_at=IF(claimed_at IS NOT NULL,next_attempt_at,VALUES(next_attempt_at)),
                claimed_at=claimed_at,
                last_error='', updated_at=VALUES(updated_at)",
            $orderId, $queueCycleUuid, wp_generate_uuid4(), $now, $now, $now
        ));
        if ($result === false) {
            throw new \RuntimeException('Unable to persist WooCommerce order inventory event.');
        }
    }

    public function acknowledgeCompleted(int $orderId, string $cycleUuid): void {
        if ($orderId < 1 || !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid)) {
            throw new \InvalidArgumentException('A valid WooCommerce order and cycle identity are required.');
        }
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for order inventory queue.');
        }

        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        // A worker may already own the row. Leave claimed rows fenced for that
        // worker; remove only an unclaimed pending row after the ledger commit.
        $deleted = $wpdb->delete($table, [
            'wc_order_id' => $orderId,
            'cycle_uuid' => $cycleUuid,
            'status' => 'pending',
            'claimed_at' => null,
        ], ['%d', '%s', '%s', '%s']);
        if ($deleted === false) {
            throw new \RuntimeException('Unable to acknowledge synchronously completed WooCommerce inventory event.');
        }
    }

    private function recoverUnqueuedCycleSales(int $limit): void {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for cycle sale recovery.');

        $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
        $queueTable = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.wc_order_id,e.cycle_uuid
             FROM {$eventsTable} e
             LEFT JOIN {$queueTable} q
                ON q.wc_order_id=e.wc_order_id AND q.cycle_uuid=e.cycle_uuid
             WHERE e.event_kind='reduction' AND e.status='pending'
               AND e.next_attempt_at<=%s AND q.id IS NULL
             ORDER BY e.created_at ASC LIMIT %d",
            current_time('mysql'),
            max(1, min(100, $limit))
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to inspect pending WooCommerce stock-cycle sale events.');

        foreach ($rows as $row) {
            $orderId = (int) $row['wc_order_id'];
            $cycleUuid = (string) $row['cycle_uuid'];
            if ($orderId < 1 || !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid)) {
                throw new \RuntimeException('Pending WooCommerce stock-cycle sale event cannot be reconstructed safely.');
            }
            $this->enqueue($orderId, $cycleUuid);
        }
    }

    public function execute(int $limit = 20): array {
        try {
            $this->recoverUnqueuedCycleSales($limit);
        } catch (\Throwable $recoveryError) {
            $this->log->critical('WooCommerce cycle sale queue recovery failed; pending cycle events remain durable.', [
                'error_class' => get_class($recoveryError),
            ]);
        }
        $jobs = $this->claimBatch($limit);
        $result = ['processed' => 0, 'failed' => 0];

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $orderId = (int) ($job['wc_order_id'] ?? 0);
            $attempts = (int) ($job['attempts'] ?? 1);
            $generation = (string) ($job['generation'] ?? '');
            if ($id < 1 || $orderId < 1 || $generation === '') continue;

            try {
                $cycleUuid = (string) ($job['cycle_uuid'] ?? self::LEGACY_CYCLE_UUID);
                $claimToken = (string) ($job['claim_token'] ?? '');
                $order = wc_get_order($orderId);
                if (!($order instanceof \WC_Order)) throw new \RuntimeException('WooCommerce order is unavailable.');
                if ($cycleUuid === self::LEGACY_CYCLE_UUID
                    && (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true) === '1') {
                    $cycleUuid = $this->cycleCapture->capture($order);
                    $order->update_meta_data('_pkstore_wc_active_cycle_uuid', $cycleUuid);
                    if ((int) $order->save() !== $orderId) {
                        throw new \RuntimeException('Unable to persist retried WooCommerce stock-cycle identity.');
                    }
                    if (!$this->bindCycleIdentity($id, $orderId, $generation, $claimToken, $cycleUuid)) {
                        continue;
                    }
                } elseif ($cycleUuid !== self::LEGACY_CYCLE_UUID) {
                    $this->ensureActiveCycleMeta($order, $orderId, $cycleUuid);
                }
                $this->syncInventory->onWooCommerceSale($orderId, $cycleUuid !== self::LEGACY_CYCLE_UUID ? $cycleUuid : null);
                $this->complete($id, $generation, $claimToken);
                $result['processed']++;
            } catch (\Throwable $error) {
                $result['failed']++;
                try {
                    $this->retry($id, $generation, (string) ($job['claim_token'] ?? ''), $attempts, get_class($error));
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

    private function ensureActiveCycleMeta(\WC_Order $order, int $orderId, string $cycleUuid): void {
        $currentCycleUuid = (string) $order->get_meta('_pkstore_wc_active_cycle_uuid', true);
        if ($currentCycleUuid === $cycleUuid) return;

        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for cycle metadata recovery.');
        $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
        $targetNumber = $wpdb->get_var($wpdb->prepare(
            "SELECT cycle_number FROM {$cyclesTable} WHERE wc_order_id=%d AND cycle_uuid=%s",
            $orderId,
            $cycleUuid
        ));
        if ($wpdb->last_error !== '' || !is_numeric($targetNumber)) {
            throw new \RuntimeException('WooCommerce stock-cycle identity is unavailable for order metadata recovery.');
        }

        $currentNumber = 0;
        if ($currentCycleUuid !== '') {
            $currentNumber = $wpdb->get_var($wpdb->prepare(
                "SELECT cycle_number FROM {$cyclesTable} WHERE wc_order_id=%d AND cycle_uuid=%s",
                $orderId,
                $currentCycleUuid
            ));
            if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to inspect current WooCommerce stock-cycle metadata.');
            $currentNumber = is_numeric($currentNumber) ? (int) $currentNumber : 0;
        }

        if ((int) $targetNumber >= $currentNumber) {
            $order->update_meta_data('_pkstore_wc_active_cycle_uuid', $cycleUuid);
            if ((int) $order->save() !== $orderId) {
                throw new \RuntimeException('Unable to persist active WooCommerce stock-cycle metadata.');
            }
        }
    }

    private function bindCycleIdentity(int $id, int $orderId, string $generation, string $claimToken, string $cycleUuid): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $existingId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE wc_order_id=%d AND cycle_uuid=%s AND id<>%d LIMIT 1",
            $orderId,
            $cycleUuid,
            $id
        ));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Unable to inspect cycle-specific order queue row.');
        if ($existingId) {
            $deleted = $wpdb->delete($table, [
                'id' => $id,
                'generation' => $generation,
                'status' => 'processing',
                'claim_token' => $claimToken,
            ], ['%d', '%s', '%s', '%s']);
            if ($deleted !== 1) throw new \RuntimeException('Unable to merge duplicate legacy cycle-capture queue row.');
            return false;
        }

        $updated = $wpdb->update($table, ['cycle_uuid' => $cycleUuid], [
            'id' => $id,
            'generation' => $generation,
            'status' => 'processing',
            'claim_token' => $claimToken,
        ], ['%s'], ['%d', '%s', '%s', '%s']);
        if ($updated !== 1) throw new \RuntimeException('Unable to bind WooCommerce order queue row to its stock cycle.');
        return true;
    }

    private function claimBatch(int $limit): array {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for order inventory queue.');
        }
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $limit = max(1, min(100, $limit));
        $recovered = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='pending', claimed_at=NULL, claim_token=NULL
             WHERE status='processing' AND claimed_at < DATE_SUB(%s, INTERVAL 10 MINUTE)",
            current_time('mysql')
        ));
        if ($recovered === false) throw new \RuntimeException('Unable to recover stale order inventory claims.');

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start order inventory queue claim.');
        }
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, wc_order_id, cycle_uuid, attempts, generation FROM {$table}
                 WHERE status='pending' AND next_attempt_at <= %s
                 ORDER BY next_attempt_at ASC, id ASC LIMIT %d FOR UPDATE",
                current_time('mysql'), $limit
            ), ARRAY_A);
            if ($rows === null && $wpdb->last_error !== '') {
                throw new \RuntimeException('Unable to read order inventory queue.');
            }
            $rows = $rows ?: [];
            foreach ($rows as &$row) {
                $claimToken = wp_generate_uuid4();
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status='processing', claimed_at=%s, claim_token=%s, attempts=attempts+1
                     WHERE id=%d AND status='pending'",
                    current_time('mysql'), $claimToken, (int) $row['id']
                ));
                if ($updated !== 1) throw new \RuntimeException('Unable to claim order inventory event.');
                $row['attempts'] = (int) $row['attempts'] + 1;
                $row['claim_token'] = $claimToken;
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

    private function complete(int $id, string $generation, string $claimToken): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $deleted = $wpdb->delete($table, [
            'id' => $id,
            'generation' => $generation,
            'status' => 'processing',
            'claim_token' => $claimToken,
        ], ['%d', '%s', '%s', '%s']);
        if ($deleted === false) throw new \RuntimeException('Unable to complete order inventory event.');
        if ($deleted === 0) {
            // A duplicate lifecycle hook refreshed this event while it ran.
            // Release the newer generation; ledger movement references prevent
            // a second stock deduction on the subsequent attempt.
            $released = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='pending', claimed_at=NULL, claim_token=NULL, attempts=0, next_attempt_at=%s
                 WHERE id=%d AND status='processing' AND generation<>%s AND claim_token=%s",
                current_time('mysql'), $id, $generation, $claimToken
            ));
            if ($released === false) throw new \RuntimeException('Unable to release refreshed order inventory event.');
        }
    }

    private function retry(int $id, string $generation, string $claimToken, int $attempts, string $errorClass): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $needsReview = $attempts >= self::MAX_AUTOMATIC_ATTEMPTS;
        $delay = min(21600, 30 * (2 ** min(10, max(0, $attempts - 1))));
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status=%s, claimed_at=NULL, claim_token=NULL,
                 next_attempt_at=IF(%s='needs_review',%s,DATE_ADD(%s, INTERVAL %d SECOND)),
                 last_error=%s
             WHERE id=%d AND generation=%s AND status='processing' AND claim_token=%s",
            $needsReview ? 'needs_review' : 'pending',
            $needsReview ? 'needs_review' : 'pending',
            $now, $now, $delay, substr(sanitize_key($errorClass), 0, 100), $id, $generation, $claimToken
        ));
        if ($updated === false) throw new \RuntimeException('Unable to reschedule order inventory event.');
        if ($updated === 0) {
            $released = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='pending', claimed_at=NULL, claim_token=NULL, attempts=0, next_attempt_at=%s
                 WHERE id=%d AND status='processing' AND generation<>%s AND claim_token=%s",
                current_time('mysql'), $id, $generation, $claimToken
            ));
            if ($released === false) throw new \RuntimeException('Unable to release refreshed order inventory event after failure.');
        }
    }
}
