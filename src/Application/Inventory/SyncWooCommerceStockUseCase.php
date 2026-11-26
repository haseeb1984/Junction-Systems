<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, StockRepositoryInterface, LocationRepositoryInterface};
use Psr\Log\LoggerInterface;
final class SyncWooCommerceStockUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly StockRepositoryInterface $stockRepo,
        private readonly LocationRepositoryInterface $locationRepo,
        private readonly AdjustStockUseCase $adjustStock,
        private readonly LoggerInterface $log,
    ) {}
    public function onWooCommerceSale(int $orderId, ?string $cycleUuid = null): void {
        if ($orderId < 1 || ($cycleUuid !== null && !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid))) {
            throw new \InvalidArgumentException('Invalid WooCommerce order or stock-cycle identity.');
        }
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) throw new \RuntimeException('WooCommerce order is unavailable for inventory ingestion.');
        $this->withOrderProductLocks($order, function () use ($orderId, $cycleUuid, $order): void {
            $this->withOrderLock($orderId, function () use ($orderId, $cycleUuid, $order): void {
                if ($cycleUuid !== null) {
                    $this->applyWooCommerceSaleCycle($order, $cycleUuid);
                    return;
                }
                $snapshotVersion = (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true);
                if ($snapshotVersion !== '' && $snapshotVersion !== '1') {
                    throw new \RuntimeException('WooCommerce stock-reduction snapshot capture is incomplete and must be reviewed before ledger ingestion.');
                }
                if ($snapshotVersion === '1') {
                    throw new \RuntimeException('Cycle-aware WooCommerce sale must be bound to a cycle UUID by its durable queue worker.');
                }
                $this->applyWooCommerceSale($orderId);
            });
        });
    }

    private function applyWooCommerceSaleCycle(\WC_Order $order, string $cycleUuid): void {
        $orderId = (int) $order->get_id();
        if ($orderId < 1 || !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid)) {
            throw new \InvalidArgumentException('WooCommerce stock-cycle identity is invalid.');
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for stock-cycle sale.');
        $cycleItemsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
        $lines = [];
        $metadataCount = 0;
        foreach ($order->get_items('line_item') as $item) {
            $reducedStock = $item->get_meta('_reduced_stock', true);
            if ($reducedStock === '' || $reducedStock === null) {
                $reducedStock = $item->get_meta('_pkstore_wc_reduced_stock_snapshot', true);
            }
            if ($reducedStock === '' || $reducedStock === null) continue;
            if (!is_numeric($reducedStock)) {
                throw new \RuntimeException('WooCommerce stock-cycle reduction quantity is invalid.');
            }
            if ((float) $reducedStock === 0.0) continue;
            if ((float) $reducedStock < 0
                || floor((float) $reducedStock) !== (float) $reducedStock) {
                throw new \RuntimeException('WooCommerce stock-cycle reduction quantity is invalid.');
            }
            $orderedQuantity = $item->get_quantity();
            if (!is_numeric($orderedQuantity) || floor((float) $orderedQuantity) !== (float) $orderedQuantity
                || (int) $orderedQuantity !== (int) $reducedStock) {
                throw new \RuntimeException('WooCommerce order item quantity changed after its stock reduction.');
            }

            $itemId = (int) $item->get_id();
            $wcProductId = (int) $item->get_product_id();
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $capturedLine = $wpdb->get_row($wpdb->prepare(
                "SELECT inventory_product_id,wc_parent_product_id,wc_variation_id,reduced_quantity,refund_cumulative_baseline
                 FROM {$cycleItemsTable} WHERE cycle_uuid=%s AND wc_order_item_id=%d",
                $cycleUuid,
                $itemId
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($capturedLine)) {
                throw new \RuntimeException('WooCommerce order item has no immutable stock-cycle mapping snapshot.');
            }
            if ($itemId < 1 || (int) $capturedLine['wc_parent_product_id'] !== $wcProductId
                || (int) $capturedLine['wc_variation_id'] !== $variationId
                || (int) $capturedLine['reduced_quantity'] !== (int) $reducedStock) {
                throw new \RuntimeException('WooCommerce order item changed after its stock-cycle mapping was captured.');
            }
            $product = $this->productRepo->findById((int) $capturedLine['inventory_product_id']);
            if (!$product) {
                throw new \RuntimeException('Captured Junction inventory product is unavailable.');
            }

            $rawStates = $item->get_meta('_pkstore_inventory_cycle_states', true);
            $states = is_array($rawStates)
                ? $rawStates
                : (is_string($rawStates) ? json_decode($rawStates, true) : null);
            if ($rawStates !== '' && $rawStates !== null && !is_array($states)) {
                throw new \RuntimeException('WooCommerce order has malformed cycle allocation metadata.');
            }
            $states = is_array($states) ? $states : [];
            $cycleState = $states[$cycleUuid] ?? null;
            if ($cycleState !== null) {
                if (!is_array($cycleState)) {
                    throw new \RuntimeException('WooCommerce order has malformed cycle allocation state.');
                }
                $metadataCount++;
            }

            $lines[] = [
                'item' => $item,
                'item_id' => $itemId,
                'wc_parent_product_id' => $wcProductId,
                'wc_variation_id' => $variationId,
                'captured_refund_baseline' => (int) $capturedLine['refund_cumulative_baseline'],
                'product_id' => (int) $product->id,
                'quantity' => (int) $reducedStock,
                'states' => $states,
                'cycle_state' => $cycleState,
            ];
        }
        if (!$lines) throw new \RuntimeException('Captured WooCommerce stock cycle has no reduced order lines.');
        if ($metadataCount !== 0 && $metadataCount !== count($lines)) {
            throw new \RuntimeException('WooCommerce stock cycle has only a partial allocation snapshot.');
        }
        $hasSnapshot = $metadataCount === count($lines);

        $locations = $this->locationRepo->findAll();
        if (!$locations && !$hasSnapshot) {
            throw new \RuntimeException('No active Junction inventory locations are available for online order allocation.');
        }
        $onlineLocation = $this->locationRepo->findOnline();
        usort($locations, static function ($a, $b) use ($onlineLocation): int {
            $aOnline = $onlineLocation && $a->id === $onlineLocation->id ? 0 : 1;
            $bOnline = $onlineLocation && $b->id === $onlineLocation->id ? 0 : 1;
            return ($aOnline <=> $bOnline) ?: ($a->id <=> $b->id);
        });
        if ($hasSnapshot) {
            $knownLocationIds = array_fill_keys(array_map(static fn($location): int => (int) $location->id, $locations), true);
            foreach ($lines as $line) {
                foreach (($line['cycle_state']['allocations'] ?? []) as $allocation) {
                    $locationId = is_array($allocation) ? (int) ($allocation['location_id'] ?? 0) : 0;
                    if ($locationId < 1 || isset($knownLocationIds[$locationId])) continue;
                    $savedLocation = $this->locationRepo->findById($locationId);
                    if (!$savedLocation) {
                        throw new \RuntimeException('A saved WooCommerce cycle allocation location no longer exists.');
                    }
                    $locations[] = $savedLocation;
                    $knownLocationIds[$locationId] = true;
                }
            }
        }

        $products = array_values(array_unique(array_column($lines, 'product_id')));
        sort($products, SORT_NUMERIC);
        $productLocks = InventoryProductLock::acquireMany($products);
        if ($wpdb->query('START TRANSACTION') === false) {
            InventoryProductLock::release($productLocks);
            throw new \RuntimeException('Unable to start cycle-aware WooCommerce inventory transaction.');
        }

        $stockLevels = [];
        $allocationsByProductLocation = [];
        try {
            foreach ($products as $productId) {
                foreach ($locations as $location) {
                    $stockLevels[$productId][$location->id] = $this->stockRepo->getStock($productId, $location->id);
                }
            }

            $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
            $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
            $cycleHeader = $wpdb->get_row($wpdb->prepare(
                "SELECT state,cycle_number FROM {$cyclesTable} WHERE cycle_uuid=%s AND wc_order_id=%d FOR UPDATE",
                $cycleUuid,
                $orderId
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($cycleHeader)) {
                throw new \RuntimeException('WooCommerce stock-cycle header is unavailable.');
            }
            $reductionEvent = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$eventsTable} WHERE cycle_uuid=%s AND event_kind='reduction' FOR UPDATE",
                $cycleUuid
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($reductionEvent)) {
                throw new \RuntimeException('WooCommerce stock-cycle reduction event is unavailable.');
            }
            $earlierPendingCycle = $wpdb->get_var($wpdb->prepare(
                "SELECT c.cycle_uuid
                 FROM {$cyclesTable} c
                 INNER JOIN {$eventsTable} e ON e.cycle_uuid=c.cycle_uuid AND e.event_kind='reduction'
                 WHERE c.wc_order_id=%d AND c.cycle_number<%d AND e.status<>'processed'
                 LIMIT 1",
                $orderId,
                (int) $cycleHeader['cycle_number']
            ));
            if ($wpdb->last_error !== '' || $earlierPendingCycle) {
                throw new \RuntimeException('An earlier WooCommerce stock-cycle sale must be processed first.');
            }
            if (in_array($cycleHeader['state'], ['restoring', 'closed'], true)
                && $reductionEvent['status'] === 'processed') {
                // This queue generation is a duplicate for a sale cycle already
                // committed; the return queue owns its later restoration.
                if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to acknowledge completed WooCommerce stock cycle.');
                return;
            }
            if ($cycleHeader['state'] === 'closed') {
                throw new \RuntimeException('WooCommerce stock cycle is closed without a completed sale event.');
            }
            if (!in_array($cycleHeader['state'], ['reducing', 'active', 'restoring'], true)) {
                throw new \RuntimeException('WooCommerce stock cycle is in an unsupported state.');
            }

            if ($hasSnapshot) {
                foreach ($lines as &$line) {
                    $state = $line['cycle_state'];
                    $allocations = $state['allocations'] ?? null;
                    if ((int) ($state['reduced_quantity'] ?? 0) !== $line['quantity']
                        || !array_key_exists('refund_cumulative_baseline', $state)
                        || (int) ($state['refund_cumulative_baseline'] ?? -1) < 0
                        || !is_array($allocations) || !$allocations
                        || (int) ($state['returned_quantity'] ?? 0) < 0
                        || (int) ($state['refund_returned_quantity'] ?? 0) < 0
                        || (int) ($state['cancellation_returned_quantity'] ?? 0) < 0
                        || (int) ($state['returned_quantity'] ?? 0) !==
                            (int) ($state['refund_returned_quantity'] ?? 0) + (int) ($state['cancellation_returned_quantity'] ?? 0)) {
                        throw new \RuntimeException('Saved cycle allocation state does not reconcile to the reduction.');
                    }
                    $allocated = 0;
                    foreach ($allocations as $allocation) {
                        $productId = is_array($allocation) ? filter_var($allocation['product_id'] ?? null, FILTER_VALIDATE_INT) : false;
                        $locationId = is_array($allocation) ? filter_var($allocation['location_id'] ?? null, FILTER_VALIDATE_INT) : false;
                        $quantity = is_array($allocation) ? filter_var($allocation['quantity'] ?? null, FILTER_VALIDATE_INT) : false;
                        if ($productId !== $line['product_id'] || !$locationId || $locationId < 1 || !$quantity || $quantity < 1
                            || !isset($stockLevels[$productId][$locationId])) {
                            throw new \RuntimeException('Saved cycle allocation references an invalid product or location.');
                        }
                        $allocated += $quantity;
                        $allocationsByProductLocation[$productId][$locationId] =
                            ($allocationsByProductLocation[$productId][$locationId] ?? 0) + $quantity;
                    }
                    if ($allocated !== $line['quantity']) {
                        throw new \RuntimeException('Saved cycle location allocations do not match the captured reduction.');
                    }
                }
                unset($line);
            } else {
                $available = [];
                foreach ($stockLevels as $productId => $byLocation) {
                    foreach ($byLocation as $locationId => $stock) {
                        $available[$productId][$locationId] = $stock->getQuantity();
                    }
                }
                foreach ($lines as &$line) {
                    $remaining = $line['quantity'];
                    $allocations = [];
                    foreach ($locations as $location) {
                        $locationId = $location->id;
                        $onHand = $available[$line['product_id']][$locationId] ?? 0;
                        if ($onHand < 1) continue;
                        $take = min($remaining, $onHand);
                        if ($take < 1) continue;
                        $allocations[] = [
                            'product_id' => $line['product_id'],
                            'location_id' => $locationId,
                            'quantity' => $take,
                        ];
                        $available[$line['product_id']][$locationId] -= $take;
                        $allocationsByProductLocation[$line['product_id']][$locationId] =
                            ($allocationsByProductLocation[$line['product_id']][$locationId] ?? 0) + $take;
                        $remaining -= $take;
                        if ($remaining === 0) break;
                    }
                    if ($remaining > 0) {
                        throw new \DomainException('Combined Junction inventory is insufficient for the WooCommerce order.');
                    }
                    $line['cycle_state'] = [
                        'reduced_quantity' => $line['quantity'],
                        'refund_cumulative_baseline' => 0,
                        'allocations' => $allocations,
                        'returned_quantity' => 0,
                        'refund_returned_quantity' => 0,
                        'cancellation_returned_quantity' => 0,
                    ];
                }
                unset($line);
            }

            // Previous order-level references cannot be safely assigned to a new
            // stock cycle. Stop rather than double-deduct legacy ledger entries.
            foreach ($products as $productId) {
                foreach ($locations as $location) {
                    if ($this->stockRepo->hasMovementReference(
                        (int) $productId, $location->id, 'wc_order', (string) $orderId, MovementType::OnlineSale
                    ) || $this->stockRepo->hasMovementReference(
                        (int) $productId, $location->id, 'wc_order', $orderId . ':' . $location->id, MovementType::OnlineSale
                    )) {
                        throw new \RuntimeException('A legacy WooCommerce sale exists without a verifiable stock-cycle identity.');
                    }
                }
            }

            foreach ($allocationsByProductLocation as $productId => $byLocation) {
                foreach ($byLocation as $locationId => $quantity) {
                    $referenceId = $cycleUuid . ':' . $locationId;
                    $alreadyApplied = $this->stockRepo->hasMovementReference(
                        (int) $productId, (int) $locationId, 'wc_stock_cycle', $referenceId, MovementType::OnlineSale
                    );
                    if ($hasSnapshot && !$alreadyApplied) {
                        throw new \RuntimeException('Saved stock-cycle allocation has no matching ledger movement.');
                    }
                    if (!$hasSnapshot && $alreadyApplied) {
                        throw new \RuntimeException('Stock-cycle ledger movement exists without its saved allocation snapshot.');
                    }
                    if (!$hasSnapshot) {
                        $this->adjustStock->execute(
                            (int) $productId,
                            (int) $locationId,
                            -$quantity,
                            MovementType::OnlineSale,
                            "WooCommerce order #{$orderId} cycle {$cycleUuid}",
                            'wc_stock_cycle',
                            $referenceId,
                            false
                        );
                    }
                }
            }

            $now = current_time('mysql');
            foreach ($lines as $line) {
                $encoded = wp_json_encode($line['cycle_state']['allocations']);
                if (!is_string($encoded)) throw new \RuntimeException('Unable to encode WooCommerce stock-cycle allocation snapshot.');
                $firstLocationId = (int) $line['cycle_state']['allocations'][0]['location_id'];
                $cycleItemRow = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,inventory_product_id,wc_parent_product_id,wc_variation_id,reduced_quantity,refund_cumulative_baseline,cancellation_restored_quantity,refund_restored_quantity FROM {$cycleItemsTable} WHERE cycle_uuid=%s AND wc_order_item_id=%d FOR UPDATE",
                    $cycleUuid,
                    $line['item_id']
                ), ARRAY_A);
                if ($wpdb->last_error !== '' || !is_array($cycleItemRow)) {
                    throw new \RuntimeException('Captured WooCommerce stock-cycle line is unavailable.');
                }
                if ((int) $cycleItemRow['inventory_product_id'] !== $line['product_id']
                    || (int) $cycleItemRow['wc_parent_product_id'] !== $line['wc_parent_product_id']
                    || (int) $cycleItemRow['wc_variation_id'] !== $line['wc_variation_id']
                    || (int) $cycleItemRow['reduced_quantity'] !== $line['quantity']) {
                    throw new \RuntimeException('Captured stock-cycle product mapping or quantity conflicts with the current WooCommerce order line.');
                }
                if (!$hasSnapshot) {
                    if ((int) $cycleItemRow['cancellation_restored_quantity'] !== 0
                        || (int) $cycleItemRow['refund_restored_quantity'] !== 0
                        || (int) $line['captured_refund_baseline'] !== (int) $cycleItemRow['refund_cumulative_baseline']) {
                        throw new \RuntimeException('New WooCommerce stock cycle baseline or return counters do not match its captured snapshot.');
                    }
                    $line['cycle_state']['refund_cumulative_baseline'] = (int) $cycleItemRow['refund_cumulative_baseline'];
                } elseif ((int) $line['cycle_state']['refund_cumulative_baseline'] !== (int) $cycleItemRow['refund_cumulative_baseline']
                    || (int) ($line['cycle_state']['cancellation_returned_quantity'] ?? -1) !== (int) $cycleItemRow['cancellation_restored_quantity']
                    || (int) ($line['cycle_state']['refund_returned_quantity'] ?? -1) !== (int) $cycleItemRow['refund_restored_quantity']) {
                    throw new \RuntimeException('WooCommerce refund baseline or return counters do not match the captured cycle.');
                }
                $updated = $wpdb->update($cycleItemsTable, [
                    'inventory_location_id' => $firstLocationId,
                    'allocation_snapshot' => $encoded,
                    'updated_at' => $now,
                ], [
                    'cycle_uuid' => $cycleUuid,
                    'wc_order_item_id' => $line['item_id'],
                ]);
                if ($updated === false) throw new \RuntimeException('Unable to persist stock-cycle allocation snapshot.');

                if (!$hasSnapshot) {
                    $line['states'][$cycleUuid] = $line['cycle_state'];
                    $encodedStates = wp_json_encode($line['states']);
                    if (!is_string($encodedStates)) throw new \RuntimeException('Unable to encode order item cycle state.');
                    $line['item']->update_meta_data('_pkstore_inventory_cycle_states', $encodedStates);
                    if ((int) $line['item']->save() !== $line['item_id']) {
                        throw new \RuntimeException('Unable to persist order item cycle allocation state.');
                    }
                }
            }

            $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
            $cycle = $wpdb->get_row($wpdb->prepare(
                "SELECT state FROM {$cyclesTable} WHERE cycle_uuid=%s FOR UPDATE",
                $cycleUuid
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($cycle) || $cycle['state'] === 'closed') {
                throw new \RuntimeException('WooCommerce stock cycle is unavailable or already closed.');
            }
            if ($cycle['state'] === 'reducing') {
                $updated = $wpdb->update($cyclesTable, [
                    'state' => 'active',
                    'activated_at' => $now,
                    'updated_at' => $now,
                ], ['cycle_uuid' => $cycleUuid, 'state' => 'reducing']);
                if ($updated === false) throw new \RuntimeException('Unable to activate WooCommerce stock cycle.');
            } elseif (!in_array($cycle['state'], ['active', 'restoring'], true)) {
                // A cancellation/refund callback may arrive before the sale
                // worker. Keep the cycle in restoring state while the original
                // reduction is committed; the return worker waits for that sale.
                throw new \RuntimeException('WooCommerce stock cycle is in an unsupported state.');
            }

            $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
            $event = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$eventsTable} WHERE cycle_uuid=%s AND event_kind='reduction' FOR UPDATE",
                $cycleUuid
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($event)) {
                throw new \RuntimeException('WooCommerce stock-cycle reduction event is unavailable.');
            }
            if ($cycle['state'] === 'restoring' && $event['status'] === 'processed') {
                throw new \RuntimeException('Previous WooCommerce stock cycle is still restoring; this sale belongs to a later cycle.');
            }
            if ($event['status'] !== 'processed') {
                $updated = $wpdb->update($eventsTable, [
                    'status' => 'processed',
                    'processed_at' => $now,
                    'claimed_at' => null,
                    'claim_token' => null,
                    'last_error' => '',
                    'updated_at' => $now,
                ], ['id' => (int) $event['id']]);
                if ($updated === false) throw new \RuntimeException('Unable to acknowledge WooCommerce stock-cycle reduction event.');
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit cycle-aware WooCommerce inventory ledger.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('Cycle-aware WooCommerce inventory ledger was rolled back; event must be retried.', [
                'order_id' => $orderId,
                'cycle_uuid' => $cycleUuid,
                'error_class' => get_class($error),
            ]);
            throw $error;
        } finally {
            InventoryProductLock::release($productLocks);
        }

        foreach (array_keys($allocationsByProductLocation) as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce((int) $productId);
            } catch (\Throwable $error) {
                $this->log->error('WooCommerce cycle sale committed; aggregate stock sync remains queued.', [
                    'order_id' => $orderId,
                    'cycle_uuid' => $cycleUuid,
                    'product_id' => (int) $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
    }

    private function applyWooCommerceSale(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!($order instanceof \WC_Order)) {
            throw new \RuntimeException('WooCommerce order is unavailable for inventory ingestion.');
        }
        $lines = [];
        $metadataCount = 0;
        foreach ($order->get_items('line_item') as $item) {
            $reducedStock = $item->get_meta('_reduced_stock', true);
            if ($reducedStock === '' || $reducedStock === null) {
                $reducedStock = $item->get_meta('_pkstore_wc_reduced_stock_snapshot', true);
            }
            if ($reducedStock === '' || $reducedStock === null) continue;
            if (!is_numeric($reducedStock)) {
                throw new \RuntimeException('WooCommerce order contains a malformed actual stock-reduction quantity.');
            }
            $quantity = (float) $reducedStock;
            if ($quantity === 0.0) continue;
            if ($quantity < 0 || floor($quantity) !== $quantity) {
                throw new \RuntimeException('WooCommerce order contains an invalid actual stock-reduction quantity.');
            }

            $wcProductId = (int) $item->get_product_id();
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ($wcProductId < 1) {
                throw new \RuntimeException('WooCommerce order contains an invalid reduced product.');
            }
            $stockManagedProductId = $this->stockManagedWooCommerceProductId($item, $wcProductId, $variationId);
            $product = $this->productRepo->findByWcProductId($stockManagedProductId);
            if (!$product || !$product->isActive) {
                throw new \RuntimeException('WooCommerce order product is not linked to active Junction inventory.');
            }

            $rawAllocations = $item->get_meta('_pkstore_inventory_allocations', true);
            $hasRawAllocationMeta = $rawAllocations !== '' && $rawAllocations !== null;
            $allocations = is_array($rawAllocations)
                ? $rawAllocations
                : (is_string($rawAllocations) ? json_decode($rawAllocations, true) : null);
            if ($hasRawAllocationMeta && !is_array($allocations)) {
                throw new \RuntimeException('WooCommerce order has malformed Junction location-allocation metadata.');
            }
            if (is_array($allocations)) $metadataCount++;

            $lines[] = [
                'item' => $item,
                'item_id' => (int) $item->get_id(),
                'product_id' => (int) $product->id,
                'quantity' => (int) $quantity,
                'allocations' => $allocations,
            ];
        }
        if (!$lines) return;
        if ($metadataCount !== 0 && $metadataCount !== count($lines)) {
            throw new \RuntimeException('WooCommerce order has only a partial Junction location-allocation snapshot.');
        }
        $hasAllocationSnapshot = $metadataCount === count($lines);

        $locations = $this->locationRepo->findAll();
        if (!$locations) {
            throw new \RuntimeException('No active Junction inventory locations are available for online order allocation.');
        }
        $onlineLocation = $this->locationRepo->findOnline();
        usort($locations, static function ($a, $b) use ($onlineLocation): int {
            $aOnline = $onlineLocation && $a->id === $onlineLocation->id ? 0 : 1;
            $bOnline = $onlineLocation && $b->id === $onlineLocation->id ? 0 : 1;
            return ($aOnline <=> $bOnline) ?: ($a->id <=> $b->id);
        });

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for WooCommerce order inventory ledger.');
        }
        $products = array_values(array_unique(array_column($lines, 'product_id')));
        sort($products, SORT_NUMERIC);
        $productLocks = InventoryProductLock::acquireMany($products);
        if ($wpdb->query('START TRANSACTION') === false) {
            InventoryProductLock::release($productLocks);
            $this->log->critical('WooCommerce order inventory ledger could not start its transaction.', [
                'order_id' => $orderId,
            ]);
            throw new \RuntimeException('Unable to start WooCommerce order inventory ledger transaction.');
        }

        $stockLevels = [];
        $allocationsByProductLocation = [];
        try {
            // Lock every candidate location in deterministic product/location
            // order. POS and online sales therefore serialize on the same rows.
            foreach ($products as $productId) {
                foreach ($locations as $location) {
                    $stockLevels[$productId][$location->id] = $this->stockRepo->getStock($productId, $location->id);
                }
            }

            if ($hasAllocationSnapshot) {
                foreach ($lines as &$line) {
                    $allocations = $line['allocations'];
                    if (!$allocations) {
                        throw new \RuntimeException('WooCommerce order has an empty Junction allocation snapshot.');
                    }
                    $allocated = 0;
                    foreach ($allocations as $allocation) {
                        $allocationProductId = is_array($allocation) ? filter_var($allocation['product_id'] ?? null, FILTER_VALIDATE_INT) : false;
                        $allocationLocationId = is_array($allocation) ? filter_var($allocation['location_id'] ?? null, FILTER_VALIDATE_INT) : false;
                        $allocationQuantity = is_array($allocation) ? filter_var($allocation['quantity'] ?? null, FILTER_VALIDATE_INT) : false;
                        if (!is_array($allocation)
                            || $allocationProductId !== $line['product_id']
                            || !$allocationLocationId || $allocationLocationId < 1
                            || !$allocationQuantity || $allocationQuantity < 1) {
                            throw new \RuntimeException('WooCommerce order has an invalid Junction allocation snapshot.');
                        }
                        $locationId = (int) $allocationLocationId;
                        if (!isset($stockLevels[$line['product_id']][$locationId])) {
                            throw new \RuntimeException('A saved Junction allocation references an inactive or unknown location.');
                        }
                        $quantity = (int) $allocationQuantity;
                        $allocated += $quantity;
                        $allocationsByProductLocation[$line['product_id']][$locationId] =
                            ($allocationsByProductLocation[$line['product_id']][$locationId] ?? 0) + $quantity;
                    }
                    if ($allocated !== $line['quantity']) {
                        throw new \RuntimeException('Saved Junction location allocations do not match the actual WooCommerce reduction.');
                    }
                }
                unset($line);
            } else {
                $available = [];
                foreach ($stockLevels as $productId => $byLocation) {
                    foreach ($byLocation as $locationId => $stock) {
                        $available[$productId][$locationId] = $stock->getQuantity();
                    }
                }

                // Allocate each actual reduced line from the online location
                // first, then other active locations in stable ID order. This
                // preserves the shared multi-location pool without negative stock.
                foreach ($lines as &$line) {
                    $remaining = $line['quantity'];
                    $allocations = [];
                    foreach ($locations as $location) {
                        $locationId = $location->id;
                        $onHand = $available[$line['product_id']][$locationId] ?? 0;
                        if ($onHand < 1) continue;
                        $take = min($remaining, $onHand);
                        if ($take < 1) continue;
                        $allocations[] = [
                            'product_id' => $line['product_id'],
                            'location_id' => $locationId,
                            'quantity' => $take,
                        ];
                        $available[$line['product_id']][$locationId] -= $take;
                        $allocationsByProductLocation[$line['product_id']][$locationId] =
                            ($allocationsByProductLocation[$line['product_id']][$locationId] ?? 0) + $take;
                        $remaining -= $take;
                        if ($remaining === 0) break;
                    }
                    if ($remaining > 0) {
                        throw new \DomainException('Combined Junction inventory is insufficient for the WooCommerce order.');
                    }
                    $line['allocations'] = $allocations;
                }
                unset($line);
            }

            // Detect legacy order-level deductions in every active location
            // before writing new location-scoped references. Never recompute an
            // allocation over a sale committed by the previous order-level model.
            foreach ($products as $productId) {
                foreach ($locations as $location) {
                    if ($this->stockRepo->hasMovementReference(
                        (int) $productId,
                        $location->id,
                        'wc_order',
                        (string) $orderId,
                        MovementType::OnlineSale
                    )) {
                        throw new \RuntimeException('A legacy order-level stock deduction exists without a verifiable location allocation.');
                    }
                }
            }

            foreach ($allocationsByProductLocation as $productId => $byLocation) {
                foreach ($byLocation as $locationId => $quantity) {
                    $referenceId = $orderId . ':' . $locationId;
                    $alreadyApplied = $this->stockRepo->hasMovementReference(
                        (int) $productId, (int) $locationId, 'wc_order', $referenceId, MovementType::OnlineSale
                    );
                    if ($hasAllocationSnapshot && !$alreadyApplied) {
                        throw new \RuntimeException('Saved Junction allocations exist without their matching stock ledger movements.');
                    }
                    if (!$hasAllocationSnapshot && $alreadyApplied) {
                        throw new \RuntimeException('A stock ledger movement exists without its Junction location allocation snapshot.');
                    }
                }
            }

            if (!$hasAllocationSnapshot) {
                foreach ($allocationsByProductLocation as $productId => $byLocation) {
                    foreach ($byLocation as $locationId => $quantity) {
                        $this->adjustStock->execute(
                            (int) $productId,
                            (int) $locationId,
                            -$quantity,
                            MovementType::OnlineSale,
                            "WooCommerce order #{$orderId}",
                            'wc_order',
                            $orderId . ':' . $locationId,
                            false
                        );
                    }
                }

                foreach ($lines as $line) {
                    $line['item']->update_meta_data('_pkstore_inventory_allocations', wp_json_encode($line['allocations']));
                    $line['item']->update_meta_data('_pkstore_inventory_reduced_quantity', $line['quantity']);
                    $line['item']->update_meta_data('_pkstore_inventory_returned_quantity', 0);
                    $line['item']->update_meta_data('_pkstore_inventory_refund_returned_quantity', 0);
                    $line['item']->update_meta_data('_pkstore_inventory_cancellation_returned_quantity', 0);
                    if (!$line['item']->save()) {
                        throw new \RuntimeException('Unable to persist WooCommerce order location allocation metadata.');
                    }
                }
                $order->update_meta_data('_pkstore_inventory_allocation_version', '1');
                if ((int) $order->save() !== $orderId) {
                    throw new \RuntimeException('Unable to persist WooCommerce order allocation version.');
                }
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit WooCommerce order inventory ledger.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce order inventory ledger was rolled back; event must be retried.', [
                'order_id' => $orderId,
                'error_class' => get_class($error),
            ]);
            throw $error;
        } finally {
            InventoryProductLock::release($productLocks);
        }

        foreach (array_keys($allocationsByProductLocation) as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce((int) $productId);
            } catch (\Throwable $error) {
                $this->log->error('WooCommerce order committed; aggregate stock sync remains queued.', [
                    'order_id' => $orderId,
                    'product_id' => (int) $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
    }

    /**
     * Mirror a cancellation restock only after WooCommerce has actually restored
     * the individual order item. The callback's stock delta is authoritative.
     */
    public function onWooCommerceOrderItemRestored($item, int $newStock, int $oldStock, $order, ?string $cycleUuid = null): bool {
        if (!($order instanceof \WC_Order)) throw new \InvalidArgumentException('WooCommerce restore event is malformed.');
        $orderId = (int) $order->get_id();
        return $this->withOrderProductLocks($order, function () use ($orderId, $item, $newStock, $oldStock, $order, $cycleUuid): bool {
            return $this->withOrderLock($orderId, function () use ($item, $newStock, $oldStock, $order, $cycleUuid): bool {
                return $this->applyWooCommerceOrderItemRestored($item, $newStock, $oldStock, $order, $cycleUuid);
            });
        });
    }

    private function applyWooCommerceOrderItemRestored($item, int $newStock, int $oldStock, $order, ?string $cycleUuid = null): bool {
        if ($cycleUuid === null && $order instanceof \WC_Order
            && (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true) !== '') {
            throw new \RuntimeException('Cycle-aware WooCommerce cancellation return cannot use legacy order-level accounting without its cycle UUID.');
        }
        if (!($order instanceof \WC_Order) || !is_object($item)
            || !method_exists($item, 'get_id') || !method_exists($item, 'get_product_id')) {
            throw new \InvalidArgumentException('WooCommerce restore event is malformed.');
        }
        $orderId = (int) $order->get_id();
        $itemId = (int) $item->get_id();
        $quantity = $newStock - $oldStock;
        if ($orderId < 1 || $itemId < 1 || $quantity < 1) {
            throw new \InvalidArgumentException('WooCommerce restore quantity is invalid.');
        }

        $isPosOrder = $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos';
        if (!$isPosOrder) {
            $rawAllocations = $item->get_meta('_pkstore_inventory_allocations', true);
            $rawCycleStates = $item->get_meta('_pkstore_inventory_cycle_states', true);
            if ($cycleUuid !== null || ($rawAllocations !== '' && $rawAllocations !== null)
                || ($rawCycleStates !== '' && $rawCycleStates !== null)) {
                return $this->applyAllocatedOnlineReturn($order, $item, 'cancellation', $quantity, null, $cycleUuid);
            }
        }
        $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
        $wcProductId = (int) $item->get_product_id();
        $stockManagedProductId = $this->stockManagedWooCommerceProductId($item, $wcProductId, $variationId);
        if ($isPosOrder) {
            $product = $this->productRepo->findById((int) $item->get_meta('_pkstore_inventory_product_id', true));
            $locationId = (int) $item->get_meta('_pkstore_inventory_location_id', true);
            $posOrderRef = (string) $item->get_meta('_pkstore_pos_order_ref', true);
            if (!$product || !$locationId || $posOrderRef === ''
                || (int) $product->wcProductId !== $stockManagedProductId) {
                throw new \RuntimeException('POS cancellation item is missing its original Junction inventory mapping.');
            }
            $location = $this->locationRepo->findById($locationId);
            $saleSource = 'pos_order';
            $saleReference = $posOrderRef;
            $saleMovementType = MovementType::Sale;
        } else {
            $product = $this->productRepo->findByWcProductId($stockManagedProductId);
            $location = $this->locationRepo->findOnline();
            $saleSource = 'wc_order';
            $saleReference = (string) $orderId;
            $saleMovementType = MovementType::OnlineSale;
        }
        if (!$product) {
            throw new \RuntimeException('Restored WooCommerce item is not linked to Junction inventory.');
        }
        if (!$location || !$location->isActive) {
            throw new \RuntimeException('The original Junction inventory location is unavailable.');
        }

        $referenceId = $orderId . ':' . $itemId;
        global $wpdb;
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start WooCommerce return ledger transaction.');
        }
        try {
            $this->stockRepo->getStock($product->id, $location->id);
            if (!$this->stockRepo->hasMovementReference($product->id, $location->id, $saleSource, $saleReference, $saleMovementType)) {
                if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to commit deferred cancellation restock.');
                return false;
            }
            if (!$this->stockRepo->hasMovementReference(
                $product->id,
                $location->id,
                'wc_order_restore',
                $referenceId,
                MovementType::OnlineReturn
            )) {
                $this->adjustStock->execute(
                    $product->id,
                    $location->id,
                    $quantity,
                    MovementType::OnlineReturn,
                    "WooCommerce cancellation restock for order #{$orderId}, item #{$itemId}",
                    'wc_order_restore',
                    $referenceId,
                    false
                );
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit WooCommerce return ledger transaction.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce cancellation restock was not applied to Junction inventory.', [
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'error_class' => get_class($error),
            ]);
            throw $error;
        }

        try {
            $this->adjustStock->syncProductToWooCommerce($product->id);
        } catch (\Throwable $error) {
            $this->log->error('WooCommerce return committed; aggregate stock sync remains queued.', [
                'order_id' => $orderId,
                'product_id' => $product->id,
                'error_class' => get_class($error),
            ]);
        }
        return true;
    }

    /**
     * Mirror a refund only when WooCommerce's explicit restock action fires.
     * The original order item carries cumulative _restock_refunded_items metadata.
     */
    public function onWooCommerceRefundItemRestocked(int $wcProductId, int $newStock, int $oldStock, $order, $product, ?int $orderItemId = null, ?int $cumulativeRestocked = null, ?string $cycleUuid = null): bool {
        if (!($order instanceof \WC_Order)) throw new \InvalidArgumentException('WooCommerce refund restock event is malformed.');
        $orderId = (int) $order->get_id();
        return $this->withOrderProductLocks($order, function () use ($orderId, $wcProductId, $newStock, $oldStock, $order, $product, $orderItemId, $cumulativeRestocked, $cycleUuid): bool {
            return $this->withOrderLock($orderId, function () use ($wcProductId, $newStock, $oldStock, $order, $product, $orderItemId, $cumulativeRestocked, $cycleUuid): bool {
                return $this->applyWooCommerceRefundItemRestocked($wcProductId, $newStock, $oldStock, $order, $product, $orderItemId, $cumulativeRestocked, $cycleUuid);
            });
        });
    }

    private function applyWooCommerceRefundItemRestocked(int $wcProductId, int $newStock, int $oldStock, $order, $product, ?int $orderItemId = null, ?int $cumulativeRestocked = null, ?string $cycleUuid = null): bool {
        if ($cycleUuid === null && $order instanceof \WC_Order
            && (string) $order->get_meta('_pkstore_wc_reduction_snapshot_version', true) !== '') {
            throw new \RuntimeException('Cycle-aware WooCommerce refund return cannot use legacy order-level accounting without its cycle UUID.');
        }
        if (!($order instanceof \WC_Order) || $wcProductId < 1 || ($newStock - $oldStock) < 1) {
            throw new \InvalidArgumentException('WooCommerce refund restock event is malformed.');
        }
        $orderId = (int) $order->get_id();
        $candidates = [];
        foreach ($order->get_items('line_item') as $item) {
            $parentId = (int) $item->get_product_id();
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ($wcProductId !== $parentId && $wcProductId !== $variationId) {
                continue;
            }
            if ($orderItemId !== null && (int) $item->get_id() !== $orderItemId) {
                continue;
            }
            $cumulative = (int) $item->get_meta('_restock_refunded_items', true);
            $stockManagedProductId = $this->stockManagedWooCommerceProductId($item, $parentId, $variationId);
            // Keep every product-matching line in the ambiguity check, even
            // when its cumulative restock metadata is zero or smaller. Filtering
            // first could incorrectly select one line while ignoring another.
            $candidates[] = [
                'item' => $item,
                'cumulative' => $cumulative,
                'variation_id' => $variationId,
                'parent_id' => $parentId,
                'stock_managed_product_id' => $stockManagedProductId,
            ];
        }
        $eventCumulative = $cumulativeRestocked ?? ($candidates[0]['cumulative'] ?? 0);
        if (count($candidates) !== 1 || $eventCumulative < ($newStock - $oldStock)
            || $eventCumulative > (int) $candidates[0]['item']->get_quantity()) {
            // The WooCommerce hook omits the original order-item ID. Do not guess
            // when the same product appears on multiple order lines, including
            // lines whose cumulative metadata is zero or below this callback delta.
            throw new \RuntimeException('Refund restock cannot be mapped unambiguously to one WooCommerce order item.');
        }
        $candidate = $candidates[0];
        $item = $candidate['item'];
        $isPosOrder = $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos';
        if (!$isPosOrder) {
            $rawAllocations = $item->get_meta('_pkstore_inventory_allocations', true);
            $rawCycleStates = $item->get_meta('_pkstore_inventory_cycle_states', true);
            if ($cycleUuid !== null || ($rawAllocations !== '' && $rawAllocations !== null)
                || ($rawCycleStates !== '' && $rawCycleStates !== null)) {
                return $this->applyAllocatedOnlineReturn(
                    $order,
                    $item,
                    'refund',
                    $newStock - $oldStock,
                    $eventCumulative,
                    $cycleUuid
                );
            }
        }
        if ($isPosOrder) {
            $productRecord = $this->productRepo->findById((int) $item->get_meta('_pkstore_inventory_product_id', true));
            $returnLocation = $this->locationRepo->findById((int) $item->get_meta('_pkstore_inventory_location_id', true));
            $saleReference = (string) $item->get_meta('_pkstore_pos_order_ref', true);
            $saleSource = 'pos_order';
            $saleMovementType = MovementType::Sale;
            if (!$productRecord || !$returnLocation || $saleReference === ''
                || (int) $productRecord->wcProductId !== (int) $candidate['stock_managed_product_id']) {
                throw new \RuntimeException('POS refund item is missing its original Junction inventory mapping.');
            }
        } else {
            $productRecord = $this->productRepo->findByWcProductId((int) $candidate['stock_managed_product_id']);
            $returnLocation = $this->locationRepo->findOnline();
            $saleReference = (string) $orderId;
            $saleSource = 'wc_order';
            $saleMovementType = MovementType::OnlineSale;
            if (!$productRecord || !$returnLocation) {
                throw new \RuntimeException('Refund-restocked WooCommerce item is not linked to Junction inventory.');
            }
        }
        if (!$returnLocation->isActive) {
            throw new \RuntimeException('The original Junction inventory location is unavailable.');
        }

        $itemId = (int) $item->get_id();
        $referenceId = $orderId . ':' . $itemId . ':' . $eventCumulative;
        global $wpdb;
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start WooCommerce refund return transaction.');
        }
        try {
            $this->stockRepo->getStock($productRecord->id, $returnLocation->id);
            if (!$this->stockRepo->hasMovementReference($productRecord->id, $returnLocation->id, $saleSource, $saleReference, $saleMovementType)) {
                if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to commit deferred refund restock.');
                return false;
            }
            if (!$this->stockRepo->hasMovementReference(
                $productRecord->id,
                $returnLocation->id,
                'wc_refund_restock',
                $referenceId,
                MovementType::OnlineReturn
            )) {
                $this->adjustStock->execute(
                    $productRecord->id,
                    $returnLocation->id,
                    $newStock - $oldStock,
                    MovementType::OnlineReturn,
                    "WooCommerce refund restock for order #{$orderId}, item #{$itemId}",
                    'wc_refund_restock',
                    $referenceId,
                    false
                );
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit WooCommerce refund return transaction.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce refund restock was not applied to Junction inventory.', [
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'error_class' => get_class($error),
            ]);
            throw $error;
        }

        try {
            $this->adjustStock->syncProductToWooCommerce($productRecord->id);
        } catch (\Throwable $error) {
            $this->log->error('WooCommerce refund return committed; aggregate stock sync remains queued.', [
                'order_id' => $orderId,
                'product_id' => $productRecord->id,
                'error_class' => get_class($error),
            ]);
        }
        return true;
    }

    private function applyAllocatedCycleReturn(
        \WC_Order $order,
        $item,
        string $kind,
        int $quantity,
        ?int $refundCumulative,
        string $cycleUuid
    ): bool {
        if (!in_array($kind, ['cancellation', 'refund'], true)
            || !is_object($item) || !method_exists($item, 'get_id')
            || $quantity < 1 || !preg_match('/^[a-f0-9-]{36}$/i', $cycleUuid)) {
            throw new \InvalidArgumentException('WooCommerce cycle return event is malformed.');
        }

        $orderId = (int) $order->get_id();
        $itemId = (int) $item->get_id();
        $rawStates = $item->get_meta('_pkstore_inventory_cycle_states', true);
        $states = is_array($rawStates)
            ? $rawStates
            : (is_string($rawStates) ? json_decode($rawStates, true) : null);
        $state = is_array($states) ? ($states[$cycleUuid] ?? null) : null;
        if (!is_array($state)) {
            throw new \RuntimeException('WooCommerce stock-cycle allocation snapshot is not ready for return processing.');
        }

        $reducedQuantity = (int) ($state['reduced_quantity'] ?? 0);
        $returnedQuantity = (int) ($state['returned_quantity'] ?? 0);
        $refundReturned = (int) ($state['refund_returned_quantity'] ?? 0);
        $cancellationReturned = (int) ($state['cancellation_returned_quantity'] ?? 0);
        $refundBaseline = (int) ($state['refund_cumulative_baseline'] ?? -1);
        $allocations = $state['allocations'] ?? null;
        if ($orderId < 1 || $itemId < 1 || $reducedQuantity < 1
            || $returnedQuantity < 0 || $refundReturned < 0 || $cancellationReturned < 0
            || $refundBaseline < 0 || !is_array($allocations) || !$allocations
            || $returnedQuantity !== $refundReturned + $cancellationReturned
            || $returnedQuantity > $reducedQuantity) {
            throw new \RuntimeException('WooCommerce stock-cycle return state is invalid.');
        }

        if ($kind === 'cancellation') {
            if ($cancellationReturned > 0) return true;
            $remaining = $reducedQuantity - $returnedQuantity;
            if ($quantity !== $remaining) {
                throw new \RuntimeException('Cancellation restock quantity does not match the remaining stock cycle.');
            }
            $targetReturned = $reducedQuantity;
            $nextCancellationReturned = $cancellationReturned + $quantity;
            $nextRefundReturned = $refundReturned;
            $eventKind = 'cancellation';
            $sourceHook = 'woocommerce_restore_order_item_stock';
            $note = "WooCommerce cancellation restock for order #{$orderId}, cycle {$cycleUuid}";
        } else {
            if ($refundCumulative === null || $refundCumulative < 1) {
                throw new \InvalidArgumentException('WooCommerce refund cumulative quantity is invalid.');
            }
            $cycleRefundCumulative = $refundCumulative - $refundBaseline;
            if ($cycleRefundCumulative <= $refundReturned) return true;
            if ($cycleRefundCumulative < 1
                || $cycleRefundCumulative !== $refundReturned + $quantity
                || $returnedQuantity + $quantity > $reducedQuantity) {
                throw new \RuntimeException('WooCommerce refund restock sequence does not match this stock cycle.');
            }
            $targetReturned = $returnedQuantity + $quantity;
            $nextCancellationReturned = $cancellationReturned;
            $nextRefundReturned = $cycleRefundCumulative;
            $eventKind = 'refund';
            $sourceHook = 'woocommerce_restock_refunded_item';
            $note = "WooCommerce refund restock for order #{$orderId}, cycle {$cycleUuid}";
        }

        $normalized = [];
        $allocatedQuantity = 0;
        $seenLocations = [];
        foreach ($allocations as $allocation) {
            $productId = is_array($allocation) ? filter_var($allocation['product_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $locationId = is_array($allocation) ? filter_var($allocation['location_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $allocationQuantity = is_array($allocation) ? filter_var($allocation['quantity'] ?? null, FILTER_VALIDATE_INT) : false;
            if (!$productId || $productId < 1 || !$locationId || $locationId < 1
                || !$allocationQuantity || $allocationQuantity < 1
                || isset($seenLocations[$productId . ':' . $locationId])) {
                throw new \RuntimeException('WooCommerce stock-cycle allocation line is malformed or duplicated.');
            }
            $seenLocations[$productId . ':' . $locationId] = true;
            $location = $this->locationRepo->findById($locationId);
            $product = $this->productRepo->findById($productId);
            if (!$location || !$product) {
                throw new \RuntimeException('An original Junction stock-cycle product or location is unavailable.');
            }
            $normalized[] = [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $allocationQuantity,
            ];
            $allocatedQuantity += $allocationQuantity;
        }
        if ($allocatedQuantity !== $reducedQuantity) {
            throw new \RuntimeException('WooCommerce stock-cycle allocation does not reconcile to the original reduction.');
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for WooCommerce stock-cycle return.');
        }
        $lockProductIds = array_values(array_unique(array_column($normalized, 'product_id')));
        sort($lockProductIds, SORT_NUMERIC);
        $productLocks = InventoryProductLock::acquireMany($lockProductIds);
        if ($wpdb->query('START TRANSACTION') === false) {
            InventoryProductLock::release($productLocks);
            throw new \RuntimeException('Unable to start WooCommerce stock-cycle return transaction.');
        }
        $productIds = [];
        try {
            $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
            $itemsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
            $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
            $cycle = $wpdb->get_row($wpdb->prepare(
                "SELECT state FROM {$cyclesTable} WHERE cycle_uuid=%s AND wc_order_id=%d FOR UPDATE",
                $cycleUuid,
                $orderId
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($cycle)) {
                throw new \RuntimeException('WooCommerce stock-cycle header is unavailable.');
            }
            if ($cycle['state'] === 'reducing') {
                if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to defer early cycle return.');
                return false;
            }
            if (!in_array($cycle['state'], ['active', 'restoring'], true)) {
                throw new \RuntimeException('WooCommerce stock cycle is not active for return processing.');
            }

            $cycleItem = $wpdb->get_row($wpdb->prepare(
                "SELECT inventory_product_id,wc_parent_product_id,wc_variation_id,allocation_snapshot,reduced_quantity,refund_cumulative_baseline,
                        cancellation_restored_quantity,refund_restored_quantity
                 FROM {$itemsTable} WHERE cycle_uuid=%s AND wc_order_item_id=%d FOR UPDATE",
                $cycleUuid,
                $itemId
            ), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($cycleItem)) {
                throw new \RuntimeException('WooCommerce stock-cycle item is unavailable.');
            }
            $storedAllocations = json_decode((string) $cycleItem['allocation_snapshot'], true);
            foreach ($normalized as $allocation) {
                if ((int) $allocation['product_id'] !== (int) $cycleItem['inventory_product_id']) {
                    throw new \RuntimeException('Saved return allocation does not match the captured Junction product.');
                }
            }
            $currentParentId = (int) $item->get_product_id();
            $currentVariationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ((int) $cycleItem['wc_parent_product_id'] !== $currentParentId
                || (int) $cycleItem['wc_variation_id'] !== $currentVariationId
                || (int) $cycleItem['reduced_quantity'] !== $reducedQuantity
                || (int) $cycleItem['refund_cumulative_baseline'] !== $refundBaseline
                || !is_array($storedAllocations) || $storedAllocations !== $normalized
                || (int) $cycleItem['cancellation_restored_quantity'] !== $cancellationReturned
                || (int) $cycleItem['refund_restored_quantity'] !== $refundReturned) {
                throw new \RuntimeException('WooCommerce stock-cycle allocation or return ledger is inconsistent.');
            }

            // Verify the complete original sale allocation before writing any
            // return movement. A missing sale row must defer the whole return,
            // not commit earlier allocation chunks and leave a partial restoration.
            foreach ($normalized as $allocation) {
                $this->stockRepo->getStock($allocation['product_id'], $allocation['location_id']);
                $saleReference = $cycleUuid . ':' . $allocation['location_id'];
                if (!$this->stockRepo->hasMovementReference(
                    $allocation['product_id'],
                    $allocation['location_id'],
                    'wc_stock_cycle',
                    $saleReference,
                    MovementType::OnlineSale
                )) {
                    if ($wpdb->query('COMMIT') === false) {
                        throw new \RuntimeException('Unable to defer cycle return pending its sale movement.');
                    }
                    return false;
                }
            }

            $cursor = 0;
            foreach ($normalized as $allocation) {
                $start = $cursor;
                $end = $cursor + $allocation['quantity'];
                $cursor = $end;
                $chunk = max(0, min($targetReturned, $end) - max($returnedQuantity, $start));
                if ($chunk < 1) continue;

                $productId = $allocation['product_id'];
                $locationId = $allocation['location_id'];
                $this->stockRepo->getStock($productId, $locationId);
                // Stock movement reference_id is VARCHAR(64); hash the full
                // cycle/item/cumulative/location identity to a fixed 64 chars.
                $returnReference = hash('sha256', 'wc_stock_cycle_return:' . $cycleUuid . ':' . $itemId . ':' . $targetReturned . ':' . $locationId);
                if (!$this->stockRepo->hasMovementReference(
                    $productId, $locationId, 'wc_stock_cycle_return', $returnReference, MovementType::OnlineReturn
                )) {
                    $this->adjustStock->execute(
                        $productId,
                        $locationId,
                        $chunk,
                        MovementType::OnlineReturn,
                        $note,
                        'wc_stock_cycle_return',
                        $returnReference,
                        false
                    );
                }
                $productIds[$productId] = $productId;
            }

            $states[$cycleUuid]['returned_quantity'] = $targetReturned;
            $states[$cycleUuid]['refund_returned_quantity'] = $nextRefundReturned;
            $states[$cycleUuid]['cancellation_returned_quantity'] = $nextCancellationReturned;
            $encodedStates = wp_json_encode($states);
            if (!is_string($encodedStates)) throw new \RuntimeException('Unable to encode WooCommerce cycle return state.');
            $item->update_meta_data('_pkstore_inventory_cycle_states', $encodedStates);
            if ((int) $item->save() !== $itemId) {
                throw new \RuntimeException('Unable to persist WooCommerce cycle return state.');
            }

            $updated = $wpdb->update($itemsTable, [
                'cancellation_restored_quantity' => $nextCancellationReturned,
                'refund_restored_quantity' => $nextRefundReturned,
                'updated_at' => current_time('mysql'),
            ], [
                'cycle_uuid' => $cycleUuid,
                'wc_order_item_id' => $itemId,
            ]);
            if ($updated === false) throw new \RuntimeException('Unable to persist WooCommerce cycle return counters.');

            $openItems = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$itemsTable}
                 WHERE cycle_uuid=%s AND reduced_quantity > cancellation_restored_quantity + refund_restored_quantity",
                $cycleUuid
            ));
            if ($wpdb->last_error !== '' || !is_numeric($openItems)) {
                throw new \RuntimeException('Unable to verify WooCommerce stock-cycle completion.');
            }

            $now = current_time('mysql');
            if ((int) $openItems === 0) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$cyclesTable} SET state='closed', closed_at=%s, updated_at=%s
                     WHERE cycle_uuid=%s AND state IN ('active','restoring')",
                    $now,
                    $now,
                    $cycleUuid
                ));
                if ($updated === false) throw new \RuntimeException('Unable to close WooCommerce stock cycle.');
            }

            $eventCumulative = $eventKind === 'refund' ? $nextRefundReturned : $targetReturned;
            $eventKey = 'wc_stock_cycle_' . $eventKind . ':' . $cycleUuid . ':' . $itemId . ':' . $eventCumulative;
            $existingEvent = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$eventsTable} WHERE event_key=%s FOR UPDATE",
                $eventKey
            ), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Unable to inspect WooCommerce stock-cycle return event.');
            }
            if (!is_array($existingEvent)) {
                throw new \RuntimeException('WooCommerce stock-cycle return event was not captured at hook time.');
            }
            if ($existingEvent['status'] === 'processed') {
                throw new \RuntimeException('WooCommerce stock-cycle return event was already acknowledged before its ledger counters.');
            }
            if (!in_array($existingEvent['status'], ['pending', 'processing'], true)) {
                throw new \RuntimeException('WooCommerce stock-cycle return event is not eligible for processing.');
            }
            $updated = $wpdb->update($eventsTable, [
                'status' => 'processed',
                'processed_at' => $now,
                'claimed_at' => null,
                'claim_token' => null,
                'last_error' => '',
                'updated_at' => $now,
            ], ['id' => (int) $existingEvent['id']]);
            if ($updated === false) throw new \RuntimeException('Unable to acknowledge WooCommerce stock-cycle return event.');

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit WooCommerce stock-cycle return transaction.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce stock-cycle return was rolled back; event must be retried.', [
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'cycle_uuid' => $cycleUuid,
                'error_class' => get_class($error),
            ]);
            throw $error;
        } finally {
            InventoryProductLock::release($productLocks);
        }

        foreach ($productIds as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce($productId);
            } catch (\Throwable $error) {
                $this->log->error('WooCommerce stock-cycle return committed; aggregate stock sync remains queued.', [
                    'order_id' => $orderId,
                    'cycle_uuid' => $cycleUuid,
                    'product_id' => $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
        return true;
    }

    /**
     * Restore an online order's stock to the exact locations used by its sale.
     * Cumulative return counters live on the order item and are committed in
     * the same transaction as the return movements, making queue replay safe.
     */
    private function applyAllocatedOnlineReturn(
        \WC_Order $order,
        $item,
        string $kind,
        int $quantity,
        ?int $refundCumulative,
        ?string $cycleUuid = null
    ): bool {
        if ($cycleUuid !== null) {
            return $this->applyAllocatedCycleReturn($order, $item, $kind, $quantity, $refundCumulative, $cycleUuid);
        }
        if (!in_array($kind, ['cancellation', 'refund'], true)
            || !is_object($item) || !method_exists($item, 'get_id') || $quantity < 1) {
            throw new \InvalidArgumentException('WooCommerce allocated return event is malformed.');
        }

        $orderId = (int) $order->get_id();
        $itemId = (int) $item->get_id();
        $rawAllocations = $item->get_meta('_pkstore_inventory_allocations', true);
        $allocations = is_array($rawAllocations)
            ? $rawAllocations
            : (is_string($rawAllocations) ? json_decode($rawAllocations, true) : null);
        $reducedQuantity = (int) $item->get_meta('_pkstore_inventory_reduced_quantity', true);
        $returnedQuantity = (int) $item->get_meta('_pkstore_inventory_returned_quantity', true);
        $refundReturned = (int) $item->get_meta('_pkstore_inventory_refund_returned_quantity', true);
        $cancellationReturned = (int) $item->get_meta('_pkstore_inventory_cancellation_returned_quantity', true);

        if ($orderId < 1 || $itemId < 1 || !is_array($allocations) || !$allocations
            || $reducedQuantity < 1 || $returnedQuantity < 0
            || $refundReturned < 0 || $cancellationReturned < 0
            || $returnedQuantity !== $refundReturned + $cancellationReturned) {
            throw new \RuntimeException('WooCommerce order has an invalid saved stock-allocation return snapshot.');
        }

        $allocatedQuantity = 0;
        $normalized = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)
                || (int) ($allocation['product_id'] ?? 0) < 1
                || (int) ($allocation['location_id'] ?? 0) < 1
                || (int) ($allocation['quantity'] ?? 0) < 1) {
                throw new \RuntimeException('WooCommerce order has a malformed stock-allocation return line.');
            }
            $productId = (int) $allocation['product_id'];
            $locationId = (int) $allocation['location_id'];
            $allocationQuantity = (int) $allocation['quantity'];
            $location = $this->locationRepo->findById($locationId);
            if (!$location || !$location->isActive) {
                throw new \RuntimeException('An original Junction stock-allocation location is unavailable.');
            }
            $normalized[] = [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $allocationQuantity,
            ];
            $allocatedQuantity += $allocationQuantity;
        }
        if ($allocatedQuantity !== $reducedQuantity || $returnedQuantity > $reducedQuantity) {
            throw new \RuntimeException('WooCommerce stock-allocation quantities do not reconcile to the original sale.');
        }
        foreach ($normalized as $allocation) {
            if (!$this->productRepo->findById($allocation['product_id'])) {
                throw new \RuntimeException('An original Junction inventory product is unavailable for return.');
            }
        }

        if ($kind === 'cancellation') {
            // WooCommerce's native cancellation restore callback represents the
            // remaining reduced quantity for this line. A duplicate callback
            // after a committed restore is an acknowledgement, not a new return.
            if ($cancellationReturned > 0) return true;
            $remaining = $reducedQuantity - $returnedQuantity;
            if ($quantity !== $remaining) {
                throw new \RuntimeException('Cancellation restock quantity does not match the remaining allocated sale quantity.');
            }
            $targetReturned = $reducedQuantity;
            $nextCancellationReturned = $cancellationReturned + $quantity;
            $nextRefundReturned = $refundReturned;
            $referenceType = 'wc_order_restore';
            $note = "WooCommerce cancellation restock for order #{$orderId}, item #{$itemId}";
        } else {
            if ($refundCumulative === null || $refundCumulative < 1) {
                throw new \InvalidArgumentException('WooCommerce refund cumulative restock quantity is invalid.');
            }
            if ($refundCumulative <= $refundReturned) return true;
            if ($refundCumulative !== $refundReturned + $quantity
                || $returnedQuantity + $quantity > $reducedQuantity) {
                throw new \RuntimeException('WooCommerce refund restock sequence does not match the saved allocation return state.');
            }
            $targetReturned = $returnedQuantity + $quantity;
            $nextCancellationReturned = $cancellationReturned;
            $nextRefundReturned = $refundCumulative;
            $referenceType = 'wc_refund_restock';
            $note = "WooCommerce refund restock for order #{$orderId}, item #{$itemId}";
        }

        $productIds = [];
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for allocated WooCommerce return.');
        }
        $lockProductIds = array_values(array_unique(array_column($normalized, 'product_id')));
        sort($lockProductIds, SORT_NUMERIC);
        $productLocks = InventoryProductLock::acquireMany($lockProductIds);
        if ($wpdb->query('START TRANSACTION') === false) {
            InventoryProductLock::release($productLocks);
            throw new \RuntimeException('Unable to start allocated WooCommerce return transaction.');
        }

        try {
            $cursor = 0;
            foreach ($normalized as $allocation) {
                $start = $cursor;
                $end = $cursor + $allocation['quantity'];
                $cursor = $end;
                $chunk = max(0, min($targetReturned, $end) - max($returnedQuantity, $start));
                if ($chunk < 1) continue;

                $productId = $allocation['product_id'];
                $locationId = $allocation['location_id'];
                $this->stockRepo->getStock($productId, $locationId);

                $saleReference = $orderId . ':' . $locationId;
                if (!$this->stockRepo->hasMovementReference(
                    $productId,
                    $locationId,
                    'wc_order',
                    $saleReference,
                    MovementType::OnlineSale
                )) {
                    if ($wpdb->query('COMMIT') === false) {
                        throw new \RuntimeException('Unable to commit deferred allocated WooCommerce return.');
                    }
                    return false;
                }

                $returnReference = $orderId . ':' . $itemId . ':' . $targetReturned . ':' . $locationId;
                if (!$this->stockRepo->hasMovementReference(
                    $productId,
                    $locationId,
                    $referenceType,
                    $returnReference,
                    MovementType::OnlineReturn
                )) {
                    $this->adjustStock->execute(
                        $productId,
                        $locationId,
                        $chunk,
                        MovementType::OnlineReturn,
                        $note,
                        $referenceType,
                        $returnReference,
                        false
                    );
                }
                $productIds[$productId] = $productId;
            }

            $item->update_meta_data('_pkstore_inventory_returned_quantity', $targetReturned);
            $item->update_meta_data('_pkstore_inventory_refund_returned_quantity', $nextRefundReturned);
            $item->update_meta_data('_pkstore_inventory_cancellation_returned_quantity', $nextCancellationReturned);
            if ((int) $item->save() !== $itemId) {
                throw new \RuntimeException('Unable to persist WooCommerce allocated return state.');
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit allocated WooCommerce return transaction.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->log->error('WooCommerce allocated return was rolled back; event must be retried.', [
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'error_class' => get_class($error),
            ]);
            throw $error;
        } finally {
            InventoryProductLock::release($productLocks);
        }

        foreach ($productIds as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce($productId);
            } catch (\Throwable $error) {
                $this->log->error('WooCommerce allocated return committed; aggregate stock sync remains queued.', [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'error_class' => get_class($error),
                ]);
            }
        }
        return true;
    }

    /**
     * Resolve the WooCommerce product that actually owns stock for this order
     * item. A variation can be managed by itself or by its variable parent.
     */
    private function stockManagedWooCommerceProductId($item, int $parentId, int $variationId): int {
        if ($parentId < 1) {
            throw new \RuntimeException('WooCommerce order item has an invalid parent product ID.');
        }
        if ($variationId < 1) return $parentId;
        if (!is_object($item) || !method_exists($item, 'get_product')) {
            throw new \RuntimeException('Unable to resolve the WooCommerce variation stock owner.');
        }
        $variation = $item->get_product();
        if (!is_object($variation) || !method_exists($variation, 'get_stock_managed_by_id')
            || !method_exists($variation, 'get_id')
            || (int) $variation->get_id() !== $variationId) {
            throw new \RuntimeException('WooCommerce variation stock ownership could not be verified.');
        }
        if (method_exists($variation, 'managing_stock') && !$variation->managing_stock()) {
            throw new \RuntimeException('WooCommerce order variation does not manage stock despite reduced-stock metadata.');
        }
        $stockManagedId = (int) $variation->get_stock_managed_by_id();
        if (!in_array($stockManagedId, [$parentId, $variationId], true)) {
            throw new \RuntimeException('WooCommerce variation resolved to an unexpected stock-owning product.');
        }
        return $stockManagedId;
    }

    /**
     * Acquire product mutexes before the per-order mutex. Live WooCommerce hooks
     * and background queue workers must use the same ordering to avoid deadlocks.
     */
    private function withOrderProductLocks(\WC_Order $order, callable $callback): mixed {
        $inventoryProductIds = [];
        foreach ($order->get_items('line_item') as $item) {
            $inventoryProductId = (int) $item->get_meta('_pkstore_inventory_product_id', true);
            if ($inventoryProductId > 0) {
                $inventoryProductIds[] = $inventoryProductId;
                continue;
            }
            $wcProduct = $item->get_product();
            if (!$wcProduct instanceof \WC_Product || !$wcProduct->managing_stock()) continue;
            $stockOwnerId = method_exists($wcProduct, 'get_stock_managed_by_id')
                ? (int) $wcProduct->get_stock_managed_by_id()
                : (int) $wcProduct->get_id();
            if ($stockOwnerId < 1) throw new \RuntimeException('WooCommerce stock owner could not be resolved for order locking.');
            $inventoryProduct = $this->productRepo->findByWcProductId($stockOwnerId);
            if ($inventoryProduct) $inventoryProductIds[] = (int) $inventoryProduct->id;
        }
        if (!$inventoryProductIds) return $callback();
        $locks = InventoryProductLock::acquireMany($inventoryProductIds);
        try {
            return $callback();
        } finally {
            InventoryProductLock::release($locks);
        }
    }

    /**
     * Serialize sale ingestion and all return callbacks for one WooCommerce
     * order. MySQL releases this advisory lock automatically if the request dies.
     */
    private function withOrderLock(int $orderId, callable $callback): mixed {
        global $wpdb;
        if ($orderId < 1 || !$wpdb instanceof \wpdb) {
            throw new \RuntimeException('A valid WooCommerce order lock is unavailable.');
        }
        $lockName = 'pkstore_wc_order_' . $orderId;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 10));
        if ((int) $acquired !== 1) {
            throw new \RuntimeException('Unable to acquire WooCommerce order inventory lock.');
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public function syncAllToWooCommerce(): int {
        $products = $this->productRepo->findAll(500, 0);
        $synced = 0;
        foreach ($products as $product) {
            if (!$product->wcProductId) continue;
            // Keep the reconciliation retryable and use the same per-product lock
            // as POS, manual adjustments and the background queue worker.
            $this->stockRepo->enqueueWooCommerceSync($product->id);
            try {
                $this->adjustStock->syncProductToWooCommerce($product->id);
                $synced++;
            } catch (\Throwable $error) {
                $this->log->error('Hourly WooCommerce stock reconciliation remains queued.', [
                    'product_id' => $product->id,
                    'error_class' => get_class($error),
                ]);
            }
        }
        return $synced;
    }
}
