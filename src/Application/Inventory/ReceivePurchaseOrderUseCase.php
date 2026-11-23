<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface,PurchaseOrderRepositoryInterface,StockRepositoryInterface};
use Psr\Log\LoggerInterface;
final class ReceivePurchaseOrderUseCase {
    public function __construct(private readonly PurchaseOrderRepositoryInterface $orders,private readonly InventoryProductRepositoryInterface $products,private readonly StockRepositoryInterface $stock,private readonly LoggerInterface $log) {}
    public function execute(int $purchaseOrderId,array $items,string $receiptReference): void {
        if ($purchaseOrderId<=0 || trim($receiptReference)==='' || strlen(trim($receiptReference))>64) throw new \InvalidArgumentException('Purchase order and stable receipt reference are required.');
        if (!$items) throw new \InvalidArgumentException('At least one received item is required.');
        $clean=[]; $seen=[];
        foreach($items as $item) {
            $lineId=(int)($item['item_id']??0); $qty=(int)($item['quantity']??0);
            if($lineId<=0 || $qty<=0 || isset($seen[$lineId])) throw new \InvalidArgumentException('Receipt lines require unique item IDs and positive quantities.');
            $seen[$lineId]=true; $clean[]=['item_id'=>$lineId,'quantity'=>$qty];
        }
        $this->orders->receive($purchaseOrderId,$clean,trim($receiptReference),get_current_user_id());
        $po=$this->orders->findById($purchaseOrderId);
        if($po) foreach($po->getItems() as $item) {
            $product=$this->products->findById((int)$item['product_id']);
            if($product && $product->wcProductId && function_exists('wc_get_product')) {
                $wc=wc_get_product($product->wcProductId);
                if($wc) { $total=$this->stock->getTotalStock($product->id); $wc->set_manage_stock(true); $wc->set_stock_quantity($total); $wc->set_stock_status($total>0?'instock':'outofstock'); $wc->save(); }
            }
        }
        $this->log->info('Purchase order receipt processed.',['purchase_order_id'=>$purchaseOrderId,'receipt_reference'=>$receiptReference]);
    }
}
