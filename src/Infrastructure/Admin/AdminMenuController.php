<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin;
use PkStore\Container\ServiceContainer;
use PkStore\Infrastructure\Admin\ListTable\{OtpAuditTable,LogisticsListTable};
use PkStore\Settings\SetupWizard;
final class AdminMenuController {
    private const SLUG='pkstore-operations'; private const CAP='manage_woocommerce';
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_action('admin_menu',[$this,'menus']);add_action('admin_enqueue_scripts',[$this,'assets']);add_action('admin_post_pkstore_bust_dashboard_cache',[$this,'bustDashboardCache']);}
    public function menus():void{
        add_menu_page('Store Operations','🛒 Store Ops',self::CAP,self::SLUG,[$this,'dashboard'],'dashicons-store',56);
        add_submenu_page(self::SLUG,'Overview','Overview',self::CAP,self::SLUG,[$this,'dashboard']);
        add_submenu_page(self::SLUG,'Logistics Monitor','Logistics',self::CAP,'pkstore-logistics',[$this,'logistics']);
        add_submenu_page(self::SLUG,'OTP Audit Log','OTP Audit',self::CAP,'pkstore-otp-audit',[$this,'otp']);
        add_submenu_page(self::SLUG,'AI Chat Insights','AI Insights',self::CAP,'pkstore-ai-insights',[$this,'ai']);
        add_submenu_page(self::SLUG,'City Mapping','City Mapping','manage_options','pkstore-city-mapping',[$this,'city']);
    }
    public function assets(string $hook):void{$our=['toplevel_page_pkstore-operations','store-ops_page_pkstore-logistics','store-ops_page_pkstore-otp-audit','store-ops_page_pkstore-ai-insights','store-ops_page_pkstore-city-mapping','store-ops_page_pkstore-setup'];if(!in_array($hook,$our,true))return;wp_enqueue_style('pkstore-admin',PKSTORE_ASSETS.'/css/admin.css',[],PKSTORE_VERSION);if($hook==='toplevel_page_pkstore-operations')wp_enqueue_script('chartjs','https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',[],'4.4.1',true);}
    public function bustDashboardCache():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');check_admin_referer('pkstore_bust_dashboard_cache');$this->c->make(DashboardService::class)->bustAllCaches();wp_safe_redirect(admin_url('admin.php?page='.self::SLUG.'&cache_refreshed=1'));exit;}
    public function dashboard():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');$ds=$this->c->make(DashboardService::class);$logistics=$ds->getLogisticsSummary();$otp=$ds->getOtpSummary();$ai=$ds->getAiSummary();$health=$ds->getDeliveryHealth();include PKSTORE_DIR.'/templates/admin/dashboard.php';}
    public function logistics():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';$table=new LogisticsListTable($GLOBALS['wpdb']);$table->prepare_items();include PKSTORE_DIR.'/templates/admin/logistics.php';}
    public function otp():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';$table=new OtpAuditTable($GLOBALS['wpdb']);$table->prepare_items();include PKSTORE_DIR.'/templates/admin/otp-audit.php';}
    public function ai():void{if(!current_user_can(self::CAP))wp_die('Unauthorized.');$ai=$this->c->make(DashboardService::class)->getAiSummary();include PKSTORE_DIR.'/templates/admin/ai-insights.php';}
    public function city():void{if(!current_user_can('manage_options'))wp_die('Unauthorized.');include PKSTORE_DIR.'/templates/admin/city-mapping.php';}
}
