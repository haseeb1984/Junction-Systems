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
        $quantities = [];
        foreach ($order->get_items() as $item) {
            $wcProductId = (int)$item->get_product_id();
            $product = $this->productRepo->findByWcProductId($wcProductId);
            if (!$product) {
                $this->log->warning('WooCommerce product has no inventory mapping.', ['order_id'=>$orderId,'wc_product_id'=>$wcProductId]);
                continue;
            }
            $quantities[$product->id] = ($quantities[$product->id] ?? 0) + (int)$item->get_quantity();
        }
        foreach ($quantities as $productId => $quantity) {
            $marker = '_pkstore_inventory_synced_product_' . (int)$productId;
            if ($order->get_meta($marker)) continue;
            try {
                $this->adjustStock->execute((int)$productId, $onlineLocation->id, -$quantity, MovementType::OnlineSale, "WooCommerce order #{$orderId}", 'wc_order', (string)$orderId);
                $order->update_meta_data($marker, 1);
                $order->save();
            } catch (\DomainException $e) {
                $this->log->error('WC stock sync failed.', ['order_id'=>$orderId,'product_id'=>$productId,'error'=>$e->getMessage()]);
            }
        }
    }
    public function syncAllToWooCommerce(): int {
        $total = $this->productRepo->countAll();
        $synced = 0;
        for ($offset = 0; $offset < $total; $offset += 500) {
            foreach ($this->productRepo->findAll(500, $offset) as $product) {
                if (!$product->wcProductId) continue;
                $totalStock = $this->stockRepo->getTotalStock($product->id);
                $wcProduct = wc_get_product($product->wcProductId);
                if (!$wcProduct) continue;
                $wcProduct->set_manage_stock(true);
                $wcProduct->set_stock_quantity($totalStock);
                $wcProduct->set_stock_status($totalStock > 0 ? 'instock' : 'outofstock');
                if ($wcProduct->save() === false) throw new \\RuntimeException('WooCommerce rejected stock projection for inventory product '.$product->id.'.');
                $synced++;
            }
        }
        return $synced;
    }
}
