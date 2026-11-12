<?php
declare(strict_types=1);
namespace JunctionStore\Core\Feature;

final class FeatureManager {
    private static ?self $instance = null;
    private array $features = [];

    public static function FEATURES(): array {
        return [
            // Payments
            'payment_jazzcash'     => ['label'=>'JazzCash Gateway',          'group'=>'Payments',       'default'=>true],
            'payment_easypaisa'    => ['label'=>'EasyPaisa Gateway',         'group'=>'Payments',       'default'=>true],
            'payment_foree'        => ['label'=>'Foree Pay (Card Terminal)',  'group'=>'Payments',       'default'=>false],
            // Checkout
            'cod_otp'              => ['label'=>'CoD OTP Verification',      'group'=>'Checkout',       'default'=>true],
            'ai_chat'              => ['label'=>'Dukaan AI Chat',            'group'=>'Checkout',       'default'=>true],
            // Couriers
            'courier_tcs'          => ['label'=>'TCS Express',               'group'=>'Couriers',       'default'=>true],
            'courier_leopards'     => ['label'=>'Leopards Courier',          'group'=>'Couriers',       'default'=>true],
            'courier_trax'         => ['label'=>'Trax (Sonic)',              'group'=>'Couriers',       'default'=>true],
            'courier_postex'       => ['label'=>'PostEx (COD Remittance)',   'group'=>'Couriers',       'default'=>false],
            'courier_rider'        => ['label'=>'Rider.pk (Same-Day)',       'group'=>'Couriers',       'default'=>false],
            // Affiliate
            'affiliate_system'     => ['label'=>'Affiliate System',          'group'=>'Marketing',      'default'=>true],
            'whatsapp_api'         => ['label'=>'WhatsApp Business API',     'group'=>'Marketing',      'default'=>false],
            'mailchimp'            => ['label'=>'Mailchimp Email Sync',      'group'=>'Marketing',      'default'=>false],
            'klaviyo'              => ['label'=>'Klaviyo Email Flows',       'group'=>'Marketing',      'default'=>false],
            // Analytics
            'google_analytics'     => ['label'=>'Google Analytics 4',        'group'=>'Analytics',      'default'=>false],
            'facebook_pixel'       => ['label'=>'Facebook Pixel + CAPI',     'group'=>'Analytics',      'default'=>false],
            // Inventory & POS
            'inventory'            => ['label'=>'Inventory Management',      'group'=>'Operations',     'default'=>true],
            'pos'                  => ['label'=>'POS Register',              'group'=>'Operations',     'default'=>true],
            'barcode_labels'       => ['label'=>'Barcode Label Printer',     'group'=>'Operations',     'default'=>true],
            'multi_location'       => ['label'=>'Multi-Location Stock',      'group'=>'Operations',     'default'=>false],
            // Integrations
            'quickbooks'           => ['label'=>'QuickBooks Sync',           'group'=>'Integrations',   'default'=>false],
            'erpnext'              => ['label'=>'ERPNext Sync',              'group'=>'Integrations',   'default'=>false],
            'daraz_sync'           => ['label'=>'Daraz Marketplace Sync',   'group'=>'Integrations',   'default'=>false],
        ];
    }

    private function __construct() {
        $saved = get_option('pkstore_features', []);
        foreach (self::FEATURES() as $key => $def) {
            $this->features[$key] = isset($saved[$key]) ? (bool)$saved[$key] : $def['default'];
        }
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function isEnabled(string $feature): bool {
        return $this->features[$feature] ?? false;
    }

    public function enable(string $feature): void  { $this->features[$feature] = true;  $this->save(); }
    public function disable(string $feature): void { $this->features[$feature] = false; $this->save(); }

    public function saveAll(array $submitted): void {
        foreach (self::FEATURES() as $key => $def) {
            $this->features[$key] = isset($submitted[$key]) && $submitted[$key] === '1';
        }
        $this->save();
    }

    public function getAll(): array { return $this->features; }

    private function save(): void { update_option('pkstore_features', $this->features); }

    public static function reset(): void { self::$instance = null; }
}
