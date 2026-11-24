<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\{POStatus,PurchaseOrder};
use PkStore\Domain\Inventory\Repository\{PurchaseOrderRepositoryInterface, StockRepositoryInterface};
final class WpPurchaseOrderRepository implements PurchaseOrderRepositoryInterface {
    public function __construct(private readonly \wpdb $db, private readonly StockRepositoryInterface $stock) {}
    private function t(): string { return $this->db->prefix.'pkstore_purchase_orders'; }
    private function ti(): string { return $this->db->prefix.'pkstore_purchase_order_items'; }
    private function ts(): string { return $this->db->prefix.'pkstore_inventory_stock'; }
    private function tm(): string { return $this->db->prefix.'pkstore_inventory_movements'; }
    private function tr(): string { return $this->db->prefix.'pkstore_purchase_receipts'; }
    private function tri(): string { return $this->db->prefix.'pkstore_purchase_receipt_items'; }
    public function supplierExists(int $supplierId): bool {
        return $supplierId>0 && (bool)$this->db->get_var($this->db->prepare("SELECT id FROM {$this->db->prefix}pkstore_inventory_suppliers WHERE id=%d AND is_active=1",$supplierId));
    }
    public function create(PurchaseOrder $order,array $items): int {
        $total=0;
        foreach($items as $item) {
            $quantity=(int)($item['quantity']??0); $unitCost=(int)($item['unit_cost_pkr']??-1);
            if($quantity<1 || $quantity>2147483647 || $unitCost<0 || $unitCost>2147483647
                || ($unitCost>0 && $quantity>intdiv(2147483647,$unitCost))) {
                throw new \InvalidArgumentException('Purchase order quantity or unit cost exceeds the supported database range.');
            }
            $lineTotal=$quantity*$unitCost;
            if($total>2147483647-$lineTotal) throw new \InvalidArgumentException('Purchase order total exceeds the supported PKR range.');
            $total+=$lineTotal;
        }
        if($this->db->query('START TRANSACTION')===false) throw new \RuntimeException('Unable to start purchase order transaction: '.$this->db->last_error);
        try {
            $ok=$this->db->insert($this->t(),['po_number'=>$order->poNumber,'supplier_id'=>$order->supplierId,'location_id'=>$order->locationId,'status'=>POStatus::Draft->value,'total_pkr'=>$total,'received_at'=>null,'notes'=>$order->notes,'created_by'=>$order->createdBy,'created_at'=>$order->createdAt->format('Y-m-d H:i:s')]);
            if($ok===false) throw new \RuntimeException('Unable to create purchase order: '.$this->db->last_error);
            $id=(int)$this->db->insert_id;
            foreach($items as $item) {
                $ok=$this->db->insert($this->ti(),['po_id'=>$id,'product_id'=>$item['product_id'],'quantity_ordered'=>$item['quantity'],'quantity_received'=>0,'unit_cost_pkr'=>$item['unit_cost_pkr']]);
                if($ok===false) throw new \RuntimeException('Unable to save purchase order item: '.$this->db->last_error);
            }
            if($this->db->query('COMMIT')===false) throw new \RuntimeException('Unable to commit purchase order: '.$this->db->last_error);
            return $id;
        } catch(\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
    }
    public function findById(int $id): ?PurchaseOrder {
        $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A);
        if(!$r) return null;
        $po=$this->hydrate($r);
        $po->setItems($this->db->get_results($this->db->prepare("SELECT id AS item_id,product_id,quantity_ordered,quantity_received,unit_cost_pkr FROM {$this->ti()} WHERE po_id=%d ORDER BY id",$id),ARRAY_A)?:[]);
        return $po;
    }
    public function findAll(int $limit=100): array {
        $limit=max(1,min(500,$limit));
        $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} ORDER BY created_at DESC LIMIT %d",$limit),ARRAY_A)?:[];
        return array_map(fn($r)=>$this->hydrate($r),$rows);
    }
    public function receive(int $purchaseOrderId,array $items,string $receiptReference,int $userId): void {
        if($purchaseOrderId<=0 || $receiptReference==='' || strlen($receiptReference)>64 || !$items) throw new \InvalidArgumentException('Invalid receipt identity.');
        if($this->db->query('START TRANSACTION')===false) throw new \RuntimeException('Unable to start receiving transaction: '.$this->db->last_error);
        try {
            $po=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d FOR UPDATE",$purchaseOrderId),ARRAY_A);
            if(!$po) throw new \RuntimeException('Purchase order not found.');
            $existing=$this->db->get_row($this->db->prepare("SELECT id,po_id FROM {$this->tr()} WHERE receipt_reference=%s FOR UPDATE",$receiptReference),ARRAY_A);
            if($existing) {
                if((int)$existing['po_id']!==$purchaseOrderId) throw new \DomainException('Receipt reference has already been used for another purchase order.');
                $stored=$this->db->get_results($this->db->prepare("SELECT item_id,quantity FROM {$this->tri()} WHERE receipt_id=%d ORDER BY item_id",(int)$existing['id']),ARRAY_A)?:[];
                $expected=$items; usort($expected,fn($a,$b)=>(int)$a['item_id']<=>(int)$b['item_id']);
                if(count($stored)!==count($expected)) throw new \DomainException('Receipt reference replay does not match the original receipt payload.');
                foreach($stored as $i=>$line) if((int)$line['item_id']!==(int)$expected[$i]['item_id'] || (int)$line['quantity']!==(int)$expected[$i]['quantity']) throw new \DomainException('Receipt reference replay does not match the original receipt payload.');
                if($this->db->query('COMMIT')===false) throw new \RuntimeException('Unable to finish duplicate receipt check: '.$this->db->last_error);
                return;
            }
            if($po['status']==='cancelled' || $po['status']==='received') throw new \DomainException('This purchase order cannot receive further stock.');
            $receiptLines=[];
            foreach($items as $line) {
                $itemId=(int)$line['item_id']; $qty=(int)$line['quantity'];
                if($itemId<=0 || $qty<=0 || isset($receiptLines[$itemId])) throw new \InvalidArgumentException('Receipt lines must have unique item IDs and positive quantities.');
                $item=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->ti()} WHERE id=%d AND po_id=%d FOR UPDATE",$itemId,$purchaseOrderId),ARRAY_A);
                if(!$item) throw new \InvalidArgumentException("Purchase order line {$itemId} does not exist.");
                $remaining=(int)$item['quantity_ordered']-(int)$item['quantity_received'];
                if($qty>$remaining) throw new \DomainException("Received quantity exceeds remaining quantity for purchase order line {$itemId}.");
                $receiptLines[$itemId]=['item'=>$item,'quantity'=>$qty];
            }
            // Lock and update shared stock rows in a stable product order.
            uasort($receiptLines, static fn(array $a, array $b): int => (int)$a['item']['product_id'] <=> (int)$b['item']['product_id']);
            $ok=$this->db->insert($this->tr(),['receipt_reference'=>$receiptReference,'po_id'=>$purchaseOrderId,'received_by'=>$userId,'received_at'=>current_time('mysql')]);
            if($ok===false) throw new \RuntimeException('Unable to create receipt record: '.$this->db->last_error);
            $receiptId=(int)$this->db->insert_id;
            foreach($receiptLines as $itemId=>$line) {
                $item=$line['item']; $qty=$line['quantity']; $productId=(int)$item['product_id']; $locationId=(int)$po['location_id'];
                $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->ts()} (product_id,location_id,quantity,updated_at) VALUES (%d,%d,0,%s)",$productId,$locationId,current_time('mysql')));
                $stock=$this->db->get_row($this->db->prepare("SELECT id,quantity FROM {$this->ts()} WHERE product_id=%d AND location_id=%d FOR UPDATE",$productId,$locationId),ARRAY_A);
                if(!$stock) throw new \RuntimeException('Unable to lock stock row for purchase receipt.');
                $before=(int)$stock['quantity']; if($before<0 || $before>2147483647-$qty) throw new \OverflowException('Received stock exceeds the supported inventory quantity range.'); $after=$before+$qty;
                if($this->db->update($this->ts(),['quantity'=>$after,'updated_at'=>current_time('mysql')],['id'=>(int)$stock['id']])===false) throw new \RuntimeException('Unable to update received stock: '.$this->db->last_error);
                if($this->db->update($this->ti(),['quantity_received'=>(int)$item['quantity_received']+$qty],['id'=>$itemId])===false) throw new \RuntimeException('Unable to update purchase order received quantity: '.$this->db->last_error);
                if($this->db->insert($this->tri(),['receipt_id'=>$receiptId,'item_id'=>$itemId,'quantity'=>$qty])===false) throw new \RuntimeException('Unable to save receipt line: '.$this->db->last_error);
                if($this->db->insert($this->tm(),['product_id'=>$productId,'location_id'=>$locationId,'movement_type'=>'purchase_receive','quantity_delta'=>$qty,'quantity_before'=>$before,'quantity_after'=>$after,'reference_type'=>'purchase_receipt','reference_id'=>$receiptReference,'note'=>'Purchase order '.$po['po_number'],'created_by'=>$userId,'created_at'=>current_time('mysql')])===false) throw new \RuntimeException('Unable to write stock movement ledger: '.$this->db->last_error);
                // Queue the combined WooCommerce projection in the same DB transaction.
                $this->stock->enqueueWooCommerceSync($productId);
            }
            $remaining=$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->ti()} WHERE po_id=%d AND quantity_received<quantity_ordered",$purchaseOrderId));
            if($this->db->last_error!=='' || !is_numeric($remaining)) throw new \RuntimeException('Unable to verify remaining purchase order quantities: '.$this->db->last_error);
            $status=(int)$remaining===0?'received':'partial';
            $data=['status'=>$status]; if($status==='received') $data['received_at']=current_time('mysql');
            if($this->db->update($this->t(),$data,['id'=>$purchaseOrderId])===false) throw new \RuntimeException('Unable to update purchase order status: '.$this->db->last_error);
            if($this->db->query('COMMIT')===false) throw new \RuntimeException('Unable to commit purchase receipt: '.$this->db->last_error);
        } catch(\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
    }
    private function hydrate(array $r): PurchaseOrder {
        return new PurchaseOrder((int)$r['id'],$r['po_number'],(int)$r['supplier_id'],(int)$r['location_id'],POStatus::from($r['status']),(int)$r['total_pkr'],$r['received_at']?new \DateTimeImmutable($r['received_at']):null,$r['notes'],(int)$r['created_by'],new \DateTimeImmutable($r['created_at']));
    }
}
