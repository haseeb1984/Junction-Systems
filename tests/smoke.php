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
$inventoryHttpSource = file_get_contents($src . '/Infrastructure/Http/InventoryController.php');
$barcodeLabelSource = file_get_contents($src . '/Infrastructure/Barcode/BarcodeLabel.php');
$posHttpSource = file_get_contents($src . '/Infrastructure/Http/POSController.php');
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
    || strpos($bootstrapSource, "if (\$this->fm->isEnabled('pos') && \$this->fm->isEnabled('inventory'))") === false) {
    throw new \RuntimeException('POS runtime registration must require both POS and shared inventory entitlements.');
}

if (!is_string($bootstrapSource)
    || strpos($bootstrapSource, "if (\$this->fm->isEnabled('affiliate_system'))") === false
    || !is_string($adminAffiliateSource)
    || strpos($adminAffiliateSource, "if (!FeatureManager::getInstance()->isEnabled('affiliate_system'))") === false) {
    throw new \RuntimeException('Affiliate admin registration and pages must enforce the affiliate entitlement.');
}


if (!is_string($inventoryHttpSource)
    || strpos($inventoryHttpSource, "if (FeatureManager::getInstance()->isEnabled('barcode_labels'))") === false
    || strpos($inventoryHttpSource, "Barcode labels are not available on the current package.") === false
    || !is_string($barcodeLabelSource)
    || strpos($barcodeLabelSource, "if (!FeatureManager::getInstance()->isEnabled('barcode_labels')) wp_die") === false) {
    throw new \RuntimeException('Barcode generation REST and admin handlers must enforce barcode entitlement.');
}


if (!is_string($inventoryHttpSource)
    || strpos($inventoryHttpSource, "\$auth = fn()=>current_user_can('manage_woocommerce') && FeatureManager::getInstance()->isEnabled('inventory')") === false
    || strpos($inventoryHttpSource, "\$barcodeAuth = fn()=>\$auth() && FeatureManager::getInstance()->isEnabled('barcode_labels')") === false) {
    throw new \RuntimeException('Inventory REST endpoints must enforce inventory entitlement, with barcode generation requiring its separate entitlement.');
}


if (!is_string($posHttpSource)
    || strpos($posHttpSource, "Receipt destination must match the phone number saved with this order.") === false
    || strpos($posHttpSource, "hash_equals(\$storedPhone, \$clean)") === false) {
    throw new \RuntimeException('POS WhatsApp receipts must be restricted to the customer phone stored on the order.');
}


if (!is_string($barcodeLabelSource)
    || strpos($barcodeLabelSource, "private function safeZplText(string \$value): string") === false
    || strpos($barcodeLabelSource, "str_replace(['^', '~', '\\\\']") === false) {
    throw new \RuntimeException('ZPL output must strip command introducers from catalogue and branding text.');
}

if (!is_string($inventoryHttpSource)
    || strpos($inventoryHttpSource, 'Prices and reorder point must be non-negative whole numbers.') === false
    || strpos($inventoryHttpSource, 'Linked WooCommerce product does not exist.') === false
    || strpos($inventoryHttpSource, '$wcProductId > 0 ? $wcProductId : null') === false) {
    throw new \RuntimeException('Inventory product creation must validate monetary values and WooCommerce product mappings.');
}



$stockAdjustmentSource = file_get_contents($src . '/Application/Inventory/AdjustStockUseCase.php');
if (!is_string($stockAdjustmentSource)
    || strpos($stockAdjustmentSource, 'public function executeManualAdjustment(') === false
    || strpos($stockAdjustmentSource, "START TRANSACTION") === false
    || strpos($stockAdjustmentSource, "ROLLBACK") === false
    || strpos($stockAdjustmentSource, "syncProductToWooCommerce(\$productId)") === false) {
    throw new \RuntimeException('Manual stock adjustments must commit ledger and outbox changes atomically before WooCommerce synchronization.');
}
if (!is_string($inventoryHttpSource)
    || strpos($inventoryHttpSource, 'MovementType::tryFrom($typeValue)') === false
    || strpos($inventoryHttpSource, 'MovementType::PurchaseReceive, MovementType::Damage') === false
    || strpos($inventoryHttpSource, 'filter_var($raw, FILTER_VALIDATE_INT)') === false
    || strpos($inventoryHttpSource, 'Stock adjustment could not be completed.') === false) {
    throw new \RuntimeException('Manual stock REST endpoint must validate integer inputs, allowed movement types and avoid exposing internal exceptions.');
}


if (!is_string($adminInventorySource)
    || strpos($adminInventorySource, 'executeManualAdjustment(') === false
    || strpos($adminInventorySource, 'Purchase receipts and opening stock must add a positive quantity.') === false
    || strpos($adminInventorySource, 'Damage/loss must reduce stock using a negative quantity.') === false
    || strpos($adminInventorySource, 'filter_var($_POST[\'quantity\'] ?? null, FILTER_VALIDATE_INT)') === false) {
    throw new \RuntimeException('Admin stock adjustments must use atomic writes and validate movement direction and integer quantities.');
}
if (!is_string($inventoryHttpSource)
    || strpos($inventoryHttpSource, 'MovementType::Opening') === false
    || strpos($inventoryHttpSource, 'Purchase receipts and opening stock must add a positive quantity.') === false
    || strpos($inventoryHttpSource, 'Damage/loss must reduce stock using a negative quantity.') === false) {
    throw new \RuntimeException('REST stock adjustments must enforce valid movement direction.');
}


if (!is_string($adminInventorySource)
    || strpos($adminInventorySource, 'FILTER_VALIDATE_INT') === false
    || strpos($adminInventorySource, 'wc_get_product($wcProductId)') === false
    || strpos($adminInventorySource, "isEnabled('barcode_labels')") === false) {
    throw new \RuntimeException('Admin product creation must validate integer prices and WooCommerce mappings and respect barcode entitlement.');
}
if (!is_string($inventoryHttpSource)
    || strpos($inventoryHttpSource, "if (!\$p->hasBarcode() && FeatureManager::getInstance()->isEnabled('barcode_labels'))") === false) {
    throw new \RuntimeException('REST product creation must not generate package-restricted barcodes when the entitlement is disabled.');
}


$woocommerceStockSyncSource = file_get_contents($src . '/Application/Inventory/SyncWooCommerceStockUseCase.php');
if (!is_string($woocommerceStockSyncSource)
    || strpos($woocommerceStockSyncSource, "get_variation_id") === false
    || strpos($woocommerceStockSyncSource, "findByWcProductId(\$variationId)") === false
    || strpos($woocommerceStockSyncSource, "findByWcProductId(\$wcProductId)") === false) {
    throw new \RuntimeException('WooCommerce variation orders must prefer a mapped variation and fall back to the parent product mapping.');
}


$inventoryProductRepositorySource = file_get_contents($src . '/Infrastructure/Inventory/WpInventoryProductRepository.php');
if (!is_string($inventoryProductRepositorySource)
    || strpos($inventoryProductRepositorySource, 'Unable to save inventory product.') === false
    || strpos($inventoryProductRepositorySource, 'Unable to update inventory product.') === false
    || strpos($inventoryProductRepositorySource, '$inserted === false') === false
    || strpos($inventoryProductRepositorySource, '$updated === false') === false) {
    throw new \RuntimeException('Inventory product repository must fail closed when database writes fail.');
}

$returnSource = file_get_contents($src . '/Application/Inventory/SyncWooCommerceStockUseCase.php');
$returnHooks = file_get_contents($src . '/Infrastructure/WooCommerce/InventoryHooks.php');
if (!is_string($returnSource)
    || strpos($returnSource, 'onWooCommerceOrderItemRestored(') === false
    || strpos($returnSource, "'wc_order_restore'") === false
    || strpos($returnSource, 'MovementType::OnlineReturn') === false
    || !is_string($returnHooks)
    || strpos($returnHooks, "woocommerce_restore_order_item_stock") === false
    || strpos($returnHooks, "onOrderItemRestored") === false
    || !in_array(\PkStore\Domain\Inventory\Entity\MovementType::OnlineReturn, \PkStore\Domain\Inventory\Entity\MovementType::cases(), true)) {
    throw new \RuntimeException('Actual WooCommerce cancellation restocks must be mirrored idempotently into the Junction ledger.');
}

if (strpos($returnSource, 'onWooCommerceRefundItemRestocked(') === false
    || strpos($returnSource, "'wc_refund_restock'") === false
    || strpos($returnSource, "_restock_refunded_items") === false
    || strpos($returnSource, 'count($candidates) !== 1 || $candidates[0][\'cumulative\'] <') === false
    || strpos($returnHooks, "woocommerce_restock_refunded_item") === false
    || strpos($returnHooks, "onRefundItemRestocked") === false) {
    throw new \RuntimeException('Only explicit WooCommerce refund restocks may create online return movements.');
}


$migrationRunnerSource = file_get_contents($src . '/Infrastructure/Database/MigrationRunner.php');
if (!is_string($migrationRunnerSource)
    || strpos($migrationRunnerSource, '$isExistingInstall = self::hasExistingTables();') === false
    || strpos($migrationRunnerSource, 'self::verifyRequiredTables($version)') === false
    || strpos($migrationRunnerSource, "version_compare(\$version, '2.0.3', '>=')") === false) {
    throw new \RuntimeException('Fresh-install backup gating and version-aware migration schema checks must remain intact.');
}

echo "Smoke tests passed.\n";
