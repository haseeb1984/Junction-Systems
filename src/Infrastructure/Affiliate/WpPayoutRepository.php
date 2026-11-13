<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Affiliate;
use PkStore\Domain\Affiliate\Entity\{Payout, PayoutStatus};
use PkStore\Domain\Affiliate\Repository\PayoutRepositoryInterface;
final class WpPayoutRepository implements PayoutRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_affiliate_payouts'; }
    public function save(Payout $p): int { $this->db->insert($this->t(),['affiliate_id'=>$p->affiliateId,'amount_pkr'=>$p->amountPkr,'payment_method'=>$p->paymentMethod,'payment_account'=>$p->paymentAccount,'status'=>$p->status->value,'transaction_id'=>$p->transactionId,'created_at'=>$p->createdAt->format('Y-m-d H:i:s')]); return (int)$this->db->insert_id; }
    public function updateStatus(int $id, string $status, ?string $txId=null): void { $d=['status'=>$status]; if($txId!==null)$d['transaction_id']=$txId; $this->db->update($this->t(),$d,['id'=>$id]); }
    public function findByAffiliate(int $affiliateId, int $limit=20): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_id=%d ORDER BY created_at DESC LIMIT %d",$affiliateId,$limit),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function findPending(): array { $rows=$this->db->get_results("SELECT * FROM {$this->t()} WHERE status='pending'",ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    private function h(array $r): Payout { return new Payout((int)$r['id'],(int)$r['affiliate_id'],(int)$r['amount_pkr'],$r['payment_method'],$r['payment_account'],PayoutStatus::from($r['status']),$r['transaction_id'],new \DateTimeImmutable($r['created_at'])); }
}
