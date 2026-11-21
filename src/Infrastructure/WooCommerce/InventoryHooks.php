<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
use PkStore\Application\Inventory\SyncWooCommerceStockUseCase;
use PkStore\Application\Inventory\ProcessWooCommerceOrderInventoryQueueUseCase;
use PkStore\Container\ServiceContainer;
final class InventoryHooks {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('woocommerce_payment_complete',           [$this, 'onSale']);
        add_action('woocommerce_order_status_completed',     [$this, 'onSale']);
        add_action('woocommerce_order_status_processing',    [$this, 'onSale']);
        // This per-line hook fires only after WooCommerce actually restores stock.
        add_action('woocommerce_restore_order_item_stock', [$this, 'onOrderItemRestored'], 10, 4);
        // Scheduled full sync every hour
        if (!wp_next_scheduled('pkstore_inventory_hourly_sync'))
            wp_schedule_event(time(), 'hourly', 'pkstore_inventory_hourly_sync');
        add_action('pkstore_inventory_hourly_sync', [$this, 'hourlySyncAll']);
    }
    public function onSale(int $orderId): void {
        try {
            $this->c->make(ProcessWooCommerceOrderInventoryQueueUseCase::class)->enqueue($orderId);
        } catch (\Throwable $e) {
            // Avoid leaking exception details that may contain customer/provider data.
            error_log('[PKStore Inventory] Unable to enqueue order inventory event for '.$orderId.' ('.get_class($e).').');
        }
    }
    public function onOrderItemRestored($item, int $newStock, int $oldStock, $order): void {
        try {
            $this->c->make(SyncWooCommerceStockUseCase::class)
                ->onWooCommerceOrderItemRestored($item, $newStock, $oldStock, $order);
        } catch (\Throwable $e) {
            // The source event is authoritative, but failures need operator attention.
            error_log('[PKStore Inventory] WooCommerce cancellation restock was not mirrored ('.get_class($e).').');
        }
    }
    public function hourlySyncAll(): void {
        try { $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce(); }
        catch (\Throwable $e) { error_log('[PKStore Inventory] Hourly sync failed ('.get_class($e).').'); }
    }
}
