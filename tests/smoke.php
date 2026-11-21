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

$fingerprint = str_repeat('a', 64);
$posOrder = \PkStore\Domain\POS\Entity\POSOrder::create(
    7,
    3,
    [['product_id' => 12, 'sku' => 'SKU-12', 'name' => 'Sample', 'quantity' => 2, 'unit_price_pkr' => 100, 'discount_pkr' => 0]],
    'cash',
    '',
    'Walk-in',
    0,
    null,
    'P-0123456789abcdef0123456789',
    $fingerprint
);
if ($posOrder->requestFingerprint !== $fingerprint) {
    throw new \RuntimeException('POS order request fingerprint was not retained by the domain entity.');
}

$fingerprintA = \PkStore\Application\POS\ProcessPOSSaleUseCase::requestFingerprint(
    7, 3,
    [
        ['product_id' => 12, 'quantity' => 2],
        ['product_id' => 4, 'quantity' => 1],
    ],
    'cash', '', 'Walk-in', 0, null
);
$fingerprintB = \PkStore\Application\POS\ProcessPOSSaleUseCase::requestFingerprint(
    7, 3,
    [
        ['product_id' => 4, 'quantity' => 1],
        ['product_id' => 12, 'quantity' => 2],
    ],
    'cash', '', 'Walk-in', 0, null
);
if (!hash_equals($fingerprintA, $fingerprintB)) {
    throw new \RuntimeException('POS request fingerprint must be stable when line item order changes.');
}
$fingerprintChanged = \PkStore\Application\POS\ProcessPOSSaleUseCase::requestFingerprint(
    7, 3,
    [['product_id' => 12, 'quantity' => 3]],
    'cash', '', 'Walk-in', 0, null
);
if (hash_equals($fingerprintA, $fingerprintChanged)) {
    throw new \RuntimeException('POS request fingerprint must change when sale quantities change.');
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

if (!method_exists(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class, 'hasMovementReference')) {
    throw new \RuntimeException('Stock repository must expose WooCommerce order movement idempotency lookup.');
}
if (!method_exists(\PkStore\Application\Inventory\SyncWooCommerceStockUseCase::class, 'onWooCommerceSale')) {
    throw new \RuntimeException('WooCommerce order inventory ingestion use case is unavailable.');
}
$inventoryUseCaseSource = file_get_contents($src . '/Application/Inventory/SyncWooCommerceStockUseCase.php');
if (!is_string($inventoryUseCaseSource)
    || strpos($inventoryUseCaseSource, 'WooCommerce order is unavailable for inventory ingestion.') === false
    || strpos($inventoryUseCaseSource, 'Junction online inventory location is not configured.') === false) {
    throw new \RuntimeException('WooCommerce order inventory ingestion must fail closed when prerequisites are missing.');
}
if (!method_exists(\PkStore\Application\Inventory\ProcessWooCommerceOrderInventoryQueueUseCase::class, 'enqueue')
    || !method_exists(\PkStore\Application\Inventory\ProcessWooCommerceOrderInventoryQueueUseCase::class, 'execute')) {
    throw new \RuntimeException('WooCommerce order inventory durable queue API is unavailable.');
}


foreach ([
    \PkStore\Domain\Inventory\Entity\MovementType::class,
    \PkStore\Domain\POS\Entity\SessionStatus::class,
    \PkStore\Domain\POS\Entity\SyncStatus::class,
] as $enum) {
    if (!enum_exists($enum)) {
        throw new \RuntimeException('Domain enum failed PSR-4 autoload: ' . $enum);
    }
}

foreach ([
    \PkStore\Infrastructure\Inventory\WpStockRepository::class,
    \PkStore\Application\Inventory\ProcessWooCommerceStockSyncQueueUseCase::class,
    \PkStore\Application\Inventory\AdjustStockUseCase::class,
] as $class) {
    if (!class_exists($class)) {
        throw new \RuntimeException('Stock sync queue class failed to autoload: ' . $class);
    }
}


$bootstrapSource = file_get_contents($src . '/Bootstrap/ThemeBootstrapper.php');
$adminInventorySource = file_get_contents($src . '/Infrastructure/Admin/Inventory/InventoryAdminController.php');
$adminAffiliateSource = file_get_contents($src . '/Infrastructure/Admin/Affiliate/AffiliateAdminController.php');
if (!is_string($bootstrapSource)
    || strpos($bootstrapSource, "if (\$this->fm->isEnabled('inventory'))") === false
    || strpos($bootstrapSource, "if (\$this->fm->isEnabled('barcode_labels'))") === false
    || strpos($bootstrapSource, "current_user_can('manage_woocommerce') || !\$this->fm->isEnabled('barcode_labels')") === false) {
    throw new \RuntimeException('Inventory and barcode admin registration must enforce package entitlements.');
}
if (!is_string($adminInventorySource)
    || strpos($adminInventorySource, "if (!\$features->isEnabled('inventory')) return;") === false
    || strpos($adminInventorySource, "if (\$features->isEnabled('pos'))") === false
    || strpos($adminInventorySource, "if (!FeatureManager::getInstance()->isEnabled('pos'))") === false) {
    throw new \RuntimeException('Inventory admin routes must enforce inventory and POS entitlements independently.');
}


if (!is_string($bootstrapSource)
    || strpos($bootstrapSource, "if (\$this->fm->isEnabled('affiliate_system'))") === false
    || !is_string($adminAffiliateSource)
    || strpos($adminAffiliateSource, "if (!FeatureManager::getInstance()->isEnabled('affiliate_system'))") === false) {
    throw new \RuntimeException('Affiliate admin registration and pages must enforce the affiliate entitlement.');
}

echo "Smoke tests passed.\n";
