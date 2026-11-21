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
        $synced = 0;
        foreach ($products as $product) {
            if (!$product->wcProductId) continue;
            // Keep the reconciliation retryable and use the same per-product lock
            // as POS, manual adjustments and the background queue worker.
            $this->stockRepo->enqueueWooCommerceSync($product->id);
            try {
                $this->adjustStock->syncProductToWooCommerce($product->id);
                $synced++;
            } catch (\Throwable $error) {
                $this->log->error('Hourly WooCommerce stock reconciliation remains queued.', [
                    'product_id' => $product->id,
                    'error_class' => get_class($error),
                ]);
            }
        }
        return $synced;
    }
}
