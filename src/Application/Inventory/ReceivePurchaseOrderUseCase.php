<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Repository\PurchaseOrderRepositoryInterface;
use Psr\Log\LoggerInterface;
final class ReceivePurchaseOrderUseCase {
    public function __construct(private readonly PurchaseOrderRepositoryInterface $orders, private readonly LoggerInterface $log) {}
    public function execute(int $purchaseOrderId, array $items, string $receiptReference): void {
        if ($purchaseOrderId <= 0 || trim($receiptReference) === '' || strlen(trim($receiptReference)) > 64) {
            throw new \InvalidArgumentException('Purchase order and stable receipt reference are required.');
        }
        if (!$items) throw new \InvalidArgumentException('At least one received item is required.');
        $clean = [];
        $seen = [];
        foreach ($items as $item) {
            $lineId = filter_var($item['item_id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$lineId || !$qty || $lineId < 1 || $qty < 1 || isset($seen[$lineId])) {
                throw new \InvalidArgumentException('Receipt lines require unique item IDs and positive integer quantities.');
            }
            $seen[$lineId] = true;
            $clean[] = ['item_id' => $lineId, 'quantity' => $qty];
        }
        $reference = trim($receiptReference);
        $this->orders->receive($purchaseOrderId, $clean, $reference, get_current_user_id());
        $this->log->info('Purchase order receipt processed.', ['purchase_order_id' => $purchaseOrderId, 'receipt_reference' => $reference]);
    }
}
