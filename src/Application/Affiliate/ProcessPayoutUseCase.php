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
        $a = $this->affiliateRepo->findById($affiliateId);
        if ($a === null) throw new \RuntimeException("Affiliate [{$affiliateId}] not found.");
        if (!$a->isEligibleForPayout()) throw new InsufficientBalanceException($a->getPendingPayoutPkr());
        $amount = $a->getPendingPayoutPkr();
        $payout = Payout::create($affiliateId, $amount, $a->paymentMethod, $a->paymentAccount);
        $payoutId = $this->payoutRepo->save($payout);
        $success = $a->paymentMethod === 'jazzcash' ? $this->payJazzCash($a->paymentAccount, $amount, $payoutId) : false;
        if ($success) {
            $a->recordPayout($amount);
            $this->affiliateRepo->update($a);
            $this->payoutRepo->updateStatus($payoutId, 'completed');
            $this->log->info('Affiliate payout completed.', ['affiliate_id'=>$affiliateId,'amount'=>$amount]);
            wp_mail($a->email, '[PK Store] Payout Sent!', sprintf("Dear %s,\n\nPKR %s has been sent to your %s account (%s).\n\nThank you!", $a->name, number_format($amount), strtoupper($a->paymentMethod), $a->paymentAccount));
        } else {
            $this->payoutRepo->updateStatus($payoutId, 'failed');
            wp_mail(get_option('admin_email'), '[PK Store] Affiliate Payout FAILED', "Payout of PKR {$amount} to affiliate #{$affiliateId} ({$a->name}) failed. Process manually to {$a->paymentMethod}: {$a->paymentAccount}");
        }
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
