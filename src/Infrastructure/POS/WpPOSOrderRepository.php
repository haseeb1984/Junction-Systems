<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\POS;
use PkStore\Domain\POS\Entity\{POSOrder, SyncStatus};
use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;
final class WpPOSOrderRepository implements POSOrderRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_pos_orders'; }
    private function ti(): string { return $this->db->prefix . 'pkstore_pos_order_items'; }
    public function save(POSOrder $o): int {
        $ok=$this->db->insert($this->t(),['session_id'=>$o->sessionId,'location_id'=>$o->locationId,'order_ref'=>$o->orderRef,'subtotal_pkr'=>$o->subtotalPkr,'discount_pkr'=>$o->discountPkr,'total_pkr'=>$o->totalPkr,'payment_method'=>$o->paymentMethod,'payment_split'=>$o->paymentSplit?wp_json_encode($o->paymentSplit):null,'customer_phone'=>$o->customerPhone,'customer_name'=>$o->customerName,'receipt_sent'=>0,'sync_status'=>'pending','wc_order_id'=>null,'created_at'=>$o->createdAt->format('Y-m-d H:i:s'),'synced_at'=>null]);
        if ($ok === false) throw new \RuntimeException('Unable to insert POS order: '.$this->db->last_error);
        return (int)$this->db->insert_id;
    }
    public function saveWithItems(POSOrder $o, array $items): int {
        if ($this->db->query('START TRANSACTION') === false) throw new \RuntimeException('Unable to start POS order transaction: '.$this->db->last_error);
        try {
            $id = $this->save($o);
            if ($id <= 0) throw new \RuntimeException('Unable to save POS order: '.$this->db->last_error);
            $this->saveItems($id, $items);
            if ($this->db->query('COMMIT') === false) throw new \RuntimeException('Unable to commit POS order: '.$this->db->last_error);
            return $id;
        } catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
    }
    public function update(POSOrder $o): void {
        $data=['sync_status'=>$o->getSyncStatus()->value,'wc_order_id'=>$o->getWcOrderId()];
        if ($o->getSyncStatus() === SyncStatus::Synced) $data['synced_at']=current_time('mysql');
        $this->db->update($this->t(),$data,['id'=>$o->id]);
    }
    public function findById(int $id): ?POSOrder {
        $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A);
        if (!$r) return null;
        $o=$this->h($r); $o->setItems($this->loadItems($o->id)); return $o;
    }
    public function findByRef(string $ref): ?POSOrder {
        $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE order_ref=%s",$ref),ARRAY_A);
        if (!$r) return null;
        $o=$this->h($r); $o->setItems($this->loadItems($o->id)); return $o;
    }
    public function findPending(): array {
        $rows=$this->db->get_results("SELECT * FROM {$this->t()} WHERE sync_status IN ('pending','failed') ORDER BY created_at ASC LIMIT 50",ARRAY_A)?:[];
        return array_map(function($r){$o=$this->h($r);$o->setItems($this->loadItems($o->id));return $o;},$rows);
    }
    public function findBySession(int $sessionId): array {
        $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE session_id=%d ORDER BY created_at DESC",$sessionId),ARRAY_A)?:[];
        return array_map(fn($r)=>$this->h($r),$rows);
    }
    public function saveItems(int $orderId, array $items): void {
        foreach ($items as $item) {
            $ok=$this->db->insert($this->ti(),['order_id'=>$orderId,'product_id'=>$item['product_id'],'sku'=>$item['sku'],'product_name'=>$item['name'],'quantity'=>$item['quantity'],'unit_price_pkr'=>$item['unit_price_pkr'],'discount_pkr'=>$item['discount_pkr']??0,'total_pkr'=>max(0,$item['unit_price_pkr']*$item['quantity']-($item['discount_pkr']??0))]);
            if ($ok === false) throw new \RuntimeException('Unable to save POS order item: '.$this->db->last_error);
        }
    }
    private function loadItems(int $orderId): array {
        return $this->db->get_results($this->db->prepare("SELECT product_id,sku,product_name AS name,quantity,unit_price_pkr,discount_pkr FROM {$this->ti()} WHERE order_id=%d ORDER BY id ASC",$orderId),ARRAY_A)?:[];
    }
    private function h(array $r): POSOrder {
        return new POSOrder((int)$r['id'],(int)$r['session_id'],(int)$r['location_id'],$r['order_ref'],(int)$r['subtotal_pkr'],(int)$r['discount_pkr'],(int)$r['total_pkr'],$r['payment_method'],$r['payment_split']?json_decode($r['payment_split'],true):null,$r['customer_phone'],$r['customer_name'],(bool)$r['receipt_sent'],SyncStatus::from($r['sync_status']),$r['wc_order_id']?(int)$r['wc_order_id']:null,new \DateTimeImmutable($r['created_at']));
    }
}
