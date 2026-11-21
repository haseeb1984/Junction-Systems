<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Application\POS\{ProcessPOSSaleUseCase, SyncPOSQueueUseCase};
use PkStore\Container\ServiceContainer;
use PkStore\Domain\POS\Repository\{POSSessionRepositoryInterface, POSOrderRepositoryInterface};
use PkStore\Domain\POS\Entity\POSSession;
final class POSController {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('rest_api_init', function () {
            $ns   = 'pkstore/v1';
            $auth = fn()=>current_user_can('manage_woocommerce');
            register_rest_route($ns,'/pos/session/open',      ['methods'=>'POST','callback'=>[$this,'openSession'],  'permission_callback'=>$auth]);
            register_rest_route($ns,'/pos/session/close',     ['methods'=>'POST','callback'=>[$this,'closeSession'], 'permission_callback'=>$auth]);
            register_rest_route($ns,'/pos/session/current',   ['methods'=>'GET', 'callback'=>[$this,'currentSession'],'permission_callback'=>$auth]);
            register_rest_route($ns,'/pos/sale',              ['methods'=>'POST','callback'=>[$this,'processSale'],  'permission_callback'=>$auth]);
            register_rest_route($ns,'/pos/sync',              ['methods'=>'POST','callback'=>[$this,'syncQueue'],    'permission_callback'=>$auth]);
            register_rest_route($ns,'/pos/orders',            ['methods'=>'GET', 'callback'=>[$this,'listOrders'],   'permission_callback'=>$auth]);
            register_rest_route($ns,'/pos/receipt/whatsapp',  ['methods'=>'POST','callback'=>[$this,'sendWhatsApp'], 'permission_callback'=>$auth]);
        });
    }
    public function openSession(\WP_REST_Request $req): \WP_REST_Response {
        $locationId  = (int)($req->get_param('location_id')??1);
        $openingCash = (int)($req->get_param('opening_cash')??0);
        $sessionRepo = $this->c->make(POSSessionRepositoryInterface::class);
        $existing    = $sessionRepo->findOpenByLocation($locationId);
        if ($existing) {
            if ($existing->cashierId !== get_current_user_id()) {
                return new \WP_REST_Response(['success'=>false,'message'=>'This location has an open session assigned to another cashier.'],409);
            }
            return new \WP_REST_Response(['success'=>true,'session_id'=>$existing->id,'message'=>'Existing session resumed.','resumed'=>true],200);
        }
        $session   = POSSession::open($locationId, get_current_user_id(), $openingCash);
        $sessionId = $sessionRepo->save($session);
        return new \WP_REST_Response(['success'=>true,'session_id'=>$sessionId,'message'=>'POS session opened.','resumed'=>false],201);
    }
    public function closeSession(\WP_REST_Request $req): \WP_REST_Response {
        $sessionId   = (int)($req->get_param('session_id')??0);
        $closingCash = (int)($req->get_param('closing_cash')??0);
        $sessionRepo = $this->c->make(POSSessionRepositoryInterface::class);
        $session     = $sessionRepo->findById($sessionId);
        if (!$session||!$session->isOpen()) return new \WP_REST_Response(['success'=>false,'message'=>'Session not found or already closed.'],404);
        if ($session->cashierId !== get_current_user_id() && !current_user_can('manage_options')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'You are not assigned to this POS session.'],403);
        }
        $session->close($closingCash);
        $sessionRepo->update($session);
        return new \WP_REST_Response(['success'=>true,'message'=>'Session closed.','total_sales'=>$session->getTotalSalesPkr(),'cash_variance'=>$session->getCashVariance()],200);
    }
    public function currentSession(\WP_REST_Request $req): \WP_REST_Response {
        $locationId = (int)($req->get_param('location_id')??1);
        $session    = $this->c->make(POSSessionRepositoryInterface::class)->findOpenByLocation($locationId);
        if (!$session) return new \WP_REST_Response(['success'=>false,'message'=>'No open session.'],404);
        if ($session->cashierId !== get_current_user_id() && !current_user_can('manage_options')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'You are not assigned to this POS session.'],403);
        }
        return new \WP_REST_Response(['success'=>true,'session'=>['id'=>$session->id,'location_id'=>$session->locationId,'cashier_id'=>$session->cashierId,'opening_cash'=>$session->openingCash,'total_sales'=>$session->getTotalSalesPkr(),'opened_at'=>$session->openedAt->format('Y-m-d H:i:s')]],200);
    }
    public function processSale(\WP_REST_Request $req): \WP_REST_Response {
        $sessionId    = (int)($req->get_param('session_id')??0);
        $locationId   = (int)($req->get_param('location_id')??1);
        $items        = $req->get_param('items')??[];
        $method       = sanitize_key($req->get_param('payment_method')??'cash');
        $customerPhone= sanitize_text_field($req->get_param('customer_phone')??''  );
        $customerName = sanitize_text_field($req->get_param('customer_name')??'Walk-in');
        $discount     = (int)($req->get_param('discount_pkr')??0);
        $split        = $req->get_param('payment_split');
        if (empty($items)) return new \WP_REST_Response(['success'=>false,'message'=>'No items in sale.'],422);
        $session = $this->c->make(POSSessionRepositoryInterface::class)->findById($sessionId);
        if (!$session || !$session->isOpen() || $session->locationId !== $locationId) {
            return new \WP_REST_Response(['success'=>false,'message'=>'The POS session is invalid or closed.'],409);
        }
        if ($session->cashierId !== get_current_user_id() && !current_user_can('manage_options')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'You are not assigned to this POS session.'],403);
        }
        try {
            $order = $this->c->make(ProcessPOSSaleUseCase::class)->execute($sessionId,$locationId,$items,$method,$customerPhone,$customerName,$discount,$split);
            return new \WP_REST_Response(['success'=>true,'order_ref'=>$order->orderRef,'total'=>$order->totalPkr,'message'=>'Sale processed.'],201);
        } catch (\Throwable $e) {
            error_log('[PK Store POS] Sale processing failed: ' . get_class($e));
            return new \WP_REST_Response(['success'=>false,'message'=>'Sale could not be completed. Please retry or contact support.'],422);
        }
    }
    public function syncQueue(\WP_REST_Request $req): \WP_REST_Response {
        $result = $this->c->make(SyncPOSQueueUseCase::class)->execute();
        $available = ($result['failed'] ?? 0) === 0;
        return new \WP_REST_Response([
            'success' => $available,
            'synced' => (int)($result['synced'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'errors' => $result['errors'] ?? [],
            'message' => $available
                ? 'No pending POS orders require synchronization.'
                : 'POS orders are safely retained as pending; WooCommerce synchronization is not yet available.',
        ], $available ? 200 : 501);
    }
    public function listOrders(\WP_REST_Request $req): \WP_REST_Response {
        $sessionId = (int)($req->get_param('session_id')??0);
        $session = $this->c->make(POSSessionRepositoryInterface::class)->findById($sessionId);
        if (!$session) return new \WP_REST_Response(['success'=>false,'message'=>'Session not found.'],404);
        if ($session->cashierId !== get_current_user_id() && !current_user_can('manage_options')) {
            return new \WP_REST_Response(['success'=>false,'message'=>'You are not assigned to this POS session.'],403);
        }
        $orders = $this->c->make(POSOrderRepositoryInterface::class)->findBySession($sessionId);
        return new \WP_REST_Response(['success'=>true,'orders'=>array_map(fn($o)=>['id'=>$o->id,'ref'=>$o->orderRef,'total'=>$o->totalPkr,'method'=>$o->paymentMethod,'customer'=>$o->customerName,'sync'=>$o->getSyncStatus()->value,'created_at'=>$o->createdAt->format('H:i:s')],$orders)],200);
    }
    public function sendWhatsApp(\WP_REST_Request $req): \WP_REST_Response {
        $phone    = sanitize_text_field($req->get_param('phone')??''  );
        $orderRef = sanitize_text_field($req->get_param('order_ref')??''  );
        $total    = (int)($req->get_param('total')??0);
        $items    = $req->get_param('items')??[];
        if (!$phone) return new \WP_REST_Response(['success'=>false,'message'=>'Phone required.'],422);
        $msg = get_bloginfo('name').PHP_EOL."Order: {$orderRef}".PHP_EOL;
        foreach($items as $item) $msg .= "- {$item['name']} x{$item['quantity']} = PKR ".number_format($item['total_pkr']).PHP_EOL;
        $msg .= "Total: PKR ".number_format($total).PHP_EOL."Shukriya!";
        $clean  = preg_replace('/[^\d]/','',$phone);
        if (str_starts_with($clean,'0')) $clean = '92'.substr($clean,1);
        $waUrl  = 'https://wa.me/'.$clean.'?text='.rawurlencode($msg);
        return new \WP_REST_Response(['success'=>true,'whatsapp_url'=>$waUrl,'message'=>$msg],200);
    }
}
