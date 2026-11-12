<?php
declare(strict_types=1);
namespace JunctionStore\Application\Affiliate;
use JunctionStore\Domain\Affiliate\Entity\Commission;
use JunctionStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface, CommissionRepositoryInterface};
use JunctionStore\Domain\Affiliate\ValueObject\AffiliateCode;
use Psr\Log\LoggerInterface;
final class RecordCommissionUseCase {
    public function __construct(private readonly AffiliateRepositoryInterface $affiliateRepo, private readonly CommissionRepositoryInterface $commissionRepo, private readonly ProcessPayoutUseCase $payoutUseCase, private readonly LoggerInterface $log) {}
    public function execute(int $orderId, int $orderTotalPkr, ?string $affiliateCode): void {
        if ($affiliateCode === null) return;
        if ($this->commissionRepo->findByOrderId($orderId) !== null) return;
        try { $code = new AffiliateCode($affiliateCode); } catch (\InvalidArgumentException) { return; }
        $a = $this->affiliateRepo->findByCode($code);
        if ($a === null || !$a->isActive()) return;
        $commissionPkr = $a->commissionRate->calculate($orderTotalPkr);
        if ($commissionPkr <= 0) return;
        $c = Commission::create($a->id, $orderId, $orderTotalPkr, $commissionPkr, $a->commissionRate->percentage);
        $this->commissionRepo->save($c);
        $a->creditCommission($commissionPkr);
        $this->affiliateRepo->update($a);
        $this->log->info('Commission recorded.', ['affiliate_id'=>$a->id,'order_id'=>$orderId,'commission_pkr'=>$commissionPkr]);
        if ($a->isEligibleForPayout()) { try { $this->payoutUseCase->execute($a->id); } catch (\Throwable $e) { $this->log->error('Auto payout failed.', ['error'=>$e->getMessage()]); } }
        TrackAffiliateClickUseCase::clearCookie();
    }
}
