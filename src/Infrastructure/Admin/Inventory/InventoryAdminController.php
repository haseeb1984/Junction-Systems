<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin\Inventory;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface,StockRepositoryInterface,LocationRepositoryInterface};
use PkStore\Application\Inventory\{AdjustStockUseCase,GenerateBarcodeUseCase,SyncWooCommerceStockUseCase};

final class InventoryAdminController {
    private const CAP = 'manage_woocommerce';
    public function __construct(private readonly ServiceContainer $c) {}

    private function schemaReady(): bool {
        return version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.13', '>=');
    }

    private function requireSchemaReady(): void {
        if (!$this->schemaReady()) {
            wp_die('Inventory and POS are temporarily paused until the protected database migration and backup confirmation are complete.');
        }
    }

    public function register(): void {
        add_action('admin_menu',            [$this, 'menus']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_init',            [$this, 'handleForms']);
    }

    public function menus(): void {
        if (!$this->schemaReady()) return;
        $features = FeatureManager::getInstance();
        if (!$features->isEnabled('inventory')) return;

        add_submenu_page('pkstore-operations','Inventory','📦 Inventory',self::CAP,'pkstore-inventory',[$this,'inventory']);
        if ($features->isEnabled('pos')) {
            // Cashiers have only use_junction_pos, not manage_woocommerce. Give
            // them a direct top-level POS entry instead of hiding it under the
            // manager-only operations menu.
            add_menu_page('Junction POS','🖥 Junction POS','use_junction_pos','pkstore-pos-register',[$this,'pos'],'dashicons-store',56);
            add_submenu_page('pkstore-operations','POS Register','🖥 POS Register',self::CAP,'pkstore-pos',[$this,'pos']);
        }
        add_submenu_page('pkstore-operations','Stock Movements','📊 Stock Log',self::CAP,'pkstore-stock-log',[$this,'stockLog']);
        add_submenu_page('pkstore-operations','Inventory Event Health','🔄 Sync Health',self::CAP,'pkstore-inventory-events',[$this,'inventoryEvents']);
        add_submenu_page('pkstore-operations','Purchase Orders','🚚 Purchase Orders','manage_options','pkstore-purchase-orders',[$this,'purchaseOrders']);
        if ($features->isEnabled('multi_location')) {
            add_submenu_page('pkstore-operations','Inventory Locations','📍 Locations',self::CAP,'pkstore-locations',[$this,'locations']);
        }
    }

    public function assets(string $hook): void {
        $ours = ['store-ops_page_pkstore-inventory','store-ops_page_pkstore-pos','store-ops_page_pkstore-stock-log','store-ops_page_pkstore-inventory-events','store-ops_page_pkstore-purchase-orders','store-ops_page_pkstore-locations'];
        if (!in_array($hook,$ours,true)) return;
        wp_enqueue_style('pkstore-admin',PKSTORE_ASSETS.'/css/admin.css',[],PKSTORE_VERSION);
    }

    public function handleForms(): void {
        if (!isset($_POST['pkstore_inventory_action'])) return;
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        $features = FeatureManager::getInstance();
        if (!$features->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        check_admin_referer('pkstore_inventory_action');
        $action = sanitize_key($_POST['pkstore_inventory_action']);
        if ($action === 'generate_barcodes' && !$features->isEnabled('barcode_labels')) {
            wp_die('Barcode labels are not available on the current package.');
        }
        if ($action === 'add_location' && !$features->isEnabled('multi_location')) {
            wp_die('Multi-location inventory is not available on the current package.');
        }
        if (in_array($action, ['retry_inventory_event', 'ignore_inventory_event'], true)) {
            $this->handleInventoryEventAction($action);
            return;
        }
        match($action) {
            'add_product'     => $this->handleAddProduct(),
            'add_location'    => $this->handleAddLocation(),
            'adjust_stock'    => $this->handleAdjustStock(),
            'generate_barcodes' => $this->handleGenerateBarcodes(),
            'sync_woocommerce'  => $this->handleSyncWooCommerce(),
            default            => null,
        };
    }

    private function handleInventoryEventAction(string $action): void {
        global $wpdb;
        $queueType = sanitize_key($_POST['queue_type'] ?? '');
        $queueId = filter_var($_POST['queue_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        if (!in_array($queueType, ['sales', 'returns', 'stock'], true)
            || ($action === 'ignore_inventory_event' && ($queueType === 'stock' || ($_POST['confirm_ignore'] ?? '') !== '1')) || $queueId === false || $queueId < 1
            || strlen(trim($reason)) < 5 || strlen($reason) > 1000) {
            $this->redirect('pkstore-inventory-events', 'error', 'A valid event and an operator reason of at least 5 characters are required.');
            return;
        }

        $table = $wpdb->prefix . ($queueType === 'sales'
            ? 'pkstore_wc_order_inventory_queue'
            : ($queueType === 'returns' ? 'pkstore_wc_return_inventory_queue' : 'pkstore_wc_stock_sync'));
        $auditTable = $wpdb->prefix . 'pkstore_inventory_queue_audit';
        $now = current_time('mysql');
        if ($wpdb->query('START TRANSACTION') === false) {
            $this->redirect('pkstore-inventory-events', 'error', 'Could not start the queue action.');
            return;
        }

        try {
            if ($action === 'retry_inventory_event') {
                if ($queueType === 'sales' || $queueType === 'stock') {
                    $updated = $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET status='pending', generation=%s, attempts=0,
                         next_attempt_at=%s, claimed_at=NULL, claim_token=NULL, last_error='', updated_at=%s
                         WHERE id=%d AND status='needs_review'",
                        wp_generate_uuid4(), $now, $now, $queueId
                    ));
                } else {
                    $updated = $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET status='pending', attempts=0,
                         next_attempt_at=%s, claimed_at=NULL, claim_token=NULL, last_error='', updated_at=%s
                         WHERE id=%d AND status='needs_review'",
                        $now, $now, $queueId
                    ));
                }
            } else {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status='ignored', claimed_at=NULL, claim_token=NULL, updated_at=%s
                     WHERE id=%d AND status='needs_review'",
                    $now, $queueId
                ));
            }

            if ($updated !== 1) {
                throw new \RuntimeException('Queue event is no longer in needs_review state.');
            }
            $inserted = $wpdb->insert($auditTable, [
                'queue_type' => $queueType,
                'queue_id' => $queueId,
                'action' => $action === 'retry_inventory_event' ? 'retry' : 'ignore',
                'previous_status' => 'needs_review',
                'resulting_status' => $action === 'retry_inventory_event' ? 'pending' : 'ignored',
                'reason' => $reason,
                'actor_id' => get_current_user_id(),
                'created_at' => $now,
            ]);
            if ($inserted !== 1) throw new \RuntimeException('Could not append the operator audit record.');
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Could not commit the queue action.');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            error_log('[PKStore Inventory] Queue operator action failed ('.get_class($error).').');
            $this->redirect('pkstore-inventory-events', 'error', 'The queue action was not completed. Refresh and inspect the event.');
            return;
        }

        $this->redirect('pkstore-inventory-events', 'updated', 'Queue action saved with an audit record.');
    }

    private function handleAddLocation(): void {
        if (!FeatureManager::getInstance()->isEnabled('multi_location')) {
            wp_die('Multi-location inventory is not available on the current package.');
        }
        $name = sanitize_text_field($_POST['location_name'] ?? '');
        $type = sanitize_key($_POST['location_type'] ?? 'physical');
        $address = sanitize_textarea_field($_POST['location_address'] ?? '');
        $isPos = isset($_POST['location_is_pos']) && $_POST['location_is_pos'] === '1';
        if ($name === '' || strlen($name) > 100
            || !in_array($type, ['physical', 'warehouse'], true)
            || ($isPos && $type !== 'physical')) {
            $this->redirect('pkstore-locations', 'error', 'Enter a valid location name and type. POS can only be enabled for physical locations.');
            return;
        }
        try {
            $this->c->make(LocationRepositoryInterface::class)->create($name, $type, $address, $isPos);
            $this->redirect('pkstore-locations', 'added', 'Inventory location added.');
        } catch (\Throwable $error) {
            $this->redirect('pkstore-locations', 'error', 'Location could not be added. Check for a duplicate active name and try again.');
        }
    }

    private function handleAddProduct(): void {
        $repo = $this->c->make(InventoryProductRepositoryInterface::class);
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        if ($sku === '' || $name === '') {
            $this->redirect('pkstore-inventory','error','SKU and name are required.'); return;
        }
        if ($repo->findBySku($sku)) {
            $this->redirect('pkstore-inventory','error','SKU already exists.'); return;
        }

        $costPrice = current_user_can('manage_options') ? filter_var($_POST['cost_price'] ?? 0, FILTER_VALIDATE_INT) : 0;
        $salePrice = filter_var($_POST['sale_price'] ?? 0, FILTER_VALIDATE_INT);
        $reorderPoint = filter_var($_POST['reorder_point'] ?? 5, FILTER_VALIDATE_INT);
        $wcProductRaw = $_POST['wc_product_id'] ?? 0;
        $wcProductId = filter_var($wcProductRaw, FILTER_VALIDATE_INT);
        if ($costPrice === false || $salePrice === false || $reorderPoint === false || $wcProductId === false
            || $costPrice < 0 || $salePrice < 0 || $reorderPoint < 0 || $wcProductId < 0
            || ($wcProductId > 0 && !wc_get_product($wcProductId))) {
            $this->redirect('pkstore-inventory','error','Prices, reorder point or WooCommerce product mapping are invalid.'); return;
        }

        $p = \PkStore\Domain\Inventory\Entity\InventoryProduct::create(
            $sku,
            $name,
            $costPrice,
            $salePrice,
            sanitize_text_field($_POST['category'] ?? ''),
            $wcProductId > 0 ? $wcProductId : null,
            sanitize_text_field($_POST['barcode'] ?? ''),
            sanitize_text_field($_POST['unit'] ?? 'piece'),
            $reorderPoint
        );
        $id = $repo->save($p);
        if (!$p->hasBarcode() && FeatureManager::getInstance()->isEnabled('barcode_labels')) {
            $this->c->make(GenerateBarcodeUseCase::class)->execute($id);
        }
        $this->redirect('pkstore-inventory','added','Product added.');
    }

    private function handleAdjustStock(): void {
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $type = \PkStore\Domain\Inventory\Entity\MovementType::tryFrom(sanitize_key($_POST['movement_type'] ?? 'adjustment'));
        $allowedTypes = [
            \PkStore\Domain\Inventory\Entity\MovementType::Adjustment,
            \PkStore\Domain\Inventory\Entity\MovementType::PurchaseReceive,
            \PkStore\Domain\Inventory\Entity\MovementType::Damage,
            \PkStore\Domain\Inventory\Entity\MovementType::Opening,
        ];
        if ($productId === false || $locationId === false || $quantity === false || $productId < 1 || $locationId < 1 || $quantity === 0 || !$type || !in_array($type, $allowedTypes, true)) {
            $this->redirect('pkstore-inventory','error','Invalid stock adjustment details.'); return;
        }
        if (($type === \PkStore\Domain\Inventory\Entity\MovementType::PurchaseReceive || $type === \PkStore\Domain\Inventory\Entity\MovementType::Opening) && $quantity < 1) {
            $this->redirect('pkstore-inventory','error','Purchase receipts and opening stock must add a positive quantity.'); return;
        }
        if ($type === \PkStore\Domain\Inventory\Entity\MovementType::Damage && $quantity > -1) {
            $this->redirect('pkstore-inventory','error','Damage/loss must reduce stock using a negative quantity.'); return;
        }
        $product = $this->c->make(InventoryProductRepositoryInterface::class)->findById($productId);
        $location = $this->c->make(LocationRepositoryInterface::class)->findById($locationId);
        if (!$product || !$product->isActive || !$location || !$location->isActive) {
            $this->redirect('pkstore-inventory','error','Active product and location are required.'); return;
        }
        try {
            $this->c->make(AdjustStockUseCase::class)->executeManualAdjustment(
                $productId,
                $locationId,
                $quantity,
                $type,
                sanitize_text_field($_POST['note'] ?? 'Manual adjustment')
            );
            $this->redirect('pkstore-inventory','adjusted','Stock updated.');
        } catch(\Throwable $e) {
            $this->redirect('pkstore-inventory','error','Stock adjustment could not be completed. Verify the available stock and try again.');
        }
    }

    private function handleGenerateBarcodes(): void {
        $count = $this->c->make(GenerateBarcodeUseCase::class)->generateForAll();
        $this->redirect('pkstore-inventory','barcodes_generated',"Generated barcodes for {$count} products.");
    }

    private function handleSyncWooCommerce(): void {
        $count = $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce();
        $this->redirect('pkstore-inventory','synced',"Synced {$count} products to WooCommerce.");
    }

    private function redirect(string $page, string $status, string $msg): void {
        wp_safe_redirect(add_query_arg(['page'=>$page,'status'=>$status,'msg'=>urlencode($msg)],admin_url('admin.php'))); exit;
    }

    public function locations(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('multi_location')) {
            wp_die('Multi-location inventory is not available on the current package.');
        }
        $locations = $this->c->make(LocationRepositoryInterface::class)->findAll();
        $msg = sanitize_text_field($_GET['msg'] ?? '');
        $status = sanitize_key($_GET['status'] ?? '');
        include PKSTORE_DIR . '/templates/admin/inventory/locations.php';
    }

    public function inventory(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        $repo      = $this->c->make(InventoryProductRepositoryInterface::class);
        $stockRepo = $this->c->make(StockRepositoryInterface::class);
        $locations = $this->c->make(LocationRepositoryInterface::class)->findAll();
        $search    = sanitize_text_field($_GET['s']??''  );
        $products  = $repo->findAll(50, 0, $search);
        $lowStock  = $repo->findBelowReorderPoint();
        $msg       = sanitize_text_field($_GET['msg']??''  );
        $status    = sanitize_key($_GET['status']??''  );
        include PKSTORE_DIR.'/templates/admin/inventory/inventory.php';
    }

    public function pos(): void {
        $this->requireSchemaReady();
        if (!current_user_can('use_junction_pos')) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('pos')) wp_die('POS is not available on the current package.');
        include PKSTORE_DIR.'/templates/pos/pos-app.php';
    }

    public function stockLog(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        global $wpdb;
        $movements = $wpdb->get_results(
            "SELECT m.*,p.name AS product_name,p.sku,l.name AS location_name
             FROM {$wpdb->prefix}pkstore_inventory_movements m
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_products p ON m.product_id=p.id
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_locations l ON m.location_id=l.id
             ORDER BY m.created_at DESC LIMIT 200",
            ARRAY_A
        ) ?: [];
        include PKSTORE_DIR.'/templates/admin/inventory/stock-log.php';
    }


    /**
     * Read-only operational view of durable WooCommerce inventory queues.
     * This deliberately offers no force-complete or direct stock mutation action.
     */
    public function inventoryEvents(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');

        global $wpdb;
        $tables = [
            'sales' => $wpdb->prefix . 'pkstore_wc_order_inventory_queue',
            'returns' => $wpdb->prefix . 'pkstore_wc_return_inventory_queue',
            'stock' => $wpdb->prefix . 'pkstore_wc_stock_sync',
        ];
        $available = [];
        $missingQueues = [];
        foreach ($tables as $key => $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            $available[$key] = ($found === $table);
            if (!$available[$key]) $missingQueues[] = $key;
        }

        $queueCounts = [];
        foreach ($tables as $key => $table) {
            $queueCounts[$key] = [];
            if (!$available[$key]) continue;
            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
                ARRAY_A
            ) ?: [];
            foreach ($rows as $row) {
                $queueCounts[$key][sanitize_key($row['status'])] = (int) $row['total'];
            }
        }

        $salesEvents = $available['sales'] ? ($wpdb->get_results(
            "SELECT id, 'WooCommerce sale' AS event, CONCAT('#',wc_order_id) AS reference,
                    CONCAT('Cycle ',LEFT(cycle_uuid,8)) AS details, status, attempts,
                    next_attempt_at, claimed_at, last_error, created_at
             FROM {$tables['sales']} ORDER BY updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $returnEvents = $available['returns'] ? ($wpdb->get_results(
            "SELECT id, CONCAT('WooCommerce ',event_kind,' restock') AS event,
                    CONCAT('#',wc_order_id) AS reference,
                    CONCAT('Cycle ',LEFT(COALESCE(cycle_uuid,'legacy'),8),' · Item ',COALESCE(wc_order_item_id,'—'),' · Product ',wc_product_id,' · Qty ',quantity) AS details,
                    status, attempts, next_attempt_at, claimed_at, last_error, created_at
             FROM {$tables['returns']} ORDER BY updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $stockEvents = $available['stock'] ? ($wpdb->get_results(
            "SELECT 'WooCommerce stock sync' AS event,
                    CONCAT('Junction product #',q.product_id) AS reference,
                    COALESCE(p.name,'Product mapping unavailable') AS details,
                    q.status, q.attempts, q.next_attempt_at, q.claimed_at, q.last_error, q.created_at
             FROM {$tables['stock']} q
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_products p ON p.id=q.product_id
             ORDER BY q.updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $cycleEventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
        $cycleEventsAvailable = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($cycleEventsTable)
        )) === $cycleEventsTable;
        if (!$cycleEventsAvailable) $missingQueues[] = 'stock-cycle events';
        $cycleEvents = $cycleEventsAvailable ? ($wpdb->get_results(
            "SELECT event_uuid,cycle_uuid,wc_order_id,wc_order_item_id,event_kind,quantity,
                    cumulative_quantity,source_hook,status,attempts,next_attempt_at,claimed_at,last_error,created_at
             FROM {$cycleEventsTable} ORDER BY updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $auditTable = $wpdb->prefix . 'pkstore_inventory_queue_audit';
        $auditExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($auditTable))) === $auditTable;
        if (!$auditExists) $missingQueues[] = 'operator audit';
        $operatorAudit = $auditExists ? ($wpdb->get_results(
            "SELECT queue_type, queue_id, action, previous_status, resulting_status, reason, actor_id, created_at
             FROM {$auditTable} ORDER BY id DESC LIMIT 50",
            ARRAY_A
        ) ?: []) : [];

        include PKSTORE_DIR.'/templates/admin/inventory/inventory-events.php';
    }

    public function purchaseOrders(): void {
        $this->requireSchemaReady();
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        global $wpdb;
        $pos = $wpdb->get_results(
            "SELECT po.*,s.name AS supplier_name,l.name AS location_name
             FROM {$wpdb->prefix}pkstore_purchase_orders po
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_suppliers s ON po.supplier_id=s.id
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_locations l ON po.location_id=l.id
             ORDER BY po.created_at DESC LIMIT 100",
            ARRAY_A
        ) ?: [];
        include PKSTORE_DIR.'/templates/admin/inventory/purchase-orders.php';
    }
}
