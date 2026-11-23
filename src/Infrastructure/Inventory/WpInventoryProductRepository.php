<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\InventoryProduct;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
final class WpInventoryProductRepository implements InventoryProductRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_inventory_products'; }
    public function save(InventoryProduct $p): int {
        return $this->withWooCommerceMappingLock($p->wcProductId, function () use ($p): int {
            if ($p->wcProductId !== null && $p->wcProductId > 0) {
                try {
                    $existingMapping = $this->findByWcProductId($p->wcProductId);
                } catch (\RuntimeException $error) {
                    if ($error->getMessage() === 'Multiple Junction inventory products are mapped to the same WooCommerce product.') {
                        throw new \DomainException('Existing WooCommerce product mapping is ambiguous; reconcile duplicates before creating another mapping.');
                    }
                    throw $error;
                }
                if ($existingMapping) {
                    throw new \DomainException('This WooCommerce product is already mapped to Junction inventory.');
                }
            }

            return $this->withBarcodeLock($p->getBarcode(), 0, function () use ($p): int {
                $now = current_time('mysql');
                $inserted = $this->db->insert($this->t(), [
                    'wc_product_id'=>$p->wcProductId,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),
                    'barcode_type'=>$p->barcodeType,'name'=>$p->name,'category'=>$p->category,
                    'cost_price_pkr'=>$p->costPricePkr,'sale_price_pkr'=>$p->salePricePkr,
                    'reorder_point'=>$p->reorderPoint,'unit'=>$p->unit,'is_active'=>1,
                    'created_at'=>$now,'updated_at'=>$now
                ]);
                if ($inserted === false) {
                    if (stripos((string) $this->db->last_error, 'Duplicate entry') !== false) {
                        throw new \DomainException('An inventory product with this SKU or WooCommerce mapping already exists.');
                    }
                    throw new \RuntimeException('Unable to save inventory product.');
                }
                if ((int) $this->db->insert_id < 1) {
                    throw new \RuntimeException('Unable to save inventory product.');
                }
                return (int) $this->db->insert_id;
            });
        });
    }
    public function update(InventoryProduct $p): void {
        $this->withBarcodeLock($p->getBarcode(), $p->id, function () use ($p): void {
            $updated = $this->db->update($this->t(), [
                'barcode'=>$p->getBarcode(),'name'=>$p->name,'category'=>$p->category,
                'cost_price_pkr'=>$p->costPricePkr,'sale_price_pkr'=>$p->salePricePkr,
                'reorder_point'=>$p->reorderPoint,'updated_at'=>current_time('mysql')
            ], ['id'=>$p->id]);
            if ($updated === false) {
                throw new \RuntimeException('Unable to update inventory product.');
            }
        });
    }
    private function withWooCommerceMappingLock(?int $wcProductId, callable $callback): mixed {
        if ($wcProductId === null || $wcProductId < 1) return $callback();
        $lockName = 'pkstore_inv_wc_map_' . $wcProductId;
        $locked = $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
        if ($this->db->last_error !== '' || (int) $locked !== 1) {
            throw new \RuntimeException('Unable to reserve this WooCommerce product mapping; retry the operation.');
        }
        try {
            return $callback();
        } finally {
            $released = $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            if ($this->db->last_error !== '' || (int) $released !== 1) {
                error_log('[PK Store] WooCommerce inventory mapping lock release could not be confirmed.');
            }
        }
    }

    private function withBarcodeLock(string $barcode, int $exceptProductId, callable $callback): mixed {
        if ($barcode === '') return $callback();
        $lockName = 'pkstore_inv_barcode_' . substr(hash('sha256', $barcode), 0, 32);
        $locked = $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
        if ($this->db->last_error !== '' || (int) $locked !== 1) {
            throw new \RuntimeException('Unable to reserve this inventory barcode; retry the operation.');
        }
        try {
            $existing = $this->findByBarcode($barcode);
            if ($existing && $existing->id !== $exceptProductId) {
                throw new \DomainException('Barcode already exists.');
            }
            return $callback();
        } finally {
            $released = $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            if ($this->db->last_error !== '' || (int) $released !== 1) {
                error_log('[PK Store] Inventory barcode reservation lock release could not be confirmed.');
            }
        }
    }
    public function findById(int $id): ?InventoryProduct {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d", $id), ARRAY_A);
        if ($row === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to read inventory product.');
        }
        return $this->h($row);
    }
    public function findBySku(string $sku): ?InventoryProduct {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE sku=%s", $sku), ARRAY_A);
        if ($row === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to check inventory SKU.');
        }
        return $this->h($row);
    }
    public function findByBarcode(string $barcode): ?InventoryProduct {
        if ($barcode === '') return null;
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t()} WHERE barcode=%s ORDER BY id ASC LIMIT 2",
            $barcode
        ), ARRAY_A);
        if ($rows === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to check inventory barcode.');
        }
        if (count($rows ?: []) > 1) {
            throw new \DomainException('Multiple Junction inventory products share the same barcode.');
        }
        return $this->h($rows[0] ?? null);
    }
    public function findByWcProductId(int $wcId): ?InventoryProduct {
        if ($wcId < 1) throw new \InvalidArgumentException('WooCommerce product ID must be positive.');
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t()} WHERE wc_product_id=%d ORDER BY id ASC LIMIT 2",
            $wcId
        ), ARRAY_A);
        if ($rows === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to resolve WooCommerce inventory product mapping.');
        }
        if (count($rows ?: []) > 1) {
            throw new \RuntimeException('Multiple Junction inventory products are mapped to the same WooCommerce product.');
        }
        return $this->h($rows[0] ?? null);
    }
    public function findAll(int $limit=50, int $offset=0, string $search=''): array {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        if ($search !== '') {
            $like = '%' . $this->db->esc_like($search) . '%';
            $query = $this->db->prepare(
                "SELECT * FROM {$this->t()} WHERE is_active=1 AND (name LIKE %s OR sku LIKE %s OR barcode LIKE %s) ORDER BY name LIMIT %d OFFSET %d",
                $like, $like, $like, $limit, $offset
            );
        } else {
            $query = $this->db->prepare(
                "SELECT * FROM {$this->t()} WHERE is_active=1 ORDER BY name LIMIT %d OFFSET %d",
                $limit, $offset
            );
        }
        $rows = $this->db->get_results($query, ARRAY_A);
        if ($rows === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to list inventory products.');
        }
        return array_map(fn($r) => $this->h($r), $rows ?: []);
    }
    public function findBelowReorderPoint(): array {
        $rows = $this->db->get_results(
            "SELECT p.*,SUM(s.quantity) as total_stock FROM {$this->t()} p LEFT JOIN {$this->db->prefix}pkstore_inventory_stock s ON p.id=s.product_id WHERE p.is_active=1 GROUP BY p.id HAVING total_stock<=p.reorder_point ORDER BY total_stock ASC",
            ARRAY_A
        );
        if ($rows === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to list inventory products below reorder point.');
        }
        return array_map(fn($r) => $this->h($r), $rows ?: []);
    }
    public function countAll(): int {
        $count = $this->db->get_var("SELECT COUNT(*) FROM {$this->t()} WHERE is_active=1");
        if ($count === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to count inventory products.');
        }
        return (int) $count;
    }
    private function h(?array $r): ?InventoryProduct {
        if (!$r) return null;
        return new InventoryProduct((int)$r['id'],$r['wc_product_id']?(int)$r['wc_product_id']:null,$r['sku'],$r['barcode'],$r['barcode_type'],$r['name'],$r['category'],(int)$r['cost_price_pkr'],(int)$r['sale_price_pkr'],(int)$r['reorder_point'],$r['unit'],(bool)$r['is_active'],new \DateTimeImmutable($r['created_at']));
    }
}
