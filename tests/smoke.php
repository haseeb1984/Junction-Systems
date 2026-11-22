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
    || strpos($returnHooks, "woocommerce_reduce_order_stock") === false
    || strpos($returnHooks, "onOrderStockReduced") === false
    || strpos($returnHooks, "woocommerce_payment_complete") !== false
    || strpos($returnHooks, "woocommerce_order_status_processing") !== false
    || strpos($returnHooks, "woocommerce_order_status_completed") !== false
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
    || strpos($migrationRunnerSource, "version_compare(\$version, '2.0.3', '>=')") === false
    || strpos($migrationRunnerSource, 'Unable to determine whether this is an existing Junction installation.') === false
    || strpos($migrationRunnerSource, 'Never interpret a failed schema probe as a fresh install') === false
    || strpos($migrationRunnerSource, "'pkstore_') . '%'") === false) {
    throw new \RuntimeException('Fresh-install backup gating and version-aware migration schema checks must remain intact.');
}


$wcLifecycleSource = file_get_contents($src . '/Application/Inventory/SyncWooCommerceStockUseCase.php');
if (!is_string($wcLifecycleSource)
    || strpos($wcLifecycleSource, "SELECT GET_LOCK(%s, %d)") === false
    || strpos($wcLifecycleSource, "SELECT RELEASE_LOCK(%s)") === false
    || strpos($wcLifecycleSource, "['cancelled', 'failed', 'pending']") === false
    || strpos($wcLifecycleSource, "get_meta('_restock_refunded_items', true)") === false
    || strpos($wcLifecycleSource, 'Always book the original sale quantity') === false
    || strpos($wcLifecycleSource, 'return false;') === false
    || strpos($wcLifecycleSource, 'return $this->withOrderLock($orderId') === false
    || strpos($wcLifecycleSource, 'return $callback();') === false
    || strpos($wcLifecycleSource, "MovementType::OnlineSale))") === false) {
    throw new \RuntimeException('WooCommerce sale/return ordering and net-restock safeguards must remain intact.');
}


$returnQueueSource = file_get_contents($src . '/Application/Inventory/ProcessWooCommerceReturnInventoryQueueUseCase.php');
$inventoryInstallerSource = file_get_contents($src . '/Infrastructure/Database/InventoryInstaller.php');
if (!is_string($returnQueueSource)
    || strpos($returnQueueSource, 'enqueueCancellation(') === false
    || strpos($returnQueueSource, 'enqueueRefund(') === false
    || strpos($returnQueueSource, 'claimBatch(') === false
    || strpos($returnQueueSource, 'DATE_SUB(%s, INTERVAL 10 MINUTE)') === false
    || strpos($returnQueueSource, 'next_attempt_at=DATE_ADD') === false
    || strpos($returnQueueSource, 'Cancellation return is waiting for its matching sale movement.') === false
    || strpos($returnQueueSource, 'Refund return is waiting for its matching sale movement.') === false
    || strpos($returnQueueSource, 'if (!$applied)') === false
    || strpos($returnQueueSource, 'A refunded payment does not prove WooCommerce restored stock.') === false
    || !is_string($inventoryInstallerSource)
    || strpos($inventoryInstallerSource, 'pkstore_wc_return_inventory_queue') === false
    || strpos($migrationRunnerSource, "'2.0.4' => [InventoryInstaller::class, 'installWooCommerceReturnInventoryQueue']") === false
    || strpos($migrationRunnerSource, "'pkstore_wc_return_inventory_queue'") === false
    || strpos($returnHooks, 'ProcessWooCommerceReturnInventoryQueueUseCase::class') === false
    || strpos($returnHooks, 'enqueueCancellation(') === false
    || strpos($returnHooks, 'enqueueRefund(') === false) {
    throw new \RuntimeException('WooCommerce cancellation/refund callbacks must persist durable retryable return events.');
}


$inventoryEventsSource = file_get_contents($src . '/Infrastructure/Admin/Inventory/InventoryAdminController.php');
$inventoryEventsTemplate = file_get_contents(dirname(__DIR__) . '/templates/admin/inventory/inventory-events.php');
if (!is_string($inventoryEventsSource)
    || strpos($inventoryEventsSource, "pkstore-inventory-events") === false
    || strpos($inventoryEventsSource, "public function inventoryEvents(): void") === false
    || strpos($inventoryEventsSource, "current_user_can(self::CAP)") === false
    || strpos($inventoryEventsSource, "isEnabled('inventory')") === false
    || strpos($inventoryEventsSource, "LIMIT 100") === false
    || strpos($inventoryEventsSource, "SELECT status, COUNT(*) AS total") === false
    || strpos($inventoryEventsSource, "\$queueCounts[\$key]") === false
    || !is_string($inventoryEventsTemplate)
    || strpos($inventoryEventsTemplate, "Last error category") === false
    || strpos($inventoryEventsTemplate, "Queue status totals") === false
    || strpos($inventoryEventsTemplate, "needs_review") === false
    || strpos($inventoryEventsTemplate, "Ignore does not change stock") === false
    || strpos($inventoryEventsTemplate, "esc_html(") === false) {
    throw new \RuntimeException('Inventory queue health screen must be read-only, entitlement-protected and escape displayed queue data.');
}


$queueOperatorSource = file_get_contents($src . '/Infrastructure/Admin/Inventory/InventoryAdminController.php');
$queueOperatorTemplate = file_get_contents(dirname(__DIR__) . '/templates/admin/inventory/inventory-events.php');
$queueOperatorSchema = file_get_contents($src . '/Infrastructure/Database/InventoryInstaller.php');
$queueOperatorMigration = file_get_contents($src . '/Infrastructure/Database/MigrationRunner.php');
if (!is_string($queueOperatorSource)
    || strpos($queueOperatorSource, "retry_inventory_event") === false
    || strpos($queueOperatorSource, "ignore_inventory_event") === false
    || strpos($queueOperatorSource, "confirm_ignore") === false
    || strpos($queueOperatorTemplate, "confirm_ignore") === false
    || strpos($queueOperatorSource, "WHERE id=%d AND status='needs_review'") === false
    || strpos($queueOperatorSource, "pkstore_inventory_queue_audit") === false
    || strpos($queueOperatorSource, "'previous_status' => 'needs_review'") === false
    || strpos($queueOperatorSource, "'resulting_status' =>") === false
    || substr_count($queueOperatorSource, "updated_at=%s") < 3
    || strpos($queueOperatorSource, "'START TRANSACTION'") === false
    || strpos($queueOperatorSource, "'ROLLBACK'") === false
    || strpos($queueOperatorSource, "strlen(trim(\$reason)) < 5") === false
    || !is_string($queueOperatorTemplate)
    || strpos($queueOperatorTemplate, "Recent operator audit") === false
    || strpos($queueOperatorTemplate, "retry_inventory_event") === false
    || strpos($queueOperatorTemplate, "ignore_inventory_event") === false
    || !is_string($queueOperatorSchema)
    || strpos($queueOperatorSchema, "CREATE TABLE {\$wpdb->prefix}pkstore_inventory_queue_audit") === false
    || strpos($queueOperatorSchema, "previous_status VARCHAR(20)") === false
    || strpos($queueOperatorSchema, "resulting_status VARCHAR(20)") === false
    || !is_string($queueOperatorMigration)
    || strpos($queueOperatorMigration, "'2.0.7' => [InventoryInstaller::class, 'installInventoryQueueAudit']") === false
    || strpos($queueOperatorMigration, "'pkstore_inventory_queue_audit'") === false) {
    throw new \RuntimeException('Inventory queue review actions must be reasoned, audited and restricted to needs_review.');
}


$orderQueueRetrySource = file_get_contents($src . '/Application/Inventory/ProcessWooCommerceOrderInventoryQueueUseCase.php');
$returnQueueRetrySource = file_get_contents($src . '/Application/Inventory/ProcessWooCommerceReturnInventoryQueueUseCase.php');
$stockQueueRetrySource = file_get_contents($src . '/Infrastructure/Inventory/WpStockRepository.php');
if (!is_string($orderQueueRetrySource)
    || strpos($orderQueueRetrySource, 'MAX_AUTOMATIC_ATTEMPTS = 12') === false
    || strpos($orderQueueRetrySource, "'needs_review' : 'pending'") === false
    || strpos($orderQueueRetrySource, "DATE_ADD(%s, INTERVAL %d SECOND)") === false
    || !is_string($returnQueueRetrySource)
    || strpos($returnQueueRetrySource, 'MAX_AUTOMATIC_ATTEMPTS = 12') === false
    || strpos($returnQueueRetrySource, "'needs_review' : 'pending'") === false
    || !is_string($stockQueueRetrySource)
    || strpos($stockQueueRetrySource, 'MAX_AUTOMATIC_SYNC_ATTEMPTS = 12') === false
    || strpos($stockQueueRetrySource, "'needs_review' : 'pending'") === false
    || strpos($stockQueueRetrySource, "claim_token=%s") === false
    || strpos($stockQueueRetrySource, "\$row['claim_token'] = \$claimToken") === false
    || substr_count($stockQueueRetrySource, "generation<>%s AND claim_token=%s") !== 2
    || strpos($stockQueueRetrySource, "claim_token=NULL WHERE status='processing'") === false
    || strpos($stockQueueRetrySource, "function completeWooCommerceSync(int \$id, string \$generation, string \$claimToken)") === false
    || strpos($migrationRunnerSource, "'2.0.8' => [InventoryInstaller::class, 'installStockSyncQueue']") === false
    || strpos($migrationRunnerSource, "Required stock sync queue claim-token column was not created.") === false
    || strpos($inventoryEventsSource, "queueType === 'stock'") === false) {
    throw new \RuntimeException('WooCommerce inventory queues must stop automatic retries after 12 attempts and expose needs_review.');
}


$returnQueueSource = file_get_contents($src . '/Application/Inventory/ProcessWooCommerceReturnInventoryQueueUseCase.php');
$returnQueueSchema = file_get_contents($src . '/Infrastructure/Database/InventoryInstaller.php');
$returnQueueMigrations = file_get_contents($src . '/Infrastructure/Database/MigrationRunner.php');
if (!is_string($returnQueueSource)
    || strpos($returnQueueSource, 'claim_token=NULL') === false
    || strpos($returnQueueSource, 'claim_token=%s') === false
    || strpos($returnQueueSource, "['id' => $id, 'status' => 'processing', 'claim_token' => $claimToken]") === false
    || strpos($returnQueueSource, "'claim_token' => $claimToken") === false
    || !is_string($returnQueueSchema)
    || strpos($returnQueueSchema, 'claim_token CHAR(36) DEFAULT NULL') === false
    || !is_string($returnQueueMigrations)
    || strpos($returnQueueMigrations, "'2.0.5' => [InventoryInstaller::class, 'installWooCommerceReturnInventoryQueue']") === false
    || strpos($returnQueueMigrations, "'claim_token'") === false) {
    throw new \RuntimeException('WooCommerce return queue workers must be fenced by persisted claim tokens.');
}

$orderQueueSource = file_get_contents($src . '/Application/Inventory/ProcessWooCommerceOrderInventoryQueueUseCase.php');
if (!is_string($orderQueueSource)
    || strpos($orderQueueSource, 'claim_token=NULL') === false
    || strpos($orderQueueSource, 'claim_token=%s') === false
    || strpos($orderQueueSource, '$claimToken = wp_generate_uuid4()') === false
    || strpos($orderQueueSource, "'claim_token' => $claimToken") === false
    || strpos($orderQueueSource, 'function complete(int $id, string $generation, string $claimToken)') === false
    || substr_count($orderQueueSource, 'generation<>%s AND claim_token=%s') !== 2
    || strpos($orderQueueSource, 'SET status=%s, claimed_at=NULL, claim_token=NULL') === false
    || strpos($inventoryInstallerSource, 'claim_token CHAR(36) DEFAULT NULL') === false
    || strpos($migrationRunnerSource, "'2.0.6' => [InventoryInstaller::class, 'installWooCommerceOrderInventoryQueue']") === false
    || strpos($migrationRunnerSource, 'Required order queue claim-token column was not created.') === false) {
    throw new \RuntimeException('WooCommerce order queue workers must be fenced by persisted claim tokens.');
}


$posSyncSource = file_get_contents($src . '/Application/POS/SyncPOSQueueUseCase.php');
$posEntitySource = file_get_contents($src . '/Domain/POS/Entity/POSOrder.php');
$posRepositorySource = file_get_contents($src . '/Infrastructure/POS/WpPOSOrderRepository.php');
$posInventoryHooks = file_get_contents($src . '/Infrastructure/WooCommerce/InventoryHooks.php');
$posStockLifecycle = file_get_contents($src . '/Application/Inventory/SyncWooCommerceStockUseCase.php');
if (!is_string($posSyncSource)
    || strpos($posSyncSource, "SELECT GET_LOCK(%s, %d)") === false
    || strpos($posSyncSource, "_pkstore_pos_order_ref") === false
    || strpos($posSyncSource, "_order_stock_reduced") === false
    || strpos($posSyncSource, "get_woocommerce_currency() !== 'PKR'") === false
    || strpos($posSyncSource, "payment_complete('POS-'") === false
    || strpos($posSyncSource, "paidWooCommerceOrderMatches(") === false
    || strpos($posSyncSource, "_pkstore_inventory_product_id") === false
    || strpos($posSyncSource, "locationId !== \$posOrder->locationId") === false
    || strpos($posSyncSource, "hash_equals(\$posOrder->orderRef, \$posOrderRef)") === false
    || strpos($posSyncSource, "WooCommerce POS order has no persisted ID.") === false
    || strpos($posSyncSource, "markSynced((int) \$wcOrder->get_id())") === false
    || strpos($posSyncSource, "_pkstore_inventory_location_id") === false
    || strpos($posSyncSource, "update_meta_data('_reduced_stock', \$quantity)") === false
    || strpos($posSyncSource, "get_meta('_reduced_stock', true)") === false
    || !is_string($posEntitySource)
    || strpos($posEntitySource, "public ?int \$wcOrderId") === false
    || strpos($posEntitySource, "markSynced(int \$wcOrderId)") === false
    || !is_string($posRepositorySource)
    || strpos($posRepositorySource, "wc_order_id'=>\$o->wcOrderId") === false
    || strpos($posRepositorySource, "SELECT product_id, sku, product_name AS name") === false
    || !is_string($posInventoryHooks)
    || strpos($posInventoryHooks, "_pkstore_pos_origin") === false
    || strpos($posInventoryHooks, "junction_pos") === false
    || !is_string($posStockLifecycle)
    || strpos($posStockLifecycle, "findById((int) \$item->get_meta('_pkstore_inventory_product_id', true))") === false
    || strpos($posStockLifecycle, "findById((int) \$item->get_meta('_pkstore_inventory_location_id', true))") === false
    || strpos($posStockLifecycle, "\$saleSource, \$saleReference, \$saleMovementType") === false) {
    throw new \RuntimeException('POS-to-WooCommerce mirroring must be idempotent and preserve the single shared inventory deduction and original-location returns.');
}


if (strpos($posSyncSource, "replaceWooCommerceOrderItems(") === false
    || strpos($posSyncSource, "_pkstore_inventory_product_id") === false
    || strpos($posSyncSource, "get_discount_total()") === false
    || strpos($posSyncSource, "get_currency() !== 'PKR'") === false
    || strpos($posStockLifecycle, "MovementType::Sale") === false
    || strpos($posStockLifecycle, "'pos_order'") === false
    || strpos($posStockLifecycle, "get_meta('_reduced_stock', true)") === false
    || strpos($posStockLifecycle, "get_items('line_item')") === false
    || strpos($posStockLifecycle, "Only count lines WooCommerce actually reduced.") === false
    || strpos($posStockLifecycle, "The original Junction inventory location is unavailable.") === false) {
    throw new \RuntimeException('POS order retry, original-location return, and stock-cycle safeguards must remain in place.');
}

$posSyncSource = file_get_contents($src . '/Application/POS/SyncPOSQueueUseCase.php');
if (!is_string($posSyncSource)
    || strpos($posSyncSource, '\\\\wpdb') !== false
    || strpos($posSyncSource, '\\\\RuntimeException') !== false
    || strpos($posSyncSource, 'private function paidWooCommerceOrderMatches') === false
    || strpos($posSyncSource, "_pkstore_inventory_location_id") === false
    || strpos($posSyncSource, "_pkstore_pos_order_ref") === false) {
    throw new \RuntimeException('POS-to-WooCommerce recovery must use valid PHP namespaces and verify trusted per-line origin metadata.');
}


$stockCycleInstallerSource = file_get_contents($src . '/Infrastructure/Database/InventoryInstaller.php');
$stockCycleMigrationSource = file_get_contents($src . '/Infrastructure/Database/MigrationRunner.php');
if (!is_string($stockCycleInstallerSource)
    || strpos($stockCycleInstallerSource, 'pkstore_wc_stock_cycles') === false
    || strpos($stockCycleInstallerSource, 'pkstore_wc_stock_cycle_items') === false
    || strpos($stockCycleInstallerSource, 'pkstore_wc_stock_cycle_events') === false
    || strpos($stockCycleInstallerSource, 'UNIQUE INDEX idx_cycle_uuid') === false
    || strpos($stockCycleInstallerSource, 'UNIQUE INDEX idx_order_cycle') === false
    || strpos($stockCycleInstallerSource, 'claim_token CHAR(36) DEFAULT NULL') === false
    || strpos($stockCycleMigrationSource, "'2.0.9' => [InventoryInstaller::class, 'installWooCommerceStockCycles']") === false
    || strpos($stockCycleMigrationSource, "version_compare(\$version, '2.0.9', '>=')") === false
    || strpos($stockCycleMigrationSource, 'SHOW INDEX FROM ') === false
    || strpos($stockCycleMigrationSource, 'idx_cycle_order_item') === false
    || strpos($stockCycleMigrationSource, 'idx_event_key') === false
    || strpos($stockCycleMigrationSource, 'verifyUniqueIndex(') === false
    || strpos($stockCycleMigrationSource, "['wc_order_id', 'cycle_number']") === false
    || strpos($stockCycleMigrationSource, "['cycle_uuid', 'wc_order_item_id']") === false
    || strpos($stockCycleMigrationSource, "['Non_unique'] ?? 1") === false) {
    throw new \RuntimeException('WooCommerce stock-cycle storage must be durable, uniquely keyed and version-migrated.');
}



$trackingControllerSource = file_get_contents($src . '/Infrastructure/Http/TrackingController.php');
$shippingRegistryLifecycleSource = file_get_contents($src . '/Infrastructure/WooCommerce/ShippingRegistry.php');
if (!is_string($trackingControllerSource) || !is_string($shippingRegistryLifecycleSource)
    || strpos($trackingControllerSource, 'use PkStore\\Core\\Feature\\FeatureManager;') === false
    || strpos($trackingControllerSource, "isEnabled('courier_tcs')") === false
    || strpos($trackingControllerSource, "isEnabled('courier_leopards')") === false
    || strpos($trackingControllerSource, "isEnabled('courier_trax')") === false
    || strpos($shippingRegistryLifecycleSource, 'wc_get_orders([') === false
    || strpos($shippingRegistryLifecycleSource, "'paginate' => true") === false
    || strpos($shippingRegistryLifecycleSource, "'return' => 'objects'") === false) {
    throw new \\RuntimeException('Courier tracking must use HPOS-compatible order queries and licensed providers only.');
}

$shippingWebhookSource = file_get_contents($src . '/Infrastructure/Http/ShippingWebhookHandler.php');
if (!is_string($shippingWebhookSource)
    || strpos($shippingWebhookSource, 'isEnabled($featureMap[$cc])') === false
    || strpos($shippingWebhookSource, "return new \\WP_REST_Response(['status'=>'disabled'],403)") === false) {
    throw new \\RuntimeException('Courier webhooks must be unavailable when the corresponding licensed feature is disabled.');
}

$shippingRegistrySource = file_get_contents($src . '/Infrastructure/WooCommerce/ShippingRegistry.php');
$servicesSource = file_get_contents(dirname(__DIR__) . '/config/services.php');
$courierRetrySource = file_get_contents($src . '/Application/Logistics/BookShipmentUseCase.php');
if (!is_string($shippingRegistrySource) || !is_string($servicesSource) || !is_string($courierRetrySource)
    || strpos($shippingRegistrySource, "isEnabled('courier_tcs')") === false
    || strpos($shippingRegistrySource, "isEnabled('courier_leopards')") === false
    || strpos($shippingRegistrySource, "isEnabled('courier_trax')") === false
    || strpos($servicesSource, 'if($features->isEnabled(\'courier_tcs\'))') === false
    || strpos($servicesSource, 'if($features->isEnabled(\'courier_leopards\'))') === false
    || strpos($servicesSource, 'if($features->isEnabled(\'courier_trax\'))') === false
    || strpos($courierRetrySource, '$this->cb->hasCouriers()') === false) {
    throw new \\RuntimeException('Courier shipping methods and booking failover must respect central package entitlements and local feature switches.');
}

$stockRepositorySource = file_get_contents($src . '/Infrastructure/Inventory/WpStockRepository.php');
if (!is_string($stockRepositorySource)
    || strpos($stockRepositorySource, 'Unable to verify stock movement idempotency reference.') === false
    || strpos($stockRepositorySource, 'Unable to read aggregate inventory stock.') === false
    || strpos($stockRepositorySource, 'if ($this->db->last_error !== \'\')') === false) {
    throw new \\RuntimeException('A failed movement-reference query must not be treated as an unseen stock movement.');
}

$stockCycleCaptureSource = file_get_contents($src . '/Application/Inventory/CaptureWooCommerceStockCycleUseCase.php');
$stockCycleHooksSource = file_get_contents($src . '/Infrastructure/WooCommerce/InventoryHooks.php');
$stockCycleServicesSource = file_get_contents($root . '/config/services.php');
if (!is_string($stockCycleCaptureSource)
    || strpos($stockCycleCaptureSource, "get_meta('_reduced_stock', true)") === false
    || strpos($stockCycleCaptureSource, "SELECT GET_LOCK(%s, %d)") === false
    || strpos($stockCycleCaptureSource, "state<>'closed'") === false
    || strpos($stockCycleCaptureSource, 'An open WooCommerce stock cycle conflicts with the current reduction snapshot.') === false
    || strpos($stockCycleCaptureSource, "'wc_stock_cycle_reduce:'") === false
    || strpos($stockCycleCaptureSource, "'woocommerce_reduce_order_stock'") === false
    || strpos($stockCycleCaptureSource, "'START TRANSACTION'") === false
    || strpos($stockCycleCaptureSource, "'COMMIT'") === false
    || strpos($stockCycleCaptureSource, "'ROLLBACK'") === false
    || strpos($stockCycleCaptureSource, '$wpdb->last_error') === false
    || strpos($stockCycleCaptureSource, '$this->productRepo->findByWcProductId($variationId)') === false
    || strpos($stockCycleCaptureSource, 'wp_generate_uuid4()') === false
    || !is_string($stockCycleHooksSource)
    || strpos($stockCycleHooksSource, 'CaptureWooCommerceStockCycleUseCase') !== false
    || !is_string($stockCycleServicesSource)
    || strpos($stockCycleServicesSource, 'CaptureWooCommerceStockCycleUseCase::class') !== false) {
    throw new \RuntimeException('WooCommerce stock-cycle capture must snapshot actual reductions atomically and remain disabled until cycle-aware workers are ready.');
}

$stockCycleCaptureSource = file_get_contents($src . '/Application/Inventory/CaptureWooCommerceStockCycleUseCase.php');
if (!is_string($stockCycleCaptureSource)
    || strpos($stockCycleCaptureSource, 'malformed reduced-stock metadata') === false
    || strpos($stockCycleCaptureSource, "if (!is_numeric($reduced))") === false) {
    throw new \\RuntimeException('Stock-cycle capture must reject malformed reduced-stock metadata instead of silently skipping it.');
}

$stockSaleSource = file_get_contents($src . '/Application/Inventory/SyncWooCommerceStockUseCase.php');
if (!is_string($stockSaleSource)
    || strpos($stockSaleSource, 'malformed actual stock-reduction quantity') === false
    || strpos($stockSaleSource, "if (!is_numeric($reducedStock))") === false) {
    throw new \\RuntimeException('WooCommerce sale ingestion must reject malformed reduced-stock metadata.');
}

$jazzCashSource = file_get_contents($src . '/Infrastructure/Payment/JazzCashGateway.php');
if (!is_string($jazzCashSource)
    || strpos($jazzCashSource, "get_woocommerce_currency() !== 'PKR'") === false
    || strpos($jazzCashSource, 'wc_format_decimal($order->get_total(), 2, false)') === false
    || strpos($jazzCashSource, '$wholeRupees . $paisa') === false
    || strpos($jazzCashSource, 'strlen($minorUnits) > 12') === false
    || strpos($jazzCashSource, "WC()->api_request_url('jazzcash_callback')") === false
    || strpos($jazzCashSource, "'pp_ReturnURL'=>$callbackUrl") === false
    || strpos($jazzCashSource, 'wp_safe_redirect($this->get_return_url($order))') === false) {
    throw new \\RuntimeException('JazzCash must reject non-PKR stores and format the PKR minor-unit amount without float truncation.');
}

echo "Smoke tests passed.\n";
