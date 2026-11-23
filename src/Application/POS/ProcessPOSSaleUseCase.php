<?php
declare(strict_types=1);
namespace PkStore\Application\POS;
use PkStore\Application\Inventory\AdjustStockUseCase;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\POS\Entity\POSOrder;
use PkStore\Domain\POS\Repository\{POSOrderRepositoryInterface, POSSessionRepositoryInterface};
use Psr\Log\LoggerInterface;
final class ProcessPOSSaleUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly POSSessionRepositoryInterface $sessionRepo,
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly AdjustStockUseCase $adjustStock,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(int $sessionId, int $locationId, array $items, string $paymentMethod, string $customerPhone = '', string $customerName = 'Walk-in', int $discountPkr = 0, ?array $paymentSplit = null): POSOrder {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session || !$session->isOpen()) throw new \RuntimeException('No open POS session found.');
        // Validate items and enrich with product data
        $enriched = [];
        foreach ($items as $item) {
            $product = $this->productRepo->findById((int)$item['product_id']);
            if (!$product) throw new \RuntimeException("Product [{$item['product_id']}] not found.");
            $enriched[] = ['product_id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name,'quantity'=>(int)$item['quantity'],'unit_price_pkr'=>(int)($item['unit_price_pkr'] ?? $product->salePricePkr),'discount_pkr'=>(int)($item['discount_pkr'] ?? 0)];
        }
        $order   = POSOrder::create($sessionId, $locationId, $enriched, $paymentMethod, $customerPhone, $customerName, $discountPkr, $paymentSplit);
        $orderId = $this->orderRepo->save($order);
        $this->orderRepo->saveItems($orderId, $enriched);
        // Deduct stock from physical location
        foreach ($enriched as $item) {
            try {
                $this->adjustStock->execute($item['product_id'], $locationId, -$item['quantity'], MovementType::Sale, "POS sale {$order->orderRef}", 'pos_order', $order->orderRef);
            } catch (\DomainException $e) {
                $this->log->warning('POS stock deduction failed — sold below zero.', ['product'=>$item['sku'],'error'=>$e->getMessage()]);
            }
        }
        // Update session totals
        $session->recordSale($order->totalPkr, $paymentMethod, $paymentSplit);
        $this->sessionRepo->update($session);
        $this->log->info('POS sale processed.', ['order_ref'=>$order->orderRef,'total'=>$order->totalPkr,'method'=>$paymentMethod]);
        return $this->orderRepo->findById($orderId);
    }
}
