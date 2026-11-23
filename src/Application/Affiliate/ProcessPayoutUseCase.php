<?php
declare(strict_types=1);
namespace PkStore\Application\Affiliate;
final class ProcessPayoutUseCase {
    public function execute(int $affiliateId): void {
        throw new \RuntimeException(
            'Automated affiliate disbursement is disabled until a verified JazzCash/Easypaisa payout API contract is configured and approved.'
        );
    }

}
