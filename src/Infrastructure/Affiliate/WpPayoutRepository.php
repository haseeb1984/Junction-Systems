<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Affiliate;
use PkStore\Domain\Affiliate\Entity\{Payout, PayoutStatus};
use PkStore\Settings\SettingsManager;
use PkStore\Domain\Affiliate\Repository\PayoutRepositoryInterface;
final class WpPayoutRepository implements PayoutRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_affiliate_payouts'; }
    public function save(Payout $p): int {
        $inserted = $this->db->insert($this->t(), [
            'affiliate_id'=>$p->affiliateId,'amount_pkr'=>$p->amountPkr,
            'payment_method'=>$p->paymentMethod,'payment_account'=>'__encrypted__',
            'status'=>$p->status->value,'transaction_id'=>$p->transactionId,
            'created_at'=>$p->createdAt->format('Y-m-d H:i:s')
        ]);
        $id = (int) $this->db->insert_id;
        if ($inserted === false || $id < 1) {
            throw new \RuntimeException('Unable to persist affiliate payout.');
        }
        if (!SettingsManager::getInstance()->setSecret('AFFILIATE_PAYOUT_ACCOUNT_' . $id, $p->paymentAccount)) {
            $this->db->delete($this->t(), ['id'=>$id], ['%d']);
            throw new \RuntimeException('Unable to encrypt affiliate payout account.');
        }
        return $id;
    }
    public function updateStatus(int $id, string $status, ?string $txId=null): void { $d=['status'=>$status]; if($txId!==null)$d['transaction_id']=$txId; $this->db->update($this->t(),$d,['id'=>$id]); }
    public function findByAffiliate(int $affiliateId, int $limit=20): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_id=%d ORDER BY created_at DESC LIMIT %d",$affiliateId,$limit),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function findPending(): array { $rows=$this->db->get_results("SELECT * FROM {$this->t()} WHERE status='pending'",ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    private function h(array $r): Payout {
        $id = (int) $r['id'];
        $storedAccount = (string) ($r['payment_account'] ?? '');
        $accountKey = 'AFFILIATE_PAYOUT_ACCOUNT_' . $id;
        $settings = SettingsManager::getInstance();
        if ($storedAccount === '__encrypted__') {
            $paymentAccount = $settings->get($accountKey, '');
        } elseif ($storedAccount !== '') {
            $encrypted = $settings->setSecret($accountKey, $storedAccount)
                || hash_equals($storedAccount, $settings->get($accountKey, ''));
            $updated = $encrypted
                ? $this->db->update($this->t(), ['payment_account'=>'__encrypted__'], ['id'=>$id])
                : false;
            if ($updated !== 1) {
                error_log('[PK Store] Affiliate payout destination encryption migration failed for payout ' . $id . '.');
                $paymentAccount = '';
            } else {
                $paymentAccount = $storedAccount;
            }
        } else {
            $paymentAccount = '';
        }
        return new Payout($id,(int)$r['affiliate_id'],(int)$r['amount_pkr'],
            $r['payment_method'],$paymentAccount,PayoutStatus::from($r['status']),
            $r['transaction_id'],new \DateTimeImmutable($r['created_at']));
    }
}
