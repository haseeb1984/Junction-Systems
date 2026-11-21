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

        foreach (['pkstore_city_mappings', 'pkstore_pos_sessions', 'pkstore_inventory_products'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found === $table) return true;
        }

        return false;
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

        foreach ($required as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new \RuntimeException('Required database table was not created: ' . $suffix);
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
