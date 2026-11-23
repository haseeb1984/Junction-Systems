<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
use PkStore\Application\Inventory\SyncWooCommerceStockUseCase;
use PkStore\Container\ServiceContainer;
final class InventoryHooks {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('woocommerce_payment_complete',           [$this, 'onSale']);
        add_action('woocommerce_order_status_completed',     [$this, 'onSale']);
        add_action('woocommerce_order_status_processing',    [$this, 'onSale']);
        if (!wp_next_scheduled('pkstore_inventory_hourly_sync'))
            wp_schedule_event(time(), 'hourly', 'pkstore_inventory_hourly_sync');
        add_action('pkstore_inventory_hourly_sync', [$this, 'hourlySyncAll']);
    }
    public function onSale(int $orderId): void {
        $order = wc_get_order($orderId);
        // POS sales already adjust their physical location before being mirrored online.
        if ($order && $order->get_meta('_pkstore_pos_order_ref')) return;
        try {
            $this->c->make(SyncWooCommerceStockUseCase::class)->onWooCommerceSale($orderId);
        } catch (\Throwable $e) {
            error_log('[PKStore Inventory] Stock sync failed for order '.$orderId.': '.$e->getMessage());
        }
    }
    public function hourlySyncAll(): void {
        $this->retryPOSStockProjections();
        try { $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce(); }
        catch (\Throwable $e) { error_log('[PKStore Inventory] Hourly sync failed: '.$e->getMessage()); }
    }
    private function retryPOSStockProjections(): void {
        global $wpdb;
        $queue=$wpdb->prefix.'pkstore_stock_projection_queue';
        $stock=$wpdb->prefix.'pkstore_inventory_stock';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT product_id,attempts,revision FROM {$queue} WHERE status IN ('pending','failed') AND next_attempt_at<=%s ORDER BY updated_at ASC LIMIT 100",
            current_time('mysql')
        ),ARRAY_A)?:[];
        foreach($rows as $row) {
            $productId=(int)$row['product_id'];
            try {
                $productRow=$wpdb->get_row($wpdb->prepare(
                    "SELECT wc_product_id FROM {$wpdb->prefix}pkstore_inventory_products WHERE id=%d",$productId
                ),ARRAY_A);
                if(!$productRow || !(int)$productRow['wc_product_id']) throw new \RuntimeException('Inventory product has no WooCommerce product mapping.');
                $wcProduct=wc_get_product((int)$productRow['wc_product_id']);
                if(!$wcProduct) throw new \RuntimeException('Mapped WooCommerce product is unavailable.');
                $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM {$stock} WHERE product_id=%d",$productId));
                $wcProduct->set_manage_stock(true);
                $wcProduct->set_stock_quantity($total);
                $wcProduct->set_stock_status($total>0?'instock':'outofstock');
                if($wcProduct->save()===false) throw new \RuntimeException('WooCommerce rejected the stock projection save.');
                $wpdb->delete($queue,['product_id'=>$productId,'revision'=>(int)$row['revision']],['%d','%d']);
            } catch(\Throwable $e) {
                $attempts=(int)$row['attempts']+1;
                $delay=min(86400,60*(2**min(10,$attempts-1)));
                $wpdb->update($queue,[
                    'status'=>'failed','attempts'=>$attempts,
                    'next_attempt_at'=>gmdate('Y-m-d H:i:s',current_time('timestamp')+$delay),
                    'last_error'=>substr($e->getMessage(),0,2000),'updated_at'=>current_time('mysql'),
                ],['product_id'=>$productId,'revision'=>(int)$row['revision']],['%s','%d','%s','%s','%s'],['%d','%d']);
                error_log('[PKStore Inventory] POS stock projection retry failed for product '.$productId.': '.$e->getMessage());
            }
        }
    }
}
