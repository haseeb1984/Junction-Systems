<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Entity;
use DateTimeImmutable;
enum POStatus: string { case Draft='draft'; case Sent='sent'; case PartiallyReceived='partial'; case Received='received'; case Cancelled='cancelled'; }
final class PurchaseOrder {
    private array $items = [];
    public function __construct(
        public readonly int $id, public readonly string $poNumber,
        public readonly int $supplierId, public readonly int $locationId,
        private POStatus $status, public readonly int $totalPkr,
        public readonly ?DateTimeImmutable $receivedAt, public readonly string $notes,
        public readonly int $createdBy, public readonly DateTimeImmutable $createdAt,
    ) {}
    public function getStatus(): POStatus { return $this->status; }
    public function getItems(): array { return $this->items; }
    public function setItems(array $items): void { $this->items = $items; }
    public function markReceived(): void { $this->status = POStatus::Received; }
    public function markPartial(): void { $this->status = POStatus::PartiallyReceived; }
    public static function create(int $supplierId, int $locationId, string $notes = ''): self {
        $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return new self(0, $poNumber, $supplierId, $locationId, POStatus::Draft, 0, null, $notes, get_current_user_id(), new DateTimeImmutable());
    }
}
