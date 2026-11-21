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

    public function register(): void {
        add_action('admin_menu',            [$this, 'menus']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_init',            [$this, 'handleForms']);
    }

    public function menus(): void {
        $features = FeatureManager::getInstance();
        if (!$features->isEnabled('inventory')) return;

        add_submenu_page('pkstore-operations','Inventory','📦 Inventory',self::CAP,'pkstore-inventory',[$this,'inventory']);
        if ($features->isEnabled('pos')) {
            add_submenu_page('pkstore-operations','POS Register','🖥 POS Register',self::CAP,'pkstore-pos',[$this,'pos']);
        }
        add_submenu_page('pkstore-operations','Stock Movements','📊 Stock Log',self::CAP,'pkstore-stock-log',[$this,'stockLog']);
        add_submenu_page('pkstore-operations','Purchase Orders','🚚 Purchase Orders',self::CAP,'pkstore-purchase-orders',[$this,'purchaseOrders']);
    }

    public function assets(string $hook): void {
        $ours = ['store-ops_page_pkstore-inventory','store-ops_page_pkstore-pos','store-ops_page_pkstore-stock-log','store-ops_page_pkstore-purchase-orders'];
        if (!in_array($hook,$ours,true)) return;
        wp_enqueue_style('pkstore-admin',PKSTORE_ASSETS.'/css/admin.css',[],PKSTORE_VERSION);
    }

    public function handleForms(): void {
        if (!isset($_POST['pkstore_inventory_action'])) return;
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        $features = FeatureManager::getInstance();
        if (!$features->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        check_admin_referer('pkstore_inventory_action');
        $action = sanitize_key($_POST['pkstore_inventory_action']);
        if ($action === 'generate_barcodes' && !$features->isEnabled('barcode_labels')) {
            wp_die('Barcode labels are not available on the current package.');
        }
        match($action) {
            'add_product'     => $this->handleAddProduct(),
            'adjust_stock'    => $this->handleAdjustStock(),
            'generate_barcodes' => $this->handleGenerateBarcodes(),
            'sync_woocommerce'  => $this->handleSyncWooCommerce(),
            default            => null,
        };
    }

    private function handleAddProduct(): void {
        $repo = $this->c->make(InventoryProductRepositoryInterface::class);
        $sku  = sanitize_text_field($_POST['sku']??''  );
        $name = sanitize_text_field($_POST['name']??''  );
        if (empty($sku)||empty($name)){ $this->redirect('pkstore-inventory','error','SKU and name are required.'); return; }
        if ($repo->findBySku($sku)){ $this->redirect('pkstore-inventory','error','SKU already exists.'); return; }
        $p  = \PkStore\Domain\Inventory\Entity\InventoryProduct::create($sku,$name,(int)($_POST['cost_price']??0),(int)($_POST['sale_price']??0),sanitize_text_field($_POST['category']??''),(int)($_POST['wc_product_id']??0)?:(null),sanitize_text_field($_POST['barcode']??''),(sanitize_text_field($_POST['unit']??'piece')),(int)($_POST['reorder_point']??5));
        $id = $repo->save($p);
        $this->c->make(GenerateBarcodeUseCase::class)->execute($id);
        $this->redirect('pkstore-inventory','added','Product added and barcode generated.');
    }

    private function handleAdjustStock(): void {
        try {
            $this->c->make(AdjustStockUseCase::class)->execute(
                (int)($_POST['product_id']??0),(int)($_POST['location_id']??1),
                (int)($_POST['quantity']??0),\PkStore\Domain\Inventory\Entity\MovementType::from(sanitize_key($_POST['movement_type']??'adjustment')),
                sanitize_text_field($_POST['note']??'Manual adjustment')
            );
            $this->redirect('pkstore-inventory','adjusted','Stock updated.');
        } catch(\Throwable $e){ $this->redirect('pkstore-inventory','error',$e->getMessage()); }
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

    public function inventory(): void {
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
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('pos')) wp_die('POS is not available on the current package.');
        include PKSTORE_DIR.'/templates/pos/pos-app.php';
    }

    public function stockLog(): void {
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

    public function purchaseOrders(): void {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
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
