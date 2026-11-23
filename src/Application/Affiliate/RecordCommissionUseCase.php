<?php
declare(strict_types=1);
namespace PkStore\Application\Affiliate;
use PkStore\Domain\Affiliate\Entity\Commission;
use PkStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface, CommissionRepositoryInterface};
use PkStore\Domain\Affiliate\ValueObject\AffiliateCode;
use Psr\Log\LoggerInterface;

final class RecordCommissionUseCase {
    public function __construct(
        private readonly AffiliateRepositoryInterface $affiliateRepo,
        private readonly CommissionRepositoryInterface $commissionRepo,
        private readonly LoggerInterface $log
    ) {}

    public function execute(int $orderId, int $orderTotalPkr, ?string $affiliateCode): void {
        if ($affiliateCode === null || $orderId < 1 || $orderTotalPkr < 1) return;
        try {
            $code = new AffiliateCode($affiliateCode);
        } catch (\InvalidArgumentException) {
            return;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('Database is unavailable for affiliate commission accounting.');
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start affiliate commission transaction.');
        }

        $affiliate = null;
        $pendingBefore = 0;
        $commissionPkr = 0;
        try {
            // Lock the affiliate balance before reading/updating its totals.
            $affiliate = $this->affiliateRepo->findByCodeForUpdate($code);
            if ($affiliate === null || !$affiliate->isActive()) {
                $wpdb->query('COMMIT');
                return;
            }
            if ($this->commissionRepo->findByOrderId($orderId) !== null) {
                $wpdb->query('COMMIT');
                return;
            }

            $pendingBefore = $affiliate->getPendingPayoutPkr();
            $commissionPkr = $affiliate->commissionRate->calculate($orderTotalPkr);
            if ($commissionPkr <= 0) {
                $wpdb->query('COMMIT');
                return;
            }

            $commission = Commission::create(
                $affiliate->id,
                $orderId,
                $orderTotalPkr,
                $commissionPkr,
                $affiliate->commissionRate->percentage
            );
            $this->commissionRepo->save($commission);
            $affiliate->creditCommission($commissionPkr);
            $this->affiliateRepo->update($affiliate);

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit affiliate commission transaction.');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            // Duplicate lifecycle hooks can race on the unique order_id index.
            // If another transaction committed this exact order, treat it as
            // idempotent success; otherwise propagate the real persistence error.
            if ($this->commissionRepo->findByOrderId($orderId) !== null) return;
            throw $error;
        }

        $this->log->info('Commission recorded.', [
            'affiliate_id' => $affiliate->id,
            'order_id' => $orderId,
            'commission_pkr' => $commissionPkr,
        ]);

        if ($pendingBefore < 1000 && $affiliate->isEligibleForPayout()) {
            $message = sprintf(
                "Affiliate #%d (%s) has reached the payout threshold with PKR %s pending. Automated disbursement is disabled until a verified JazzCash/Easypaisa payout API contract is approved. Please review and process manually.",
                $affiliate->id,
                $affiliate->name,
                number_format($affiliate->getPendingPayoutPkr())
            );
            $this->log->info('Affiliate reached payout threshold; manual review required.', [
                'affiliate_id' => $affiliate->id,
                'pending_pkr' => $affiliate->getPendingPayoutPkr(),
            ]);
            wp_mail(get_option('admin_email'), '[PK Store] Affiliate payout review required', $message);
        }
        TrackAffiliateClickUseCase::clearCookie();
    }
}
