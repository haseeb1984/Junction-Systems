<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Repository;
use PkStore\Domain\Inventory\Entity\Location;
interface LocationRepositoryInterface {
    public function findAll(): array;
    public function findById(int $id): ?Location;
    public function findPOS(): array;
    public function findOnline(): ?Location;
    public function create(string $name, string $type, string $address, bool $isPos): int;
}
