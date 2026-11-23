<?php
declare(strict_types=1);
namespace PkStore\Application\Affiliate;
use PkStore\Domain\Affiliate\Entity\Payout;
use PkStore\Domain\Affiliate\Exception\InsufficientBalanceException;
use PkStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface, PayoutRepositoryInterface};
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
final class ProcessPayoutUseCase {
    public function __construct(private readonly AffiliateRepositoryInterface $affiliateRepo, private readonly PayoutRepositoryInterface $payoutRepo, private readonly SettingsManager $settings, private readonly LoggerInterface $log) {}
    public function execute(int $affiliateId): void {
        throw new \RuntimeException(
            'Automated affiliate disbursement is disabled until a verified JazzCash/Easypaisa payout API contract is configured and approved.'
        );
    }

}
