<?php
declare(strict_types=1);

namespace PkStore\Application\Inventory;

/**
 * Cross-channel mutex for every writer of a Junction inventory product.
 * The name matches AdjustStockUseCase's WooCommerce snapshot lock.
 */
final class InventoryProductLock {
    /**
     * @param int[] $productIds
     * @return string[] Acquired lock names, in acquisition order.
     */
    public static function acquireMany(array $productIds, int $timeoutSeconds = 10): array {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('Inventory product locking requires an active database connection.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        if (!$ids) {
            throw new \InvalidArgumentException('At least one valid inventory product is required for locking.');
        }

        $acquired = [];
        try {
            foreach ($ids as $productId) {
                $name = 'pkstore_wc_stock_' . $productId;
                $result = $wpdb->get_var($wpdb->prepare(
                    'SELECT GET_LOCK(%s, %d)',
                    $name,
                    max(0, min(60, $timeoutSeconds))
                ));
                if ((int) $result !== 1) {
                    throw new \RuntimeException('Unable to acquire the shared inventory product lock.');
                }
                $acquired[] = $name;
            }
            return $acquired;
        } catch (\Throwable $error) {
            self::release($acquired);
            throw $error;
        }
    }

    /** @param string[] $lockNames */
    public static function release(array $lockNames): void {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) return;

        foreach (array_reverse($lockNames) as $name) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}
