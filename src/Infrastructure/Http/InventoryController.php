<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Application\Inventory\{AdjustStockUseCase, GenerateBarcodeUseCase};
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface, LocationRepositoryInterface};
final class InventoryController {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('rest_api_init', function () {
            $ns = 'pkstore/v1';
            $auth = fn()=>current_user_can('manage_woocommerce') && FeatureManager::getInstance()->isEnabled('inventory');
            $barcodeAuth = fn()=>$auth() && FeatureManager::getInstance()->isEnabled('barcode_labels');
            register_rest_route($ns, '/inventory/products',         ['methods'=>'GET', 'callback'=>[$this,'listProducts'],     'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/products',         ['methods'=>'POST','callback'=>[$this,'createProduct'],    'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/products/(?P<id>\d+)', ['methods'=>'GET', 'callback'=>[$this,'getProduct'],   'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/search',           ['methods'=>'GET', 'callback'=>[$this,'searchProduct'],    'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/barcode/(?P<barcode>[A-Za-z0-9\-]+)', ['methods'=>'GET','callback'=>[$this,'lookupBarcode'],'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/stock/adjust',     ['methods'=>'POST','callback'=>[$this,'adjustStock'],     'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/stock/location/(?P<id>\d+)', ['methods'=>'GET','callback'=>[$this,'locationStock'],'permission_callback'=>$auth]);
            register_rest_route($ns, '/inventory/locations',        ['methods'=>'GET', 'callback'=>[$this,'listLocations'],    'permission_callback'=>$auth]);
            if (FeatureManager::getInstance()->isEnabled('barcode_labels')) {
                register_rest_route($ns, '/inventory/barcode/generate/(?P<id>\d+)', ['methods'=>'POST','callback'=>[$this,'generateBarcode'],'permission_callback'=>$barcodeAuth]);
            }
            register_rest_route($ns, '/inventory/low-stock',        ['methods'=>'GET', 'callback'=>[$this,'lowStock'],         'permission_callback'=>$auth]);
        });
    }
    public function listProducts(\WP_REST_Request $req): \WP_REST_Response {
        $limit  = min(100, max(1, (int)($req->get_param('limit') ?? 50)));
        $offset = max(0, (int)($req->get_param('offset') ?? 0));
        $search = sanitize_text_field($req->get_param('search') ?? '');
        $repo   = $this->c->make(InventoryProductRepositoryInterface::class);
        $stockRepo = $this->c->make(StockRepositoryInterface::class);
        $products = $repo->findAll($limit, $offset, $search);
        return new \WP_REST_Response(['success'=>true,'total'=>$repo->countAll(),'products'=>array_map(fn($p)=>['id'=>$p->id,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'barcode_type'=>$p->barcodeType,'name'=>$p->name,'category'=>$p->category,'cost_price'=>$p->costPricePkr,'sale_price'=>$p->salePricePkr,'total_stock'=>$stockRepo->getTotalStock($p->id),'reorder_point'=>$p->reorderPoint,'unit'=>$p->unit,'has_barcode'=>$p->hasBarcode(),'wc_product_id'=>$p->wcProductId],$products)],200);
    }
    public function getProduct(\WP_REST_Request $req): \WP_REST_Response {
        $p = $this->c->make(InventoryProductRepositoryInterface::class)->findById((int)$req->get_param('id'));
        if (!$p) return new \WP_REST_Response(['success'=>false,'message'=>'Product not found.'],404);
        $movements = $this->c->make(StockRepositoryInterface::class)->getMovements($p->id, 20);
        return new \WP_REST_Response(['success'=>true,'product'=>['id'=>$p->id,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'name'=>$p->name,'category'=>$p->category,'cost_price'=>$p->costPricePkr,'sale_price'=>$p->salePricePkr,'reorder_point'=>$p->reorderPoint,'unit'=>$p->unit,'total_stock'=>$this->c->make(StockRepositoryInterface::class)->getTotalStock($p->id)],'movements'=>$movements],200);
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
        $repo    = $this->c->make(InventoryProductRepositoryInterface::class);
        $sr      = $this->c->make(StockRepositoryInterface::class);
        $p = $repo->findByBarcode($barcode) ?? $repo->findBySku($barcode);
        if (!$p) return new \WP_REST_Response(['success'=>false,'message'=>'Product not found for this barcode.'],404);
        return new \WP_REST_Response(['success'=>true,'product'=>['id'=>$p->id,'sku'=>$p->sku,'barcode'=>$p->getBarcode(),'name'=>$p->name,'sale_price'=>$p->salePricePkr,'stock'=>$sr->getTotalStock($p->id),'unit'=>$p->unit]],200);
    }
    public function createProduct(\WP_REST_Request $req): \WP_REST_Response {
        $sku   = sanitize_text_field($req->get_param('sku') ?? '');
        $name  = sanitize_text_field($req->get_param('name') ?? '');
        if (empty($sku)||empty($name)) return new \WP_REST_Response(['success'=>false,'message'=>'SKU and name required.'],422);
        $costPrice = $req->get_param('cost_price') ?? 0;
        $salePrice = $req->get_param('sale_price') ?? 0;
        $reorderPoint = $req->get_param('reorder_point') ?? 5;
        $wcProductRaw = $req->get_param('wc_product_id');
        if ($wcProductRaw !== null && $wcProductRaw !== '' && filter_var($wcProductRaw, FILTER_VALIDATE_INT) === false) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Linked WooCommerce product ID must be a whole number.'],422);
        }
        $wcProductId = (int) ($wcProductRaw ?? 0);
        foreach ([$costPrice, $salePrice, $reorderPoint] as $numericValue) {
            if (!is_numeric($numericValue) || (float) $numericValue < 0 || floor((float) $numericValue) !== (float) $numericValue) {
                return new \WP_REST_Response(['success'=>false,'message'=>'Prices and reorder point must be non-negative whole numbers.'],422);
            }
        }
        if ($wcProductId < 0 || ($wcProductId > 0 && !wc_get_product($wcProductId))) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Linked WooCommerce product does not exist.'],422);
        }
        $repo = $this->c->make(InventoryProductRepositoryInterface::class);
        if ($repo->findBySku($sku)) return new \WP_REST_Response(['success'=>false,'message'=>'SKU already exists.'],409);
        $p = \PkStore\Domain\Inventory\Entity\InventoryProduct::create(
            $sku,
            $name,
            (int) $costPrice,
            (int) $salePrice,
            sanitize_text_field($req->get_param('category') ?? ''),
            $wcProductId > 0 ? $wcProductId : null,
            sanitize_text_field($req->get_param('barcode') ?? ''),
            sanitize_text_field($req->get_param('unit') ?? 'piece'),
            (int) $reorderPoint
        );
        $id  = $repo->save($p);
        if (!$p->hasBarcode() && FeatureManager::getInstance()->isEnabled('barcode_labels')) $this->c->make(GenerateBarcodeUseCase::class)->execute($id);
        return new \WP_REST_Response(['success'=>true,'product_id'=>$id,'message'=>'Product created.'],201);
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
            return new \\WP_REST_Response(['success'=>false,'message'=>'Purchase receipts and opening stock must add a positive quantity.'],422);
        }
        if ($type === MovementType::Damage && $quantity > -1) {
            return new \\WP_REST_Response(['success'=>false,'message'=>'Damage/loss must reduce stock using a negative quantity.'],422);
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
        $locations = $this->c->make(LocationRepositoryInterface::class)->findAll();
        return new \WP_REST_Response(['success'=>true,'locations'=>array_map(fn($l)=>['id'=>$l->id,'name'=>$l->name,'type'=>$l->type,'is_pos'=>$l->isPos,'is_online'=>$l->isOnline],$locations)],200);
    }
    public function generateBarcode(\WP_REST_Request $req): \WP_REST_Response {
        if (!FeatureManager::getInstance()->isEnabled('barcode_labels')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Barcode labels are not available on the current package.'],403);
        }
        try { $barcode=$this->c->make(GenerateBarcodeUseCase::class)->execute((int)$req->get_param('id')); return new \WP_REST_Response(['success'=>true,'barcode'=>$barcode],200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],404); }
    }
    public function lowStock(\WP_REST_Request $req): \WP_REST_Response {
        $products = $this->c->make(InventoryProductRepositoryInterface::class)->findBelowReorderPoint();
        $sr = $this->c->make(StockRepositoryInterface::class);
        return new \WP_REST_Response(['success'=>true,'count'=>count($products),'products'=>array_map(fn($p)=>['id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name,'total_stock'=>$sr->getTotalStock($p->id),'reorder_point'=>$p->reorderPoint],$products)],200);
    }
}
