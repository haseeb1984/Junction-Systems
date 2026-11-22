<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\{StockLevel, StockMovement, MovementType};
use PkStore\Domain\Inventory\Repository\StockRepositoryInterface;
final class WpStockRepository implements StockRepositoryInterface {
    private const MAX_AUTOMATIC_SYNC_ATTEMPTS = 12;
    public function __construct(private readonly \wpdb $db) {}
    private function ts(): string { return $this->db->prefix . 'pkstore_inventory_stock'; }
    private function tm(): string { return $this->db->prefix . 'pkstore_inventory_movements'; }
    public function getStock(int $productId, int $locationId): StockLevel {
        // Ensure the unique product/location row exists before locking it. A
        // SELECT ... FOR UPDATE cannot protect a row that does not exist; two
        // first-time sales could otherwise race to insert the same stock row.
        $inserted = $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->ts()} (product_id, location_id, quantity, updated_at) VALUES (%d, %d, 0, %s)",
            $productId,
            $locationId,
            current_time('mysql')
        ));
        if ($inserted === false) throw new \RuntimeException('Unable to initialize stock level.');

        $r = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->ts()} WHERE product_id=%d AND location_id=%d FOR UPDATE",
            $productId,
            $locationId
        ), ARRAY_A);
        if (!$r) throw new \RuntimeException('Unable to lock stock level.');

        return new StockLevel(
            (int)$r['id'],
            (int)$r['product_id'],
            (int)$r['location_id'],
            (int)$r['quantity'],
            new \DateTimeImmutable($r['updated_at'])
        );
    }
    public function updateStock(StockLevel $stock): void {
        $updated = $this->db->update($this->ts(),['quantity'=>$stock->getQuantity(),'updated_at'=>current_time('mysql')],['product_id'=>$stock->productId,'location_id'=>$stock->locationId]);
        if ($updated === false) throw new \RuntimeException('Unable to persist stock quantity.');
    }
    public function recordMovement(StockMovement $m): int {
        $inserted = $this->db->insert($this->tm(),['product_id'=>$m->productId,'location_id'=>$m->locationId,'movement_type'=>$m->type->value,'quantity_delta'=>$m->quantityDelta,'quantity_before'=>$m->quantityBefore,'quantity_after'=>$m->quantityAfter,'reference_type'=>$m->referenceType,'reference_id'=>$m->referenceId,'note'=>$m->note,'created_by'=>$m->createdBy,'created_at'=>$m->createdAt->format('Y-m-d H:i:s')]);
        if ($inserted === false || (int)$this->db->insert_id < 1) throw new \RuntimeException('Unable to record stock movement.');
        return (int)$this->db->insert_id;
    }
    public function hasMovementReference(int $productId, int $locationId, string $referenceType, string $referenceId, MovementType $movementType): bool {
        $found = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->tm()} WHERE product_id=%d AND location_id=%d AND reference_type=%s AND reference_id=%s AND movement_type=%s LIMIT 1",
            $productId, $locationId, $referenceType, $referenceId, $movementType->value
        ));
        return $found !== null;
    }
    public function getMovements(int $productId, int $limit=20): array { return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->tm()} WHERE product_id=%d ORDER BY created_at DESC LIMIT %d",$productId,$limit),ARRAY_A)?:[]; }
    public function getLocationStock(int $locationId): array { return $this->db->get_results($this->db->prepare("SELECT s.*,p.name,p.sku,p.barcode,p.reorder_point FROM {$this->ts()} s INNER JOIN {$this->db->prefix}pkstore_inventory_products p ON s.product_id=p.id WHERE s.location_id=%d ORDER BY p.name",$locationId),ARRAY_A)?:[]; }
    public function getTotalStock(int $productId): int { return (int)$this->db->get_var($this->db->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$this->ts()} WHERE product_id=%d",$productId)); }

    public function enqueueWooCommerceSync(int $productId): void {
        $table = $this->db->prefix . 'pkstore_wc_stock_sync';
        $now = current_time('mysql');
        $result = $this->db->query($this->db->prepare(
            "INSERT INTO {$table} (product_id, generation, status, attempts, next_attempt_at, claimed_at, last_error, created_at, updated_at)
             VALUES (%d, %s, 'pending', 0, %s, NULL, '', %s, %s)
             ON DUPLICATE KEY UPDATE
             generation=VALUES(generation),
             status=IF(claimed_at IS NOT NULL,'processing','pending'),
             attempts=IF(claimed_at IS NOT NULL,attempts,0),
             next_attempt_at=IF(claimed_at IS NOT NULL,next_attempt_at,VALUES(next_attempt_at)),
             claimed_at=claimed_at,
             claim_token=IF(claimed_at IS NOT NULL,claim_token,NULL),
             last_error='', updated_at=VALUES(updated_at)",
            $productId, wp_generate_uuid4(), $now, $now, $now
        ));
        if ($result === false) throw new \RuntimeException('Unable to queue WooCommerce stock synchronization.');
    }
    public function claimWooCommerceSyncBatch(int $limit = 20): array {
        $table = $this->db->prefix . 'pkstore_wc_stock_sync';
        $limit = max(1, min(100, $limit));
        // Recover jobs abandoned by a terminated PHP worker.
        $recovered = $this->db->query($this->db->prepare(
            "UPDATE {$table} SET status='pending', claimed_at=NULL, claim_token=NULL WHERE status='processing' AND claimed_at < DATE_SUB(%s, INTERVAL 10 MINUTE)",
            current_time('mysql')
        ));
        if ($recovered === false) throw new \RuntimeException('Unable to recover stale stock sync claims.');
        if ($this->db->query('START TRANSACTION') === false) throw new \RuntimeException('Unable to start stock sync queue claim.');
        try {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT id, product_id, attempts, generation FROM {$table}
                 WHERE status='pending' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d FOR UPDATE",
                current_time('mysql'), $limit
            ), ARRAY_A);
            if ($rows === null && $this->db->last_error !== '') {
                throw new \RuntimeException('Unable to read stock sync queue.');
            }
            $rows = $rows ?: [];
            foreach ($rows as &$row) {
                $claimToken = wp_generate_uuid4();
                $updated = $this->db->query($this->db->prepare(
                    "UPDATE {$table} SET status='processing', claimed_at=%s, claim_token=%s, attempts=attempts+1 WHERE id=%d AND status='pending'",
                    current_time('mysql'), $claimToken, (int)$row['id']
                ));
                if ($updated !== 1) throw new \RuntimeException('Unable to claim stock sync queue item.');
                $row['attempts'] = (int)$row['attempts'] + 1;
                $row['claim_token'] = $claimToken;
            }
            unset($row);
            if ($this->db->query('COMMIT') === false) throw new \RuntimeException('Unable to commit stock sync queue claim.');
            return $rows;
        } catch (\Throwable $error) {
            $this->db->query('ROLLBACK');
            throw $error;
        }
    }
    public function completeWooCommerceSync(int $id, string $generation, string $claimToken): void {
        $table = $this->db->prefix . 'pkstore_wc_stock_sync';
        $deleted = $this->db->delete($table, ['id'=>$id, 'generation'=>$generation, 'status'=>'processing', 'claim_token'=>$claimToken], ['%d','%s','%s','%s']);
        if ($deleted === false) throw new \RuntimeException('Unable to complete stock sync queue item.');
        if ($deleted === 0) {
            // A newer stock change arrived while this job was processing. Keep the
            // product serialized, then release the newer generation for a fresh read.
            $released = $this->db->query($this->db->prepare(
                "UPDATE {$table} SET status='pending', claimed_at=NULL, claim_token=NULL, attempts=0, next_attempt_at=%s
                 WHERE id=%d AND status='processing' AND generation<>%s AND claim_token=%s",
                current_time('mysql'), $id, $generation, $claimToken
            ));
            if ($released === false) throw new \RuntimeException('Unable to release newer stock sync generation.');
        }
    }
    public function retryWooCommerceSync(int $id, string $generation, string $claimToken, int $attempts, string $errorClass): void {
        $table = $this->db->prefix . 'pkstore_wc_stock_sync';
        $needsReview = $attempts >= self::MAX_AUTOMATIC_SYNC_ATTEMPTS;
        $delay = min(21600, 30 * (2 ** min(10, max(0, $attempts - 1))));
        $now = current_time('mysql');
        $updated = $this->db->query($this->db->prepare(
            "UPDATE {$table} SET status=%s, claimed_at=NULL, claim_token=NULL,
             next_attempt_at=IF(%s='needs_review',%s,DATE_ADD(%s, INTERVAL %d SECOND)), last_error=%s
             WHERE id=%d AND generation=%s AND status='processing' AND claim_token=%s",
            $needsReview ? 'needs_review' : 'pending', $needsReview ? 'needs_review' : 'pending',
            $now, $now, $delay, substr(sanitize_key($errorClass), 0, 100), $id, $generation, $claimToken
        ));
        if ($updated === false) throw new \RuntimeException('Unable to reschedule stock sync queue item.');
        if ($updated === 0) {
            // If a newer generation was enqueued during this failed attempt, retry
            // that latest snapshot immediately rather than applying the old backoff.
            $released = $this->db->query($this->db->prepare(
                "UPDATE {$table} SET status='pending', claimed_at=NULL, claim_token=NULL, attempts=0, next_attempt_at=%s
                 WHERE id=%d AND status='processing' AND generation<>%s AND claim_token=%s",
                current_time('mysql'), $id, $generation, $claimToken
            ));
            if ($released === false) throw new \RuntimeException('Unable to release newer stock sync generation after failure.');
        }
    }
}
