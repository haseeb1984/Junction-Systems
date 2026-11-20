<?php
declare(strict_types=1);
namespace PkStore\Core\Branding;

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
        if (!is_array($saved)) $saved = [];
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
    public function hidePkStoreBrand(): bool  { return $this->get('hide_pkstore_brand') === '1'; }
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
        // Portable exports must never contain license keys or credentials.
        $branding = $this->config;
        unset($branding['client_license_key']);

        return wp_json_encode([
            'branding' => $branding,
            'exported_at' => current_time('mysql'),
            'version' => PKSTORE_VERSION,
        ], JSON_PRETTY_PRINT);
    }

    public static function importConfig(string $json): bool {
        if ($json === '' || strlen($json) > 65535) return false;

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['branding']) || !is_array($data['branding'])) {
            return false;
        }

        // Only presentation settings are portable. Entitlements and license keys
        // must always come from the central licensing service.
        $allowed = array_diff(array_keys(self::$DEFAULTS), ['client_license_key']);
        $branding = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data['branding']) || !is_scalar($data['branding'][$key])) {
                continue;
            }

            $value = (string) $data['branding'][$key];
            if (in_array($key, ['primary_color', 'secondary_color', 'accent_color', 'email_header_color'], true)) {
                $branding[$key] = sanitize_hex_color($value) ?: self::$DEFAULTS[$key];
            } elseif (in_array($key, ['logo_url', 'favicon_url', 'pos_logo_url', 'email_logo_url'], true)) {
                $branding[$key] = esc_url_raw($value);
            } elseif ($key === 'custom_css') {
                $branding[$key] = wp_strip_all_tags($value);
            } elseif (in_array($key, ['hide_pkstore_brand', 'receipt_show_logo'], true)) {
                $branding[$key] = $value === '1' ? '1' : '0';
            } elseif ($key === 'pos_theme') {
                $branding[$key] = in_array($value, ['light', 'dark'], true) ? $value : 'light';
            } elseif (in_array($key, ['receipt_header', 'receipt_footer', 'store_tagline'], true)) {
                $branding[$key] = sanitize_textarea_field($value);
            } else {
                $branding[$key] = sanitize_text_field($value);
            }
        }

        update_option('pkstore_branding', array_merge(self::$DEFAULTS, $branding));
        self::$instance = null;
        return true;
    }

    public static function reset(): void { self::$instance = null; }
}
