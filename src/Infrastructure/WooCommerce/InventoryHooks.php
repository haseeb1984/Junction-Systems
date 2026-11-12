<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\WooCommerce;
use JunctionStore\Application\Inventory\SyncWooCommerceStockUseCase;
use JunctionStore\Container\ServiceContainer;
final class InventoryHooks {
    public function __construct(private readonly ServiceContainer $c) {}
    public function register(): void {
        add_action('woocommerce_payment_complete',           [$this, 'onSale']);
        add_action('woocommerce_order_status_completed',     [$this, 'onSale']);
        add_action('woocommerce_order_status_processing',    [$this, 'onSale']);
        // Scheduled full sync every hour
        if (!wp_next_scheduled('pkstore_inventory_hourly_sync'))
            wp_schedule_event(time(), 'hourly', 'pkstore_inventory_hourly_sync');
        add_action('pkstore_inventory_hourly_sync', [$this, 'hourlySyncAll']);
    }
    public function onSale(int $orderId): void {
        try {
            $this->c->make(SyncWooCommerceStockUseCase::class)->onWooCommerceSale($orderId);
        } catch (\Throwable $e) {
            error_log('[JunctionStore Inventory] Stock sync failed for order '.$orderId.': '.$e->getMessage());
        }
    }
    public function hourlySyncAll(): void {
        try { $this->c->make(SyncWooCommerceStockUseCase::class)->syncAllToWooCommerce(); }
        catch (\Throwable $e) { error_log('[JunctionStore Inventory] Hourly sync failed: '.$e->getMessage()); }
    }
}
