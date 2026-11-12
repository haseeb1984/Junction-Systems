<?php
declare(strict_types=1);
namespace JunctionStore\License;

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
        // Standard tier
        'junction_pos',
        'inventory_management',
        'barcode_labels',
        'analytics_ga4',
        'facebook_pixel',
        'mailchimp',
        // Enterprise tier
        'postex_courier',
        'rider_courier',
        'whatsapp_api',
        'quickbooks_sync',
        'daraz_sync',
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
        ],
        'standard' => [
            // Includes everything in basic
            'jazzcash_payment', 'easypaisa_payment', 'cod_otp',
            'tcs_courier', 'leopards_courier', 'trax_courier',
            'affiliate_system', 'white_label', 'basic_reports', 'dukaan_ai',
            // Standard extras
            'junction_pos',
            'inventory_management',
            'barcode_labels',
            'analytics_ga4',
            'facebook_pixel',
            'mailchimp',
        ],
        'enterprise' => self::ALL, // Everything
    ];

    public static function forTier(string $tier): array {
        return self::BY_TIER[$tier] ?? self::BY_TIER['basic'];
    }
}
