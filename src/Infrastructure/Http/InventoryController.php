<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Application\Inventory\{AdjustStockUseCase, GenerateBarcodeUseCase};
use PkStore\Container\ServiceContainer;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface, LocationRepositoryInterface};
final class InventoryController {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('rest_api_init', function () {
            $ns = 'pkstore/v1';
            $auth = 'manage_woocommerce';
            register_rest_route($ns, '/inventory/products',         ['methods'=>'GET', 'callback'=>[$this,'listProducts'],     'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/products',         ['methods'=>'POST','callback'=>[$this,'createProduct'],    'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/products/(?P<id>\d+)', ['methods'=>'GET', 'callback'=>[$this,'getProduct'],   'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/search',           ['methods'=>'GET', 'callback'=>[$this,'searchProduct'],    'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/barcode/(?P<barcode>[A-Za-z0-9\-]+)', ['methods'=>'GET','callback'=>[$this,'lookupBarcode'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/stock/adjust',     ['methods'=>'POST','callback'=>[$this,'adjustStock'],     'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/stock/transfer', ['methods'=>'POST','callback'=>[$this,'transferStock'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/stock/location/(?P<id>\d+)', ['methods'=>'GET','callback'=>[$this,'locationStock'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/locations',        ['methods'=>'GET', 'callback'=>[$this,'listLocations'],    'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/barcode/generate/(?P<id>\d+)', ['methods'=>'POST','callback'=>[$this,'generateBarcode'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/low-stock',        ['methods'=>'GET', 'callback'=>[$this,'lowStock'],         'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/suppliers', ['methods'=>'GET','callback'=>[$this,'listSuppliers'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/suppliers', ['methods'=>'POST','callback'=>[$this,'createSupplier'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/purchase-orders', ['methods'=>'POST','callback'=>[$this,'createPurchaseOrder'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/purchase-orders/(?P<id>\d+)', ['methods'=>'GET','callback'=>[$this,'getPurchaseOrder'],'permission_callback'=>fn()=>current_user_can($auth)]);
            register_rest_route($ns, '/inventory/purchase-orders/(?P<id>\d+)/receive', ['methods'=>'POST','callback'=>[$this,'receivePurchaseOrder'],'permission_callback'=>fn()=>current_user_can($auth)]);
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
        $repo  = $this->c->make(InventoryProductRepositoryInterface::class);
        if ($repo->findBySku($sku)) return new \WP_REST_Response(['success'=>false,'message'=>'SKU already exists.'],409);
        $p   = \PkStore\Domain\Inventory\Entity\InventoryProduct::create($sku,$name,(int)($req->get_param('cost_price')??0),(int)($req->get_param('sale_price')??0),sanitize_text_field($req->get_param('category')??''),(((int)($req->get_param('wc_product_id')??0))>0 ? (int)$req->get_param('wc_product_id') : null),sanitize_text_field($req->get_param('barcode')??''),(sanitize_text_field($req->get_param('unit')??'piece')),(int)($req->get_param('reorder_point')??5));
        $id  = $repo->save($p);
        if (!$p->hasBarcode()) $this->c->make(GenerateBarcodeUseCase::class)->execute($id);
        return new \WP_REST_Response(['success'=>true,'product_id'=>$id,'message'=>'Product created.'],201);
    }
    public function adjustStock(\WP_REST_Request $req): \WP_REST_Response {
        $productId  = (int)($req->get_param('product_id')??0);
        $locationId = (int)($req->get_param('location_id')??0);
        $quantity   = (int)($req->get_param('quantity')??0);
        $type       = sanitize_key($req->get_param('type')??'adjustment');
        $note       = sanitize_text_field($req->get_param('note')??'Manual adjustment');
        if (!$productId||!$locationId) return new \WP_REST_Response(['success'=>false,'message'=>'product_id and location_id required.'],422);
        try { $this->c->make(AdjustStockUseCase::class)->execute($productId,$locationId,$quantity,MovementType::from($type),$note); return new \WP_REST_Response(['success'=>true,'message'=>'Stock adjusted.'],200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422); }
    }
    public function transferStock(\WP_REST_Request $req): \WP_REST_Response {
        try {
            $reference=sanitize_text_field($req->get_param('reference_id')??'');
            if($reference==='') return new \WP_REST_Response(['success'=>false,'message'=>'A stable reference_id is required for safe transfer retries.'],422);
            $result=$this->c->make(\PkStore\Application\Inventory\TransferStockUseCase::class)->execute(
                (int)$req->get_param('product_id'),(int)$req->get_param('from_location_id'),(int)$req->get_param('to_location_id'),
                (int)$req->get_param('quantity'),$reference,sanitize_text_field($req->get_param('note')??'')
            );
            return new \WP_REST_Response(['success'=>true,'reference_id'=>$result,'message'=>'Stock transferred.'],200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422); }
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
        try { $barcode=$this->c->make(GenerateBarcodeUseCase::class)->execute((int)$req->get_param('id')); return new \WP_REST_Response(['success'=>true,'barcode'=>$barcode],200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],404); }
    }
    public function listSuppliers(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb; $table=$wpdb->prefix.'pkstore_inventory_suppliers';
        $rows=$wpdb->get_results("SELECT id,name,contact,phone,email,address FROM {$table} WHERE is_active=1 ORDER BY name",ARRAY_A)?:[];
        return new \WP_REST_Response(['success'=>true,'suppliers'=>$rows],200);
    }
    public function createSupplier(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb; $table=$wpdb->prefix.'pkstore_inventory_suppliers';
        $name=sanitize_text_field($req->get_param('name')??'');
        if($name==='') return new \WP_REST_Response(['success'=>false,'message'=>'Supplier name is required.'],422);
        $ok=$wpdb->insert($table,['name'=>$name,'contact'=>sanitize_text_field($req->get_param('contact')??''),'phone'=>sanitize_text_field($req->get_param('phone')??''),'email'=>sanitize_email($req->get_param('email')??''),'address'=>sanitize_textarea_field($req->get_param('address')??''),'is_active'=>1,'created_at'=>current_time('mysql')]);
        if($ok===false) return new \WP_REST_Response(['success'=>false,'message'=>'Unable to create supplier.'],500);
        return new \WP_REST_Response(['success'=>true,'supplier_id'=>(int)$wpdb->insert_id],201);
    }
    public function createPurchaseOrder(\WP_REST_Request $req): \WP_REST_Response {
        try {
            $items=$req->get_param('items');
            if(!is_array($items)) return new \WP_REST_Response(['success'=>false,'message'=>'items must be an array.'],422);
            $id=$this->c->make(\PkStore\Application\Inventory\CreatePurchaseOrderUseCase::class)->execute(
                (int)$req->get_param('supplier_id'),(int)$req->get_param('location_id'),$items,sanitize_textarea_field($req->get_param('notes')??'')
            );
            return new \WP_REST_Response(['success'=>true,'purchase_order_id'=>$id,'message'=>'Purchase order created.'],201);
        } catch (\Throwable $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422); }
    }
    public function getPurchaseOrder(\WP_REST_Request $req): \WP_REST_Response {
        $po=$this->c->make(\PkStore\Domain\Inventory\Repository\PurchaseOrderRepositoryInterface::class)->findById((int)$req->get_param('id'));
        if(!$po) return new \WP_REST_Response(['success'=>false,'message'=>'Purchase order not found.'],404);
        return new \WP_REST_Response(['success'=>true,'purchase_order'=>['id'=>$po->id,'po_number'=>$po->poNumber,'supplier_id'=>$po->supplierId,'location_id'=>$po->locationId,'status'=>$po->getStatus()->value,'total_pkr'=>$po->totalPkr,'items'=>$po->getItems()]],200);
    }
    public function receivePurchaseOrder(\WP_REST_Request $req): \WP_REST_Response {
        try {
            $items=$req->get_param('items'); $reference=sanitize_text_field($req->get_param('receipt_reference')??'');
            if(!is_array($items) || $reference==='') return new \WP_REST_Response(['success'=>false,'message'=>'items and a stable receipt_reference are required.'],422);
            $this->c->make(\PkStore\Application\Inventory\ReceivePurchaseOrderUseCase::class)->execute((int)$req->get_param('id'),$items,$reference);
            return new \WP_REST_Response(['success'=>true,'message'=>'Purchase receipt processed.','receipt_reference'=>$reference],200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422); }
    }
    public function lowStock(\WP_REST_Request $req): \WP_REST_Response {
        $products = $this->c->make(InventoryProductRepositoryInterface::class)->findBelowReorderPoint();
        $sr = $this->c->make(StockRepositoryInterface::class);
        return new \WP_REST_Response(['success'=>true,'count'=>count($products),'products'=>array_map(fn($p)=>['id'=>$p->id,'sku'=>$p->sku,'name'=>$p->name,'total_stock'=>$sr->getTotalStock($p->id),'reorder_point'=>$p->reorderPoint],$products)],200);
    }
}
