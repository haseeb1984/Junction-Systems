<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/src';
require_once $src . '/Bootstrap/Autoloader.php';

\PkStore\Bootstrap\Autoloader::register($src, 'PkStore');
\PkStore\Bootstrap\Autoloader::register($src, 'Psr');

$container = \PkStore\Container\ServiceContainer::getInstance();
$container->singleton('smoke.service', static fn() => new \stdClass());
$first = $container->make('smoke.service');
$second = $container->make('smoke.service');
if ($first !== $second) {
    throw new \RuntimeException('Container singleton binding is not stable.');
}

$exceptions = [
    \PkStore\License\Exception\LicenseException::class,
    \PkStore\License\Exception\LicenseExpiredException::class,
    \PkStore\License\Exception\LicenseDomainMismatch::class,
    \PkStore\License\Exception\LicenseInvalidException::class,
    \PkStore\License\Exception\LicenseFeatureNotAvailable::class,
];
foreach ($exceptions as $exception) {
    if (!class_exists($exception)) {
        throw new \RuntimeException('License exception failed PSR-4 autoload: ' . $exception);
    }
}

if (\PkStore\License\LicenseFeatures::forTier('not-a-tier') !== []) {
    throw new \RuntimeException('Unknown license tiers must not receive default entitlements.');
}

$basic = \PkStore\License\LicenseFeatures::forTier('basic');
foreach (['junction_pos', 'inventory_management', 'whatsapp_api', 'quickbooks_sync', 'daraz_sync'] as $required) {
    if (!in_array($required, $basic, true)) {
        throw new \RuntimeException('Basic package entitlement is missing: ' . $required);
    }
}
if (in_array('mailchimp', $basic, true)) {
    throw new \RuntimeException('Mailchimp must remain a Standard package extra.');
}


if (in_array('mailchimp', $basic, true) || in_array('barcode_labels', $basic, true)
    || in_array('analytics_ga4', $basic, true) || in_array('facebook_pixel', $basic, true)) {
    throw new \RuntimeException('Standard package extras must not leak into Basic.');
}

$standard = \PkStore\License\LicenseFeatures::forTier('standard');
foreach (['barcode_labels', 'analytics_ga4', 'facebook_pixel', 'mailchimp'] as $required) {
    if (!in_array($required, $standard, true)) {
        throw new \RuntimeException('Standard package entitlement is missing: ' . $required);
    }
}
if (in_array('postex_courier', $standard, true) || in_array('klaviyo', $standard, true)) {
    throw new \RuntimeException('Enterprise-only extras must not leak into Standard.');
}

$enterprise = \PkStore\License\LicenseFeatures::forTier('enterprise');
foreach (['postex_courier', 'rider_courier', 'klaviyo', 'multi_location_inventory', 'foree_pay'] as $required) {
    if (!in_array($required, $enterprise, true)) {
        throw new \RuntimeException('Enterprise package entitlement is missing: ' . $required);
    }
}

foreach ([
    \PkStore\Infrastructure\Inventory\WpStockRepository::class,
    \PkStore\Application\Inventory\ProcessWooCommerceStockSyncQueueUseCase::class,
] as $class) {
    if (!class_exists($class)) {
        throw new \RuntimeException('Stock sync queue class failed to autoload: ' . $class);
    }
}

echo "Smoke tests passed.\n";
