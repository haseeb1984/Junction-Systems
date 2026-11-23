<?php
declare(strict_types=1);
namespace PkStore\Application\POS;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface,StockRepositoryInterface};
use PkStore\Domain\POS\Entity\POSOrder;
use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;
use Psr\Log\LoggerInterface;
final class ProcessPOSSaleUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly StockRepositoryInterface $stockRepo,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(int $sessionId,int $locationId,array $items,string $paymentMethod,string $customerPhone='',string $customerName='Walk-in',int $discountPkr=0,?array $paymentSplit=null,?string $orderRef=null): POSOrder {
        $enriched=[];
        foreach($items as $item) {
            $product=$this->productRepo->findById((int)($item['product_id']??0));
            if(!$product) throw new \RuntimeException('POS product not found.');
            $enriched[]=['product_id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name,'quantity'=>(int)($item['quantity']??0),'unit_price_pkr'=>(int)($item['unit_price_pkr']??$product->salePricePkr),'discount_pkr'=>(int)($item['discount_pkr']??0)];
        }
        $order=POSOrder::create($sessionId,$locationId,$enriched,$paymentMethod,$customerPhone,$customerName,$discountPkr,$paymentSplit,$orderRef);
        // Order, lines, stock ledger, stock balances and tender totals commit together.
        $orderId=$this->orderRepo->processSaleAtomic($order,$enriched);
        // WooCommerce is an external projection: sync only after the database transaction commits.
        $productIds=array_values(array_unique(array_map(fn($item)=>(int)$item['product_id'],$enriched)));
        foreach($productIds as $productId) {
            try {
                $product=$this->productRepo->findById($productId);
                if(!$product || !$product->wcProductId || !function_exists('wc_get_product')) continue;
                $wc=wc_get_product($product->wcProductId);
                if(!$wc) continue;
                $total=$this->stockRepo->getTotalStock($productId);
                $wc->set_manage_stock(true);
                $wc->set_stock_quantity($total);
                $wc->set_stock_status($total>0?'instock':'outofstock');
                $wc->save();
            } catch(\Throwable $e) {
                $this->log->error('POS stock committed but WooCommerce stock projection failed.',['product_id'=>$productId,'order_ref'=>$order->orderRef,'error'=>$e->getMessage()]);
            }
        }
        $this->log->info('POS sale processed atomically.',['order_ref'=>$order->orderRef,'total'=>$order->totalPkr,'method'=>$paymentMethod]);
        $savedOrder=$this->orderRepo->findById($orderId);
        if(!$savedOrder) throw new \RuntimeException('POS order was committed but could not be reloaded.');
        return $savedOrder;
    }
}
