<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Database;
final class InventoryInstaller {
    public static function run(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();

        // Master product catalogue with inventory data
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_inventory_products (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_product_id   BIGINT UNSIGNED DEFAULT NULL,
            sku             VARCHAR(100) NOT NULL,
            barcode         VARCHAR(50)  NOT NULL DEFAULT '',
            barcode_type    VARCHAR(20)  NOT NULL DEFAULT 'CODE128',
            name            VARCHAR(255) NOT NULL,
            category        VARCHAR(100) NOT NULL DEFAULT '',
            cost_price_pkr  INT NOT NULL DEFAULT 0,
            sale_price_pkr  INT NOT NULL DEFAULT 0,
            reorder_point   INT NOT NULL DEFAULT 5,
            unit            VARCHAR(20)  NOT NULL DEFAULT 'piece',
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_sku (sku),
            INDEX idx_barcode (barcode),
            UNIQUE INDEX idx_wc_product (wc_product_id)
        ) ENGINE=InnoDB {$cs};");

        // Stock levels per location
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_inventory_stock (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id      INT UNSIGNED NOT NULL,
            location_id     INT UNSIGNED NOT NULL,
            quantity        INT NOT NULL DEFAULT 0,
            updated_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_product_location (product_id, location_id),
            INDEX idx_location (location_id)
        ) ENGINE=InnoDB {$cs};");

        // Locations (online store, physical shop, warehouse)
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_inventory_locations (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(100) NOT NULL,
            type        VARCHAR(20)  NOT NULL DEFAULT 'physical',
            address     VARCHAR(255) NOT NULL DEFAULT '',
            is_pos      TINYINT(1)   NOT NULL DEFAULT 0,
            is_online   TINYINT(1)   NOT NULL DEFAULT 0,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB {$cs};");

        // Every stock movement (full audit ledger)
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_inventory_movements (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id      INT UNSIGNED NOT NULL,
            location_id     INT UNSIGNED NOT NULL,
            movement_type   VARCHAR(30)  NOT NULL,
            quantity_delta  INT NOT NULL,
            quantity_before INT NOT NULL,
            quantity_after  INT NOT NULL,
            reference_type  VARCHAR(30)  NOT NULL DEFAULT '',
            reference_id    VARCHAR(64)  NOT NULL DEFAULT '',
            note            VARCHAR(255) NOT NULL DEFAULT '',
            created_by      BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_product (product_id, created_at),
            INDEX idx_location (location_id, created_at),
            INDEX idx_reference (reference_type, reference_id)
        ) ENGINE=InnoDB {$cs};");

        // POS sessions (each open/close of cash drawer)
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_pos_sessions (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id     INT UNSIGNED NOT NULL,
            cashier_id      BIGINT UNSIGNED NOT NULL,
            opening_cash    INT NOT NULL DEFAULT 0,
            closing_cash    INT DEFAULT NULL,
            total_sales_pkr INT NOT NULL DEFAULT 0,
            total_cash_pkr  INT NOT NULL DEFAULT 0,
            total_card_pkr  INT NOT NULL DEFAULT 0,
            total_jazzcash  INT NOT NULL DEFAULT 0,
            total_easypaisa INT NOT NULL DEFAULT 0,
            status          VARCHAR(20)  NOT NULL DEFAULT 'open',
            opened_at       DATETIME NOT NULL,
            closed_at       DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_location_status (location_id, status),
            INDEX idx_cashier (cashier_id, opened_at)
        ) ENGINE=InnoDB {$cs};");

        // POS orders (offline queue + completed)
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_pos_orders (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      INT UNSIGNED NOT NULL,
            location_id     INT UNSIGNED NOT NULL,
            order_ref       VARCHAR(30)  NOT NULL,
            request_fingerprint CHAR(64) DEFAULT NULL,
            subtotal_pkr    INT NOT NULL DEFAULT 0,
            discount_pkr    INT NOT NULL DEFAULT 0,
            total_pkr       INT NOT NULL DEFAULT 0,
            payment_method  VARCHAR(20)  NOT NULL DEFAULT 'cash',
            payment_split   JSON DEFAULT NULL,
            customer_phone  VARCHAR(20)  NOT NULL DEFAULT '',
            customer_name   VARCHAR(100) NOT NULL DEFAULT 'Walk-in',
            receipt_sent    TINYINT(1)   NOT NULL DEFAULT 0,
            sync_status     VARCHAR(20)  NOT NULL DEFAULT 'pending',
            wc_order_id     BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME NOT NULL,
            synced_at       DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_order_ref (order_ref),
            INDEX idx_session (session_id),
            INDEX idx_sync (sync_status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB {$cs};");

        // POS order line items
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_pos_order_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        INT UNSIGNED NOT NULL,
            product_id      INT UNSIGNED NOT NULL,
            sku             VARCHAR(100) NOT NULL,
            product_name    VARCHAR(255) NOT NULL,
            quantity        INT NOT NULL,
            unit_price_pkr  INT NOT NULL,
            discount_pkr    INT NOT NULL DEFAULT 0,
            total_pkr       INT NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_order (order_id),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB {$cs};");

        // Suppliers
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_inventory_suppliers (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(150) NOT NULL,
            contact     VARCHAR(100) NOT NULL DEFAULT '',
            phone       VARCHAR(20)  NOT NULL DEFAULT '',
            email       VARCHAR(150) NOT NULL DEFAULT '',
            address     TEXT NOT NULL,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB {$cs};");

        // Purchase orders
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_purchase_orders (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_number       VARCHAR(30)  NOT NULL,
            supplier_id     INT UNSIGNED NOT NULL,
            location_id     INT UNSIGNED NOT NULL,
            status          VARCHAR(20)  NOT NULL DEFAULT 'draft',
            total_pkr       INT NOT NULL DEFAULT 0,
            received_at     DATETIME DEFAULT NULL,
            notes           TEXT NOT NULL,
            created_by      BIGINT UNSIGNED NOT NULL,
            created_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_po_number (po_number),
            INDEX idx_supplier (supplier_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB {$cs};");

        // Purchase order items
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_purchase_order_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id           INT UNSIGNED NOT NULL,
            product_id      INT UNSIGNED NOT NULL,
            quantity_ordered INT NOT NULL,
            quantity_received INT NOT NULL DEFAULT 0,
            unit_cost_pkr   INT NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_po (po_id),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB {$cs};");

        self::installStockSyncQueue();

        // Seed default locations
        self::seedLocations();
    }


    public static function installStockSyncQueue(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_wc_stock_sync (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id INT UNSIGNED NOT NULL,
            generation CHAR(36) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claimed_at DATETIME DEFAULT NULL,
            claim_token CHAR(36) DEFAULT NULL,
            last_error VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_product (product_id),
            INDEX idx_due (status, next_attempt_at),
            INDEX idx_claimed (status, claimed_at)
        ) ENGINE=InnoDB {$cs};");
    }


    public static function installWooCommerceOrderInventoryQueue(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_wc_order_inventory_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            cycle_uuid CHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
            generation CHAR(36) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claimed_at DATETIME DEFAULT NULL,
            claim_token CHAR(36) DEFAULT NULL,
            last_error VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_order_cycle (wc_order_id, cycle_uuid),
            INDEX idx_due (status, next_attempt_at),
            INDEX idx_claimed (status, claimed_at)
        ) ENGINE=InnoDB {$cs};");
    }

    public static function installWooCommerceOrderInventoryQueueCycleIdentity(): void {
        self::installWooCommerceOrderInventoryQueue();
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $legacyIndex = $wpdb->get_var($wpdb->prepare(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s',
            'idx_wc_order'
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to inspect legacy WooCommerce order queue index.');
        }
        if ($legacyIndex) {
            $result = $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_wc_order");
            if ($result === false) throw new \RuntimeException('Unable to replace legacy WooCommerce order queue index.');
        }
    }

    public static function installWooCommerceReturnInventoryQueue(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_wc_return_inventory_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(191) NOT NULL,
            event_kind VARCHAR(24) NOT NULL,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            wc_order_item_id BIGINT UNSIGNED DEFAULT NULL,
            wc_product_id BIGINT UNSIGNED NOT NULL,
            cycle_uuid CHAR(36) DEFAULT NULL,
            quantity INT UNSIGNED NOT NULL,
            cumulative_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claimed_at DATETIME DEFAULT NULL,
            claim_token CHAR(36) DEFAULT NULL,
            last_error VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_event_key (event_key),
            INDEX idx_due (status, next_attempt_at),
            INDEX idx_order_item (wc_order_id, wc_order_item_id)
        ) ENGINE=InnoDB {$cs};");
    }


    /**
     * Durable WooCommerce stock-cycle history used by the actual reduction hook
     * and cycle-aware sale/return ledger processing.
     */
    public static function installWooCommerceStockCycles(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_wc_stock_cycles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            cycle_uuid CHAR(36) NOT NULL,
            cycle_number INT UNSIGNED NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'reducing',
            reduction_event_key VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            activated_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_cycle_uuid (cycle_uuid),
            UNIQUE INDEX idx_order_cycle (wc_order_id, cycle_number),
            UNIQUE INDEX idx_reduction_event (reduction_event_key),
            INDEX idx_order_state (wc_order_id, state)
        ) ENGINE=InnoDB {$cs};");

        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_wc_stock_cycle_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cycle_uuid CHAR(36) NOT NULL,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            wc_order_item_id BIGINT UNSIGNED NOT NULL,
            wc_parent_product_id BIGINT UNSIGNED NOT NULL,
            wc_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            inventory_product_id INT UNSIGNED NOT NULL,
            inventory_location_id INT UNSIGNED NOT NULL,
            reduced_quantity INT UNSIGNED NOT NULL,
            refund_cumulative_baseline INT UNSIGNED NOT NULL DEFAULT 0,
            allocation_snapshot LONGTEXT DEFAULT NULL,
            cancellation_restored_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            refund_restored_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_cycle_order_item (cycle_uuid, wc_order_item_id),
            INDEX idx_order_item (wc_order_id, wc_order_item_id),
            INDEX idx_inventory_product (inventory_product_id, inventory_location_id)
        ) ENGINE=InnoDB {$cs};");

        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_wc_stock_cycle_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid CHAR(36) NOT NULL,
            event_key VARCHAR(191) NOT NULL,
            cycle_uuid CHAR(36) NOT NULL,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            wc_order_item_id BIGINT UNSIGNED DEFAULT NULL,
            event_kind VARCHAR(24) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            cumulative_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            source_hook VARCHAR(80) NOT NULL,
            source_metadata LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claimed_at DATETIME DEFAULT NULL,
            claim_token CHAR(36) DEFAULT NULL,
            last_error VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_event_uuid (event_uuid),
            UNIQUE INDEX idx_event_key (event_key),
            INDEX idx_cycle_kind (cycle_uuid, event_kind),
            INDEX idx_due (status, next_attempt_at),
            INDEX idx_claimed (status, claimed_at),
            INDEX idx_order_item (wc_order_id, wc_order_item_id)
        ) ENGINE=InnoDB {$cs};");
    }

    public static function installWooCommerceStockCycleRuntime(): void {
        // Re-run dbDelta for additive columns required by cycle-aware sale/return
        // processing. Existing queue rows remain legacy (cycle_uuid NULL).
        self::installWooCommerceReturnInventoryQueue();
        self::installWooCommerceStockCycles();
    }

    public static function installInventoryQueueAudit(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}pkstore_inventory_queue_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            queue_type VARCHAR(20) NOT NULL,
            queue_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            previous_status VARCHAR(20) NOT NULL,
            resulting_status VARCHAR(20) NOT NULL,
            reason TEXT NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_queue (queue_type, queue_id),
            INDEX idx_actor (actor_id, created_at)
        ) ENGINE=InnoDB {$cs};");
    }

    /**
     * Enforce one Junction inventory record per WooCommerce product.
     * Existing duplicate mappings are never deleted or auto-merged: the
     * migration stops until an administrator reconciles them.
     */
    public static function installInventoryProductMappingConstraint(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_inventory_products';

        $duplicate = $wpdb->get_var(
            "SELECT wc_product_id FROM {$table}
             WHERE wc_product_id IS NOT NULL AND wc_product_id > 0
             GROUP BY wc_product_id HAVING COUNT(*) > 1 LIMIT 1"
        );
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to audit existing WooCommerce inventory product mappings.');
        }
        if ($duplicate !== null) {
            throw new \RuntimeException('Duplicate WooCommerce inventory product mappings must be reconciled before this upgrade.');
        }

        // Zero is not a valid WooCommerce product ID; normalize legacy sentinel
        // values to NULL so multiple unlinked products remain valid.
        $normalized = $wpdb->query("UPDATE {$table} SET wc_product_id=NULL WHERE wc_product_id=0");
        if ($normalized === false) {
            throw new \RuntimeException('Unable to normalize empty WooCommerce inventory product mappings.');
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s',
            'idx_wc_product'
        ), ARRAY_A);
        if ($rows === null && $wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to inspect the WooCommerce inventory mapping index.');
        }

        $valid = is_array($rows) && count($rows) === 1
            && (int) ($rows[0]['Non_unique'] ?? 1) === 0
            && (int) ($rows[0]['Seq_in_index'] ?? 0) === 1
            && (string) ($rows[0]['Column_name'] ?? '') === 'wc_product_id';
        if ($valid) return;

        if (is_array($rows) && $rows) {
            $dropped = $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_wc_product");
            if ($dropped === false) {
                throw new \RuntimeException('Unable to replace the legacy WooCommerce inventory mapping index.');
            }
        }

        $created = $wpdb->query("ALTER TABLE {$table} ADD UNIQUE INDEX idx_wc_product (wc_product_id)");
        if ($created === false) {
            throw new \RuntimeException('Unable to enforce unique WooCommerce inventory product mappings.');
        }
    }

    private static function seedLocations(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'pkstore_inventory_locations';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}") > 0) return;
        $now = current_time('mysql');
        $wpdb->insert($t, ['name'=>'Main Shop','type'=>'physical','address'=>'','is_pos'=>1,'is_online'=>0,'is_active'=>1,'created_at'=>$now]);
        $wpdb->insert($t, ['name'=>'Online Store','type'=>'online','address'=>'','is_pos'=>0,'is_online'=>1,'is_active'=>1,'created_at'=>$now]);
    }
}
