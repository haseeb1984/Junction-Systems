<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin\Inventory;
use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface,StockRepositoryInterface,LocationRepositoryInterface};
use PkStore\Application\Inventory\{AdjustStockUseCase,GenerateBarcodeUseCase,SyncWooCommerceStockUseCase,WooCommerceStockMappingPolicy};

final class InventoryAdminController {
    private const CAP = 'manage_woocommerce';
    public function __construct(private readonly ServiceContainer $c) {}

    private function schemaReady(): bool {
        return version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.13', '>=');
    }

    private function requireSchemaReady(): void {
        if (!$this->schemaReady()) {
            wp_die('Inventory and POS are temporarily paused until the protected database migration and backup confirmation are complete.');
        }
    }

    public function register(): void {
        add_action('admin_menu',            [$this, 'menus']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_init',            [$this, 'handleForms']);
    }

    public function menus(): void {
        if (!$this->schemaReady()) return;
        $features = FeatureManager::getInstance();
        if (!$features->isEnabled('inventory')) return;

        add_submenu_page('pkstore-operations','Inventory','📦 Inventory',self::CAP,'pkstore-inventory',[$this,'inventory']);
        if ($features->isEnabled('pos')) {
            // Cashiers have only use_junction_pos, not manage_woocommerce. Give
            // them a direct top-level POS entry instead of hiding it under the
            // manager-only operations menu.
            add_menu_page('Junction POS','🖥 Junction POS','use_junction_pos','pkstore-pos-register',[$this,'pos'],'dashicons-store',56);
            add_submenu_page('pkstore-operations','POS Register','🖥 POS Register',self::CAP,'pkstore-pos',[$this,'pos']);
        }
        add_submenu_page('pkstore-operations','Stock Movements','📊 Stock Log',self::CAP,'pkstore-stock-log',[$this,'stockLog']);
        add_submenu_page('pkstore-operations','Inventory Event Health','🔄 Sync Health',self::CAP,'pkstore-inventory-events',[$this,'inventoryEvents']);
        if (version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.15', '>=')) {
            add_submenu_page('pkstore-operations','Purchase Orders','🚚 Purchase Orders','manage_options','pkstore-purchase-orders',[$this,'purchaseOrders']);
        }
        if ($features->isEnabled('multi_location')) {
            add_submenu_page('pkstore-operations','Inventory Locations','📍 Locations',self::CAP,'pkstore-locations',[$this,'locations']);
        }
    }

    public function assets(string $hook): void {
        $ours = ['store-ops_page_pkstore-inventory','store-ops_page_pkstore-pos','store-ops_page_pkstore-stock-log','store-ops_page_pkstore-inventory-events','store-ops_page_pkstore-purchase-orders','store-ops_page_pkstore-locations'];
        if (!in_array($hook,$ours,true)) return;
        wp_enqueue_style('pkstore-admin',PKSTORE_ASSETS.'/css/admin.css',[],PKSTORE_VERSION);
    }

    public function handleForms(): void {
        if (!isset($_POST['pkstore_inventory_action'])) return;
        $this->requireSchemaReady();
        $action = sanitize_key($_POST['pkstore_inventory_action']);
        $purchaseActions = ['create_supplier', 'create_purchase_order', 'receive_purchase_order'];
        if (in_array($action, $purchaseActions, true)) {
            if (!current_user_can('manage_options')) wp_die('Unauthorized.');
            if (version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.15', '<')) wp_die('Purchase receiving schema migration is not complete.');
        } elseif (!current_user_can(self::CAP)) {
            wp_die('Unauthorized.');
        }
        $features = FeatureManager::getInstance();
        if (!$features->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        if ($action === 'transfer_stock' && !$features->isEnabled('multi_location')) wp_die('Multi-location inventory is not available on the current package.');
        check_admin_referer('pkstore_inventory_action');
        if ($action === 'generate_barcodes' && !$features->isEnabled('barcode_labels')) {
            wp_die('Barcode labels are not available on the current package.');
        }
        if ($action === 'add_location' && !$features->isEnabled('multi_location')) {
            wp_die('Multi-location inventory is not available on the current package.');
        }
        if (in_array($action, ['retry_inventory_event', 'ignore_inventory_event'], true)) {
            $this->handleInventoryEventAction($action);
            return;
        }
        match($action) {
            'add_product'     => $this->handleAddProduct(),
            'add_location'    => $this->handleAddLocation(),
            'adjust_stock'    => $this->handleAdjustStock(),
            'transfer_stock'  => $this->handleTransferStock(),
            'create_supplier' => $this->handleCreateSupplier(),
            'create_purchase_order' => $this->handleCreatePurchaseOrder(),
            'receive_purchase_order' => $this->handleReceivePurchaseOrder(),
            'generate_barcodes' => $this->handleGenerateBarcodes(),
            'sync_woocommerce'  => $this->handleSyncWooCommerce(),
            default            => null,
        };
    }

    private function handleInventoryEventAction(string $action): void {
        global $wpdb;
        $queueType = sanitize_key($_POST['queue_type'] ?? '');
        $queueId = filter_var($_POST['queue_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        if (!in_array($queueType, ['sales', 'returns', 'stock'], true)
            || ($action === 'ignore_inventory_event' && ($queueType === 'stock' || ($_POST['confirm_ignore'] ?? '') !== '1'))
            || ($action === 'retry_inventory_event' && $queueType === 'returns' && ($_POST['confirm_reconciliation'] ?? '') !== '1')
            || $queueId === false || $queueId < 1
            || strlen(trim($reason)) < 5 || strlen($reason) > 1000) {
            $this->redirect('pkstore-inventory-events', 'error', 'A valid event and an operator reason of at least 5 characters are required.');
            return;
        }

        $table = $wpdb->prefix . ($queueType === 'sales'
            ? 'pkstore_wc_order_inventory_queue'
            : ($queueType === 'returns' ? 'pkstore_wc_return_inventory_queue' : 'pkstore_wc_stock_sync'));
        // Build a read-only reconciliation snapshot for cycle-bound returns.
        // It compares each captured allocation with the original sale movement and
        // the cumulative return movements already recorded for that exact cycle.
        $returnEvidence = [];
        if ($available['returns'] && $cycleEventsAvailable) {
            $reviewReturns = $wpdb->get_results(
                "SELECT id,event_kind,wc_order_id,wc_order_item_id,wc_product_id,cycle_uuid,quantity,cumulative_quantity
                 FROM {$tables['returns']}
                 WHERE status IN ('needs_review','failed') AND cycle_uuid IS NOT NULL AND cycle_uuid<>''
                 ORDER BY updated_at ASC,id ASC LIMIT 50",
                ARRAY_A
            );
            if ($wpdb->last_error !== '') throw new \\RuntimeException('Unable to load return reconciliation evidence.');
            foreach ((array) $reviewReturns as $reviewReturn) {
                $cycleUuid = (string) $reviewReturn['cycle_uuid'];
                $itemId = (int) $reviewReturn['wc_order_item_id'];
                $cycleItem = $wpdb->get_row($wpdb->prepare(
                    "SELECT inventory_product_id,reduced_quantity,refund_cumulative_baseline,
                            cancellation_restored_quantity,refund_restored_quantity,allocation_snapshot
                     FROM {$wpdb->prefix}pkstore_wc_stock_cycle_items
                     WHERE cycle_uuid=%s AND wc_order_id=%d AND wc_order_item_id=%d",
                    $cycleUuid, (int) $reviewReturn['wc_order_id'], $itemId
                ), ARRAY_A);
                if ($wpdb->last_error !== '') throw new \\RuntimeException('Unable to inspect original stock-cycle allocation.');
                $evidence = [
                    'queue_id' => (int) $reviewReturn['id'],
                    'order_id' => (int) $reviewReturn['wc_order_id'],
                    'item_id' => $itemId,
                    'cycle_uuid' => $cycleUuid,
                    'kind' => (string) $reviewReturn['event_kind'],
                    'quantity' => (int) $reviewReturn['quantity'],
                    'cumulative_quantity' => (int) $reviewReturn['cumulative_quantity'],
                    'state' => 'Allocation snapshot unavailable',
                    'allocations' => [],
                ];
                if (is_array($cycleItem)) {
                    $reduced = (int) $cycleItem['reduced_quantity'];
                    $returned = (int) $cycleItem['cancellation_restored_quantity'] + (int) $cycleItem['refund_restored_quantity'];
                    $allocations = json_decode((string) $cycleItem['allocation_snapshot'], true);
                    if (is_array($allocations) && $allocations && $reduced > 0 && $returned <= $reduced) {
                        $allocated = 0;
                        $valid = true;
                        $cursor = 0;
                        foreach ($allocations as $allocation) {
                            $productId = is_array($allocation) ? (int) ($allocation['product_id'] ?? 0) : 0;
                            $locationId = is_array($allocation) ? (int) ($allocation['location_id'] ?? 0) : 0;
                            $allocationQuantity = is_array($allocation) ? (int) ($allocation['quantity'] ?? 0) : 0;
                            if ($productId < 1 || $locationId < 1 || $allocationQuantity < 1) {
                                $valid = false;
                                break;
                            }
                            $saleMovement = $wpdb->get_row($wpdb->prepare(
                                "SELECT quantity_delta FROM {$wpdb->prefix}pkstore_inventory_movements
                                 WHERE product_id=%d AND location_id=%d AND reference_type='wc_stock_cycle'
                                   AND reference_id=%s AND movement_type='online_sale' LIMIT 1",
                                $productId, $locationId, $cycleUuid . ':' . $locationId
                            ), ARRAY_A);
                            if ($wpdb->last_error !== '') throw new \\RuntimeException('Unable to verify original sale stock movement.');
                            $returnMovement = $wpdb->get_row($wpdb->prepare(
                                "SELECT COUNT(*) AS movement_count,COALESCE(SUM(quantity_delta),0) AS restored_quantity
                                 FROM {$wpdb->prefix}pkstore_inventory_movements
                                 WHERE product_id=%d AND location_id=%d AND reference_type='wc_stock_cycle_return'
                                   AND movement_type='online_return' AND note LIKE %s",
                                $productId, $locationId, '%' . $wpdb->esc_like($cycleUuid) . '%'
                            ), ARRAY_A);
                            if ($wpdb->last_error !== '') throw new \\RuntimeException('Unable to verify existing return stock movements.');
                            $expectedReturn = max(0, min($returned, $cursor + $allocationQuantity) - $cursor);
                            $evidence['allocations'][] = [
                                'product_id' => $productId,
                                'location_id' => $locationId,
                                'quantity' => $allocationQuantity,
                                'sale_verified' => is_array($saleMovement) && (int) $saleMovement['quantity_delta'] === -$allocationQuantity,
                                'expected_returned' => $expectedReturn,
                                'actual_returned' => (int) ($returnMovement['restored_quantity'] ?? 0),
                                'return_movement_count' => (int) ($returnMovement['movement_count'] ?? 0),
                            ];
                            if (!is_array($saleMovement) || (int) $saleMovement['quantity_delta'] !== -$allocationQuantity
                                || (int) ($returnMovement['restored_quantity'] ?? -1) !== $expectedReturn) {
                                $valid = false;
                            }
                            $allocated += $allocationQuantity;
                            $cursor += $allocationQuantity;
                        }
                        if ($allocated !== $reduced) $valid = false;
                        $evidence['state'] = $valid ? 'Ledger evidence reconciles' : 'Ledger mismatch — do not retry';
                        $evidence['reduced_quantity'] = $reduced;
                        $evidence['returned_quantity'] = $returned;
                        $evidence['refund_baseline'] = (int) $cycleItem['refund_cumulative_baseline'];
                    }
                }
                $returnEvidence[] = $evidence;
            }
        }

        $auditTable = $wpdb->prefix . 'pkstore_inventory_queue_audit';
        $now = current_time('mysql');
        if ($wpdb->query('START TRANSACTION') === false) {
            $this->redirect('pkstore-inventory-events', 'error', 'Could not start the queue action.');
            return;
        }

        try {
            if ($action === 'retry_inventory_event') {
                if ($queueType === 'sales' || $queueType === 'stock') {
                    $updated = $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET status='pending', generation=%s, attempts=0,
                         next_attempt_at=%s, claimed_at=NULL, claim_token=NULL, last_error='', updated_at=%s
                         WHERE id=%d AND status='needs_review'",
                        wp_generate_uuid4(), $now, $now, $queueId
                    ));
                } else {
                    // Reopen the matching immutable cycle event atomically with
                    // its queue row; otherwise markReturnPending() rejects it.
                    $returnRow = $wpdb->get_row($wpdb->prepare(
                        "SELECT cycle_uuid,wc_order_id,wc_order_item_id,event_kind
                         FROM {$table} WHERE id=%d AND status='needs_review' FOR UPDATE",
                        $queueId
                    ), ARRAY_A);
                    if ($wpdb->last_error !== '' || !is_array($returnRow)) {
                        throw new \RuntimeException('Return queue event is no longer available for operator retry.');
                    }
                    $updated = $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET status='pending', attempts=0,
                         next_attempt_at=%s, claimed_at=NULL, claim_token=NULL, last_error='', updated_at=%s
                         WHERE id=%d AND status='needs_review'",
                        $now, $now, $queueId
                    ));
                    $cycleUuid = (string) ($returnRow['cycle_uuid'] ?? '');
                    if ($updated === 1 && $cycleUuid !== '') {
                        $cycleEventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
                        $cycleEvent = $wpdb->get_row($wpdb->prepare(
                            "SELECT id,status FROM {$cycleEventsTable}
                             WHERE cycle_uuid=%s AND wc_order_id=%d AND wc_order_item_id=%d AND event_kind=%s",
                            $cycleUuid, (int) $returnRow['wc_order_id'],
                            (int) $returnRow['wc_order_item_id'], (string) $returnRow['event_kind']
                        ), ARRAY_A);
                        if ($wpdb->last_error !== '' || !is_array($cycleEvent)) {
                            throw new \RuntimeException('Matching stock-cycle return event is unavailable.');
                        }
                        // A pending event is already eligible; a processed event
                        // means the ledger work committed and only queue acknowledgement
                        // needs replay. Never rewind processed state or steal a live claim.
                        if (in_array((string) $cycleEvent['status'], ['needs_review','failed','review'], true)) {
                            $cycleUpdated = $wpdb->query($wpdb->prepare(
                                "UPDATE {$cycleEventsTable}
                                 SET status='pending', attempts=0, next_attempt_at=%s,
                                     claimed_at=NULL, claim_token=NULL, last_error='', updated_at=%s
                                 WHERE id=%d AND status IN ('needs_review','failed','review')",
                                $now, $now, (int) $cycleEvent['id']
                            ));
                            if ($cycleUpdated !== 1) {
                                throw new \RuntimeException('Matching stock-cycle return event changed during recovery.');
                            }
                        } elseif (!in_array((string) $cycleEvent['status'], ['pending','processed'], true)) {
                            throw new \RuntimeException('Matching stock-cycle return event is not safely retryable.');
                        }
                    }
                }
            } else {
                if ($queueType === 'sales' || $queueType === 'returns') {
                    // Cycle-bound sales/returns have a second durable lifecycle record.
                    // Ignoring only the queue row would strand that cycle event and
                    // prevent recovery from rebuilding the missing queue entry.
                    $cycleEvent = $wpdb->get_row($wpdb->prepare(
                        "SELECT cycle_uuid,status FROM {$table} WHERE id=%d AND status='needs_review' FOR UPDATE",
                        $queueId
                    ), ARRAY_A);
                    if ($wpdb->last_error !== '' || !is_array($cycleEvent)) {
                        throw new \RuntimeException('Inventory queue event is no longer available for operator review.');
                    }
                    $cycleUuid = (string) ($cycleEvent['cycle_uuid'] ?? '');
                    if ($queueType === 'sales' && $cycleUuid !== '00000000-0000-0000-0000-000000000000') {
                        throw new \RuntimeException('Cycle-bound sales cannot be ignored until a dedicated cycle reconciliation workflow is available. Retry the event or reconcile it outside this action.');
                    }
                    if ($queueType === 'returns' && $cycleUuid !== '') {
                        throw new \RuntimeException('Cycle-bound returns cannot be ignored until a dedicated cycle reconciliation workflow is available. Retry the event or reconcile it outside this action.');
                    }
                }
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status='ignored', claimed_at=NULL, claim_token=NULL, updated_at=%s
                     WHERE id=%d AND status='needs_review'",
                    $now, $queueId
                ));
            }

            if ($updated !== 1) {
                throw new \RuntimeException('Queue event is no longer in needs_review state.');
            }
            $inserted = $wpdb->insert($auditTable, [
                'queue_type' => $queueType,
                'queue_id' => $queueId,
                'action' => $action === 'retry_inventory_event' ? 'retry' : 'ignore',
                'previous_status' => 'needs_review',
                'resulting_status' => $action === 'retry_inventory_event' ? 'pending' : 'ignored',
                'reason' => $reason,
                'actor_id' => get_current_user_id(),
                'created_at' => $now,
            ]);
            if ($inserted !== 1) throw new \RuntimeException('Could not append the operator audit record.');
            if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Could not commit the queue action.');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            error_log('[PKStore Inventory] Queue operator action failed ('.get_class($error).').');
            $this->redirect('pkstore-inventory-events', 'error', 'The queue action was not completed. Refresh and inspect the event.');
            return;
        }

        $this->redirect('pkstore-inventory-events', 'updated', 'Queue action saved with an audit record.');
    }

    private function handleAddLocation(): void {
        if (!FeatureManager::getInstance()->isEnabled('multi_location')) {
            wp_die('Multi-location inventory is not available on the current package.');
        }
        $name = sanitize_text_field($_POST['location_name'] ?? '');
        $type = sanitize_key($_POST['location_type'] ?? 'physical');
        $address = sanitize_textarea_field($_POST['location_address'] ?? '');
        $isPos = isset($_POST['location_is_pos']) && $_POST['location_is_pos'] === '1';
        if ($name === '' || strlen($name) > 100
            || !in_array($type, ['physical', 'warehouse'], true)
            || ($isPos && $type !== 'physical')) {
            $this->redirect('pkstore-locations', 'error', 'Enter a valid location name and type. POS can only be enabled for physical locations.');
            return;
        }
        try {
            $this->c->make(LocationRepositoryInterface::class)->create($name, $type, $address, $isPos);
            $this->redirect('pkstore-locations', 'added', 'Inventory location added.');
        } catch (\Throwable $error) {
            $this->redirect('pkstore-locations', 'error', 'Location could not be added. Check for a duplicate active name and try again.');
        }
    }

    private function handleAddProduct(): void {
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $category = sanitize_text_field($_POST['category'] ?? '');
        $unit = sanitize_text_field($_POST['unit'] ?? 'piece');
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        if ($sku === '' || strlen($sku) > 100 || $name === '' || strlen($name) > 255
            || strlen($category) > 100 || $unit === '' || strlen($unit) > 20 || strlen($barcode) > 50) {
            $this->redirect('pkstore-inventory','error','Enter valid SKU, name, category, unit and barcode values.'); return;
        }

        $costPrice = current_user_can('manage_options') ? filter_var($_POST['cost_price'] ?? 0, FILTER_VALIDATE_INT) : 0;
        $salePrice = filter_var($_POST['sale_price'] ?? 0, FILTER_VALIDATE_INT);
        $reorderPoint = filter_var($_POST['reorder_point'] ?? 5, FILTER_VALIDATE_INT);
        $wcProductId = filter_var($_POST['wc_product_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($costPrice === false || $salePrice === false || $reorderPoint === false || $wcProductId === false
            || $costPrice < 0 || $salePrice < 0 || $reorderPoint < 0 || $wcProductId < 0) {
            $this->redirect('pkstore-inventory','error','Prices, reorder point or WooCommerce product mapping are invalid.'); return;
        }
        if ($wcProductId > 0) {
            $wcProduct = function_exists('wc_get_product') ? wc_get_product($wcProductId) : null;
            if (!$wcProduct) {
                $this->redirect('pkstore-inventory','error','The selected WooCommerce product does not exist.'); return;
            }
            $mappingError = WooCommerceStockMappingPolicy::errorFor($wcProduct);
            if ($mappingError !== null) {
                $this->redirect('pkstore-inventory','error',$mappingError); return;
            }
        }

        try {
            $repo = $this->c->make(InventoryProductRepositoryInterface::class);
            if ($repo->findBySku($sku)) {
                $this->redirect('pkstore-inventory','error','SKU already exists.'); return;
            }
            if ($barcode !== '' && $repo->findByBarcode($barcode)) {
                $this->redirect('pkstore-inventory','error','Barcode already exists.'); return;
            }
            if ($wcProductId > 0 && $repo->findByWcProductId($wcProductId)) {
                $this->redirect('pkstore-inventory','error','This WooCommerce product is already mapped to Junction inventory.'); return;
            }

            $product = \PkStore\Domain\Inventory\Entity\InventoryProduct::create(
                $sku, $name, $costPrice, $salePrice, $category,
                $wcProductId > 0 ? $wcProductId : null, $barcode, $unit, $reorderPoint
            );
            $id = $repo->save($product);
            if (!$product->hasBarcode() && FeatureManager::getInstance()->isEnabled('barcode_labels')) {
                try {
                    $this->c->make(GenerateBarcodeUseCase::class)->execute($id);
                } catch (\Throwable $barcodeError) {
                    error_log('[PKStore Inventory] Product was created but automatic barcode generation failed ('.get_class($barcodeError).').');
                }
            }
            $this->redirect('pkstore-inventory','added','Product added.');
        } catch (\Throwable $error) {
            error_log('[PKStore Inventory] Admin product creation failed ('.get_class($error).').');
            $this->redirect('pkstore-inventory','error','Product could not be added. Check SKU, barcode and WooCommerce mapping for duplicates.');
        }
    }
    private function handleAdjustStock(): void {
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $type = \PkStore\Domain\Inventory\Entity\MovementType::tryFrom(sanitize_key($_POST['movement_type'] ?? 'adjustment'));
        $allowedTypes = [
            \PkStore\Domain\Inventory\Entity\MovementType::Adjustment,
            \PkStore\Domain\Inventory\Entity\MovementType::PurchaseReceive,
            \PkStore\Domain\Inventory\Entity\MovementType::Damage,
            \PkStore\Domain\Inventory\Entity\MovementType::Opening,
        ];
        if ($productId === false || $locationId === false || $quantity === false || $productId < 1 || $locationId < 1 || $quantity === 0 || !$type || !in_array($type, $allowedTypes, true)) {
            $this->redirect('pkstore-inventory','error','Invalid stock adjustment details.'); return;
        }
        if (($type === \PkStore\Domain\Inventory\Entity\MovementType::PurchaseReceive || $type === \PkStore\Domain\Inventory\Entity\MovementType::Opening) && $quantity < 1) {
            $this->redirect('pkstore-inventory','error','Purchase receipts and opening stock must add a positive quantity.'); return;
        }
        if ($type === \PkStore\Domain\Inventory\Entity\MovementType::Damage && $quantity > -1) {
            $this->redirect('pkstore-inventory','error','Damage/loss must reduce stock using a negative quantity.'); return;
        }
        $product = $this->c->make(InventoryProductRepositoryInterface::class)->findById($productId);
        $location = $this->c->make(LocationRepositoryInterface::class)->findById($locationId);
        if (!$product || !$product->isActive || !$location || !$location->isActive) {
            $this->redirect('pkstore-inventory','error','Active product and location are required.'); return;
        }
        try {
            $this->c->make(AdjustStockUseCase::class)->executeManualAdjustment(
                $productId,
                $locationId,
                $quantity,
                $type,
                sanitize_text_field($_POST['note'] ?? 'Manual adjustment')
            );
            $this->redirect('pkstore-inventory','adjusted','Stock updated.');
        } catch(\Throwable $e) {
            $this->redirect('pkstore-inventory','error','Stock adjustment could not be completed. Verify the available stock and try again.');
        }
    }

    private function handleGenerateBarcodes(): void {
        $count = $this->c->make(GenerateBarcodeUseCase::class)->generateForAll();
        $this->redirect('pkstore-inventory','barcodes_generated',"Generated barcodes for {$count} products.");
    }

    private function handleSyncWooCommerce(): void {
        $count = $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce();
        $this->redirect('pkstore-inventory','synced',"Synced {$count} products to WooCommerce.");
    }


    private function handleTransferStock(): void {
        try {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $fromLocationId = filter_var($_POST['from_location_id'] ?? null, FILTER_VALIDATE_INT);
            $toLocationId = filter_var($_POST['to_location_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $fromLocationId === false || $toLocationId === false || $quantity === false
                || $productId < 1 || $fromLocationId < 1 || $toLocationId < 1 || $quantity < 1 || $quantity > 2147483647) {
                throw new \InvalidArgumentException('Transfer identifiers and quantity must be positive whole numbers.');
            }
            $reference = sanitize_text_field($_POST['reference_id'] ?? '');
            $this->c->make(\PkStore\Application\Inventory\TransferStockUseCase::class)->execute(
                $productId, $fromLocationId, $toLocationId, $quantity, $reference,
                sanitize_text_field($_POST['note'] ?? '')
            );
            $this->redirect('pkstore-inventory', 'transferred', 'Stock transfer completed.');
        } catch (\Throwable $error) {
            $this->redirect('pkstore-inventory', 'error', 'Stock transfer failed. Verify source stock, locations and transfer reference.');
        }
    }

    private function handleCreateSupplier(): void {
        global $wpdb;
        $name = sanitize_text_field($_POST['supplier_name'] ?? '');
        $email = sanitize_email($_POST['supplier_email'] ?? '');
        $contact = sanitize_text_field($_POST['supplier_contact'] ?? '');
        $phone = sanitize_text_field($_POST['supplier_phone'] ?? '');
        $address = sanitize_textarea_field($_POST['supplier_address'] ?? '');
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $contactLength = function_exists('mb_strlen') ? mb_strlen($contact, 'UTF-8') : strlen($contact);
        if ($name === '' || $nameLength > 150 || $contactLength > 100 || strlen($phone) > 20
            || strlen($email) > 150 || strlen($address) > 65000 || ($email !== '' && !is_email($email))) {
            $this->redirect('pkstore-purchase-orders', 'error', 'Supplier details exceed allowed lengths or the email is invalid.');
            return;
        }
        $inserted = $wpdb->insert($wpdb->prefix . 'pkstore_inventory_suppliers', [
            'name'=>$name,
            'contact'=>$contact,
            'phone'=>$phone,
            'email'=>$email,
            'address'=>$address,
            'is_active'=>1,
            'created_at'=>current_time('mysql'),
        ]);
        $this->redirect('pkstore-purchase-orders', $inserted === false ? 'error' : 'supplier_added',
            $inserted === false ? 'Unable to create supplier.' : 'Supplier created.');
    }

    private function handleCreatePurchaseOrder(): void {
        try {
            $raw = $_POST['items'] ?? [];
            if (!is_array($raw)) throw new \InvalidArgumentException('Purchase order items are invalid.');
            $items = [];
            foreach ($raw as $item) {
                if (!is_array($item)) throw new \InvalidArgumentException('Purchase order item is invalid.');
                $items[] = [
                    'product_id'=>$item['product_id'] ?? null,
                    'quantity'=>$item['quantity'] ?? null,
                    'unit_cost_pkr'=>$item['unit_cost_pkr'] ?? null,
                ];
            }
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
            if ($supplierId === false || $locationId === false || $supplierId < 1 || $locationId < 1) {
                throw new \InvalidArgumentException('Supplier and location identifiers must be positive integers.');
            }
            $id = $this->c->make(\PkStore\Application\Inventory\CreatePurchaseOrderUseCase::class)->execute(
                $supplierId, $locationId, $items,
                sanitize_textarea_field($_POST['notes'] ?? '')
            );
            $this->redirect('pkstore-purchase-orders', 'po_created', 'Purchase order #' . $id . ' created.');
        } catch (\Throwable $error) {
            $this->redirect('pkstore-purchase-orders', 'error', 'Purchase order could not be created. Check supplier, location, products, quantities and costs.');
        }
    }

    private function handleReceivePurchaseOrder(): void {
        try {
            $raw = $_POST['receive_qty'] ?? [];
            if (!is_array($raw)) throw new \InvalidArgumentException('Receipt quantities are invalid.');
            $items = [];
            foreach ($raw as $itemId => $quantity) {
                if (filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 0) {
                    throw new \InvalidArgumentException('Receipt quantities must be whole non-negative numbers.');
                }
                if ((int) $quantity > 0) $items[] = ['item_id'=>$itemId, 'quantity'=>$quantity];
            }
            $purchaseOrderId = filter_var($_POST['purchase_order_id'] ?? null, FILTER_VALIDATE_INT);
            if ($purchaseOrderId === false || $purchaseOrderId < 1) throw new \InvalidArgumentException('Purchase order identifier must be a positive integer.');
            $reference = sanitize_text_field($_POST['receipt_reference'] ?? '');
            $this->c->make(\PkStore\Application\Inventory\ReceivePurchaseOrderUseCase::class)->execute(
                $purchaseOrderId, $items, $reference
            );
            $this->redirect('pkstore-purchase-orders', 'received', 'Purchase receipt saved.');
        } catch (\Throwable $error) {
            $this->redirect('pkstore-purchase-orders', 'error', 'Purchase receipt could not be saved. Verify remaining quantities and receipt reference.');
        }
    }

    private function redirect(string $page, string $status, string $msg): void {
        wp_safe_redirect(add_query_arg(['page'=>$page,'status'=>$status,'msg'=>urlencode($msg)],admin_url('admin.php'))); exit;
    }

    public function locations(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('multi_location')) {
            wp_die('Multi-location inventory is not available on the current package.');
        }
        $locations = $this->c->make(LocationRepositoryInterface::class)->findAll();
        $msg = sanitize_text_field($_GET['msg'] ?? '');
        $status = sanitize_key($_GET['status'] ?? '');
        include PKSTORE_DIR . '/templates/admin/inventory/locations.php';
    }

    public function inventory(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        $repo      = $this->c->make(InventoryProductRepositoryInterface::class);
        $stockRepo = $this->c->make(StockRepositoryInterface::class);
        $locations = $this->c->make(LocationRepositoryInterface::class)->findAll();
        $canTransfer = FeatureManager::getInstance()->isEnabled('multi_location') && count($locations) > 1;
        $search    = sanitize_text_field($_GET['s']??''  );
        $products  = $repo->findAll(50, 0, $search);
        $lowStock  = $repo->findBelowReorderPoint();
        $msg       = sanitize_text_field($_GET['msg']??''  );
        $status    = sanitize_key($_GET['status']??''  );
        include PKSTORE_DIR.'/templates/admin/inventory/inventory.php';
    }

    public function pos(): void {
        $this->requireSchemaReady();
        if (!current_user_can('use_junction_pos')) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('pos')) wp_die('POS is not available on the current package.');
        include PKSTORE_DIR.'/templates/pos/pos-app.php';
    }

    public function stockLog(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        global $wpdb;
        $movements = $wpdb->get_results(
            "SELECT m.*,p.name AS product_name,p.sku,l.name AS location_name
             FROM {$wpdb->prefix}pkstore_inventory_movements m
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_products p ON m.product_id=p.id
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_locations l ON m.location_id=l.id
             ORDER BY m.created_at DESC LIMIT 200",
            ARRAY_A
        ) ?: [];
        include PKSTORE_DIR.'/templates/admin/inventory/stock-log.php';
    }


    /**
     * Read-only operational view of durable WooCommerce inventory queues.
     * This deliberately offers no force-complete or direct stock mutation action.
     */
    public function inventoryEvents(): void {
        $this->requireSchemaReady();
        if (!current_user_can(self::CAP)) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');

        global $wpdb;
        $tables = [
            'sales' => $wpdb->prefix . 'pkstore_wc_order_inventory_queue',
            'returns' => $wpdb->prefix . 'pkstore_wc_return_inventory_queue',
            'stock' => $wpdb->prefix . 'pkstore_wc_stock_sync',
        ];
        $available = [];
        $missingQueues = [];
        foreach ($tables as $key => $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            $available[$key] = ($found === $table);
            if (!$available[$key]) $missingQueues[] = $key;
        }

        $queueCounts = [];
        foreach ($tables as $key => $table) {
            $queueCounts[$key] = [];
            if (!$available[$key]) continue;
            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
                ARRAY_A
            ) ?: [];
            foreach ($rows as $row) {
                $queueCounts[$key][sanitize_key($row['status'])] = (int) $row['total'];
            }
        }

        $salesEvents = $available['sales'] ? ($wpdb->get_results(
            "SELECT id, 'WooCommerce sale' AS event, CONCAT('#',wc_order_id) AS reference,
                    CONCAT('Cycle ',LEFT(cycle_uuid,8)) AS details, status, attempts,
                    next_attempt_at, claimed_at, last_error, created_at
             FROM {$tables['sales']} ORDER BY updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $returnEvents = $available['returns'] ? ($wpdb->get_results(
            "SELECT id, CONCAT('WooCommerce ',event_kind,' restock') AS event,
                    CONCAT('#',wc_order_id) AS reference,
                    CONCAT('Cycle ',LEFT(COALESCE(cycle_uuid,'legacy'),8),' · Item ',COALESCE(wc_order_item_id,'—'),' · Product ',wc_product_id,' · Qty ',quantity) AS details,
                    cycle_uuid, status, attempts, next_attempt_at, claimed_at, last_error, created_at
             FROM {$tables['returns']} ORDER BY updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $stockEvents = $available['stock'] ? ($wpdb->get_results(
            "SELECT 'WooCommerce stock sync' AS event,
                    CONCAT('Junction product #',q.product_id) AS reference,
                    COALESCE(p.name,'Product mapping unavailable') AS details,
                    q.status, q.attempts, q.next_attempt_at, q.claimed_at, q.last_error, q.created_at
             FROM {$tables['stock']} q
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_products p ON p.id=q.product_id
             ORDER BY q.updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $cycleEventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
        $cycleEventsAvailable = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($cycleEventsTable)
        )) === $cycleEventsTable;
        if (!$cycleEventsAvailable) $missingQueues[] = 'stock-cycle events';
        $cycleEvents = $cycleEventsAvailable ? ($wpdb->get_results(
            "SELECT event_uuid,cycle_uuid,wc_order_id,wc_order_item_id,event_kind,quantity,
                    cumulative_quantity,source_hook,status,attempts,next_attempt_at,claimed_at,last_error,created_at
             FROM {$cycleEventsTable} ORDER BY updated_at DESC LIMIT 100",
            ARRAY_A
        ) ?: []) : [];

        $auditTable = $wpdb->prefix . 'pkstore_inventory_queue_audit';
        $auditExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($auditTable))) === $auditTable;
        if (!$auditExists) $missingQueues[] = 'operator audit';
        $operatorAudit = $auditExists ? ($wpdb->get_results(
            "SELECT queue_type, queue_id, action, previous_status, resulting_status, reason, actor_id, created_at
             FROM {$auditTable} ORDER BY id DESC LIMIT 50",
            ARRAY_A
        ) ?: []) : [];

        include PKSTORE_DIR.'/templates/admin/inventory/inventory-events.php';
    }

    public function purchaseOrders(): void {
        if (version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.15', '<')) {
            wp_die('Purchase receiving is paused until the protected database migration and backup confirmation are complete.');
        }
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('inventory')) wp_die('Inventory is not available on the current package.');
        global $wpdb;
        $pos = $wpdb->get_results(
            "SELECT po.*,s.name AS supplier_name,l.name AS location_name
             FROM {$wpdb->prefix}pkstore_purchase_orders po
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_suppliers s ON po.supplier_id=s.id
             LEFT JOIN {$wpdb->prefix}pkstore_inventory_locations l ON po.location_id=l.id
             ORDER BY po.created_at DESC LIMIT 100",
            ARRAY_A
        ) ?: [];
        $suppliers = $wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}pkstore_inventory_suppliers WHERE is_active=1 ORDER BY name", ARRAY_A) ?: [];
        $locations = $this->c->make(LocationRepositoryInterface::class)->findAll();
        $products = $this->c->make(InventoryProductRepositoryInterface::class)->findAll(500, 0, '');
        $poItems = [];
        if ($pos) {
            $ids = array_map(static fn(array $po): int => (int) $po['id'], $pos);
            $idList = implode(',', array_map('intval', $ids));
            $lines = $wpdb->get_results(
                "SELECT i.id AS item_id,i.po_id,i.product_id,i.quantity_ordered,i.quantity_received,i.unit_cost_pkr,p.name AS product_name,p.sku
                 FROM {$wpdb->prefix}pkstore_purchase_order_items i
                 LEFT JOIN {$wpdb->prefix}pkstore_inventory_products p ON p.id=i.product_id
                 WHERE i.po_id IN ({$idList}) ORDER BY i.id",
                ARRAY_A
            ) ?: [];
            foreach ($lines as $line) $poItems[(int) $line['po_id']][] = $line;
        }
        $msg = sanitize_text_field($_GET['msg'] ?? '');
        $status = sanitize_key($_GET['status'] ?? '');
        include PKSTORE_DIR . '/templates/admin/inventory/purchase-orders.php';
    }

}
