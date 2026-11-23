<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;

/**
 * Keep Junction mappings aligned with WooCommerce's actual stock owner.
 * A variation managed by its parent must map to the parent, while a
 * variable parent cannot represent children that own stock independently.
 */
final class WooCommerceStockMappingPolicy {
    public static function errorFor(object $product): ?string {
        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            if (!method_exists($product, 'get_stock_managed_by_id')
                || !method_exists($product, 'get_id')
                || (int) $product->get_stock_managed_by_id() !== (int) $product->get_id()) {
                return 'This variation uses parent-managed stock. Map the Junction product to the variable parent instead.';
            }
            return null;
        }

        if (method_exists($product, 'is_type') && $product->is_type('variable')) {
            if (!method_exists($product, 'get_children')) {
                return 'WooCommerce variation stock ownership could not be verified.';
            }
            $children = $product->get_children();
            $parentManagedChildren = 0;
            foreach ($children as $variationId) {
                $variation = function_exists('wc_get_product') ? wc_get_product((int) $variationId) : null;
                if (!is_object($variation) || !method_exists($variation, 'get_manage_stock')) {
                    return 'A WooCommerce variation is unavailable; stock ownership could not be verified.';
                }
                if (!(bool) $variation->get_manage_stock('edit')) {
                    $parentManagedChildren++;
                }
            }
            if ($children && $parentManagedChildren === 0) {
                return 'All variations manage stock independently. Map each stock-owning variation separately.';
            }
        }

        return null;
    }
}
