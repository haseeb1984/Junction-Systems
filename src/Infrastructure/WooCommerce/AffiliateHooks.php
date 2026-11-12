<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\WooCommerce;
use JunctionStore\Application\Affiliate\{RecordCommissionUseCase,TrackAffiliateClickUseCase};
use JunctionStore\Container\ServiceContainer;
use JunctionStore\Domain\Affiliate\Repository\AffiliateProductRepositoryInterface;
final class AffiliateHooks {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('init',[$this,'trackRef'],1);
        add_action('woocommerce_payment_complete',[$this,'recordCommission']);
        add_action('woocommerce_order_status_completed',[$this,'recordCommission']);
        add_shortcode('pkstore_affiliate_products',[$this,'productsShortcode']);
        add_shortcode('pkstore_affiliate_dashboard',[$this,'dashboardShortcode']);
    }
    public function trackRef(): void {
        $code=sanitize_text_field($_GET['ref']??''');
        if(!empty($code))$this->c->make(TrackAffiliateClickUseCase::class)->execute($code);
    }
    public function recordCommission(int $orderId): void {
        $code=TrackAffiliateClickUseCase::getTrackedAffiliateCode(); if($code===null)return;
        $order=wc_get_order($orderId); if(!($order instanceof \WC_Order))return;
        $total=(int)$order->get_total(); if($total<=0)return;
        try{$this->c->make(RecordCommissionUseCase::class)->execute($orderId,$total,$code);}catch(\Throwable $e){error_log('[JunctionStore] Commission error: '.$e->getMessage());}
    }
    public function productsShortcode(array $atts): string {
        $atts=shortcode_atts(['limit'=>12],  $atts);
        $items=$this->c->make(AffiliateProductRepositoryInterface::class)->findActive((int)$atts['limit'],0);
        if(empty($items))return'<p>No affiliate products listed yet.</p>';
        ob_start();include PKSTORE_DIR.'/templates/affiliate/products-grid.php';return ob_get_clean();
    }
    public function dashboardShortcode(array $atts): string {
        ob_start();include PKSTORE_DIR.'/templates/affiliate/dashboard.php';return ob_get_clean();
    }
}
