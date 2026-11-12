<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\AI;
use JunctionStore\Domain\AI\ProductRepositoryInterface;
use JunctionStore\Domain\AI\ValueObject\ProductContext;

final class WcProductRepository implements ProductRepositoryInterface {
    private bool $isLoading = false;
    public function __construct(private readonly \wpdb $db) {}

    public function findByKeywords(array $keywords, int $limit = 5): array {
        if (empty($keywords)) return [];
        $likes = array_map(fn($kw) => $this->db->prepare(
            "(p.post_title LIKE %s OR p.post_excerpt LIKE %s)",
            '%'.$this->db->esc_like($kw).'%',
            '%'.$this->db->esc_like($kw).'%'
        ), $keywords);
        $where = implode(' OR ', $likes);
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT DISTINCT p.ID FROM {$this->db->posts} p WHERE p.post_type='product' AND p.post_status='publish' AND ({$where}) LIMIT %d",
            $limit
        ));
        return $this->hydrateProducts((array)$ids);
    }

    public function findByCategory(string $categorySlug, int $limit = 5): array {
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT DISTINCT p.ID FROM {$this->db->posts} p
             INNER JOIN {$this->db->term_relationships} tr ON p.ID=tr.object_id
             INNER JOIN {$this->db->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
             INNER JOIN {$this->db->terms} t ON tt.term_id=t.term_id
             WHERE p.post_type='product' AND p.post_status='publish'
             AND tt.taxonomy='product_cat' AND t.slug=%s LIMIT %d",
            $categorySlug, $limit
        ));
        return $this->hydrateProducts((array)$ids);
    }

    public function findBySku(string $sku): ?ProductContext {
        $id = $this->db->get_var($this->db->prepare(
            "SELECT post_id FROM {$this->db->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1", $sku
        ));
        if (!$id) return null;
        $r = $this->hydrateProducts([(int)$id]);
        return $r[0] ?? null;
    }

    public function getStoreMetadata(): array {
        return [
            'store_name'    => get_bloginfo('name'),
            'currency'      => 'PKR',
            'cod_available' => true,
            'cities_served' => 'All major cities in Pakistan',
            'delivery_time' => '2-5 business days (physical products)',
            'digital_delivery' => 'Instant download link via email',
            'return_policy' => '7-day return on defective physical items. No refunds on digital downloads unless file is corrupt.',
            'helpline'      => get_option('junction_store_store_helpline',''),
            'whatsapp'      => get_option('junction_store_store_whatsapp',''),
        ];
    }

    private function hydrateProducts(array $ids): array {
        if (empty($ids) || $this->isLoading || !function_exists('wc_get_product')) return [];
        $this->isLoading = true;
        try {
            $contexts = [];
            foreach ($ids as $id) {
                $product = wc_get_product((int)$id);
                if (!($product instanceof \WC_Product) || !$product->is_visible()) continue;
                $contexts[] = $this->mapToContext($product);
            }
            return $contexts;
        } finally {
            $this->isLoading = false;
        }
    }

    private function mapToContext(\WC_Product $product): ProductContext
    {
        $isDigital = $product->is_downloadable() || $product->is_virtual();

        // FIX 3: Digital products never go out of stock
        // Show 'in_stock' with quantity 999 so AI never says "out of stock"
        $stockStatus = $isDigital ? 'in_stock'       : $product->get_stock_status();
        $stockQty    = $isDigital ? 999               : (int)($product->get_stock_quantity() ?? 0);

        // Build description — append delivery type for clarity
        $description = $this->sanitizeDesc($product->get_short_description());
        if ($isDigital && !str_contains(strtolower($description), 'download')) {
            $description .= ' [Instant digital download]';
        }

        return new ProductContext(
            sku:              $product->get_sku() ?: 'N/A',
            name:             $product->get_name(),
            pricePkr:         (int)round((float)$product->get_regular_price()),
            salePricePkr:     $product->is_on_sale() ? (int)round((float)$product->get_sale_price()) : null,
            stockStatus:      $stockStatus,
            stockQuantity:    $stockQty,
            shortDescription: $description,
            categoryNames:    implode(', ', wp_get_post_terms($product->get_id(),'product_cat',['fields'=>'names']) ?: []),
            attributes:       $this->extractAttrs($product),
            isFeatured:       $product->is_featured(),
            brand:            $product->get_attribute('brand') ?: null,
        );
    }

    private function sanitizeDesc(string $raw): string {
        $clean = strip_shortcodes($raw);
        $clean = html_entity_decode($clean, ENT_QUOTES|ENT_HTML5, 'UTF-8');
        $clean = wp_strip_all_tags($clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim(mb_substr($clean, 0, 300));
    }

    private function extractAttrs(\WC_Product $product): array {
        $attrs = [];
        foreach ($product->get_attributes() as $attr) {
            if (!($attr instanceof \WC_Product_Attribute)) continue;
            $name   = wc_attribute_label($attr->get_name());
            $values = $attr->get_options();
            if (empty($values)) continue;
            if ($attr->is_taxonomy()) {
                $values = array_filter(array_map(fn($tid) => get_term($tid)?->name ?? '', $values));
            }
            $attrs[$name] = implode(', ', $values);
        }
        return $attrs;
    }
}
