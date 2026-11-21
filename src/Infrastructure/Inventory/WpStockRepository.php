<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\{StockLevel, StockMovement, MovementType};
use PkStore\Domain\Inventory\Repository\StockRepositoryInterface;
final class WpStockRepository implements StockRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function ts(): string { return $this->db->prefix . 'pkstore_inventory_stock'; }
    private function tm(): string { return $this->db->prefix . 'pkstore_inventory_movements'; }
    public function getStock(int $productId, int $locationId): StockLevel {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->ts()} WHERE product_id=%d AND location_id=%d FOR UPDATE",$productId,$locationId),ARRAY_A);
        if ($r) return new StockLevel((int)$r['id'],(int)$r['product_id'],(int)$r['location_id'],(int)$r['quantity'],new \DateTimeImmutable($r['updated_at']));
        $inserted = $this->db->insert($this->ts(),['product_id'=>$productId,'location_id'=>$locationId,'quantity'=>0,'updated_at'=>current_time('mysql')]);
        if ($inserted === false || (int)$this->db->insert_id < 1) throw new \RuntimeException('Unable to initialize stock level.');
        return new StockLevel((int)$this->db->insert_id,$productId,$locationId,0,new \DateTimeImmutable());
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
    public function getMovements(int $productId, int $limit=20): array { return $this->db->get_results($this->db->prepare("SELECT * FROM {$this->tm()} WHERE product_id=%d ORDER BY created_at DESC LIMIT %d",$productId,$limit),ARRAY_A)?:[]; }
    public function getLocationStock(int $locationId): array { return $this->db->get_results($this->db->prepare("SELECT s.*,p.name,p.sku,p.barcode,p.reorder_point FROM {$this->ts()} s INNER JOIN {$this->db->prefix}pkstore_inventory_products p ON s.product_id=p.id WHERE s.location_id=%d ORDER BY p.name",$locationId),ARRAY_A)?:[]; }
    public function getTotalStock(int $productId): int { return (int)$this->db->get_var($this->db->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$this->ts()} WHERE product_id=%d",$productId)); }
}
