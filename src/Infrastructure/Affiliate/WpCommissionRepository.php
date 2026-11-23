<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Affiliate;
use PkStore\Domain\Affiliate\Entity\{Commission, CommissionStatus};
use PkStore\Domain\Affiliate\Repository\CommissionRepositoryInterface;
final class WpCommissionRepository implements CommissionRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_affiliate_commissions'; }
    public function save(Commission $c): int { $result=$this->db->insert($this->t(),['affiliate_id'=>$c->affiliateId,'order_id'=>$c->orderId,'order_total_pkr'=>$c->orderTotalPkr,'commission_pkr'=>$c->commissionPkr,'rate_percent'=>$c->ratePercent,'status'=>$c->status->value,'created_at'=>$c->createdAt->format('Y-m-d H:i:s')]); $id=(int)$this->db->insert_id; if($result===false||$id<1)throw new \RuntimeException('Unable to persist affiliate commission.'); return $id; }
    public function findByOrderId(int $orderId): ?Commission { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE order_id=%d",$orderId),ARRAY_A); if($this->db->last_error!=='')throw new \RuntimeException('Unable to check affiliate commission idempotency.'); return $r?$this->h($r):null; }
    public function findByAffiliate(int $affiliateId, int $limit=20): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_id=%d ORDER BY created_at DESC LIMIT %d",$affiliateId,$limit),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function markApproved(int $id): void { $this->db->update($this->t(),['status'=>'approved'],['id'=>$id]); }
    public function markPaid(int $id): void { $this->db->update($this->t(),['status'=>'paid'],['id'=>$id]); }
    private function h(array $r): Commission { return new Commission((int)$r['id'],(int)$r['affiliate_id'],(int)$r['order_id'],(int)$r['order_total_pkr'],(int)$r['commission_pkr'],(float)$r['rate_percent'],CommissionStatus::from($r['status']),new \DateTimeImmutable($r['created_at'])); }
}
