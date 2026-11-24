<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Application\Inventory\{AdjustStockUseCase, GenerateBarcodeUseCase, WooCommerceStockMappingPolicy};
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface, LocationRepositoryInterface};
final class InventoryController {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('rest_api_init', function () {
            $ns = 'pkstore/v1';
            $schemaReady = static fn(): bool => version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.13', '>=');
            $readAuth = fn()=>$schemaReady() && (current_user_can('manage_woocommerce') || current_user_can('use_junction_pos'))
                && FeatureManager::getInstance()->isEnabled('inventory');
            $manageAuth = fn()=>$schemaReady() && current_user_can('manage_woocommerce')
                && FeatureManager::getInstance()->isEnabled('inventory');
            $barcodeAuth = fn()=>$manageAuth() && FeatureManager::getInstance()->isEnabled('barcode_labels');
            register_rest_route($ns, '/inventory/products',         ['methods'=>'GET', 'callback'=>[$this,'listProducts'],     'permission_callback'=>$readAuth]);
            register_rest_route($ns, '/inventory/products',         ['methods'=>'POST','callback'=>[$this,'createProduct'],    'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/products/(?P<id>\d+)', ['methods'=>'GET', 'callback'=>[$this,'getProduct'],   'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/search',           ['methods'=>'GET', 'callback'=>[$this,'searchProduct'],    'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/barcode/(?P<barcode>[A-Za-z0-9\-]+)', ['methods'=>'GET','callback'=>[$this,'lookupBarcode'],'permission_callback'=>$readAuth]);
            register_rest_route($ns, '/inventory/stock/adjust',     ['methods'=>'POST','callback'=>[$this,'adjustStock'],     'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/stock/location/(?P<id>\d+)', ['methods'=>'GET','callback'=>[$this,'locationStock'],'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/locations',        ['methods'=>'GET', 'callback'=>[$this,'listLocations'],    'permission_callback'=>$readAuth]);
            if (FeatureManager::getInstance()->isEnabled('barcode_labels')) {
                register_rest_route($ns, '/inventory/barcode/generate/(?P<id>\d+)', ['methods'=>'POST','callback'=>[$this,'generateBarcode'],'permission_callback'=>$barcodeAuth]);
            }
            register_rest_route($ns, '/inventory/low-stock',        ['methods'=>'GET', 'callback'=>[$this,'lowStock'],         'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/suppliers', ['methods'=>'GET','callback'=>[$this,'listSuppliers'],'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/suppliers', ['methods'=>'POST','callback'=>[$this,'createSupplier'],'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/purchase-orders', ['methods'=>'POST','callback'=>[$this,'createPurchaseOrder'],'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/purchase-orders/(?P<id>\\d+)', ['methods'=>'GET','callback'=>[$this,'getPurchaseOrder'],'permission_callback'=>$manageAuth]);
            register_rest_route($ns, '/inventory/purchase-orders/(?P<id>\\d+)/receive', ['methods'=>'POST','callback'=>[$this,'receivePurchaseOrder'],'permission_callback'=>$manageAuth]);
        });
    }
    public function listProducts(\WP_REST_Request $req): \WP_REST_Response {
        $limit  = min(100, max(1, (int)($req->get_param('limit') ?? 50)));
        $offset = max(0, (int)($req->get_param('offset') ?? 0));
        $search = sanitize_text_field($req->get_param('search') ?? '');
        $locationId = absint($req->get_param('location_id') ?? 0);
        $isCashier = current_user_can('use_junction_pos') && !current_user_can('manage_woocommerce');

        if ($isCashier) {
            $assignedLocationId = (int) get_user_meta(get_current_user_id(), '_pkstore_pos_location_id', true);
            if ($locationId < 1 || $locationId !== $assignedLocationId) {
                return new \WP_REST_Response(['success'=>false,'message'=>'A valid assigned POS location is required.'],403);
            }
        }
        if ($locationId > 0) {
            $location = $this->c->make(LocationRepositoryInterface::class)->findById($locationId);
            if (!$location || !$location->isActive || !$location->isPos) {
                return new \WP_REST_Response(['success'=>false,'message'=>'The requested POS location is unavailable.'],404);
            }
        }

        $repo = $this->c->make(InventoryProductRepositoryInterface::class);
        $stockRepo = $this->c->make(StockRepositoryInterface::class);
        $products = $repo->findAll($limit, $offset, $search);
        $canViewCosts = current_user_can('manage_options');
        $rows = array_map(function ($p) use ($stockRepo, $canViewCosts, $locationId): array {
            $stock = $locationId > 0
                ? $stockRepo->getStock($p->id, $locationId)->getQuantity()
                : $stockRepo->getTotalStock($p->id);
            $row = [
                'id'=>$p->id,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'barcode_type'=>$p->barcodeType,
                'name'=>$p->name,'category'=>$p->category,'sale_price'=>$p->salePricePkr,
                'total_stock'=>$stock,'location_id'=>$locationId,'reorder_point'=>$p->reorderPoint,
                'unit'=>$p->unit,'has_barcode'=>$p->hasBarcode(),'wc_product_id'=>$p->wcProductId,
            ];
            if ($canViewCosts) $row['cost_price'] = $p->costPricePkr;
            return $row;
        }, $products);
        return new \WP_REST_Response(['success'=>true,'total'=>$repo->countAll(),'products'=>$rows],200);
    }

    public function getProduct(\WP_REST_Request $req): \WP_REST_Response {
        $p = $this->c->make(InventoryProductRepositoryInterface::class)->findById((int)$req->get_param('id'));
        if (!$p) return new \WP_REST_Response(['success'=>false,'message'=>'Product not found.'],404);
        $movements = $this->c->make(StockRepositoryInterface::class)->getMovements($p->id, 20);
        $productData = [
            'id'=>$p->id,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'name'=>$p->name,
            'category'=>$p->category,'sale_price'=>$p->salePricePkr,'reorder_point'=>$p->reorderPoint,
            'unit'=>$p->unit,'total_stock'=>$this->c->make(StockRepositoryInterface::class)->getTotalStock($p->id),
        ];
        if (current_user_can('manage_options')) $productData['cost_price'] = $p->costPricePkr;
        return new \WP_REST_Response(['success'=>true,'product'=>$productData,'movements'=>$movements],200);
    }
    public function searchProduct(\WP_REST_Request $req): \WP_REST_Response {
        $q    = sanitize_text_field($req->get_param('q') ?? '');
        $repo = $this->c->make(InventoryProductRepositoryInterface::class);
        $sr   = $this->c->make(StockRepositoryInterface::class);
        $products = $repo->findAll(20, 0, $q);
        return new \WP_REST_Response(['success'=>true,'products'=>array_map(fn($p)=>['id'=>$p->id,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'name'=>$p->name,'sale_price'=>$p->salePricePkr,'stock'=>$sr->getTotalStock($p->id),'unit'=>$p->unit],$products)],200);
    }
    public function lookupBarcode(\WP_REST_Request $req): \WP_REST_Response {
        $barcode = sanitize_text_field($req->get_param('barcode'));
        $locationId = absint($req->get_param('location_id') ?? 0);
        $isCashier = current_user_can('use_junction_pos') && !current_user_can('manage_woocommerce');
        if ($isCashier) {
            $assignedLocationId = (int) get_user_meta(get_current_user_id(), '_pkstore_pos_location_id', true);
            if ($locationId < 1 || $locationId !== $assignedLocationId) {
                return new \WP_REST_Response(['success'=>false,'message'=>'A valid assigned POS location is required.'],403);
            }
        }
        if ($locationId > 0) {
            $location = $this->c->make(LocationRepositoryInterface::class)->findById($locationId);
            if (!$location || !$location->isActive || !$location->isPos) {
                return new \WP_REST_Response(['success'=>false,'message'=>'The requested POS location is unavailable.'],404);
            }
        }
        $repo = $this->c->make(InventoryProductRepositoryInterface::class);
        $stockRepo = $this->c->make(StockRepositoryInterface::class);
        $product = $repo->findByBarcode($barcode) ?? $repo->findBySku($barcode);
        if (!$product) return new \WP_REST_Response(['success'=>false,'message'=>'Product not found for this barcode.'],404);
        $stock = $locationId > 0
            ? $stockRepo->getStock($product->id, $locationId)->getQuantity()
            : $stockRepo->getTotalStock($product->id);
        return new \WP_REST_Response(['success'=>true,'product'=>[
            'id'=>$product->id,'sku'=>$product->sku,'barcode'=>$product->getBarcode(),
            'name'=>$product->name,'sale_price'=>$product->salePricePkr,'total_stock'=>$stock,
            'location_id'=>$locationId,'unit'=>$product->unit,
        ]],200);
    }

    public function createProduct(\WP_REST_Request $req): \WP_REST_Response {
        $sku = sanitize_text_field((string) ($req->get_param('sku') ?? ''));
        $name = sanitize_text_field((string) ($req->get_param('name') ?? ''));
        $category = sanitize_text_field((string) ($req->get_param('category') ?? ''));
        $unit = sanitize_text_field((string) ($req->get_param('unit') ?? 'piece'));
        $barcode = sanitize_text_field((string) ($req->get_param('barcode') ?? ''));

        $salePriceRaw = $req->get_param('sale_price');
        $costPriceRaw = current_user_can('manage_options') ? ($req->get_param('cost_price') ?? 0) : 0;
        $reorderRaw = $req->get_param('reorder_point') ?? 5;
        $wcProductRaw = $req->get_param('wc_product_id') ?? 0;
        $salePrice = filter_var($salePriceRaw, FILTER_VALIDATE_INT);
        $costPrice = filter_var($costPriceRaw, FILTER_VALIDATE_INT);
        $reorderPoint = filter_var($reorderRaw, FILTER_VALIDATE_INT);
        $wcProductId = filter_var($wcProductRaw, FILTER_VALIDATE_INT);

        if ($sku === '' || strlen($sku) > 100 || $name === '' || strlen($name) > 255
            || strlen($category) > 100 || $unit === '' || strlen($unit) > 20 || strlen($barcode) > 50
            || $salePrice === false || $costPrice === false || $reorderPoint === false || $wcProductId === false
            || $salePrice < 0 || $costPrice < 0 || $reorderPoint < 0 || $wcProductId < 0) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Product fields must contain valid values and whole-number PKR amounts.'], 422);
        }
        if ($wcProductId > 0) {
            if (!function_exists('wc_get_product') || !($wcProduct = wc_get_product($wcProductId))) {
                return new \WP_REST_Response(['success' => false, 'message' => 'The selected WooCommerce product does not exist.'], 422);
            }
            $mappingError = WooCommerceStockMappingPolicy::errorFor($wcProduct);
            if ($mappingError !== null) {
                return new \WP_REST_Response(['success' => false, 'message' => $mappingError], 422);
            }
        }

        try {
            $repo = $this->c->make(InventoryProductRepositoryInterface::class);
            if ($repo->findBySku($sku)) {
                return new \WP_REST_Response(['success' => false, 'message' => 'SKU already exists.'], 409);
            }
            if ($barcode !== '' && $repo->findByBarcode($barcode)) {
                return new \WP_REST_Response(['success' => false, 'message' => 'Barcode already exists.'], 409);
            }
            if ($wcProductId > 0 && $repo->findByWcProductId($wcProductId)) {
                return new \WP_REST_Response(['success' => false, 'message' => 'This WooCommerce product is already mapped to Junction inventory.'], 409);
            }

            $product = \PkStore\Domain\Inventory\Entity\InventoryProduct::create(
                $sku,
                $name,
                $costPrice,
                $salePrice,
                $category,
                $wcProductId > 0 ? $wcProductId : null,
                $barcode,
                $unit,
                $reorderPoint
            );
            $id = $repo->save($product);
            $barcodeGenerated = false;
            if (!$product->hasBarcode() && FeatureManager::getInstance()->isEnabled('barcode_labels')) {
                try {
                    $barcodeGenerated = (bool) $this->c->make(GenerateBarcodeUseCase::class)->execute($id);
                } catch (\Throwable $barcodeError) {
                    error_log('[PKStore Inventory] Product was created but automatic barcode generation failed ('.get_class($barcodeError).').');
                }
            }
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Inventory product created.',
                'product' => [
                    'id' => $id,
                    'sku' => $sku,
                    'name' => $name,
                    'wc_product_id' => $wcProductId > 0 ? $wcProductId : null,
                    'barcode_generated' => $barcodeGenerated,
                ],
            ], 201);
        } catch (\DomainException $error) {
            return new \WP_REST_Response(['success' => false, 'message' => $error->getMessage()], 409);
        } catch (\Throwable $error) {
            error_log('[PKStore Inventory] REST product creation failed ('.get_class($error).').');
            return new \WP_REST_Response(['success' => false, 'message' => 'Inventory product could not be created. Check the product mapping and try again.'], 500);
        }
    }

    public function adjustStock(\WP_REST_Request $req): \WP_REST_Response {
        $productRaw = $req->get_param('product_id');
        $locationRaw = $req->get_param('location_id');
        $quantityRaw = $req->get_param('quantity');
        foreach ([$productRaw, $locationRaw, $quantityRaw] as $raw) {
            if ($raw === null || $raw === '' || filter_var($raw, FILTER_VALIDATE_INT) === false) {
                return new \WP_REST_Response(['success'=>false,'message'=>'Product, location and quantity must be whole numbers.'],422);
            }
        }

        $productId = (int) $productRaw;
        $locationId = (int) $locationRaw;
        $quantity = (int) $quantityRaw;
        $typeValue = sanitize_key($req->get_param('type') ?? 'adjustment');
        $allowedTypes = [MovementType::Adjustment, MovementType::PurchaseReceive, MovementType::Damage, MovementType::Opening];
        $type = MovementType::tryFrom($typeValue);
        if ($productId < 1 || $locationId < 1 || $quantity === 0 || !$type || !in_array($type, $allowedTypes, true)) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Invalid stock adjustment details or movement type.'],422);
        }

        if (($type === MovementType::PurchaseReceive || $type === MovementType::Opening) && $quantity < 1) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Purchase receipts and opening stock must add a positive quantity.'],422);
        }
        if ($type === MovementType::Damage && $quantity > -1) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Damage/loss must reduce stock using a negative quantity.'],422);
        }

        $product = $this->c->make(InventoryProductRepositoryInterface::class)->findById($productId);
        $location = $this->c->make(LocationRepositoryInterface::class)->findById($locationId);
        if (!$product || !$product->isActive || !$location || !$location->isActive) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Active product and location are required.'],422);
        }

        $note = sanitize_text_field($req->get_param('note') ?? 'Manual adjustment');
        try {
            $this->c->make(AdjustStockUseCase::class)->executeManualAdjustment($productId, $locationId, $quantity, $type, $note);
            return new \WP_REST_Response(['success'=>true,'message'=>'Stock adjusted.'],200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Stock adjustment could not be completed. Verify the available stock and try again.'],422);
        }
    }
    public function locationStock(\WP_REST_Request $req): \WP_REST_Response {
        $locationId = (int)$req->get_param('id');
        $stock = $this->c->make(StockRepositoryInterface::class)->getLocationStock($locationId);
        return new \WP_REST_Response(['success'=>true,'location_id'=>$locationId,'stock'=>$stock],200);
    }
    public function listLocations(\WP_REST_Request $req): \WP_REST_Response {
        // POS sessions may only be opened against physical locations explicitly enabled for POS.
        $locations = $this->c->make(LocationRepositoryInterface::class)->findPOS();
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            $assignedLocationId = (int) get_user_meta(get_current_user_id(), '_pkstore_pos_location_id', true);
            $locations = array_values(array_filter(
                $locations,
                static fn($location): bool => $location->id === $assignedLocationId
            ));
        }
        return new \WP_REST_Response(['success'=>true,'locations'=>array_map(fn($l)=>['id'=>$l->id,'name'=>$l->name,'type'=>$l->type,'is_pos'=>$l->isPos],$locations)],200);
    }
    public function generateBarcode(\WP_REST_Request $req): \WP_REST_Response {
        if (!FeatureManager::getInstance()->isEnabled('barcode_labels')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Barcode labels are not available on the current package.'],403);
        }
        try { $barcode=$this->c->make(GenerateBarcodeUseCase::class)->execute((int)$req->get_param('id')); return new \WP_REST_Response(['success'=>true,'barcode'=>$barcode],200);
        } catch (\Throwable) { return new \WP_REST_Response(['success'=>false,'message'=>'Barcode generation could not be completed.'],422); }
    }
    public function lowStock(\WP_REST_Request $req): \WP_REST_Response {
        $products = $this->c->make(InventoryProductRepositoryInterface::class)->findBelowReorderPoint();
        $sr = $this->c->make(StockRepositoryInterface::class);
        return new \WP_REST_Response(['success'=>true,'count'=>count($products),'products'=>array_map(fn($p)=>['id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name,'total_stock'=>$sr->getTotalStock($p->id),'reorder_point'=>$p->reorderPoint],$products)],200);
    }

    public function listSuppliers(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_inventory_suppliers';
        $rows = $wpdb->get_results("SELECT id,name,contact,phone,email,address FROM {$table} WHERE is_active=1 ORDER BY name", ARRAY_A) ?: [];
        if ($wpdb->last_error !== '') return new \WP_REST_Response(['success'=>false,'message'=>'Unable to load suppliers.'],500);
        return new \WP_REST_Response(['success'=>true,'suppliers'=>$rows],200);
    }

    public function createSupplier(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $name = sanitize_text_field($req->get_param('name') ?? '');
        $email = sanitize_email($req->get_param('email') ?? '');
        if ($name === '') return new \WP_REST_Response(['success'=>false,'message'=>'Supplier name is required.'],422);
        if ($email !== '' && !is_email($email)) return new \WP_REST_Response(['success'=>false,'message'=>'Supplier email is invalid.'],422);
        $table = $wpdb->prefix . 'pkstore_inventory_suppliers';
        $ok = $wpdb->insert($table, [
            'name'=>$name,'contact'=>sanitize_text_field($req->get_param('contact') ?? ''),
            'phone'=>sanitize_text_field($req->get_param('phone') ?? ''),'email'=>$email,
            'address'=>sanitize_textarea_field($req->get_param('address') ?? ''),
            'is_active'=>1,'created_at'=>current_time('mysql'),
        ]);
        if ($ok === false) return new \WP_REST_Response(['success'=>false,'message'=>'Unable to create supplier.'],500);
        return new \WP_REST_Response(['success'=>true,'supplier_id'=>(int)$wpdb->insert_id],201);
    }

    public function createPurchaseOrder(\WP_REST_Request $req): \WP_REST_Response {
        try {
            $items = $req->get_param('items');
            if (!is_array($items)) return new \WP_REST_Response(['success'=>false,'message'=>'items must be an array.'],422);
            $id = $this->c->make(\PkStore\Application\Inventory\CreatePurchaseOrderUseCase::class)->execute(
                (int)$req->get_param('supplier_id'), (int)$req->get_param('location_id'), $items,
                sanitize_textarea_field($req->get_param('notes') ?? '')
            );
            return new \WP_REST_Response(['success'=>true,'purchase_order_id'=>$id,'message'=>'Purchase order created.'],201);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422);
        }
    }

    public function getPurchaseOrder(\WP_REST_Request $req): \WP_REST_Response {
        $po = $this->c->make(\PkStore\Domain\Inventory\Repository\PurchaseOrderRepositoryInterface::class)->findById((int)$req->get_param('id'));
        if (!$po) return new \WP_REST_Response(['success'=>false,'message'=>'Purchase order not found.'],404);
        return new \WP_REST_Response(['success'=>true,'purchase_order'=>[
            'id'=>$po->id,'po_number'=>$po->poNumber,'supplier_id'=>$po->supplierId,
            'location_id'=>$po->locationId,'status'=>$po->getStatus()->value,
            'total_pkr'=>$po->totalPkr,'items'=>$po->getItems(),
        ]],200);
    }

    public function receivePurchaseOrder(\WP_REST_Request $req): \WP_REST_Response {
        try {
            $items = $req->get_param('items');
            $reference = sanitize_text_field($req->get_param('receipt_reference') ?? '');
            if (!is_array($items) || $reference === '') {
                return new \WP_REST_Response(['success'=>false,'message'=>'items and a stable receipt_reference are required.'],422);
            }
            $this->c->make(\PkStore\Application\Inventory\ReceivePurchaseOrderUseCase::class)->execute(
                (int)$req->get_param('id'), $items, $reference
            );
            return new \WP_REST_Response(['success'=>true,'message'=>'Purchase receipt processed.','receipt_reference'=>$reference],200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422);
        }
    }
}
