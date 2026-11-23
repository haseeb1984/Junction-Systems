<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, LocationRepositoryInterface, StockRepositoryInterface};
use Psr\Log\LoggerInterface;
final class TransferStockUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly LocationRepositoryInterface $locationRepo,
        private readonly StockRepositoryInterface $stockRepo,
        private readonly LoggerInterface $log,
    ) {}
    /** Returns the stable transfer reference; callers should retain it for safe retries. */
    public function execute(int $productId,int $fromLocationId,int $toLocationId,int $quantity,string $referenceId,string $note=''): string {
        if ($fromLocationId === $toLocationId) throw new \InvalidArgumentException('Source and destination locations must differ.');
        $product=$this->productRepo->findById($productId);
        if (!$product) throw new \RuntimeException('Inventory product not found.');
        $from=$this->locationRepo->findById($fromLocationId);
        $to=$this->locationRepo->findById($toLocationId);
        if (!$from || !$from->isActive || !$to || !$to->isActive) throw new \RuntimeException('Both transfer locations must exist and be active.');
        $referenceId=trim($referenceId);
        if($referenceId==='' || strlen($referenceId)>64) throw new \InvalidArgumentException('A stable transfer reference_id of 1-64 characters is required.');
        $this->stockRepo->transferStock($productId,$fromLocationId,$toLocationId,$quantity,$referenceId,$note,get_current_user_id());
        if ($product->wcProductId && function_exists('wc_get_product')) {
            try {
                $wcProduct=wc_get_product($product->wcProductId);
                if ($wcProduct) {
                    $total=$this->stockRepo->getTotalStock($productId);
                    $wcProduct->set_manage_stock(true);
                    $wcProduct->set_stock_quantity($total);
                    $wcProduct->set_stock_status($total>0?'instock':'outofstock');
                    if ($wcProduct->save() === false) throw new \\RuntimeException('WooCommerce rejected the stock projection save.');
                }
            } catch (\Throwable $e) {
                $this->log->error('Stock transfer committed but WooCommerce aggregate stock projection failed.',['product_id'=>$productId,'reference_id'=>$referenceId,'error'=>$e->getMessage()]);
            }
        }
        $this->log->info('Inventory stock transferred.',['product_id'=>$productId,'from_location_id'=>$fromLocationId,'to_location_id'=>$toLocationId,'quantity'=>$quantity,'reference_id'=>$referenceId]);
        return $referenceId;
    }
}
