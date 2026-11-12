<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Database;
final class InventoryInstaller {
    public static function run(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();

        // Master product catalogue with inventory data
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_inventory_products (
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
            INDEX idx_wc_product (wc_product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Stock levels per location
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_inventory_stock (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id      INT UNSIGNED NOT NULL,
            location_id     INT UNSIGNED NOT NULL,
            quantity        INT NOT NULL DEFAULT 0,
            updated_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_product_location (product_id, location_id),
            INDEX idx_location (location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Locations (online store, physical shop, warehouse)
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_inventory_locations (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(100) NOT NULL,
            type        VARCHAR(20)  NOT NULL DEFAULT 'physical',
            address     VARCHAR(255) NOT NULL DEFAULT '',
            is_pos      TINYINT(1)   NOT NULL DEFAULT 0,
            is_online   TINYINT(1)   NOT NULL DEFAULT 0,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Every stock movement (full audit ledger)
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_inventory_movements (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // POS sessions (each open/close of cash drawer)
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_pos_sessions (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // POS orders (offline queue + completed)
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_pos_orders (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      INT UNSIGNED NOT NULL,
            location_id     INT UNSIGNED NOT NULL,
            order_ref       VARCHAR(30)  NOT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // POS order line items
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_pos_order_items (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Suppliers
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_inventory_suppliers (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(150) NOT NULL,
            contact     VARCHAR(100) NOT NULL DEFAULT '',
            phone       VARCHAR(20)  NOT NULL DEFAULT '',
            email       VARCHAR(150) NOT NULL DEFAULT '',
            address     TEXT NOT NULL,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Purchase orders
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_purchase_orders (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Purchase order items
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_purchase_order_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id           INT UNSIGNED NOT NULL,
            product_id      INT UNSIGNED NOT NULL,
            quantity_ordered INT NOT NULL,
            quantity_received INT NOT NULL DEFAULT 0,
            unit_cost_pkr   INT NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_po (po_id),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$cs};");

        // Seed default locations
        self::seedLocations();
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
