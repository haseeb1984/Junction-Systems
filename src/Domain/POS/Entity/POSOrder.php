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
        private SyncStatus $syncStatus, public readonly ?int $wcOrderId,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $requestFingerprint = null,
    ) {}
    public function getItems(): array { return $this->items; }
    public function setItems(array $items): void { $this->items = $items; }
    public function getSyncStatus(): SyncStatus { return $this->syncStatus; }
    public function markSynced(?int $wcOrderId = null): void { $this->syncStatus = SyncStatus::Synced; }
    public function markFailed(): void { $this->syncStatus = SyncStatus::Failed; }
    public static function create(int $sessionId, int $locationId, array $items, string $paymentMethod, string $customerPhone = '', string $customerName = 'Walk-in', int $discountPkr = 0, ?array $paymentSplit = null, ?string $clientOrderRef = null, ?string $requestFingerprint = null): self {
        $subtotal = array_sum(array_map(fn($i) => $i['unit_price_pkr'] * $i['quantity'], $items));
        $total    = max(0, $subtotal - $discountPkr);
        $ref      = $clientOrderRef ?? ('POS-' . date('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)));
        $order    = new self(0, $sessionId, $locationId, $ref, $subtotal, $discountPkr, $total, $paymentMethod, $paymentSplit, $customerPhone, $customerName, false, SyncStatus::Pending, null, new DateTimeImmutable(), $requestFingerprint);
        $order->items = $items;
        return $order;
    }
}
