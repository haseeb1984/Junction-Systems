<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Entity;
use DateTimeImmutable;
enum SyncStatus: string { case Pending='pending'; case Synced='synced'; case Failed='failed'; }
final class POSOrder {
    private array $items = [];
    public function __construct(
        public readonly int $id, public readonly int $sessionId,
        public readonly int $locationId, public readonly string $orderRef,
        public readonly int $subtotalPkr, public readonly int $discountPkr,
        public readonly int $totalPkr, public readonly string $paymentMethod,
        public readonly ?array $paymentSplit, public readonly string $customerPhone,
        public readonly string $customerName, public readonly bool $receiptSent,
        private SyncStatus $syncStatus, private ?int $wcOrderId,
        public readonly DateTimeImmutable $createdAt,
    ) {}
    public function getItems(): array { return $this->items; }
    public function setItems(array $items): void { $this->items = $items; }
    public function getWcOrderId(): ?int { return $this->wcOrderId; }
    public function getSyncStatus(): SyncStatus { return $this->syncStatus; }
    public function markSynced(?int $wcOrderId = null): void {
        $this->syncStatus = SyncStatus::Synced;
        if ($wcOrderId !== null) $this->wcOrderId = $wcOrderId;
    }
    public function markFailed(): void { $this->syncStatus = SyncStatus::Failed; }
    public static function create(int $sessionId, int $locationId, array $items, string $paymentMethod, string $customerPhone = '', string $customerName = 'Walk-in', int $discountPkr = 0, ?array $paymentSplit = null): self {
        if ($discountPkr < 0) throw new \InvalidArgumentException('Order discount cannot be negative.');
        $subtotal = 0;
        $lineDiscountTotal = 0;
        foreach ($items as $item) {
            $quantity = (int)($item['quantity'] ?? 0);
            $unitPrice = (int)($item['unit_price_pkr'] ?? 0);
            $lineDiscount = (int)($item['discount_pkr'] ?? 0);
            if ($quantity <= 0 || $unitPrice < 0 || $lineDiscount < 0) {
                throw new \InvalidArgumentException('POS item quantity, price or discount is invalid.');
            }
            $lineGross = $unitPrice * $quantity;
            if ($lineDiscount > $lineGross) throw new \InvalidArgumentException('Line discount cannot exceed its line amount.');
            $subtotal += $lineGross;
            $lineDiscountTotal += $lineDiscount;
        }
        if ($discountPkr > ($subtotal - $lineDiscountTotal)) {
            throw new \InvalidArgumentException('Order discount cannot exceed the remaining sale amount.');
        }
        $total = $subtotal - $lineDiscountTotal - $discountPkr;
        $ref = 'POS-' . date('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $order = new self(0, $sessionId, $locationId, $ref, $subtotal, $discountPkr + $lineDiscountTotal, $total, $paymentMethod, $paymentSplit, $customerPhone, $customerName, false, SyncStatus::Pending, null, new DateTimeImmutable());
        $order->items = $items;
        return $order;
    }
}
