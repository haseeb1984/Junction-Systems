<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\Location;
use PkStore\Domain\Inventory\Repository\LocationRepositoryInterface;
final class WpLocationRepository implements LocationRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_inventory_locations'; }
    public function findAll(): array { $r=$this->db->get_results("SELECT * FROM {$this->t()} WHERE is_active=1 ORDER BY name",ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$r); }
    public function findById(int $id): ?Location { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A); return $r?$this->h($r):null; }
    public function findPOS(): array { $r=$this->db->get_results("SELECT * FROM {$this->t()} WHERE is_pos=1 AND is_active=1",ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$r); }
    public function findOnline(): ?Location { $r=$this->db->get_row("SELECT * FROM {$this->t()} WHERE is_online=1 AND is_active=1 LIMIT 1",ARRAY_A); return $r?$this->h($r):null; }
    public function create(string $name, string $type, string $address, bool $isPos): int {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || strlen($address) > 255
            || !in_array($type, ['physical', 'warehouse'], true)
            || ($isPos && $type !== 'physical')) {
            throw new \InvalidArgumentException('Inventory location details are invalid.');
        }

        $existing = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t()} WHERE name=%s AND is_active=1 LIMIT 1",
            $name
        ));
        if ($this->db->last_error !== '') {
            throw new \RuntimeException('Unable to verify inventory location name.');
        }
        if ($existing !== null) {
            throw new \DomainException('An active inventory location already uses this name.');
        }

        $inserted = $this->db->insert($this->t(), [
            'name' => $name,
            'type' => $type,
            'address' => $address,
            'is_pos' => $isPos ? 1 : 0,
            'is_online' => 0,
            'is_active' => 1,
            'created_at' => current_time('mysql'),
        ]);
        if ($inserted !== 1 || (int) $this->db->insert_id < 1) {
            throw new \RuntimeException('Unable to create inventory location.');
        }
        return (int) $this->db->insert_id;
    }

    private function h(array $r): Location { return new Location((int)$r['id'],$r['name'],$r['type'],$r['address'],(bool)$r['is_pos'],(bool)$r['is_online'],(bool)$r['is_active']); }
}
