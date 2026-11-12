<?php
declare(strict_types=1);
namespace JunctionStore\Core\Branding;

final class BrandingManager {
    private static ?self $instance = null;
    private array $config = [];

    private static array $DEFAULTS = [
        'store_name'         => '',
        'store_tagline'      => '',
        'logo_url'           => '',
        'favicon_url'        => '',
        'primary_color'      => '#0073aa',
        'secondary_color'    => '#1d2327',
        'accent_color'       => '#00a32a',
        'pos_theme'          => 'light',
        'pos_logo_url'       => '',
        'receipt_header'     => '',
        'receipt_footer'     => 'Shukriya! Thank you for shopping with us.',
        'receipt_show_logo'  => '1',
        'email_header_color' => '#0073aa',
        'email_logo_url'     => '',
        'hide_pkstore_brand' => '0',
        'custom_css'         => '',
        'client_name'        => '',
        'client_license_key' => '',
    ];

    private function __construct() {
        $saved = get_option('pkstore_branding', []);
        $this->config = array_merge(self::$DEFAULTS, $saved);
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function get(string $key, string $default = ''): string {
        return $this->config[$key] ?? $default;
    }

    public function getStoreName(): string {
        return $this->get('store_name') ?: get_bloginfo('name');
    }

    public function getPrimaryColor(): string { return $this->get('primary_color', '#0073aa'); }
    public function getLogoUrl(): string      { return $this->get('logo_url'); }
    public function getPosLogoUrl(): string   { return $this->get('pos_logo_url') ?: $this->get('logo_url'); }
    public function getReceiptFooter(): string{ return $this->get('receipt_footer'); }
    public function hideJunctionStoreBrand(): bool  { return $this->get('hide_pkstore_brand') === '1'; }
    public function getPosTheme(): string     { return $this->get('pos_theme', 'light'); }

    public function saveAll(array $data): void {
        foreach (self::$DEFAULTS as $key => $default) {
            if (isset($data[$key])) {
                $this->config[$key] = sanitize_text_field($data[$key]);
            }
        }
        if (isset($data['custom_css'])) {
            $this->config['custom_css'] = wp_strip_all_tags($data['custom_css']);
        }
        update_option('pkstore_branding', $this->config);
    }

    public function getAll(): array { return $this->config; }

    public function exportConfig(): string {
        return wp_json_encode([
            'branding' => $this->config,
            'features' => \JunctionStore\Core\Feature\FeatureManager::getInstance()->getAll(),
            'exported_at' => current_time('mysql'),
            'version' => PKSTORE_VERSION,
        ], JSON_PRETTY_PRINT);
    }

    public static function importConfig(string $json): bool {
        $data = json_decode($json, true);
        if (!$data) return false;
        if (isset($data['branding'])) update_option('pkstore_branding', $data['branding']);
        if (isset($data['features'])) update_option('pkstore_features', $data['features']);
        self::$instance = null;
        \JunctionStore\Core\Feature\FeatureManager::reset();
        return true;
    }

    public static function reset(): void { self::$instance = null; }
}
