<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Affiliate;
use PkStore\Domain\Affiliate\Entity\Affiliate;
use PkStore\Domain\Affiliate\Repository\AffiliateRepositoryInterface;
use PkStore\Domain\Affiliate\ValueObject\{AffiliateCode, CommissionRate};
use PkStore\Settings\SettingsManager;
final class WpAffiliateRepository implements AffiliateRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_affiliates'; }
    public function save(Affiliate $a): int {
        $inserted = $this->db->insert($this->t(), [
            'user_id'=>$a->userId,'name'=>$a->name,'email'=>$a->email,
            'affiliate_code'=>(string)$a->code,'commission_rate'=>$a->commissionRate->percentage,
            'total_earned_pkr'=>$a->getTotalEarnedPkr(),'total_paid_pkr'=>$a->getTotalPaidPkr(),
            'pending_pkr'=>$a->getPendingPayoutPkr(),'payment_method'=>$a->paymentMethod,
            'payment_account'=>'__encrypted__','status'=>$a->status,
            'created_at'=>$a->createdAt->format('Y-m-d H:i:s')
        ]);
        $id = (int) $this->db->insert_id;
        if ($inserted === false || $id < 1) {
            throw new \RuntimeException('Unable to persist affiliate account.');
        }
        if (!SettingsManager::getInstance()->setSecret('AFFILIATE_PAYMENT_ACCOUNT_' . $id, $a->paymentAccount)) {
            $this->db->delete($this->t(), ['id' => $id], ['%d']);
            throw new \RuntimeException('Unable to encrypt affiliate payout account.');
        }
        return $id;
    }
    public function update(Affiliate $a): void { $result=$this->db->update($this->t(), ['total_earned_pkr'=>$a->getTotalEarnedPkr(),'total_paid_pkr'=>$a->getTotalPaidPkr(),'pending_pkr'=>$a->getPendingPayoutPkr()], ['id'=>$a->id]); if($result===false||$result!==1)throw new \RuntimeException('Unable to persist affiliate balance changes.'); }
    public function findById(int $id): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A); return $r?$this->h($r):null; }
    public function findByCode(AffiliateCode $code): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_code=%s",$code->value),ARRAY_A); return $r?$this->h($r):null; }
    public function findByUserId(int $userId): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE user_id=%d",$userId),ARRAY_A); return $r?$this->h($r):null; }
    public function findByCodeForUpdate(AffiliateCode $code): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_code=%s FOR UPDATE",$code->value),ARRAY_A); if($this->db->last_error!=='')throw new \RuntimeException('Unable to lock affiliate account for commission accounting.'); return $r?$this->h($r):null; }

    public function findEligibleForPayout(): array { $rows=$this->db->get_results("SELECT * FROM {$this->t()} WHERE pending_pkr>=1000 AND status='active'",ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function findAll(int $limit=50, int $offset=0): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} ORDER BY created_at DESC LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function countAll(): int { return (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->t()}"); }
    private function h(array $r): Affiliate {
        $id = (int) $r['id'];
        $storedAccount = (string) ($r['payment_account'] ?? '');
        $accountKey = 'AFFILIATE_PAYMENT_ACCOUNT_' . $id;
        $settings = SettingsManager::getInstance();
        if ($storedAccount === '__encrypted__') {
            $paymentAccount = $settings->get($accountKey, '');
        } elseif ($storedAccount !== '') {
            // Migrate legacy plaintext accounts to authenticated encryption.
            $encrypted = $settings->setSecret($accountKey, $storedAccount)
                || hash_equals($storedAccount, $settings->get($accountKey, ''));
            $updated = $encrypted
                ? $this->db->update($this->t(), ['payment_account'=>'__encrypted__'], ['id'=>$id])
                : false;
            if ($updated !== 1) {
                error_log('[PK Store] Affiliate payout account encryption migration failed for affiliate ' . $id . '.');
                $paymentAccount = '';
            } else {
                $paymentAccount = $storedAccount;
            }
        } else {
            $paymentAccount = '';
        }
        return Affiliate::reconstitute($id,(int)$r['user_id'],$r['name'],$r['email'],
            new AffiliateCode($r['affiliate_code']),new CommissionRate((float)$r['commission_rate']),
            (int)$r['total_earned_pkr'],(int)$r['total_paid_pkr'],(int)$r['pending_pkr'],
            $r['payment_method'],$paymentAccount,$r['status'],new \DateTimeImmutable($r['created_at']));
    }
}
