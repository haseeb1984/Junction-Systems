<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Inventory;
use PkStore\Domain\Inventory\Entity\InventoryProduct;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
final class WpInventoryProductRepository implements InventoryProductRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_inventory_products'; }
    public function save(InventoryProduct $p): int {
        $now = current_time('mysql');
        $inserted = $this->db->insert($this->t(), ['wc_product_id'=>$p->wcProductId,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'barcode_type'=>$p->barcodeType,'name'=>$p->name,'category'=>$p->category,'cost_price_pkr'=>$p->costPricePkr,'sale_price_pkr'=>$p->salePricePkr,'reorder_point'=>$p->reorderPoint,'unit'=>$p->unit,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
        if ($inserted === false || (int) $this->db->insert_id < 1) {
            throw new \RuntimeException('Unable to save inventory product.');
        }
        return (int) $this->db->insert_id;
    }
    public function update(InventoryProduct $p): void {
        $updated = $this->db->update($this->t(), ['barcode'=>$p->getBarcode(),'name'=>$p->name,'category'=>$p->category,'cost_price_pkr'=>$p->costPricePkr,'sale_price_pkr'=>$p->salePricePkr,'reorder_point'=>$p->reorderPoint,'updated_at'=>current_time('mysql')], ['id'=>$p->id]);
        if ($updated === false) {
            throw new \RuntimeException('Unable to update inventory product.');
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
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE barcode=%s", $barcode), ARRAY_A);
        if ($row === null && $this->db->last_error !== '') {
            throw new \RuntimeException('Unable to check inventory barcode.');
        }
        return $this->h($row);
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
            throw new \\RuntimeException('Unable to list inventory products.');
        }
        return array_map(fn($r) => $this->h($r), $rows ?: []);
    }
    public function findBelowReorderPoint(): array {
        $rows = $this->db->get_results(
            "SELECT p.*,SUM(s.quantity) as total_stock FROM {$this->t()} p LEFT JOIN {$this->db->prefix}pkstore_inventory_stock s ON p.id=s.product_id WHERE p.is_active=1 GROUP BY p.id HAVING total_stock<=p.reorder_point ORDER BY total_stock ASC",
            ARRAY_A
        );
        if ($rows === null && $this->db->last_error !== '') {
            throw new \\RuntimeException('Unable to list inventory products below reorder point.');
        }
        return array_map(fn($r) => $this->h($r), $rows ?: []);
    }
    public function countAll(): int {
        $count = $this->db->get_var("SELECT COUNT(*) FROM {$this->t()} WHERE is_active=1");
        if ($count === null && $this->db->last_error !== '') {
            throw new \\RuntimeException('Unable to count inventory products.');
        }
        return (int) $count;
    }
    private function h(?array $r): ?InventoryProduct {
        if (!$r) return null;
        return new InventoryProduct((int)$r['id'],$r['wc_product_id']?(int)$r['wc_product_id']:null,$r['sku'],$r['barcode'],$r['barcode_type'],$r['name'],$r['category'],(int)$r['cost_price_pkr'],(int)$r['sale_price_pkr'],(int)$r['reorder_point'],$r['unit'],(bool)$r['is_active'],new \DateTimeImmutable($r['created_at']));
    }
}
