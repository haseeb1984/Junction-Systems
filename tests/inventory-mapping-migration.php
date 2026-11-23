<?php
declare(strict_types=1);

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

class wpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $queries = [];
    public ?string $duplicateMapping = null;
    public array $indexes = [];
    public mixed $queryResult = 0;

    public function get_var(string $query): mixed {
        if (str_contains($query, 'SELECT wc_product_id')) return $this->duplicateMapping;
        return null;
    }

    public function get_results(string $query, mixed $output = null): array|null {
        return $this->indexes;
    }

    public function prepare(string $query, mixed ...$args): string {
        return $query;
    }

    public function query(string $query): int|false {
        $this->queries[] = $query;
        return $this->queryResult;
    }
}

require_once dirname(__DIR__) . '/src/Infrastructure/Database/InventoryInstaller.php';

function assertMigration(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// Existing duplicate mappings must stop the migration before any write.
$wpdb = new wpdb();
$wpdb->duplicateMapping = '42';
try {
    \PkStore\Infrastructure\Database\InventoryInstaller::installInventoryProductMappingConstraint();
    throw new RuntimeException('Duplicate WooCommerce mappings were not rejected.');
} catch (RuntimeException $error) {
    assertMigration(
        $error->getMessage() === 'Duplicate WooCommerce inventory product mappings must be reconciled before this upgrade.',
        'Unexpected duplicate-mapping migration error.'
    );
}
assertMigration($wpdb->queries === [], 'Migration changed data or indexes before duplicate reconciliation.');

// A legacy non-unique index is replaced only after the duplicate audit succeeds.
$wpdb = new wpdb();
$wpdb->indexes = [[
    'Non_unique' => 1,
    'Seq_in_index' => 1,
    'Column_name' => 'wc_product_id',
]];
\PkStore\Infrastructure\Database\InventoryInstaller::installInventoryProductMappingConstraint();
$statements = implode("\n", $wpdb->queries);
assertMigration(str_contains($statements, 'UPDATE wp_pkstore_inventory_products SET wc_product_id=NULL WHERE wc_product_id=0'), 'Legacy zero mappings were not normalized.');
assertMigration(str_contains($statements, 'ALTER TABLE wp_pkstore_inventory_products DROP INDEX idx_wc_product'), 'Legacy non-unique mapping index was not removed.');
assertMigration(str_contains($statements, 'ALTER TABLE wp_pkstore_inventory_products ADD UNIQUE INDEX idx_wc_product (wc_product_id)'), 'Unique mapping index was not created.');

// An already-correct unique index is preserved.
$wpdb = new wpdb();
$wpdb->indexes = [[
    'Non_unique' => 0,
    'Seq_in_index' => 1,
    'Column_name' => 'wc_product_id',
]];
\PkStore\Infrastructure\Database\InventoryInstaller::installInventoryProductMappingConstraint();
$alterStatements = array_filter($wpdb->queries, static fn(string $query): bool => str_contains($query, 'ALTER TABLE'));
assertMigration($alterStatements === [], 'A valid unique mapping index was unnecessarily rebuilt.');

echo "Inventory mapping migration tests passed.\n";
