<?php
declare(strict_types=1);

namespace PkStore\Application\Inventory;

use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\Inventory\Repository\LocationRepositoryInterface;

/**
 * Persist an immutable snapshot of one real WooCommerce stock-reduction pass.
 *
 * This service is intentionally not registered or hooked into production yet.
 * Cycle-aware sale and return workers must exist before capture is enabled.
 */
final class CaptureWooCommerceStockCycleUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly LocationRepositoryInterface $locationRepo,
    ) {}

    /**
     * @return string The persisted cycle UUID.
     */
    public function capture($order): string {
        if (!($order instanceof \WC_Order)) {
            throw new \InvalidArgumentException('WooCommerce stock-cycle capture requires an order.');
        }

        $orderId = (int) $order->get_id();
        if ($orderId < 1 || $order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') {
            throw new \InvalidArgumentException('WooCommerce order is not eligible for online stock-cycle capture.');
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database is unavailable for stock-cycle capture.');
        }

        $lockName = 'pkstore_wc_cycle_' . $orderId;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 10));
        if ((int) $acquired !== 1) {
            throw new \RuntimeException('Unable to acquire WooCommerce stock-cycle lock.');
        }

        try {
            return $this->captureLocked($order, $orderId);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private function captureLocked(\WC_Order $order, int $orderId): string {
        global $wpdb;
        $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
        $itemsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
        $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';

        $onlineLocation = $this->locationRepo->findOnline();
        if (!$onlineLocation || !$onlineLocation->isActive) {
            throw new \RuntimeException('Junction online inventory location is unavailable.');
        }

        $snapshot = [];
        foreach ($order->get_items('line_item') as $item) {
            $itemId = (int) $item->get_id();
            $parentId = (int) $item->get_product_id();
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $reduced = $item->get_meta('_reduced_stock', true);
            if ($reduced === '' || $reduced === null) {
                continue;
            }
            if (!is_numeric($reduced)) {
                throw new \RuntimeException('WooCommerce stock-cycle line has malformed reduced-stock metadata.');
            }
            if ((float) $reduced === 0.0) {
                continue;
            }
            if ($itemId < 1 || $parentId < 1
                || (float) $reduced < 0 || floor((float) $reduced) !== (float) $reduced) {
                throw new \RuntimeException('WooCommerce stock-cycle line snapshot is invalid.');
            }

            $inventoryProduct = $variationId > 0
                ? $this->productRepo->findByWcProductId($variationId)
                : null;
            $inventoryProduct ??= $this->productRepo->findByWcProductId($parentId);
            if (!$inventoryProduct) {
                throw new \RuntimeException('Reduced WooCommerce product has no Junction inventory mapping.');
            }

            $snapshot[] = [
                'item_id' => $itemId,
                'parent_id' => $parentId,
                'variation_id' => $variationId,
                'inventory_product_id' => (int) $inventoryProduct->id,
                'inventory_location_id' => (int) $onlineLocation->id,
                'quantity' => (int) $reduced,
            ];
        }

        if (!$snapshot) {
            throw new \RuntimeException('WooCommerce order has no actual stock-reduced line items to capture.');
        }
        usort($snapshot, static fn(array $a, array $b): int => $a['item_id'] <=> $b['item_id']);

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start WooCommerce stock-cycle transaction.');
        }

        try {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_uuid, cycle_number, state FROM {$cyclesTable}
                 WHERE wc_order_id=%d AND state<>'closed'
                 ORDER BY cycle_number DESC LIMIT 1 FOR UPDATE",
                $orderId
            ), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Unable to inspect existing WooCommerce stock cycles.');
            }

            if (is_array($existing)) {
                $existingItems = $wpdb->get_results($wpdb->prepare(
                    "SELECT wc_order_item_id, wc_parent_product_id, wc_variation_id,
                            inventory_product_id, inventory_location_id, reduced_quantity
                     FROM {$itemsTable} WHERE cycle_uuid=%s ORDER BY wc_order_item_id ASC",
                    (string) $existing['cycle_uuid']
                ), ARRAY_A);
                if ($wpdb->last_error !== '') {
                    throw new \RuntimeException('Unable to inspect existing WooCommerce stock-cycle lines.');
                }
                $existingSnapshot = [];
                foreach (($existingItems ?: []) as $row) {
                    $existingSnapshot[] = [
                        'item_id' => (int) $row['wc_order_item_id'],
                        'parent_id' => (int) $row['wc_parent_product_id'],
                        'variation_id' => (int) $row['wc_variation_id'],
                        'inventory_product_id' => (int) $row['inventory_product_id'],
                        'inventory_location_id' => (int) $row['inventory_location_id'],
                        'quantity' => (int) $row['reduced_quantity'],
                    ];
                }
                if ($existingSnapshot !== $snapshot) {
                    throw new \RuntimeException('An open WooCommerce stock cycle conflicts with the current reduction snapshot.');
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Unable to commit duplicate WooCommerce stock-cycle lookup.');
                }
                return (string) $existing['cycle_uuid'];
            }

            $nextNumberValue = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM {$cyclesTable} WHERE wc_order_id=%d",
                $orderId
            ));
            if ($wpdb->last_error !== '' || !is_numeric($nextNumberValue)) {
                throw new \RuntimeException('Unable to inspect WooCommerce stock-cycle sequence.');
            }
            $nextNumber = (int) $nextNumberValue;
            if ($nextNumber < 1) {
                throw new \RuntimeException('Unable to allocate WooCommerce stock-cycle number.');
            }

            $cycleUuid = wp_generate_uuid4();
            $eventKey = 'wc_stock_cycle_reduce:' . $orderId . ':' . $nextNumber;
            $now = current_time('mysql');

            $inserted = $wpdb->insert($cyclesTable, [
                'wc_order_id' => $orderId,
                'cycle_uuid' => $cycleUuid,
                'cycle_number' => $nextNumber,
                'state' => 'reducing',
                'reduction_event_key' => $eventKey,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s']);
            if ($inserted !== 1) {
                throw new \RuntimeException('Unable to persist WooCommerce stock-cycle header.');
            }

            foreach ($snapshot as $line) {
                $inserted = $wpdb->insert($itemsTable, [
                    'cycle_uuid' => $cycleUuid,
                    'wc_order_id' => $orderId,
                    'wc_order_item_id' => $line['item_id'],
                    'wc_parent_product_id' => $line['parent_id'],
                    'wc_variation_id' => $line['variation_id'],
                    'inventory_product_id' => $line['inventory_product_id'],
                    'inventory_location_id' => $line['inventory_location_id'],
                    'reduced_quantity' => $line['quantity'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']);
                if ($inserted !== 1) {
                    throw new \RuntimeException('Unable to persist WooCommerce stock-cycle line snapshot.');
                }
            }

            $eventInserted = $wpdb->insert($eventsTable, [
                'event_uuid' => wp_generate_uuid4(),
                'event_key' => $eventKey,
                'cycle_uuid' => $cycleUuid,
                'wc_order_id' => $orderId,
                'wc_order_item_id' => null,
                'event_kind' => 'reduction',
                'quantity' => array_sum(array_column($snapshot, 'quantity')),
                'cumulative_quantity' => 0,
                'source_hook' => 'woocommerce_reduce_order_stock',
                'source_metadata' => wp_json_encode(['line_count' => count($snapshot)]),
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($eventInserted !== 1) {
                throw new \RuntimeException('Unable to persist WooCommerce stock-cycle reduction event.');
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit WooCommerce stock-cycle snapshot.');
            }
            return $cycleUuid;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
