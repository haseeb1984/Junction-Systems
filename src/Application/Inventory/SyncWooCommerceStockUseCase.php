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
    public function onWooCommerceSale(int $orderId): void {
        if ($orderId < 1) throw new \InvalidArgumentException('Invalid WooCommerce order ID.');
        $this->withOrderLock($orderId, function () use ($orderId): void {
            $this->applyWooCommerceSale($orderId);
        });
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
            $product = $variationId > 0
                ? $this->productRepo->findByWcProductId($variationId)
                : null;
            $product ??= $this->productRepo->findByWcProductId($wcProductId);
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
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
            $this->log->critical('WooCommerce order inventory ledger could not start its transaction.', [
                'order_id' => $orderId,
            ]);
            throw new \RuntimeException('Unable to start WooCommerce order inventory ledger transaction.');
        }

        $products = array_values(array_unique(array_column($lines, 'product_id')));
        sort($products, SORT_NUMERIC);
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
    public function onWooCommerceOrderItemRestored($item, int $newStock, int $oldStock, $order): bool {
        if (!($order instanceof \WC_Order)) throw new \InvalidArgumentException('WooCommerce restore event is malformed.');
        $orderId = (int) $order->get_id();
        return $this->withOrderLock($orderId, function () use ($item, $newStock, $oldStock, $order): bool {
            return $this->applyWooCommerceOrderItemRestored($item, $newStock, $oldStock, $order);
        });
    }

    private function applyWooCommerceOrderItemRestored($item, int $newStock, int $oldStock, $order): bool {
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
            if ($rawAllocations !== '' && $rawAllocations !== null) {
                return $this->applyAllocatedOnlineReturn($order, $item, 'cancellation', $quantity, null, $cycleUuid);
            }
        }
        $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
        $wcProductId = (int) $item->get_product_id();
        if ($isPosOrder) {
            $product = $this->productRepo->findById((int) $item->get_meta('_pkstore_inventory_product_id', true));
            $locationId = (int) $item->get_meta('_pkstore_inventory_location_id', true);
            $posOrderRef = (string) $item->get_meta('_pkstore_pos_order_ref', true);
            if (!$product || !$locationId || $posOrderRef === ''
                || !in_array((int) $product->wcProductId, array_filter([$variationId, $wcProductId]), true)) {
                throw new \RuntimeException('POS cancellation item is missing its original Junction inventory mapping.');
            }
            $location = $this->locationRepo->findById($locationId);
            $saleSource = 'pos_order';
            $saleReference = $posOrderRef;
            $saleMovementType = MovementType::Sale;
        } else {
            $product = $variationId > 0 ? $this->productRepo->findByWcProductId($variationId) : null;
            $product ??= $this->productRepo->findByWcProductId($wcProductId);
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
    public function onWooCommerceRefundItemRestocked(int $wcProductId, int $newStock, int $oldStock, $order, $product, ?int $orderItemId = null, ?int $cumulativeRestocked = null): bool {
        if (!($order instanceof \WC_Order)) throw new \InvalidArgumentException('WooCommerce refund restock event is malformed.');
        $orderId = (int) $order->get_id();
        return $this->withOrderLock($orderId, function () use ($wcProductId, $newStock, $oldStock, $order, $product, $orderItemId, $cumulativeRestocked): bool {
            return $this->applyWooCommerceRefundItemRestocked($wcProductId, $newStock, $oldStock, $order, $product, $orderItemId, $cumulativeRestocked);
        });
    }

    private function applyWooCommerceRefundItemRestocked(int $wcProductId, int $newStock, int $oldStock, $order, $product, ?int $orderItemId = null, ?int $cumulativeRestocked = null): bool {
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
            // Keep every product-matching line in the ambiguity check, even
            // when its cumulative restock metadata is zero or smaller. Filtering
            // first could incorrectly select one line while ignoring another.
            $candidates[] = ['item' => $item, 'cumulative' => $cumulative, 'variation_id' => $variationId, 'parent_id' => $parentId];
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
            if ($rawAllocations !== '' && $rawAllocations !== null) {
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
                || !in_array((int) $productRecord->wcProductId, array_filter([$candidate['variation_id'], $candidate['parent_id']]), true)) {
                throw new \RuntimeException('POS refund item is missing its original Junction inventory mapping.');
            }
        } else {
            $productRecord = $candidate['variation_id'] > 0
                ? $this->productRepo->findByWcProductId($candidate['variation_id'])
                : null;
            $productRecord ??= $this->productRepo->findByWcProductId($candidate['parent_id']);
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
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
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
