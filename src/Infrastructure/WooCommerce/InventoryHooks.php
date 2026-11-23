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
        try { $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce(); }
        catch (\Throwable $e) { error_log('[PKStore Inventory] Hourly sync failed: '.$e->getMessage()); }
    }
}
