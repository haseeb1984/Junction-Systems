<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\{StockLevel, StockMovement};
use PkStore\Domain\Inventory\Repository\StockRepositoryInterface;
final class WpStockRepository implements StockRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function ts(): string { return $this->db->prefix . 'pkstore_inventory_stock'; }
    private function tm(): string { return $this->db->prefix . 'pkstore_inventory_movements'; }
    public function getStock(int $productId, int $locationId): StockLevel {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->ts()} WHERE product_id=%d AND location_id=%d",$productId,$locationId),ARRAY_A);
        if ($r) return new StockLevel((int)$r['id'],(int)$r['product_id'],(int)$r['location_id'],(int)$r['quantity'],new \DateTimeImmutable($r['updated_at']));
        $this->db->insert($this->ts(),['product_id'=>$productId,'location_id'=>$locationId,'quantity'=>0,'updated_at'=>current_time('mysql')]);
        if ($this->db->last_error !== '') {
            // A concurrent request may have created the unique product/location row.
            $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->ts()} WHERE product_id=%d AND location_id=%d",$productId,$locationId),ARRAY_A);
            if (!$r) throw new \RuntimeException('Unable to initialize stock level: '.$this->db->last_error);
            return new StockLevel((int)$r['id'],(int)$r['product_id'],(int)$r['location_id'],(int)$r['quantity'],new \DateTimeImmutable($r['updated_at']));
        }
        return new StockLevel((int)$this->db->insert_id,$productId,$locationId,0,new \DateTimeImmutable());
    }
    public function updateStock(StockLevel $stock): void {
        $result=$this->db->update($this->ts(),['quantity'=>$stock->getQuantity(),'updated_at'=>current_time('mysql')],['product_id'=>$stock->productId,'location_id'=>$stock->locationId]);
        if ($result === false) throw new \RuntimeException('Unable to update stock: '.$this->db->last_error);
    }
    public function applyMovement(StockMovement $movement): ?StockMovement {
        return $this->transactionalMovement($movement->productId,$movement->locationId,$movement->quantityDelta,
            fn(int $before): array => [$before+$movement->quantityDelta,$movement->type,$movement->referenceType,$movement->referenceId,$movement->note,$movement->createdBy,$movement->createdAt]);
    }
    public function setStockAndRecordMovement(int $productId,int $locationId,int $quantity,string $note,?int $userId): ?StockMovement {
        if ($quantity < 0) throw new \InvalidArgumentException('Quantity cannot be negative.');
        return $this->transactionalMovement($productId,$locationId,null,
            fn(int $before): array => [$quantity,\PkStore\Domain\Inventory\Entity\MovementType::Adjustment,'manual','',$note,$userId,new \DateTimeImmutable()],
        );
    }
    private function transactionalMovement(int $productId,int $locationId,?int $delta,callable $build): ?StockMovement {
        if ($productId <= 0 || $locationId <= 0) throw new \InvalidArgumentException('Product and location IDs must be positive.');
        if ($this->db->query('START TRANSACTION') === false) throw new \RuntimeException('Unable to start stock transaction: '.$this->db->last_error);
        try {
            $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->ts()} (product_id,location_id,quantity,updated_at) VALUES (%d,%d,0,%s)",$productId,$locationId,current_time('mysql')));
            $row=$this->db->get_row($this->db->prepare("SELECT id,quantity FROM {$this->ts()} WHERE product_id=%d AND location_id=%d FOR UPDATE",$productId,$locationId),ARRAY_A);
            if (!$row) throw new \RuntimeException('Unable to lock stock row: '.$this->db->last_error);
            $before=(int)$row['quantity'];
            [$after,$type,$refType,$refId,$note,$userId,$createdAt]=$build($before);
            if ($after < 0) throw new \DomainException("Stock cannot go below 0. Current: {$before}, Requested: ".($delta ?? $after));
            $actualDelta=$after-$before;
            if ($delta !== null && $actualDelta !== $delta) throw new \LogicException('Stock movement delta mismatch.');
            if ($actualDelta === 0) {
                if ($this->db->query('COMMIT') === false) throw new \RuntimeException('Unable to commit stock transaction: '.$this->db->last_error);
                return null;
            }
            $updated=$this->db->update($this->ts(),['quantity'=>$after,'updated_at'=>current_time('mysql')],['id'=>(int)$row['id']]);
            if ($updated === false) throw new \RuntimeException('Unable to update stock quantity: '.$this->db->last_error);
            $entry=new StockMovement(0,$productId,$locationId,$type,$actualDelta,$before,$after,$refType,$refId,$note,$userId,$createdAt);
            $this->insertMovement($entry);
            if ($this->db->query('COMMIT') === false) throw new \RuntimeException('Unable to commit stock transaction: '.$this->db->last_error);
            return $entry;
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }
    public function recordMovement(StockMovement $m): int { return $this->insertMovement($m); }
    private function insertMovement(StockMovement $m): int {
        $ok=$this->db->insert($this->tm(),['product_id'=>$m->productId,'location_id'=>$m->locationId,'movement_type'=>$m->type->value,'quantity_delta'=>$m->quantityDelta,'quantity_before'=>$m->quantityBefore,'quantity_after'=>$m->quantityAfter,'reference_type'=>$m->referenceType,'reference_id'=>$m->referenceId,'note'=>$m->note,'created_by'=>$m->createdBy,'created_at'=>$m->createdAt->format('Y-m-d H:i:s')]);
        if ($ok === false) throw new \RuntimeException('Unable to record stock movement: '.$this->db->last_error);
        return (int)$this->db->insert_id;
    }
    public function getMovements(int $productId, int $limit=20): array { return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->tm()} WHERE product_id=%d ORDER BY created_at DESC LIMIT %d",$productId,$limit),ARRAY_A)?:[]; }
    public function getLocationStock(int $locationId): array { return $this->db->get_results($this->db->prepare("SELECT s.*,p.name,p.sku,p.barcode,p.reorder_point FROM {$this->ts()} s INNER JOIN {$this->db->prefix}pkstore_inventory_products p ON s.product_id=p.id WHERE s.location_id=%d ORDER BY p.name",$locationId),ARRAY_A)?:[]; }
    public function getTotalStock(int $productId): int { return (int)$this->db->get_var($this->db->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$this->ts()} WHERE product_id=%d",$productId)); }
}
