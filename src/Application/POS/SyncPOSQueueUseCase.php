<?php
declare(strict_types=1);
namespace PkStore\Application\POS;

use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\POS\Entity\POSOrder;
use PkStore\Domain\POS\Entity\SyncStatus;
use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors already-committed Junction POS sales into WooCommerce orders.
 *
 * Junction owns the stock deduction. The WooCommerce order is marked as
 * already stock-reduced before it is paid, so WooCommerce cannot deduct the
 * same sale a second time. Actual cancellation/refund restocks still flow
 * through the existing WooCommerce return-event hooks.
 */
final class SyncPOSQueueUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly LoggerInterface $log,
    ) {}

    public function retryFailedOrder(int $orderId): array {
        if ($orderId < 1) throw new \InvalidArgumentException('A valid POS order ID is required.');
        $order = $this->orderRepo->findById($orderId);
        if (!$order || $order->getSyncStatus() !== SyncStatus::Failed) {
            throw new \DomainException('Only an existing failed POS sync can be retried.');
        }
        $order->markPendingForRetry();
        $this->orderRepo->update($order);
        return $this->execute($orderId);
    }

    public function execute(?int $onlyOrderId = null): array {
        $pending = $onlyOrderId !== null
            ? array_filter([$this->orderRepo->findById($onlyOrderId)])
            : $this->orderRepo->findPending();
        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($pending as $candidate) {
            if (!$candidate instanceof POSOrder) {
                $failed++;
                $errors[] = 'A pending POS record could not be read.';
                continue;
            }

            global $wpdb;
            if (!$wpdb instanceof \wpdb) {
                $failed++;
                $errors[] = 'POS synchronization database is unavailable.';
                continue;
            }
            $lockName = 'pkstore_pos_sync_' . $candidate->id;
            $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
            if ($locked !== 1) {
                $failed++;
                continue;
            }

            $order = null;
            try {
                $order = $this->orderRepo->findById($candidate->id);
                if (!$order) {
                    throw new \RuntimeException('POS order no longer exists.');
                }
                if ($order->getSyncStatus() === SyncStatus::Synced && $order->wcOrderId) {
                    $synced++;
                    continue;
                }

                if (!function_exists('wc_get_orders') || !class_exists(\WC_Order::class)
                    || !function_exists('wc_get_product') || !function_exists('get_woocommerce_currency')) {
                    throw new \RuntimeException('WooCommerce order APIs are unavailable.');
                }
                if (get_woocommerce_currency() !== 'PKR') {
                    throw new \RuntimeException('POS prices are PKR; WooCommerce store currency must also be PKR.');
                }

                $wcOrder = null;
                if ($order->wcOrderId) {
                    $wcOrder = wc_get_order($order->wcOrderId);
                    if (!$wcOrder instanceof \WC_Order
                        || $wcOrder->get_meta('_pkstore_pos_order_ref', true) !== $order->orderRef
                        || $wcOrder->get_meta('_pkstore_pos_origin', true) !== 'junction_pos') {
                        throw new \RuntimeException('Saved WooCommerce order linkage failed verification.');
                    }
                } else {
                    $matches = wc_get_orders([
                        'limit' => 2,
                        'return' => 'objects',
                        'meta_key' => '_pkstore_pos_order_ref',
                        'meta_value' => $order->orderRef,
                    ]);
                    if (count($matches) > 1) {
                        throw new \RuntimeException('Multiple WooCommerce orders match this POS reference.');
                    }
                    if (count($matches) === 1) {
                        $wcOrder = $matches[0];
                        if (!$wcOrder instanceof \WC_Order
                            || $wcOrder->get_meta('_pkstore_pos_origin', true) !== 'junction_pos') {
                            throw new \RuntimeException('Existing WooCommerce order has an invalid POS origin marker.');
                        }
                    }
                }

                if (!$wcOrder) {
                    $wcOrder = $this->createWooCommerceOrder($order);
                }

                // A crash before payment is recoverable: rebuild the unpaid
                // order's lines from the immutable POS record before marking paid.
                if (!$wcOrder->is_paid()) {
                    $this->replaceWooCommerceOrderItems($wcOrder, $order);
                    $wcOrder->payment_complete('POS-' . $order->orderRef);
                } elseif ($wcOrder->get_meta('_order_stock_reduced', true) !== 'yes' || !$this->paidWooCommerceOrderMatches($wcOrder, $order)) {
                    throw new \RuntimeException('Paid WooCommerce order does not match its POS source record.');
                }
                $wcOrder->save();
                if ((int) $wcOrder->get_id() < 1) {
                    throw new \RuntimeException('WooCommerce POS order has no persisted ID.');
                }

                $order->markSynced((int) $wcOrder->get_id());
                $this->orderRepo->update($order);
                $synced++;
            } catch (\Throwable $error) {
                $failed++;
                $errors[] = 'POS order ' . $candidate->orderRef . ' could not be synchronized.';
                try {
                    // Keep transient WooCommerce/API/database failures pending so
                    // the scheduled worker can retry them with its normal cadence.
                    // Only an explicit operator action or a separately classified
                    // permanent failure should move a record out of the retry queue.
                    $order ??= $this->orderRepo->findById($candidate->id);
                    if ($order && $order->getSyncStatus() === SyncStatus::Failed) {
                        // Preserve an existing failed state; do not silently reset it.
                        $this->orderRepo->update($order);
                    }
                } catch (\Throwable $stateError) {
                    $this->log->critical('Unable to persist failed POS sync state.', [
                        'pos_order_id' => $candidate->id,
                        'error_class' => get_class($stateError),
                    ]);
                }
                $this->log->error('POS to WooCommerce order sync failed.', [
                    'pos_order_id' => $candidate->id,
                    'order_ref' => $candidate->orderRef,
                    'error_class' => get_class($error),
                ]);
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            }
        }

        return ['synced' => $synced, 'failed' => $failed, 'errors' => $errors];
    }

    private function createWooCommerceOrder(POSOrder $posOrder): \WC_Order {
        $wcOrder = new \WC_Order();
        $wcOrder->set_created_via('junction-pos');
        $wcOrder->set_currency('PKR');
        $wcOrder->set_status('pending');
        $wcOrder->set_payment_method('junction_pos_' . $posOrder->paymentMethod);
        $wcOrder->set_payment_method_title('POS ' . ucfirst($posOrder->paymentMethod));

        $nameParts = preg_split('/\s+/', trim($posOrder->customerName), 2) ?: [];
        $wcOrder->set_billing_first_name($nameParts[0] ?? 'Walk-in');
        $wcOrder->set_billing_last_name($nameParts[1] ?? '');
        $wcOrder->set_billing_phone($posOrder->customerPhone);

        $wcOrder->update_meta_data('_pkstore_pos_origin', 'junction_pos');
        $wcOrder->update_meta_data('_pkstore_pos_order_ref', $posOrder->orderRef);
        $wcOrder->update_meta_data('_pkstore_pos_tender_method', $posOrder->paymentMethod);
        $wcOrder->update_meta_data('_pkstore_pos_inventory_deducted', 'yes');
        // Junction has already committed the stock movement and publishes the
        // combined stock snapshot. This flag prevents WooCommerce reducing it again.
        $wcOrder->update_meta_data('_order_stock_reduced', 'yes');

        // Persist the idempotency marker before adding items or invoking payment
        // hooks, so a retry can always find and repair this same WooCommerce order.
        $wcOrder->save();
        if ((int) $wcOrder->get_id() < 1) {
            throw new \RuntimeException('WooCommerce did not persist the POS order.');
        }
        return $wcOrder;
    }

    private function replaceWooCommerceOrderItems(\WC_Order $wcOrder, POSOrder $posOrder): void {
        $items = $posOrder->getItems();
        if (!$items) {
            throw new \RuntimeException('POS order has no persisted line items.');
        }

        foreach ($wcOrder->get_items('line_item') as $existingItem) {
            $wcOrder->remove_item($existingItem->get_id());
        }

        foreach ($items as $item) {
            $inventoryProduct = $this->productRepo->findById((int) $item['product_id']);
            if (!$inventoryProduct || !$inventoryProduct->wcProductId) {
                throw new \RuntimeException('A POS item has no valid WooCommerce product mapping.');
            }
            $wcProduct = wc_get_product($inventoryProduct->wcProductId);
            if (!$wcProduct instanceof \WC_Product) {
                throw new \RuntimeException('A mapped WooCommerce product is unavailable.');
            }
            $quantity = (int) $item['quantity'];
            $lineTotal = (int) $item['unit_price_pkr'] * $quantity;
            if ($quantity < 1 || $lineTotal < 0) {
                throw new \RuntimeException('A persisted POS line item is invalid.');
            }
            $added = $wcOrder->add_product($wcProduct, $quantity, [
                'subtotal' => wc_format_decimal($lineTotal, 0),
                'total' => wc_format_decimal($lineTotal, 0),
            ]);
            if (!$added) {
                throw new \RuntimeException('WooCommerce rejected a POS line item.');
            }
            $lineItems = $wcOrder->get_items('line_item');
            $lineItem = end($lineItems);
            if (!$lineItem instanceof \WC_Order_Item_Product) {
                throw new \RuntimeException('WooCommerce POS line item could not be reloaded.');
            }
            $lineItem->update_meta_data('_pkstore_inventory_product_id', $inventoryProduct->id);
            $lineItem->update_meta_data('_pkstore_inventory_location_id', $posOrder->locationId);
            $lineItem->update_meta_data('_pkstore_pos_order_ref', $posOrder->orderRef);
            // WooCommerce's native cancellation/refund-restock callbacks inspect
            // this per-line marker before emitting their actual-restock hooks.
            // The aggregate WC stock was already reduced by Junction, so record
            // the quantity as bookkeeping without applying a second stock change.
            $lineItem->update_meta_data('_reduced_stock', $quantity);
        }

        $wcOrder->set_discount_total(wc_format_decimal($posOrder->discountPkr, 0));
        $wcOrder->set_shipping_total('0');
        $wcOrder->set_cart_tax('0');
        $wcOrder->set_shipping_tax('0');
        $wcOrder->set_total(wc_format_decimal($posOrder->totalPkr, 0));
        $wcOrder->save();
    }

    private function paidWooCommerceOrderMatches(\WC_Order $wcOrder, POSOrder $posOrder): bool {
        if ($wcOrder->get_currency() !== 'PKR'
            || (int) round((float) $wcOrder->get_total()) !== $posOrder->totalPkr
            || (int) round((float) $wcOrder->get_discount_total()) !== $posOrder->discountPkr) {
            return false;
        }

        $expected = [];
        $expectedAmounts = [];
        if (!$posOrder->getItems()) return false;
        foreach ($posOrder->getItems() as $item) {
            $inventoryProduct = $this->productRepo->findById((int) $item['product_id']);
            if (!$inventoryProduct || !$inventoryProduct->wcProductId) return false;
            $product = wc_get_product($inventoryProduct->wcProductId);
            if (!$product instanceof \WC_Product) return false;
            $parentId = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
            $variationId = $product->is_type('variation') ? (int) $product->get_id() : 0;
            $key = $parentId . ':' . $variationId;
            $quantity = (int) $item['quantity'];
            $lineAmount = (int) $item['unit_price_pkr'] * $quantity;
            $expected[$key] = ($expected[$key] ?? 0) + $quantity;
            $expectedAmounts[$key] = ($expectedAmounts[$key] ?? 0) + $lineAmount;
        }

        $actual = [];
        $actualAmounts = [];
        foreach ($wcOrder->get_items('line_item') as $item) {
            $key = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
            $quantity = (int) $item->get_quantity();
            $actual[$key] = ($actual[$key] ?? 0) + $quantity;
            // POS mirror line prices are immutable source data. An order-level
            // discount is stored separately, so each line's subtotal and total
            // must still equal the source unit price multiplied by quantity.
            $lineSubtotal = (float) $item->get_subtotal();
            $lineTotal = (float) $item->get_total();
            if (!is_finite($lineSubtotal) || !is_finite($lineTotal)
                || $lineSubtotal < 0 || $lineTotal < 0
                || abs($lineSubtotal - $lineTotal) > 0.00001) {
                return false;
            }
            $actualAmounts[$key] = ($actualAmounts[$key] ?? 0) + (int) round($lineTotal);

            // A paid order is immutable for recovery purposes. Verify the
            // original Junction product, physical location and POS reference
            // on every line because cancellation/refund handlers trust them.
            $inventoryProductId = (int) $item->get_meta('_pkstore_inventory_product_id', true);
            $locationId = (int) $item->get_meta('_pkstore_inventory_location_id', true);
            $posOrderRef = (string) $item->get_meta('_pkstore_pos_order_ref', true);
            $reducedStock = (int) $item->get_meta('_reduced_stock', true);
            $mappedProduct = $this->productRepo->findById($inventoryProductId);
            if ($reducedStock !== (int) $item->get_quantity()
                || !$mappedProduct
                || !$mappedProduct->wcProductId
                || !in_array((int) $mappedProduct->wcProductId, array_filter([
                    (int) $item->get_variation_id(),
                    (int) $item->get_product_id(),
                ]), true)
                || $locationId !== $posOrder->locationId
                || !hash_equals($posOrder->orderRef, $posOrderRef)) {
                return false;
            }
        }
        ksort($expected);
        ksort($actual);
        ksort($expectedAmounts);
        ksort($actualAmounts);
        return $expected === $actual && $expectedAmounts === $actualAmounts;
    }
}
