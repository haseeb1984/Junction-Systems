<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\AI;

use PkStore\Domain\AI\ProductRepositoryInterface;
use PkStore\Domain\AI\ValueObject\ProductContext;

final class WcProductRepository implements ProductRepositoryInterface {
    private bool $isLoading = false;

    public function __construct(private readonly \wpdb $db) {}

    public function findByKeywords(array $keywords, int $limit = 12, ?float $maxPrice = null): array {
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn($keyword): string => is_scalar($keyword) ? trim((string) $keyword) : '',
            $keywords
        ))));
        if (!$keywords || !function_exists('wc_get_product')) return [];

        $limit = max(1, min($limit, 50));
        $postmeta = $this->db->postmeta;
        $relationships = $this->db->term_relationships;
        $taxonomy = $this->db->term_taxonomy;
        $terms = $this->db->terms;
        $lookup = $this->db->prefix . 'wc_product_meta_lookup';

        $conditions = [];
        $params = [];
        foreach (array_slice($keywords, 0, 10) as $keyword) {
            $like = '%' . $this->db->esc_like($keyword) . '%';
            $conditions[] = "(
                p.post_title LIKE %s
                OR parent.post_title LIKE %s
                OR parent.post_excerpt LIKE %s
                OR parent.post_content LIKE %s
                OR EXISTS (SELECT 1 FROM {$postmeta} sku WHERE sku.post_id=p.ID AND sku.meta_key='_sku' AND sku.meta_value LIKE %s)
                OR EXISTS (SELECT 1 FROM {$postmeta} parent_sku WHERE parent_sku.post_id=parent.ID AND parent_sku.meta_key='_sku' AND parent_sku.meta_value LIKE %s)
                OR EXISTS (SELECT 1 FROM {$postmeta} attr WHERE attr.post_id=p.ID AND LEFT(attr.meta_key,11)='_attribute_' AND attr.meta_value LIKE %s)
                OR EXISTS (
                    SELECT 1 FROM {$relationships} tr
                    INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                    INNER JOIN {$terms} t ON t.term_id=tt.term_id
                    WHERE tr.object_id=parent.ID
                    AND (tt.taxonomy='product_cat' OR LEFT(tt.taxonomy,3)='pa_')
                    AND t.name LIKE %s
                )
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $where = implode(' AND ', $conditions);
        $keywordParams = $params;
        $params = [];
        $priceFilter = '';
        if ($maxPrice !== null) {
            $priceFilter = ' AND lookup.min_price <= %f';
            $params[] = max(0, $maxPrice);
        }
        // SQL placeholder order is price filter, then keyword clauses, then limit.
        $params = array_merge($params, $keywordParams);

        $sql = "SELECT p.ID
            FROM {$this->db->posts} p
            INNER JOIN {$this->db->posts} parent
                ON parent.ID = CASE WHEN p.post_type='product_variation' THEN p.post_parent ELSE p.ID END
            INNER JOIN {$lookup} lookup ON lookup.product_id=p.ID
            WHERE p.post_type IN ('product','product_variation')
              AND p.post_status='publish'
              AND parent.post_type='product'
              AND parent.post_status='publish'
              AND parent.post_password=''
              AND p.post_password=''
              {$priceFilter}
              AND {$where}
            ORDER BY (p.post_type='product_variation') DESC, (lookup.stock_status='instock') DESC,
              CAST(COALESCE((SELECT sales.meta_value FROM {$postmeta} sales WHERE sales.post_id=parent.ID AND sales.meta_key='total_sales' LIMIT 1), '0') AS UNSIGNED) DESC,
              p.menu_order ASC, p.ID DESC
            LIMIT %d";
        $params[] = min($limit * 10, 200);
        $prepared = $this->db->prepare($sql, ...$params);
        $ids = $this->db->get_col($prepared);
        if ($this->db->last_error !== '') return [];

        return $this->hydrateProducts((array) $ids, $limit, $maxPrice, false, false);
    }

    public function findByCategory(string $slug, int $limit = 12, ?float $maxPrice = null): array {
        $limit = max(1, min($limit, 50));
        $lookup = $this->db->prefix . 'wc_product_meta_lookup';
        $priceFilter = '';
        $params = [$slug];
        if ($maxPrice !== null) {
            $priceFilter = ' AND lookup.min_price <= %f';
            $params[] = max(0, $maxPrice);
        }
        $params[] = $limit;

        $sql = "SELECT DISTINCT p.ID
            FROM {$this->db->posts} p
            INNER JOIN {$this->db->term_relationships} tr ON p.ID=tr.object_id
            INNER JOIN {$this->db->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
            INNER JOIN {$this->db->terms} t ON tt.term_id=t.term_id
            INNER JOIN {$lookup} lookup ON lookup.product_id=p.ID
            WHERE p.post_type='product' AND p.post_status='publish' AND p.post_password=''
              AND tt.taxonomy='product_cat' AND t.slug=%s
              AND lookup.stock_status IN ('instock','onbackorder')
              {$priceFilter}
            ORDER BY CAST(COALESCE((SELECT sales.meta_value FROM {$this->db->postmeta} sales WHERE sales.post_id=p.ID AND sales.meta_key='total_sales' LIMIT 1), '0') AS UNSIGNED) DESC,
              p.menu_order ASC, p.ID DESC
            LIMIT %d";
        $ids = $this->db->get_col($this->db->prepare($sql, ...$params));
        if ($this->db->last_error !== '') return [];

        return $this->hydrateProducts((array) $ids, $limit, $maxPrice, true, true);
    }

    public function findPopular(int $limit = 12, ?float $maxPrice = null): array {
        $limit = max(1, min($limit, 50));
        $lookup = $this->db->prefix . 'wc_product_meta_lookup';
        $priceFilter = '';
        $params = [];
        if ($maxPrice !== null) {
            $priceFilter = ' AND lookup.min_price <= %f';
            $params[] = max(0, $maxPrice);
        }
        $params[] = $limit;

        $sql = "SELECT p.ID
            FROM {$this->db->posts} p
            INNER JOIN {$lookup} lookup ON lookup.product_id=p.ID
            WHERE p.post_type='product' AND p.post_status='publish' AND p.post_password=''
              AND lookup.stock_status IN ('instock','onbackorder')
              {$priceFilter}
            ORDER BY CAST(COALESCE((SELECT sales.meta_value FROM {$this->db->postmeta} sales WHERE sales.post_id=p.ID AND sales.meta_key='total_sales' LIMIT 1), '0') AS UNSIGNED) DESC,
              p.menu_order ASC, p.ID DESC
            LIMIT %d";
        $ids = $this->db->get_col($this->db->prepare($sql, ...$params));
        if ($this->db->last_error !== '') return [];

        return $this->hydrateProducts((array) $ids, $limit, $maxPrice, true, true);
    }

    public function findBySku(string $sku, ?float $maxPrice = null): ?ProductContext {
        $sku = trim($sku);
        if ($sku === '' || !function_exists('wc_get_product')) return null;

        $id = function_exists('wc_get_product_id_by_sku')
            ? wc_get_product_id_by_sku($sku)
            : $this->db->get_var($this->db->prepare(
                "SELECT post_id FROM {$this->db->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1",
                $sku
            ));
        if (!$id) return null;

        $product = wc_get_product((int) $id);
        if (!($product instanceof \WC_Product) || !$product->is_visible()) return null;

        if ($product->is_type('variable')) {
            $context = $this->mapToContext($product);
        } else {
            $context = $this->mapToContext($product);
        }

        if ($maxPrice !== null && $this->effectivePrice($context) > $maxPrice) return null;
        return $context;
    }

    public function getStoreMetadata(): array {
        $metadata = [
            'store_name' => get_bloginfo('name'),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'PKR',
            'stock_quantity_visible' => $this->stockQuantityVisible(),
            'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
        ];

        $codAvailable = false;
        if (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $codAvailable = isset($gateways['cod']) && ($gateways['cod']->enabled ?? 'no') === 'yes';
        }
        $metadata['cod_available'] = $codAvailable;

        $options = [
            'cities_served' => 'pk_store_cities_served',
            'delivery_time' => 'pk_store_delivery_time',
            'digital_delivery' => 'pk_store_digital_delivery',
            'return_policy' => 'pk_store_return_policy',
            'helpline' => 'pk_store_store_helpline',
            'whatsapp' => 'pk_store_store_whatsapp',
            'support_hours' => 'pk_store_support_hours',
        ];
        foreach ($options as $field => $option) {
            $value = get_option($option, '');
            if (is_string($value) && trim($value) !== '') {
                $metadata[$field] = wp_strip_all_tags($value);
            }
        }

        return $metadata;
    }

    private function hydrateProducts(
        array $ids,
        int $limit,
        ?float $maxPrice,
        bool $inStockOnly,
        bool $expandVariables
    ): array {
        if (!$ids || $this->isLoading || !function_exists('wc_get_product')) return [];
        $this->isLoading = true;

        try {
            $objects = [];
            $matchedVariationParents = [];
            foreach ($ids as $id) {
                $product = wc_get_product((int) $id);
                if (!($product instanceof \WC_Product) || !$product->is_visible()) continue;
                $objects[(int) $id] = $product;
                if ($product->is_type('variation')) {
                    $matchedVariationParents[(int) $product->get_parent_id()] = true;
                }
            }

            $contexts = [];
            $seen = [];
            $perParentVariationCount = [];
            foreach ($objects as $product) {
                if ($product->is_type('variable')) {
                    if (isset($matchedVariationParents[(int) $product->get_id()])) continue;
                    if ($expandVariables) {
                    $children = $product->get_children();
                    $variationContexts = [];
                    foreach ($children as $variationId) {
                        $variation = wc_get_product((int) $variationId);
                        if (!($variation instanceof \WC_Product_Variation) || !$variation->is_visible()) continue;
                        if ($inStockOnly && !$variation->is_in_stock()) continue;
                        $context = $this->mapToContext($variation);
                        if ($maxPrice !== null && $this->effectivePrice($context) > $maxPrice) continue;
                        $variationContexts[] = $context;
                    }
                    usort($variationContexts, fn(ProductContext $a, ProductContext $b): int => $this->effectivePrice($a) <=> $this->effectivePrice($b));
                    foreach (array_slice($variationContexts, 0, 3) as $context) {
                        if (isset($seen[$context->productId])) continue;
                        $seen[$context->productId] = true;
                        $contexts[] = $context;
                        if (count($contexts) >= $limit) break;
                    }
                    if (count($contexts) >= $limit) break;
                    continue;
                    }
                }

                if ($product->is_type('variation')) {
                    $parentId = (int) $product->get_parent_id();
                    if (($perParentVariationCount[$parentId] ?? 0) >= 3) continue;
                    $perParentVariationCount[$parentId] = ($perParentVariationCount[$parentId] ?? 0) + 1;
                }

                $context = $this->mapToContext($product);
                if ($inStockOnly && !$product->is_in_stock()) continue;
                if ($maxPrice !== null && $this->effectivePrice($context) > $maxPrice) continue;
                if (isset($seen[$context->productId])) continue;
                $seen[$context->productId] = true;
                $contexts[] = $context;
                if (count($contexts) >= $limit) break;
            }

            return array_slice($contexts, 0, $limit);
        } finally {
            $this->isLoading = false;
        }
    }

    private function mapToContext(\WC_Product $product): ProductContext {
        $parent = null;
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
        }
        $source = $parent instanceof \WC_Product ? $parent : $product;

        $stockStatus = match ($product->get_stock_status()) {
            'instock' => 'in_stock',
            'outofstock' => 'out_of_stock',
            'onbackorder' => 'available_on_backorder',
            default => 'unknown',
        };

        $regular = $product->get_regular_price();
        $sale = $product->is_on_sale() ? $product->get_sale_price() : '';
        if ($product->is_type('variable')) {
            $regular = $product->get_variation_price('min', false);
            $sale = $product->get_variation_sale_price('min', false);
            if ($sale === '') $sale = null;
        }
        // Quote the same tax-inclusive/exclusive price the current customer
        // sees on the storefront, not the raw catalogue storage amount.
        if (function_exists('wc_get_price_to_display')) {
            if ($regular !== '') {
                $regular = wc_get_price_to_display($product, ['price' => (float) $regular]);
            }
            if ($sale !== '' && $sale !== null) {
                $sale = wc_get_price_to_display($product, ['price' => (float) $sale]);
            }
        }

        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0;
        $price = function_exists('wc_format_decimal')
            ? wc_format_decimal($regular !== '' ? $regular : $product->get_price(), $decimals)
            : (string) ($regular !== '' ? $regular : $product->get_price());
        $salePrice = $sale !== '' && $sale !== null
            ? (function_exists('wc_format_decimal') ? wc_format_decimal($sale, $decimals) : (string) $sale)
            : null;

        $rawQuantity = $product->get_stock_quantity();
        $quantity = is_numeric($rawQuantity) && (int) $rawQuantity >= 0 ? (int) $rawQuantity : null;
        // A variation inheriting parent-managed stock does not have a
        // variation-specific quantity; never present the shared parent count
        // as the exact count for an individual size/colour.
        if ($product->is_type('variation') && $product->get_manage_stock() === 'parent') {
            $quantity = null;
        }
        if (!$this->stockQuantityVisible() || $quantity === null) {
            $quantity = null;
        } elseif (get_option('woocommerce_stock_format', '') === 'low_amount'
            && $quantity > (int) get_option('woocommerce_notify_low_stock_amount', 2)) {
            $quantity = null;
        }

        $description = $this->sanitizeDesc($source->get_short_description());
        $categories = wp_get_post_terms($source->get_id(), 'product_cat', ['fields' => 'names']);
        $brand = $source->get_attribute('brand') ?: null;
        $url = $product->get_permalink();
        if ($product->is_type('variation')) {
            $parentUrl = get_permalink($product->get_parent_id());
            $variationAttributes = method_exists($product, 'get_variation_attributes')
                ? $product->get_variation_attributes()
                : [];
            $url = $parentUrl && $variationAttributes ? add_query_arg($variationAttributes, $parentUrl) : $parentUrl;
        }

        return new ProductContext(
            sku: $product->get_sku() ?: 'N/A',
            name: $product->is_type('variation') ? $source->get_name() : $product->get_name(),
            price: (string) $price,
            salePrice: $salePrice,
            stockStatus: $stockStatus,
            stockQuantity: $quantity,
            shortDescription: $description,
            categoryNames: implode(', ', is_array($categories) ? $categories : []),
            attributes: $this->extractAttrs($product),
            isFeatured: $source->is_featured(),
            brand: $brand,
            productId: (int) $product->get_id(),
            productUrl: (string) $url,
            currency: function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'PKR',
            isVariation: $product->is_type('variation')
        );
    }

    private function sanitizeDesc(string $raw): string {
        $clean = strip_shortcodes($raw);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = wp_strip_all_tags($clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);
        return function_exists('mb_substr') ? mb_substr($clean, 0, 300) : substr($clean, 0, 300);
    }

    private function extractAttrs(\WC_Product $product): array {
        $attributes = [];
        if ($product->is_type('variation')) {
            foreach ($product->get_variation_attributes() as $key => $value) {
                $taxonomy = str_replace('attribute_', '', (string) $key);
                $label = function_exists('wc_attribute_label') ? wc_attribute_label($taxonomy) : $taxonomy;
                if (taxonomy_exists($taxonomy)) {
                    $term = get_term_by('slug', (string) $value, $taxonomy);
                    $value = $term instanceof \WP_Term ? $term->name : (string) $value;
                }
                if ((string) $value !== '') $attributes[$label] = (string) $value;
            }
            return $attributes;
        }

        foreach ($product->get_attributes() as $attribute) {
            if (!($attribute instanceof \WC_Product_Attribute)) continue;
            $name = wc_attribute_label($attribute->get_name());
            $values = $attribute->get_options();
            if (!$values) continue;
            if ($attribute->is_taxonomy()) {
                $values = array_filter(array_map(
                    static fn($termId) => get_term($termId)?->name ?? '',
                    $values
                ));
            }
            $attributes[$name] = implode(', ', $values);
        }
        return $attributes;
    }

    private function effectivePrice(ProductContext $context): float {
        return (float) ($context->salePrice ?? $context->price);
    }

    private function stockQuantityVisible(): bool {
        return in_array(get_option('woocommerce_stock_format', ''), ['always', 'low_amount'], true);
    }
}
