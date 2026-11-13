<?php
declare(strict_types=1);
namespace PkStore\Application\POS;
use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;
use Psr\Log\LoggerInterface;
final class SyncPOSQueueUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly ProcessPOSSaleUseCase $processSale,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(): array {
        $pending = $this->orderRepo->findPending();
        $results = ['synced'=>0, 'failed'=>0, 'errors'=>[]];
        foreach ($pending as $order) {
            try {
                $order->markSynced();
                $this->orderRepo->update($order);
                $results['synced']++;
            } catch (\Throwable $e) {
                $order->markFailed();
                $this->orderRepo->update($order);
                $results['failed']++;
                $results['errors'][] = "Order {$order->orderRef}: {$e->getMessage()}";
                $this->log->error('POS sync failed.', ['order_ref'=>$order->orderRef,'error'=>$e->getMessage()]);
            }
        }
        return $results;
    }
}
