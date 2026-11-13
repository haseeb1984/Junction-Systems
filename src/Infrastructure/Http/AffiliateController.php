<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;
use PkStore\Application\Affiliate\{RegisterAffiliateUseCase,SubmitAffiliateProductUseCase,TrackAffiliateProductClickUseCase,TrackAffiliateClickUseCase};
use PkStore\Container\ServiceContainer;
use PkStore\Domain\Affiliate\Exception\AffiliateAlreadyExistsException;
use PkStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface,AffiliateProductRepositoryInterface,CommissionRepositoryInterface,PayoutRepositoryInterface};

final class AffiliateController {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('init', [$this, 'handleRefTracking']);
        add_action('init', [$this, 'handleProductRedirect']);
        add_action('rest_api_init', function() {
            register_rest_route('pkstore/v1','/affiliate/register',  ['methods'=>'POST','callback'=>[$this,'handleRegister'],     'permission_callback'=>[$this,'requireLogin']]);
            register_rest_route('pkstore/v1','/affiliate/dashboard', ['methods'=>'GET', 'callback'=>[$this,'handleDashboard'],    'permission_callback'=>[$this,'requireLogin']]);
            register_rest_route('pkstore/v1','/affiliate/products',  ['methods'=>'POST','callback'=>[$this,'handleSubmitProduct'],'permission_callback'=>[$this,'requireLogin']]);
            register_rest_route('pkstore/v1','/affiliate/products/list',['methods'=>'GET','callback'=>[$this,'handleListProducts'],'permission_callback'=>'__return_true']);
        });
    }
    public function handleRefTracking(): void {
        $code = sanitize_text_field($_GET['ref'] ?? '');
        if (!empty($code)) $this->c->make(TrackAffiliateClickUseCase::class)->execute($code);
    }
    public function handleProductRedirect(): void {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!preg_match('#/affiliate-product/(\d+)/go/?#', $uri, $m)) return;
        $url = $this->c->make(TrackAffiliateProductClickUseCase::class)->execute((int)$m[1]);
        wp_redirect($url ?? home_url('/'), 302); exit;
    }
    public function handleRegister(\WP_REST_Request $req): \WP_REST_Response {
        $userId = get_current_user_id(); $user = get_userdata($userId);
        if (!$user) return new \WP_REST_Response(['success'=>false,'message'=>'User not found.'],401);
        $name    = sanitize_text_field($req->get_param('name') ?? '');
        $method  = sanitize_key($req->get_param('payment_method') ?? 'jazzcash');
        $account = sanitize_text_field($req->get_param('payment_account') ?? '');
        if (empty($name)||empty($account)) return new \WP_REST_Response(['success'=>false,'message'=>'Name and payment account are required.'],422);
        if (!in_array($method,['jazzcash','easypaisa'],true)) return new \WP_REST_Response(['success'=>false,'message'=>'Invalid payment method.'],422);
        try {
            $a = $this->c->make(RegisterAffiliateUseCase::class)->execute($userId,$name,$user->user_email,$method,$account);
            return new \WP_REST_Response(['success'=>true,'affiliate_code'=>(string)$a->code,'message'=>'Welcome! Your affiliate account is active.'],201);
        } catch (AffiliateAlreadyExistsException $e) {
            return new \WP_REST_Response(['success'=>false,'code'=>'already_registered','message'=>$e->getMessage()],409);
        }
    }
    public function handleDashboard(\WP_REST_Request $req): \WP_REST_Response {
        $a = $this->c->make(AffiliateRepositoryInterface::class)->findByUserId(get_current_user_id());
        if (!$a) return new \WP_REST_Response(['success'=>false,'message'=>'Not an affiliate.'],403);
        $commissions = $this->c->make(CommissionRepositoryInterface::class)->findByAffiliate($a->id,20);
        $payouts     = $this->c->make(PayoutRepositoryInterface::class)->findByAffiliate($a->id,10);
        $products    = $this->c->make(AffiliateProductRepositoryInterface::class)->findByAffiliate($a->id);
        return new \WP_REST_Response(['success'=>true,
            'affiliate'   =>['id'=>$a->id,'name'=>$a->name,'code'=>(string)$a->code,'tracking_url'=>$a->getTrackingUrl(home_url()),'commission_rate'=>$a->commissionRate->percentage,'total_earned'=>$a->getTotalEarnedPkr(),'total_paid'=>$a->getTotalPaidPkr(),'pending_payout'=>$a->getPendingPayoutPkr(),'payment_method'=>$a->paymentMethod,'payment_account'=>$a->paymentAccount,'status'=>$a->status],
            'commissions' =>array_map(fn($c)=>['order_id'=>$c->orderId,'order_total'=>$c->orderTotalPkr,'commission'=>$c->commissionPkr,'rate'=>$c->ratePercent,'status'=>$c->status->value,'date'=>$c->createdAt->format('Y-m-d')],$commissions),
            'payouts'     =>array_map(fn($p)=>['amount'=>$p->amountPkr,'method'=>$p->paymentMethod,'status'=>$p->status->value,'tx_id'=>$p->transactionId,'date'=>$p->createdAt->format('Y-m-d')],$payouts),
            'products'    =>array_map(fn($p)=>['id'=>$p->id,'title'=>$p->title,'price'=>$p->displayPricePkr,'platform'=>$p->platform,'clicks'=>$p->getClickCount(),'status'=>$p->status,'redirect_url'=>$p->getTrackedRedirectUrl(home_url())],$products),
        ],200);
    }
    public function handleSubmitProduct(\WP_REST_Request $req): \WP_REST_Response {
        $a = $this->c->make(AffiliateRepositoryInterface::class)->findByUserId(get_current_user_id());
        if (!$a) return new \WP_REST_Response(['success'=>false,'message'=>'Not an affiliate.'],403);
        try {
            $p = $this->c->make(SubmitAffiliateProductUseCase::class)->execute($a->id,$req->get_params());
            return new \WP_REST_Response(['success'=>true,'product_id'=>$p->id,'message'=>'Product listed successfully.'],201);
        } catch (\InvalidArgumentException $e) { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],422);
        } catch (\RuntimeException $e)         { return new \WP_REST_Response(['success'=>false,'message'=>$e->getMessage()],403); }
    }
    public function handleListProducts(\WP_REST_Request $req): \WP_REST_Response {
        $limit=min(40,max(1,(int)($req->get_param('limit')??20)));$offset=max(0,(int)($req->get_param('offset')??0));
        $repo=$this->c->make(AffiliateProductRepositoryInterface::class);$products=$repo->findActive($limit,$offset);
        return new \WP_REST_Response(['success'=>true,'total'=>$repo->countActive(),'products'=>array_map(fn($p)=>['id'=>$p->id,'title'=>$p->title,'description'=>$p->description,'price_pkr'=>$p->displayPricePkr,'platform'=>$p->platform,'category'=>$p->category,'image_url'=>$p->imageUrl,'redirect_url'=>$p->getTrackedRedirectUrl(home_url()),'clicks'=>$p->getClickCount()],$products)],200);
    }
    public function requireLogin(): bool { return is_user_logged_in(); }
}
