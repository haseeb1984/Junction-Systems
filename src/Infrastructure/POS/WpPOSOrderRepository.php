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
    public function processSaleAtomic(POSOrder $o, array $items): int {
        $sessionTable=$this->db->prefix.'pkstore_pos_sessions';
        $stockTable=$this->db->prefix.'pkstore_inventory_stock';
        $movementTable=$this->db->prefix.'pkstore_inventory_movements';
        if($this->db->query('START TRANSACTION')===false) throw new \RuntimeException('Unable to start POS checkout transaction: '.$this->db->last_error);
        try {
            $existingId=$this->existingSaleId($o,$items);
            if($existingId!==null) {
                if($this->db->query('COMMIT')===false) throw new \RuntimeException('Unable to finish POS retry check: '.$this->db->last_error);
                return $existingId;
            }
            $session=$this->db->get_row($this->db->prepare("SELECT id,location_id,status FROM {$sessionTable} WHERE id=%d FOR UPDATE",$o->sessionId),ARRAY_A);
            $existingId=$this->existingSaleId($o,$items);
            if($existingId!==null) {
                if($this->db->query('COMMIT')===false) throw new \RuntimeException('Unable to finish concurrent POS retry check: '.$this->db->last_error);
                return $existingId;
            }
            if(!$session || $session['status']!=='open') throw new \RuntimeException('POS session is closed or does not exist.');
            if((int)$session['location_id']!==$o->locationId) throw new \DomainException('POS sale location does not match its session location.');
            $amounts=['cash'=>0,'card'=>0,'jazzcash'=>0,'easypaisa'=>0];
            if($o->paymentSplit!==null) {
                foreach($o->paymentSplit as $method=>$amount) {
                    if(!array_key_exists((string)$method,$amounts) || (int)$amount<0) throw new \InvalidArgumentException('Invalid split payment entry.');
                    $amounts[$method]+=(int)$amount;
                }
                if(array_sum($amounts)!==$o->totalPkr) throw new \InvalidArgumentException('Split payment amounts must equal the sale total.');
            } else {
                if(!array_key_exists($o->paymentMethod,$amounts)) throw new \InvalidArgumentException('Unsupported POS payment method.');
                $amounts[$o->paymentMethod]=$o->totalPkr;
            }
            $deductions=[];
            foreach($items as $item) {
                $productId=(int)$item['product_id']; $quantity=(int)$item['quantity'];
                if($productId<=0 || $quantity<=0) throw new \InvalidArgumentException('POS stock deduction contains an invalid product or quantity.');
                $deductions[$productId]=($deductions[$productId]??0)+$quantity;
            }
            if(!$deductions) throw new \InvalidArgumentException('A POS sale must contain at least one product.');
            ksort($deductions,SORT_NUMERIC);
            foreach($deductions as $productId=>$quantity) {
                $this->db->query($this->db->prepare("INSERT IGNORE INTO {$stockTable} (product_id,location_id,quantity,updated_at) VALUES (%d,%d,0,%s)",$productId,$o->locationId,current_time('mysql')));
            }
            $locked=[];
            foreach($deductions as $productId=>$quantity) {
                $row=$this->db->get_row($this->db->prepare("SELECT id,quantity FROM {$stockTable} WHERE product_id=%d AND location_id=%d FOR UPDATE",$productId,$o->locationId),ARRAY_A);
                if(!$row) throw new \RuntimeException('Unable to lock POS stock row: '.$this->db->last_error);
                $before=(int)$row['quantity'];
                if($before<$quantity) throw new \DomainException("Insufficient stock for product {$productId}. Available: {$before}, Requested: {$quantity}.");
                $locked[$productId]=['row'=>$row,'before'=>$before,'after'=>$before-$quantity,'quantity'=>$quantity];
            }
            $orderId=$this->save($o);
            $this->saveItems($orderId,$items);
            foreach($locked as $productId=>$stock) {
                if($this->db->update($stockTable,['quantity'=>$stock['after'],'updated_at'=>current_time('mysql')],['id'=>(int)$stock['row']['id']])===false) throw new \RuntimeException('Unable to deduct POS stock: '.$this->db->last_error);
                $ok=$this->db->insert($movementTable,['product_id'=>$productId,'location_id'=>$o->locationId,'movement_type'=>'sale','quantity_delta'=>-$stock['quantity'],'quantity_before'=>$stock['before'],'quantity_after'=>$stock['after'],'reference_type'=>'pos_order','reference_id'=>$o->orderRef,'note'=>'POS sale '.$o->orderRef,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
                if($ok===false) throw new \RuntimeException('Unable to write POS stock movement: '.$this->db->last_error);
            }
            $sql=$this->db->prepare("UPDATE {$sessionTable} SET total_sales_pkr=total_sales_pkr+%d,total_cash_pkr=total_cash_pkr+%d,total_card_pkr=total_card_pkr+%d,total_jazzcash=total_jazzcash+%d,total_easypaisa=total_easypaisa+%d WHERE id=%d AND status='open'",
                $o->totalPkr,$amounts['cash'],$amounts['card'],$amounts['jazzcash'],$amounts['easypaisa'],$o->sessionId
            );
            $changed=$this->db->query($sql);
            if($changed===false) throw new \RuntimeException('Unable to update POS session totals: '.$this->db->last_error);
            if($changed===0) {
                $stillOpen=(int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$sessionTable} WHERE id=%d AND status='open'",$o->sessionId));
                if($stillOpen===0) throw new \RuntimeException('POS session was closed while processing the sale.');
            }
            if($this->db->query('COMMIT')===false) throw new \RuntimeException('Unable to commit POS checkout: '.$this->db->last_error);
            return $orderId;
        } catch(\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }
    private function existingSaleId(POSOrder $o,array $items): ?int {
        $existing=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE order_ref=%s",$o->orderRef),ARRAY_A);
        if(!$existing) return null;
        $same=(int)$existing['session_id']===$o->sessionId
            && (int)$existing['location_id']===$o->locationId
            && (int)$existing['subtotal_pkr']===$o->subtotalPkr
            && (int)$existing['discount_pkr']===$o->discountPkr
            && (int)$existing['total_pkr']===$o->totalPkr
            && $existing['payment_method']===$o->paymentMethod
            && $existing['customer_phone']===$o->customerPhone
            && $existing['customer_name']===$o->customerName;
        $storedSplit=$existing['payment_split']?json_decode($existing['payment_split'],true):null;
        $expectedSplit=$o->paymentSplit;
        if(is_array($storedSplit)) ksort($storedSplit);
        if(is_array($expectedSplit)) ksort($expectedSplit);
        $same=$same && $storedSplit===$expectedSplit;
        $storedItems=$this->loadItems((int)$existing['id']);
        $expectedItems=array_map(fn($item)=>[
            'product_id'=>(int)$item['product_id'],'sku'=>(string)$item['sku'],'name'=>(string)$item['name'],
            'quantity'=>(int)$item['quantity'],'unit_price_pkr'=>(int)$item['unit_price_pkr'],'discount_pkr'=>(int)($item['discount_pkr']??0)
        ],$items);
        $storedItems=array_map(fn($item)=>[
            'product_id'=>(int)$item['product_id'],'sku'=>(string)$item['sku'],'name'=>(string)$item['name'],
            'quantity'=>(int)$item['quantity'],'unit_price_pkr'=>(int)$item['unit_price_pkr'],'discount_pkr'=>(int)($item['discount_pkr']??0)
        ],$storedItems);
        if(!$same || $storedItems!==$expectedItems) throw new \DomainException('POS order reference was already used for a different sale payload.');
        return (int)$existing['id'];
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
