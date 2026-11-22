<?php
declare(strict_types=1);
namespace PkStore\Bootstrap;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Core\Branding\BrandingManager;
use PkStore\Infrastructure\Http\{CodOtpController,ShippingWebhookHandler,TrackingController,AffiliateController,InventoryController,POSController};
use PkStore\Infrastructure\WooCommerce\{GatewayRegistry,ShippingRegistry,AssetEnqueuer,AffiliateHooks,InventoryHooks};
use PkStore\Infrastructure\Admin\AdminMenuController;
use PkStore\Infrastructure\Admin\Affiliate\AffiliateAdminController;
use PkStore\Infrastructure\Admin\Inventory\InventoryAdminController;
use PkStore\Infrastructure\Admin\Settings\FeatureSettingsController;
use PkStore\Settings\SetupWizard;

final class ThemeBootstrapper {
    private FeatureManager $fm;
    private BrandingManager $bm;
    public function __construct(private readonly ServiceContainer $c) {
        $this->fm = FeatureManager::getInstance();
        $this->bm = BrandingManager::getInstance();
    }
    public function boot(): void {
        // Feature guard hook — called by any feature use case
        add_action('pkstore_require_feature', function(string $feature): void {
            $license = $this->c->make(\PkStore\License\LicenseManager::class);
            $license->requireFeature($feature);
        });
        $this->setupTheme();
        $this->applyBranding();
        (new SecurityHardener())->register();

        // ── Payments (feature-gated) ──────────────────────────────
        (new GatewayRegistry($this->c, $this->fm))->register();
        (new ShippingRegistry($this->c, $this->fm))->register();
        (new AssetEnqueuer($this->bm))->register();

        // ── Core features (always on) ─────────────────────────────
        (new TrackingController($this->c, $this->fm))->register();

        // ── CoD OTP ──────────────────────────────────────────────
        if ($this->fm->isEnabled('cod_otp'))
            (new CodOtpController($this->c))->register();

        // ── Couriers ─────────────────────────────────────────────
        (new ShippingWebhookHandler($this->c, $this->fm))->register();

        // ── Affiliate System ──────────────────────────────────────
        if ($this->fm->isEnabled('affiliate_system')) {
            (new AffiliateController($this->c))->register();
            (new AffiliateHooks($this->c))->register();
        }

        // ── Inventory + POS ───────────────────────────────────────
        if ($this->fm->isEnabled('inventory')) {
            (new InventoryController($this->c))->register();
            (new InventoryHooks($this->c))->register();
            add_filter('cron_schedules', static function(array $schedules): array {
                $schedules['pkstore_five_minutes'] = ['interval' => 300, 'display' => 'Every five minutes'];
                return $schedules;
            });
            if (!wp_next_scheduled('pkstore_process_wc_stock_sync')) {
                wp_schedule_event(time() + 60, 'pkstore_five_minutes', 'pkstore_process_wc_stock_sync');
            }
            add_action('pkstore_process_wc_stock_sync', function(): void {
                $this->c->make(\PkStore\Application\Inventory\ProcessWooCommerceStockSyncQueueUseCase::class)->execute();
            });
            add_action('pkstore_process_wc_stock_sync', function(): void {
                $result = $this->c->make(\PkStore\Application\Inventory\ProcessWooCommerceOrderInventoryQueueUseCase::class)->execute();
                if (($result['failed'] ?? 0) > 0) {
                    error_log('[PK Store] WooCommerce order inventory queue has retryable failures.');
                }
            });
            add_action('pkstore_process_wc_stock_sync', function(): void {
                $result = $this->c->make(\PkStore\Application\Inventory\ProcessWooCommerceReturnInventoryQueueUseCase::class)->execute();
                if (($result['failed'] ?? 0) > 0) {
                    error_log('[PK Store] WooCommerce return inventory queue has retryable failures.');
                }
            });
        }
        // POS is an operational layer over the shared inventory ledger; it
        // must never run when inventory entitlement is disabled.
        if ($this->fm->isEnabled('pos') && $this->fm->isEnabled('inventory'))
            (new POSController($this->c))->register();

        // ── Analytics ─────────────────────────────────────────────
        if ($this->fm->isEnabled('google_analytics'))
            $this->c->make(\PkStore\Infrastructure\Integration\Analytics\GA4Service::class)->register();
        if ($this->fm->isEnabled('facebook_pixel'))
            $this->c->make(\PkStore\Infrastructure\Integration\Analytics\FacebookPixelService::class)->register();

        // ── Marketing integrations ─────────────────────────────────
        if ($this->fm->isEnabled('whatsapp_api'))
            (new \PkStore\Infrastructure\Integration\WhatsApp\WhatsAppHooks($this->c))->register();
        if ($this->fm->isEnabled('mailchimp'))
            $this->c->make(\PkStore\Infrastructure\Integration\Email\MailchimpService::class)->register();
        if ($this->fm->isEnabled('klaviyo'))
            $this->c->make(\PkStore\Infrastructure\Integration\Email\KlaviyoService::class)->register();

        // ── Accounting ────────────────────────────────────────────
        if ($this->fm->isEnabled('quickbooks'))
            $this->c->make(\PkStore\Infrastructure\Integration\Accounting\QuickBooksService::class)->register();

        // ── Marketplace ───────────────────────────────────────────
        if ($this->fm->isEnabled('daraz_sync'))
            $this->c->make(\PkStore\Infrastructure\Integration\Marketplace\DarazSyncService::class)->register();

        // ── Admin ─────────────────────────────────────────────────
        if (is_admin()) {
            (new AdminMenuController($this->c))->register();
            if ($this->fm->isEnabled('affiliate_system')) {
                (new AffiliateAdminController($this->c))->register();
            }
            if ($this->fm->isEnabled('inventory')) {
                (new InventoryAdminController($this->c))->register();
            }
            (new FeatureSettingsController())->register();
            $this->c->make(SetupWizard::class)->register();
            if ($this->fm->isEnabled('barcode_labels')) {
                $this->registerBarcodeLabelsPage();
            }
            $this->registerExportConfig();
        }

        \PkStore\Infrastructure\Database\Installer::createCustomOrderStatuses();
        add_action('pkstore_retry_courier_booking', function(int $oid): void {
            $this->c->make(\PkStore\Application\Logistics\BookShipmentUseCase::class)->retryForOrder($oid);
        });
    }

    private function applyBranding(): void {
        // Inject CSS variables and custom CSS into storefront
        add_action('wp_head', function() {
            $primary   = $this->bm->getPrimaryColor();
            $secondary = $this->bm->get('secondary_color','#1d2327');
            $accent    = $this->bm->get('accent_color','#00a32a');
            $customCss = $this->bm->get('custom_css');
            echo "<style>:root{--pkstore-primary:{$primary};--pkstore-secondary:{$secondary};--pkstore-accent:{$accent};}";
            if ($customCss) echo $customCss;
            echo "</style>
";
            // Custom favicon
            if ($favicon = $this->bm->get('favicon_url'))
                echo '<link rel="icon" href="'.esc_url($favicon).'">'."\n";
        }, 5);
        // Override WP title with branded store name
        if ($name = $this->bm->get('store_name'))
            add_filter('bloginfo', fn($output,$show)=>$show==='name'?$name:$output, 10, 2);
        // Inject GA4 client_id cookie into order meta
        add_action('woocommerce_checkout_order_processed', function(int $orderId): void {
            $clientId = sanitize_text_field($_COOKIE['_ga'] ?? '');
            if ($clientId) update_post_meta($orderId,'_ga4_client_id',$clientId);
        });
    }

    private function registerBarcodeLabelsPage(): void {
        add_action('admin_menu', function() {
            add_submenu_page('pkstore-operations','Barcode Labels','🏷 Label Printer','manage_woocommerce','pkstore-barcode-labels', function() {
                if (!current_user_can('manage_woocommerce') || !$this->fm->isEnabled('barcode_labels')) {
                    wp_die('This feature is not available on the current package.');
                }
                $this->c->make(\PkStore\Infrastructure\Barcode\BarcodeLabel::class)->adminPage();
            });
        });
    }

    private function registerExportConfig(): void {
        add_action('wp_ajax_pkstore_export_config', function() {
            if (!current_user_can('manage_options')||!check_ajax_referer('pkstore_export_config','_wpnonce',false)) wp_die('Unauthorized');
            $storeName = sanitize_file_name($this->bm->get('client_name')?:get_bloginfo('name'));
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="pkstore-config-'.$storeName.'.json"');
            echo $this->bm->exportConfig();
            exit;
        });
    }

    private function setupTheme(): void {
        add_theme_support('title-tag'); add_theme_support('post-thumbnails');
        add_theme_support('woocommerce'); add_theme_support('wc-product-gallery-zoom');
        add_theme_support('wc-product-gallery-lightbox'); add_theme_support('wc-product-gallery-slider');
        add_theme_support('html5',['search-form','comment-form','comment-list','gallery','caption']);
        register_nav_menus(['primary'=>'Primary Menu','footer'=>'Footer Menu']);
        if (!isset($GLOBALS['content_width'])) $GLOBALS['content_width'] = 1200;
        load_theme_textdomain('pk-store', PKSTORE_DIR.'/languages');
    }
}
