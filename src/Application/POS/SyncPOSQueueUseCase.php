<?php
declare(strict_types=1);
namespace PkStore\Application\POS;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;
use Psr\Log\LoggerInterface;
final class SyncPOSQueueUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(): array {
        $pending = $this->orderRepo->findPending();
        $results = ['synced'=>0, 'failed'=>0, 'errors'=>[]];
        foreach ($pending as $order) {
            $lockKey = 'pkstore_pos_sync_lock_' . md5($order->orderRef);
            $lockAcquired = false;
            try {
                if (function_exists('get_option') && function_exists('delete_option')) {
                    $existingLock = (int)get_option($lockKey, 0);
                    if ($existingLock > 0 && $existingLock < (time() - 300)) delete_option($lockKey);
                    $lockAcquired = add_option($lockKey, time(), '', false);
                    if (!$lockAcquired) throw new \RuntimeException('This POS order is already being synchronized; retry shortly.');
                }
                if (!function_exists('wc_create_order') || !function_exists('wc_get_orders')) {
                    throw new \RuntimeException('WooCommerce order APIs are unavailable.');
                }
                // Find an earlier attempt first so a retry never creates a second order.
                $matches = wc_get_orders([
                    'limit'=>1, 'return'=>'objects',
                    'meta_key'=>'_pkstore_pos_order_ref', 'meta_value'=>$order->orderRef,
                ]);
                $wcOrder = $matches ? reset($matches) : null;
                if (!$wcOrder) {
                    $wcOrder = wc_create_order(['status'=>'pending']);
                    if (is_wp_error($wcOrder) || !$wcOrder) {
                        throw new \RuntimeException(is_wp_error($wcOrder) ? $wcOrder->get_error_message() : 'WooCommerce order creation failed.');
                    }
                    $wcOrder->update_meta_data('_pkstore_pos_order_ref', $order->orderRef);
                    $wcOrder->update_meta_data('_pkstore_pos_location_id', $order->locationId);
                    $wcOrder->update_meta_data('_pkstore_pos_sync_state', 'building');
                    $wcOrder->save();
                }
                // Rebuild incomplete imports safely after a crash or a failed item mapping.
                if ($wcOrder->get_meta('_pkstore_pos_sync_state') !== 'complete') {
                    foreach ($wcOrder->get_items('line_item') as $itemId => $lineItem) {
                        $wcOrder->remove_item($itemId);
                    }
                    foreach ($order->getItems() as $item) {
                        $product = $this->productRepo->findById((int)$item['product_id']);
                        if (!$product || !$product->wcProductId) {
                            throw new \RuntimeException('POS item '.$item['sku'].' has no linked WooCommerce product.');
                        }
                        $wcProduct = wc_get_product($product->wcProductId);
                        if (!$wcProduct) {
                            throw new \RuntimeException('WooCommerce product '.$product->wcProductId.' is unavailable.');
                        }
                        $quantity = (int)$item['quantity'];
                        $gross = (float)$item['unit_price_pkr'] * $quantity;
                        $lineDiscount = (float)($item['discount_pkr'] ?? 0);
                        $lineItemId=$wcOrder->add_product($wcProduct, $quantity, [
                            'subtotal'=>max(0, $gross),
                            'total'=>max(0, $gross - $lineDiscount),
                        ]);
                        if(!$lineItemId) throw new \\RuntimeException('WooCommerce failed to add POS line item '.$item['sku'].' to order '.$wcOrder->get_id().'.');
                    }
                    $wcOrder->set_billing_first_name($order->customerName);
                    $wcOrder->set_billing_phone($order->customerPhone);
                    $wcOrder->set_payment_method('pos');
                    $wcOrder->set_payment_method_title('POS - '.ucfirst($order->paymentMethod));
                    $wcOrder->set_discount_total((float)$order->discountPkr);
                    $wcOrder->set_total((float)$order->totalPkr);
                    // POS already deducted physical stock; WooCommerce must not deduct it again.
                    if (method_exists($wcOrder, 'set_stock_reduced')) $wcOrder->set_stock_reduced(true);
                    $wcOrder->update_meta_data('_pkstore_pos_sync_state', 'complete');
                    $wcOrder->save();
                }
                if ($wcOrder->get_status() !== 'processing') {
                    $wcOrder->update_status('processing', 'Imported from POS '.$order->orderRef, true);
                }
                $order->markSynced((int)$wcOrder->get_id());
                $this->orderRepo->update($order);
                $results['synced']++;
            } catch (\Throwable $e) {
                $order->markFailed();
                $this->orderRepo->update($order);
                $results['failed']++;
                $results['errors'][] = "Order {$order->orderRef}: {$e->getMessage()}";
                $this->log->error('POS WooCommerce sync failed.', ['order_ref'=>$order->orderRef,'error'=>$e->getMessage()]);
            } finally {
                if ($lockAcquired) delete_option($lockKey);
            }
        }
        return $results;
    }
}
