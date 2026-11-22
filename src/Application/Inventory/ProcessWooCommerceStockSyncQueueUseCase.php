<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;

use PkStore\Domain\Inventory\Repository\StockRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Replays durable WooCommerce stock-sync intents.
 *
 * Queue rows are coalesced by inventory product. The worker always recalculates
 * the current stock snapshot, rather than replaying an old quantity delta.
 */
final class ProcessWooCommerceStockSyncQueueUseCase {
    public function __construct(
        private readonly StockRepositoryInterface $stockRepo,
        private readonly AdjustStockUseCase $adjustStock,
        private readonly LoggerInterface $log,
    ) {}

    public function execute(int $limit = 20): int {
        $jobs = $this->stockRepo->claimWooCommerceSyncBatch($limit);
        $completed = 0;

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $productId = (int) ($job['product_id'] ?? 0);
            $attempts = (int) ($job['attempts'] ?? 1);
            $generation = (string) ($job['generation'] ?? '');
            $claimToken = (string) ($job['claim_token'] ?? '');

            if ($id < 1 || $productId < 1 || $generation === '' || $claimToken === '') continue;

            try {
                // This recalculates aggregate stock and writes an idempotent snapshot.
                // A newer enqueue changes generation, so completion cannot erase it.
                $this->adjustStock->syncProductToWooCommerce($productId);
                $this->stockRepo->completeWooCommerceSync($id, $generation, $claimToken);
                $completed++;
            } catch (\Throwable $error) {
                try {
                    $this->stockRepo->retryWooCommerceSync($id, $generation, $claimToken, $attempts, get_class($error));
                } catch (\Throwable $queueError) {
                    $this->log->critical('WooCommerce stock sync retry could not be persisted.', [
                        'product_id' => $productId,
                        'error_class' => get_class($queueError),
                    ]);
                }
                $this->log->error('WooCommerce stock sync failed; inspect Sync Health for retry or review status.', [
                    'product_id' => $productId,
                    'attempt' => $attempts,
                    'error_class' => get_class($error),
                ]);
            }
        }

        return $completed;
    }
}
