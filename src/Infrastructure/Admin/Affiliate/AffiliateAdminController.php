<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin\Affiliate;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
final class AffiliateAdminController {
    private const CAP='manage_woocommerce';
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_action('admin_menu',[$this,'menus']);}
    public function menus():void{
        if (!FeatureManager::getInstance()->isEnabled('affiliate_system')) return;
        add_submenu_page('pkstore-operations','Affiliate Members','👥 Affiliates',self::CAP,'pkstore-affiliates',[$this,'affiliates']);
        add_submenu_page('pkstore-operations','Affiliate Commissions','💰 Commissions',self::CAP,'pkstore-commissions',[$this,'commissions']);
        add_submenu_page('pkstore-operations','Affiliate Payouts','💸 Payouts',self::CAP,'pkstore-payouts',[$this,'payouts']);
        add_submenu_page('pkstore-operations','Affiliate Products','🔗 Aff. Products',self::CAP,'pkstore-affiliate-products',[$this,'products']);
    }
    public function affiliates():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');if(!FeatureManager::getInstance()->isEnabled('affiliate_system'))wp_die('Affiliate features are not available on the current package.');include PKSTORE_DIR.'/templates/admin/affiliate/affiliates.php';}
    public function commissions():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');if(!FeatureManager::getInstance()->isEnabled('affiliate_system'))wp_die('Affiliate features are not available on the current package.');include PKSTORE_DIR.'/templates/admin/affiliate/commissions.php';}
    public function payouts():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');if(!FeatureManager::getInstance()->isEnabled('affiliate_system'))wp_die('Affiliate features are not available on the current package.');include PKSTORE_DIR.'/templates/admin/affiliate/payouts.php';}
    public function products():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');if(!FeatureManager::getInstance()->isEnabled('affiliate_system'))wp_die('Affiliate features are not available on the current package.');include PKSTORE_DIR.'/templates/admin/affiliate/products.php';}
}
