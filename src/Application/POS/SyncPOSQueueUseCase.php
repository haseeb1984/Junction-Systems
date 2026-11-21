<?php
declare(strict_types=1);
namespace PkStore\Application\POS;

use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Safe placeholder until a real WooCommerce order adapter is implemented.
 *
 * Never mark a POS order as synced unless a WooCommerce order was created and
 * its ID was persisted. The previous implementation only changed the status.
 */
final class SyncPOSQueueUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly ProcessPOSSaleUseCase $processSale,
        private readonly LoggerInterface $log,
    ) {}

    public function execute(): array {
        $pending = $this->orderRepo->findPending();
        $count = count($pending);

        if ($count === 0) {
            return ['synced' => 0, 'failed' => 0, 'errors' => []];
        }

        $this->log->warning('POS to WooCommerce sync is unavailable; pending orders were left unchanged.', [
            'pending_count' => $count,
        ]);

        return [
            'synced' => 0,
            'failed' => $count,
            'errors' => ['WooCommerce order creation is not implemented. POS orders remain pending and were not falsely marked as synced.'],
        ];
    }
}
