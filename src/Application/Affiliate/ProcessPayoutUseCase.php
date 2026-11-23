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

    private function payJazzCash(string $mobile, int $amountPkr, int $payoutId): bool {
        $mid=$this->settings->get('JAZZCASH_MERCHANT_ID'); $pass=$this->settings->get('JAZZCASH_PASSWORD'); $ik=$this->settings->get('JAZZCASH_INTEGRITY_KEY');
        if (!$mid||!$pass||!$ik) return false;
        $txn='PAFF'.date('YmdHis').$payoutId; $amt=str_pad((string)($amountPkr*100),12,'0',STR_PAD_LEFT); $dt=date('YmdHis');
        $pd=['pp_Version'=>'2.0','pp_TxnType'=>'OTC','pp_Language'=>'EN','pp_MerchantID'=>$mid,'pp_Password'=>$pass,'pp_TxnRefNo'=>$txn,'pp_Amount'=>$amt,'pp_TxnCurrency'=>'PKR','pp_TxnDateTime'=>$dt,'pp_BillReference'=>'AFF-'.$payoutId,'pp_Description'=>'Affiliate Payout','pp_MobileNumber'=>$mobile];
        ksort($pd); $hs=$ik.'&'.implode('&',array_values($pd)); $pd['pp_SecureHash']=strtoupper(hash_hmac('sha256',$hs,$ik));
        $isSandbox=$this->settings->get('JAZZCASH_SANDBOX','0')==='1';
        $r=wp_remote_post(($isSandbox ? 'https://sandbox.jazzcash.com.pk' : 'https://payments.jazzcash.com.pk') . '/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction', ['timeout'=>15,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($pd)]);
        if (is_wp_error($r)) return false;
        $b=json_decode(wp_remote_retrieve_body($r),true);
        return ($b['pp_ResponseCode']??'')=== '000';
    }
}
