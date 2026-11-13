<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Affiliate;
use PkStore\Domain\Affiliate\Entity\Affiliate;
use PkStore\Domain\Affiliate\Repository\AffiliateRepositoryInterface;
use PkStore\Domain\Affiliate\ValueObject\{AffiliateCode, CommissionRate};
final class WpAffiliateRepository implements AffiliateRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_affiliates'; }
    public function save(Affiliate $a): int {
        $this->db->insert($this->t(), ['user_id'=>$a->userId,'name'=>$a->name,'email'=>$a->email,'affiliate_code'=>(string)$a->code,'commission_rate'=>$a->commissionRate->percentage,'total_earned_pkr'=>$a->getTotalEarnedPkr(),'total_paid_pkr'=>$a->getTotalPaidPkr(),'pending_pkr'=>$a->getPendingPayoutPkr(),'payment_method'=>$a->paymentMethod,'payment_account'=>$a->paymentAccount,'status'=>$a->status,'created_at'=>$a->createdAt->format('Y-m-d H:i:s')]);
        return (int)$this->db->insert_id;
    }
    public function update(Affiliate $a): void { $this->db->update($this->t(), ['total_earned_pkr'=>$a->getTotalEarnedPkr(),'total_paid_pkr'=>$a->getTotalPaidPkr(),'pending_pkr'=>$a->getPendingPayoutPkr()], ['id'=>$a->id]); }
    public function findById(int $id): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A); return $r?$this->h($r):null; }
    public function findByCode(AffiliateCode $code): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_code=%s",$code->value),ARRAY_A); return $r?$this->h($r):null; }
    public function findByUserId(int $userId): ?Affiliate { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE user_id=%d",$userId),ARRAY_A); return $r?$this->h($r):null; }
    public function findEligibleForPayout(): array { $rows=$this->db->get_results("SELECT * FROM {$this->t()} WHERE pending_pkr>=1000 AND status='active'",ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function findAll(int $limit=50, int $offset=0): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} ORDER BY created_at DESC LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function countAll(): int { return (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->t()}"); }
    private function h(array $r): Affiliate { return Affiliate::reconstitute((int)$r['id'],(int)$r['user_id'],$r['name'],$r['email'],new AffiliateCode($r['affiliate_code']),new CommissionRate((float)$r['commission_rate']),(int)$r['total_earned_pkr'],(int)$r['total_paid_pkr'],(int)$r['pending_pkr'],$r['payment_method'],$r['payment_account'],$r['status'],new \DateTimeImmutable($r['created_at'])); }
}
