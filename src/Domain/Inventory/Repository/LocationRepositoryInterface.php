<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Inventory\Repository;
use JunctionStore\Domain\Inventory\Entity\Location;
interface LocationRepositoryInterface {
    public function findAll(): array;
    public function findById(int $id): ?Location;
    public function findPOS(): array;
    public function findOnline(): ?Location;
}
