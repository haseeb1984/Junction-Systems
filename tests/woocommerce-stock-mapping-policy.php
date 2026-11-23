<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Application/Inventory/WooCommerceStockMappingPolicy.php';
use PkStore\Application\Inventory\WooCommerceStockMappingPolicy;

final class FakeWooProduct {
    public function __construct(
        private string $type,
        private int $id,
        private int $stockOwnerId,
        private bool $ownStock,
        private array $children = []
    ) {}
    public function is_type(string $type): bool { return $this->type === $type; }
    public function get_id(): int { return $this->id; }
    public function get_stock_managed_by_id(): int { return $this->stockOwnerId; }
    public function get_manage_stock(string $context = 'view'): bool { return $this->ownStock; }
    public function get_children(): array { return $this->children; }
}

$GLOBALS['fake_wc_products'] = [];
function wc_get_product(int $id): mixed {
    return $GLOBALS['fake_wc_products'][$id] ?? null;
}
function assertStockMapping(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// A parent-managed variation must be mapped to the parent product.
$parentManagedVariation = new FakeWooProduct('variation', 202, 100, false);
assertStockMapping(
    WooCommerceStockMappingPolicy::errorFor($parentManagedVariation) !== null,
    'Parent-managed variation mapping was not rejected.'
);

// An independently managed variation is a valid inventory mapping.
$ownStockVariation = new FakeWooProduct('variation', 203, 203, true);
assertStockMapping(
    WooCommerceStockMappingPolicy::errorFor($ownStockVariation) === null,
    'Independently managed variation mapping was rejected.'
);

// Mixed variable products may use parent stock for inheriting variations
// while independently managed variations are mapped separately.
$GLOBALS['fake_wc_products'][202] = new FakeWooProduct('variation', 202, 100, false);
$GLOBALS['fake_wc_products'][203] = new FakeWooProduct('variation', 203, 203, true);
$mixedParent = new FakeWooProduct('variable', 100, 100, true, [202, 203]);
assertStockMapping(
    WooCommerceStockMappingPolicy::errorFor($mixedParent) === null,
    'Mixed parent/variation stock ownership was rejected.'
);

// If every child owns stock independently, the parent cannot represent them.
$allOwnParent = new FakeWooProduct('variable', 300, 300, true, [301, 302]);
$GLOBALS['fake_wc_products'][301] = new FakeWooProduct('variation', 301, 301, true);
$GLOBALS['fake_wc_products'][302] = new FakeWooProduct('variation', 302, 302, true);
assertStockMapping(
    WooCommerceStockMappingPolicy::errorFor($allOwnParent) !== null,
    'Parent mapping was allowed when every variation owns stock independently.'
);

// Missing child records fail closed rather than assuming parent stock ownership.
$missingChildParent = new FakeWooProduct('variable', 400, 400, true, [401]);
assertStockMapping(
    WooCommerceStockMappingPolicy::errorFor($missingChildParent) !== null,
    'Missing variation record did not fail closed.'
);

echo "WooCommerce stock mapping policy tests passed.\n";
