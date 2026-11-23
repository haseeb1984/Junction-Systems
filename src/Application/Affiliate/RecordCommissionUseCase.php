<?php
declare(strict_types=1);
namespace PkStore\Application\Affiliate;
use PkStore\Domain\Affiliate\Entity\Commission;
use PkStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface, CommissionRepositoryInterface};
use PkStore\Domain\Affiliate\ValueObject\AffiliateCode;
use Psr\Log\LoggerInterface;
final class RecordCommissionUseCase {
    public function __construct(private readonly AffiliateRepositoryInterface $affiliateRepo, private readonly CommissionRepositoryInterface $commissionRepo, private readonly LoggerInterface $log) {}
    public function execute(int $orderId, int $orderTotalPkr, ?string $affiliateCode): void {
        if ($affiliateCode === null) return;
        if ($this->commissionRepo->findByOrderId($orderId) !== null) return;
        try { $code = new AffiliateCode($affiliateCode); } catch (\InvalidArgumentException) { return; }
        $a = $this->affiliateRepo->findByCode($code);
        if ($a === null || !$a->isActive()) return;
        $pendingBefore = $a->getPendingPayoutPkr();
        $commissionPkr = $a->commissionRate->calculate($orderTotalPkr);
        if ($commissionPkr <= 0) return;
        $c = Commission::create($a->id, $orderId, $orderTotalPkr, $commissionPkr, $a->commissionRate->percentage);
        $this->commissionRepo->save($c);
        $a->creditCommission($commissionPkr);
        $this->affiliateRepo->update($a);
        $this->log->info('Commission recorded.', ['affiliate_id'=>$a->id,'order_id'=>$orderId,'commission_pkr'=>$commissionPkr]);
        if ($pendingBefore < 1000 && $a->isEligibleForPayout()) {
            $message = sprintf(
                "Affiliate #%d (%s) has reached the payout threshold with PKR %s pending. Automated disbursement is disabled until a verified JazzCash/Easypaisa payout API contract is approved. Please review and process manually.",
                $a->id,
                $a->name,
                number_format($a->getPendingPayoutPkr())
            );
            $this->log->info('Affiliate reached payout threshold; manual review required.', [
                'affiliate_id' => $a->id,
                'pending_pkr' => $a->getPendingPayoutPkr(),
            ]);
            wp_mail(get_option('admin_email'), '[PK Store] Affiliate payout review required', $message);
        }
        TrackAffiliateClickUseCase::clearCookie();
    }
}
