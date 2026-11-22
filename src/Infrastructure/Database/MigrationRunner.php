<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Database;

/**
 * Versioned, lock-protected schema migration entry point.
 *
 * Add future migrations to MIGRATIONS with a new monotonically increasing
 * version. A migration is marked complete only after it returns successfully
 * and the required schema has been verified.
 */
final class MigrationRunner {
    private const VERSION_OPTION = 'pkstore_db_version';
    private const LOCK_OPTION = 'pkstore_db_migration_lock';
    private const ERROR_OPTION = 'pkstore_db_migration_error';
    private const LOCK_TTL = 900;

    /** @var array<string, callable> */
    private const MIGRATIONS = [
        '2.0.0' => [Installer::class, 'installSchema'],
        '2.0.1' => [InventoryInstaller::class, 'installStockSyncQueue'],
        '2.0.2' => [self::class, 'addPOSRequestFingerprint'],
        '2.0.3' => [InventoryInstaller::class, 'installWooCommerceOrderInventoryQueue'],
        '2.0.4' => [InventoryInstaller::class, 'installWooCommerceReturnInventoryQueue'],
        '2.0.5' => [InventoryInstaller::class, 'installWooCommerceReturnInventoryQueue'],
        '2.0.6' => [InventoryInstaller::class, 'installWooCommerceOrderInventoryQueue'],
        '2.0.7' => [InventoryInstaller::class, 'installInventoryQueueAudit'],
        '2.0.8' => [InventoryInstaller::class, 'installStockSyncQueue'],
        '2.0.9' => [InventoryInstaller::class, 'installWooCommerceStockCycles'],
        '2.0.10' => [Installer::class, 'addOtpConsumptionColumns'],
    ];


    public static function addPOSRequestFingerprint(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_pos_orders';
        $column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'request_fingerprint'));
        if ($column) return;

        $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN request_fingerprint CHAR(64) DEFAULT NULL AFTER order_ref");
        if ($result === false) {
            throw new \RuntimeException('Unable to add POS request fingerprint column.');
        }
    }

    public static function register(): void {
        add_action('init', [self::class, 'maybeRun'], 1);
        add_action('admin_notices', [self::class, 'adminNotice']);
    }

    public static function maybeRun(): void {
        $current = (string) get_option(self::VERSION_OPTION, '0.0.0');
        $pending = array_filter(
            array_keys(self::MIGRATIONS),
            static fn(string $version): bool => version_compare($current, $version, '<')
        );

        if (!$pending) return;

        $lockTime = (int) get_option(self::LOCK_OPTION, 0);
        if ($lockTime && (time() - $lockTime) < self::LOCK_TTL) return;
        if ($lockTime) delete_option(self::LOCK_OPTION);

        // add_option is atomic for a unique option name and prevents two requests
        // from running the same DDL migration concurrently.
        if (!add_option(self::LOCK_OPTION, time(), '', false)) return;

        try {
            // Determine whether this is an upgrade before the first migration creates
            // tables. Re-checking inside the loop misclassifies a fresh install as an
            // existing store on migration 2.0.1 and blocks setup behind the backup gate.
            $isExistingInstall = self::hasExistingTables();

            foreach (self::MIGRATIONS as $version => $migration) {
                $current = (string) get_option(self::VERSION_OPTION, '0.0.0');
                if (version_compare($current, $version, '>=')) continue;

                // Never alter an existing store until hosting confirms a recent
                // full database + website-files backup.
                if ($isExistingInstall
                    && !apply_filters('pkstore_migration_backup_confirmed', false, $version)) {
                    throw new \RuntimeException('Upgrade migration paused: a recent pre-migration hosting backup has not been confirmed.');
                }

                call_user_func($migration);
                self::verifyRequiredTables($version);
                update_option(self::VERSION_OPTION, $version, false);
            }

            delete_option(self::ERROR_OPTION);
        } catch (\Throwable $error) {
            // Do not expose SQL details or configuration values to store users.
            update_option(self::ERROR_OPTION, 'Database migration failed. Contact Junction support.', false);
            error_log('[PK Store] Database migration failed: ' . get_class($error));
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function hasExistingTables(): bool {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('Unable to determine whether this is an existing Junction installation.');
        }

        // Any pre-existing Junction table makes this an upgrade, including a
        // partial/legacy install that does not yet have the three core tables.
        // Escape the literal prefix before adding the intentional wildcard.
        $pattern = $wpdb->esc_like($wpdb->prefix . 'pkstore_') . '%';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
        if ($wpdb->last_error !== '') {
            // Never interpret a failed schema probe as a fresh install:
            // doing so could bypass the pre-migration backup confirmation.
            throw new \RuntimeException('Unable to determine whether this is an existing Junction installation.');
        }

        return is_string($found) && $found !== '';
    }

    private static function verifyRequiredTables(string $version): void {
        global $wpdb;

        $required = [
            'pkstore_otp_attempts',
            'pkstore_logistics_log',
            'pkstore_chat_history',
            'pkstore_city_mappings',
            'pkstore_affiliates',
            'pkstore_affiliate_products',
            'pkstore_affiliate_commissions',
            'pkstore_affiliate_payouts',
            'pkstore_inventory_products',
            'pkstore_wc_stock_sync',
            'pkstore_inventory_stock',
            'pkstore_inventory_locations',
            'pkstore_inventory_movements',
            'pkstore_pos_sessions',
            'pkstore_pos_orders',
            'pkstore_pos_order_items',
            'pkstore_inventory_suppliers',
            'pkstore_purchase_orders',
            'pkstore_purchase_order_items',
        ];

        // This queue is introduced by migration 2.0.3; requiring it while
        // verifying 2.0.0–2.0.2 prevents those earlier steps from completing.
        if (version_compare($version, '2.0.3', '>=')) {
            $required[] = 'pkstore_wc_order_inventory_queue';
        }
        if (version_compare($version, '2.0.4', '>=')) {
            $required[] = 'pkstore_wc_return_inventory_queue';
        }
        if (version_compare($version, '2.0.5', '>=')) {
            $returnQueue = $wpdb->prefix . 'pkstore_wc_return_inventory_queue';
            $claimToken = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $returnQueue . ' LIKE %s', 'claim_token'));
            if (!$claimToken) throw new \RuntimeException('Required return queue claim-token column was not created.');
        }
        if (version_compare($version, '2.0.6', '>=')) {
            $orderQueue = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
            $claimToken = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $orderQueue . ' LIKE %s', 'claim_token'));
            if (!$claimToken) throw new \RuntimeException('Required order queue claim-token column was not created.');
        }

        if (version_compare($version, '2.0.7', '>=')) $required[] = 'pkstore_inventory_queue_audit';
        if (version_compare($version, '2.0.10', '>=')) {
            $otpTable = $wpdb->prefix . 'pkstore_otp_attempts';
            foreach (['consumed_at', 'consumed_order_id'] as $columnName) {
                $column = $wpdb->get_var($wpdb->prepare(
                    'SHOW COLUMNS FROM ' . $otpTable . ' LIKE %s',
                    $columnName
                ));
                if (!$column) {
                    throw new \RuntimeException('Required COD OTP consumption column was not created.');
                }
            }
        }
        if (version_compare($version, '2.0.9', '>=')) {
            $required[] = 'pkstore_wc_stock_cycles';
            $required[] = 'pkstore_wc_stock_cycle_items';
            $required[] = 'pkstore_wc_stock_cycle_events';

            $cycleChecks = [
                'pkstore_wc_stock_cycles' => [
                    'cycle_uuid', 'cycle_number', 'state', 'reduction_event_key',
                ],
                'pkstore_wc_stock_cycle_items' => [
                    'cycle_uuid', 'wc_order_item_id', 'inventory_product_id',
                    'inventory_location_id', 'reduced_quantity',
                ],
                'pkstore_wc_stock_cycle_events' => [
                    'event_uuid', 'event_key', 'cycle_uuid', 'event_kind',
                    'claim_token', 'status', 'next_attempt_at',
                ],
            ];
            foreach ($cycleChecks as $suffix => $columns) {
                $table = $wpdb->prefix . $suffix;
                foreach ($columns as $columnName) {
                    $column = $wpdb->get_var($wpdb->prepare(
                        'SHOW COLUMNS FROM ' . $table . ' LIKE %s',
                        $columnName
                    ));
                    if (!$column) {
                        throw new \RuntimeException('Required WooCommerce stock-cycle column was not created.');
                    }
                }
            }

            self::verifyUniqueIndex(
                $wpdb->prefix . 'pkstore_wc_stock_cycles',
                'idx_cycle_uuid',
                ['cycle_uuid']
            );
            self::verifyUniqueIndex(
                $wpdb->prefix . 'pkstore_wc_stock_cycles',
                'idx_order_cycle',
                ['wc_order_id', 'cycle_number']
            );
            self::verifyUniqueIndex(
                $wpdb->prefix . 'pkstore_wc_stock_cycles',
                'idx_reduction_event',
                ['reduction_event_key']
            );
            self::verifyUniqueIndex(
                $wpdb->prefix . 'pkstore_wc_stock_cycle_items',
                'idx_cycle_order_item',
                ['cycle_uuid', 'wc_order_item_id']
            );
            self::verifyUniqueIndex(
                $wpdb->prefix . 'pkstore_wc_stock_cycle_events',
                'idx_event_uuid',
                ['event_uuid']
            );
            self::verifyUniqueIndex(
                $wpdb->prefix . 'pkstore_wc_stock_cycle_events',
                'idx_event_key',
                ['event_key']
            );
        }
        if (version_compare($version, '2.0.8', '>=')) {
            $stockQueue = $wpdb->prefix . 'pkstore_wc_stock_sync';
            $claimToken = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $stockQueue . ' LIKE %s', 'claim_token'));
            if (!$claimToken) throw new \RuntimeException('Required stock sync queue claim-token column was not created.');
        }

        foreach ($required as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new \RuntimeException('Required database table was not created: ' . $suffix);
            }
        }
    }

    /**
     * Verify dbDelta produced a unique index with the exact ordered columns.
     * Checking only Key_name can accept a non-unique or malformed index.
     *
     * @param list<string> $expectedColumns
     */
    private static function verifyUniqueIndex(string $table, string $indexName, array $expectedColumns): void {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s',
            $indexName
        ), ARRAY_A);
        if (!is_array($rows) || count($rows) !== count($expectedColumns)) {
            throw new \RuntimeException('Required WooCommerce stock-cycle unique index is missing or malformed.');
        }

        usort($rows, static fn(array $a, array $b): int =>
            (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']
        );
        foreach ($expectedColumns as $position => $column) {
            $row = $rows[$position] ?? [];
            if ((int) ($row['Non_unique'] ?? 1) !== 0
                || (int) ($row['Seq_in_index'] ?? 0) !== $position + 1
                || (string) ($row['Column_name'] ?? '') !== $column) {
                throw new \RuntimeException('Required WooCommerce stock-cycle unique index is missing or malformed.');
            }
        }
    }

    public static function adminNotice(): void {
        if (!current_user_can('manage_options')) return;
        $message = get_option(self::ERROR_OPTION, '');
        if (!$message) return;

        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
