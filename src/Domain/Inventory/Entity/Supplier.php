<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Inventory\Entity;
final readonly class Supplier {
    public function __construct(
        public readonly int $id, public readonly string $name, public readonly string $contact,
        public readonly string $phone, public readonly string $email,
        public readonly string $address, public readonly bool $isActive,
    ) {}
    public static function create(string $name, string $phone, string $email, string $address, string $contact = ''): self {
        return new self(0, $name, $contact, $phone, $email, $address, true);
    }
}
