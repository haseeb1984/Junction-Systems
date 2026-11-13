<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Entity;
final readonly class Location {
    public function __construct(
        public readonly int $id, public readonly string $name, public readonly string $type,
        public readonly string $address, public readonly bool $isPos,
        public readonly bool $isOnline, public readonly bool $isActive,
    ) {}
    public function isPhysical(): bool { return $this->type === 'physical'; }
}
