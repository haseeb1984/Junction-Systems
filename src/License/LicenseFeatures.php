<?php
declare(strict_types=1);
namespace PkStore\License;

final class LicenseFeatures {

    // Every feature slug in the platform
    public const ALL = [
        // Basic tier
        'jazzcash_payment',
        'easypaisa_payment',
        'cod_otp',
        'tcs_courier',
        'leopards_courier',
        'trax_courier',
        'affiliate_system',
        'white_label',
        'basic_reports',
        'dukaan_ai',
        'junction_pos',
        'inventory_management',
        'whatsapp_api',
        'quickbooks_sync',
        'daraz_sync',
        // Standard tier extras
        'barcode_labels',
        'analytics_ga4',
        'facebook_pixel',
        'mailchimp',
        // Enterprise tier extras
        'postex_courier',
        'rider_courier',
        'klaviyo',
        'multi_location_inventory',
        'foree_pay',
    ];

    public const BY_TIER = [
        'basic' => [
            'jazzcash_payment',
            'easypaisa_payment',
            'cod_otp',
            'tcs_courier',
            'leopards_courier',
            'trax_courier',
            'affiliate_system',
            'white_label',
            'basic_reports',
            'dukaan_ai',
            // These are explicitly included in the published Basic package.
            'junction_pos',
            'inventory_management',
            'whatsapp_api',
            'quickbooks_sync',
            'daraz_sync',
        ],
        'standard' => [
            // Everything in Basic, plus the published Standard extras.
            'jazzcash_payment', 'easypaisa_payment', 'cod_otp',
            'tcs_courier', 'leopards_courier', 'trax_courier',
            'affiliate_system', 'white_label', 'basic_reports', 'dukaan_ai',
            'junction_pos', 'inventory_management', 'whatsapp_api',
            'quickbooks_sync', 'daraz_sync',
            'barcode_labels',
            'analytics_ga4',
            'facebook_pixel',
            'mailchimp',
        ],
        'enterprise' => self::ALL,
    ];

    public static function forTier(string $tier): array {
        return self::BY_TIER[$tier] ?? [];
    }
}
