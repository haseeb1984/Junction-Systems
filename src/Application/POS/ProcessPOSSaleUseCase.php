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
        if (!in_array($paymentMethod, ['cash', 'card', 'jazzcash', 'easypaisa'], true) || !empty($paymentSplit)) {
            throw new \\InvalidArgumentException('Unsupported POS payment method or split-tender payload.');
        }
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session || !$session->isOpen()) throw new \RuntimeException('No open POS session found.');
        if ($session->locationId !== $locationId) throw new \RuntimeException('POS session location does not match the sale location.');

        // Never trust prices or quantities supplied by the browser. Product price
        // is read from the server-side inventory record and quantities must be positive.
        $enriched = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['product_id'], $item['quantity'])) {
                throw new \InvalidArgumentException('Each POS item must include a product and quantity.');
            }
            $productId = filter_var($item['product_id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1) {
                throw new \InvalidArgumentException('POS item product and quantity are invalid.');
            }
            $product = $this->productRepo->findById($productId);
            if (!$product || !$product->isActive) {
                throw new \RuntimeException("Product [{$productId}] is unavailable.");
            }
            $enriched[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price_pkr' => $product->salePricePkr,
                'discount_pkr' => 0,
            ];
        }

        $subtotal = array_sum(array_map(
            static fn(array $item): int => $item['unit_price_pkr'] * $item['quantity'],
            $enriched
        ));
        if ($discountPkr < 0 || $discountPkr > $subtotal) {
            throw new \InvalidArgumentException('Sale discount must be between zero and the sale subtotal.');
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
        $session->recordSale($order->totalPkr, $paymentMethod);
        $this->sessionRepo->update($session);
        $this->log->info('POS sale processed.', ['order_ref'=>$order->orderRef,'total'=>$order->totalPkr,'method'=>$paymentMethod]);
        return $this->orderRepo->findById($orderId);
    }
}
