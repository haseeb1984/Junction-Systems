<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin\Settings;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Core\Branding\BrandingManager;

final class FeatureSettingsController {
    private const CAP = 'manage_options';

    public function register(): void {
        add_action('admin_menu', [$this, 'menus']);
        add_action('admin_init', [$this, 'handleSave']);
    }

    public function menus(): void {
        add_submenu_page('pkstore-operations','Feature Settings','⚙️ Features',self::CAP,'pkstore-features',[$this,'featuresPage']);
        add_submenu_page('pkstore-operations','Branding & White-Label','🎨 Branding',self::CAP,'pkstore-branding',[$this,'brandingPage']);
    }

    public function handleSave(): void {
        if (!isset($_POST['pkstore_settings_action'])) return;
        if (!current_user_can(self::CAP)) return;
        check_admin_referer('pkstore_settings_save');

        $action = sanitize_key($_POST['pkstore_settings_action']);

        if ($action === 'save_features') {
            $submitted = [];
            foreach (FeatureManager::FEATURES() as $key => $def) {
                $submitted[$key] = isset($_POST['feature_'.$key]) ? '1' : '0';
            }
            FeatureManager::getInstance()->saveAll($submitted);
            wp_safe_redirect(add_query_arg(['page'=>'pkstore-features','saved'=>'1'], admin_url('admin.php'))); exit;
        }

        if ($action === 'save_branding') {
            BrandingManager::getInstance()->saveAll($_POST);
            wp_safe_redirect(add_query_arg(['page'=>'pkstore-branding','saved'=>'1'], admin_url('admin.php'))); exit;
        }

        if ($action === 'import_config') {
            $json = wp_unslash($_POST['import_json'] ?? '');
            $ok   = BrandingManager::importConfig($json);
            $status = $ok ? 'imported' : 'import_failed';
            wp_safe_redirect(add_query_arg(['page'=>'pkstore-branding','saved'=>$status], admin_url('admin.php'))); exit;
        }
    }

    public function featuresPage(): void {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        $fm       = FeatureManager::getInstance();
        $features = FeatureManager::FEATURES();
        $saved    = isset($_GET['saved']);
        include PKSTORE_DIR . '/templates/admin/settings/features.php';
    }

    public function brandingPage(): void {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        $bm      = BrandingManager::getInstance();
        $config  = $bm->getAll();
        $saved   = $_GET['saved'] ?? '';
        include PKSTORE_DIR . '/templates/admin/settings/branding.php';
    }
}
