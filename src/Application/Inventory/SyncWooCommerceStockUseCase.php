<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface, LocationRepositoryInterface};
use Psr\Log\LoggerInterface;
final class SyncWooCommerceStockUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly StockRepositoryInterface $stockRepo,
        private readonly LocationRepositoryInterface $locationRepo,
        private readonly AdjustStockUseCase $adjustStock,
        private readonly LoggerInterface $log,
    ) {}
    public function onWooCommerceSale(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) return;
        $onlineLocation = $this->locationRepo->findOnline();
        if (!$onlineLocation) return;
        foreach ($order->get_items() as $item) {
            $wcProductId = (int)$item->get_product_id();
            $quantity    = (int)$item->get_quantity();
            $product     = $this->productRepo->findByWcProductId($wcProductId);
            if (!$product) continue;
            try {
                $this->adjustStock->execute($product->id, $onlineLocation->id, -$quantity, MovementType::OnlineSale, "WooCommerce order #{$orderId}", 'wc_order', (string)$orderId);
            } catch (\DomainException $e) {
                $this->log->error('WC stock sync failed.', ['product_id'=>$product->id,'error'=>$e->getMessage()]);
            }
        }
    }
    public function syncAllToWooCommerce(): int {
        $products = $this->productRepo->findAll(500, 0);
        $synced   = 0;
        foreach ($products as $product) {
            if (!$product->wcProductId) continue;
            $totalStock = $this->stockRepo->getTotalStock($product->id);
            $wcProduct  = wc_get_product($product->wcProductId);
            if (!$wcProduct) continue;
            $wcProduct->set_manage_stock(true);
            $wcProduct->set_stock_quantity($totalStock);
            $wcProduct->set_stock_status($totalStock > 0 ? 'instock' : 'outofstock');
            $wcProduct->save();
            $synced++;
        }
        return $synced;
    }
}
