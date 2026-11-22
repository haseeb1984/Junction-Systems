<?php
declare(strict_types=1);

namespace PkStore\Application\Inventory;

use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\Inventory\Repository\LocationRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Persist the identity and immutable line mapping for an actual WooCommerce
 * stock-reduction pass. This capture is intentionally separate from workers.
 */
final class CaptureWooCommerceStockCycleUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $products,
        private readonly LocationRepositoryInterface $locations,
        private readonly LoggerInterface $log,
    ) {}

    public function capture($order): ?string {
        if (!($order instanceof \WC_Order)) {
            throw new \InvalidArgumentException('A WooCommerce order is required for stock-cycle capture.');
        }
        if ($order->get_meta('_pkstore_pos_origin', true) === 'junction_pos') return null;
        $orderId = (int) $order->get_id();
        if ($orderId < 1) throw new \InvalidArgumentException('Invalid WooCommerce order ID.');

        global $wpdb;
        if (!$wpdb instanceof \wpdb) throw new \RuntimeException('WordPress database is unavailable for stock-cycle capture.');
        $lockName = 'pkstore_wc_cycle_' . $orderId;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
        if ($locked !== 1) throw new \RuntimeException('Unable to acquire WooCommerce stock-cycle lock.');

        try {
            $cycleTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';
            $itemTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_items';
            $eventTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT cycle_uuid FROM {$cycleTable}
                 WHERE wc_order_id=%d AND state IN ('reducing','active','partially_returned')
                 ORDER BY cycle_number DESC LIMIT 1",
                $orderId
            ), ARRAY_A);
            if ($existing) {
                // A repeated order-level callback belongs to the still-open cycle.
                return (string) $existing['cycle_uuid'];
            }

            $location = $this->locations->findOnline();
            if (!$location || !$location->isActive) {
                throw new \RuntimeException('Junction online inventory location is unavailable.');
            }

            $snapshots = [];
            foreach ($order->get_items('line_item') as $item) {
                $itemId = (int) $item->get_id();
                $reduced = (int) $item->get_meta('_reduced_stock', true);
                if ($itemId < 1 || $reduced < 1) continue;
                $parentId = (int) $item->get_product_id();
                $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
                $product = $variationId > 0 ? $this->products->findByWcProductId($variationId) : null;
                $product ??= $this->products->findByWcProductId($parentId);
                if (!$product || !$product->isActive) {
                    throw new \RuntimeException('Reduced WooCommerce line has no active Junction inventory mapping.');
                }
                $snapshots[] = [
                    'item_id' => $itemId,
                    'parent_id' => $parentId,
                    'variation_id' => $variationId,
                    'product_id' => (int) $product->id,
                    'location_id' => (int) $location->id,
                    'quantity' => $reduced,
                ];
            }
            if (!$snapshots) return null;

            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('Unable to start stock-cycle capture transaction.');
            }
            try {
                $next = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(MAX(cycle_number),0)+1 FROM {$cycleTable} WHERE wc_order_id=%d FOR UPDATE",
                    $orderId
                ));
                $uuid = wp_generate_uuid4();
                $now = current_time('mysql');
                $eventKey = 'wc-reduction:' . $orderId . ':' . $uuid;
                $inserted = $wpdb->insert($cycleTable, [
                    'wc_order_id' => $orderId,
                    'cycle_uuid' => $uuid,
                    'cycle_number' => $next,
                    'state' => 'active',
                    'reduction_event_key' => $eventKey,
                    'created_at' => $now,
                    'activated_at' => $now,
                    'closed_at' => null,
                    'updated_at' => $now,
                ]);
                if ($inserted !== 1) throw new \RuntimeException('Unable to persist stock-cycle header.');

                foreach ($snapshots as $line) {
                    $saved = $wpdb->insert($itemTable, [
                        'cycle_uuid' => $uuid,
                        'wc_order_id' => $orderId,
                        'wc_order_item_id' => $line['item_id'],
                        'wc_parent_product_id' => $line['parent_id'],
                        'wc_variation_id' => $line['variation_id'],
                        'inventory_product_id' => $line['product_id'],
                        'inventory_location_id' => $line['location_id'],
                        'reduced_quantity' => $line['quantity'],
                        'cancellation_restored_quantity' => 0,
                        'refund_restored_quantity' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    if ($saved !== 1) throw new \RuntimeException('Unable to persist stock-cycle line snapshot.');
                }

                $eventUuid = wp_generate_uuid4();
                $savedEvent = $wpdb->insert($eventTable, [
                    'event_uuid' => $eventUuid,
                    'event_key' => $eventKey,
                    'cycle_uuid' => $uuid,
                    'wc_order_id' => $orderId,
                    'wc_order_item_id' => null,
                    'event_kind' => 'reduction',
                    'quantity' => array_sum(array_column($snapshots, 'quantity')),
                    'cumulative_quantity' => 0,
                    'source_hook' => 'woocommerce_reduce_order_stock',
                    'source_metadata' => wp_json_encode(['line_count' => count($snapshots)]),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_attempt_at' => $now,
                    'claimed_at' => null,
                    'claim_token' => null,
                    'last_error' => '',
                    'created_at' => $now,
                    'processed_at' => null,
                    'updated_at' => $now,
                ]);
                if ($savedEvent !== 1) throw new \RuntimeException('Unable to persist stock-cycle reduction event.');
                if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Unable to commit stock-cycle capture.');
                return $uuid;
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }
}
