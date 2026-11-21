<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Entity;
use DateTimeImmutable;
final readonly class StockMovement {
    public function __construct(
        public readonly int $id, public readonly int $productId,
        public readonly int $locationId, public readonly MovementType $type,
        public readonly int $quantityDelta, public readonly int $quantityBefore,
        public readonly int $quantityAfter, public readonly string $referenceType,
        public readonly string $referenceId, public readonly string $note,
        public readonly ?int $createdBy, public readonly DateTimeImmutable $createdAt,
    ) {}
    public static function create(int $productId, int $locationId, MovementType $type, int $delta, int $before, string $refType = '', string $refId = '', string $note = '', ?int $userId = null): self {
        return new self(0, $productId, $locationId, $type, $delta, $before, $before + $delta, $refType, $refId, $note, $userId, new DateTimeImmutable());
    }
}
