<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\POS;
use PkStore\Domain\POS\Entity\{POSSession, SessionStatus};
use PkStore\Domain\POS\Repository\POSSessionRepositoryInterface;
final class WpPOSSessionRepository implements POSSessionRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_pos_sessions'; }
    public function save(POSSession $s): int {
        $inserted = $this->db->insert($this->t(),['location_id'=>$s->locationId,'cashier_id'=>$s->cashierId,'opening_cash'=>$s->openingCash,'closing_cash'=>null,'total_sales_pkr'=>0,'total_cash_pkr'=>0,'total_card_pkr'=>0,'total_jazzcash'=>0,'total_easypaisa'=>0,'status'=>'open','opened_at'=>$s->openedAt->format('Y-m-d H:i:s'),'closed_at'=>null]);
        if ($inserted === false || (int)$this->db->insert_id < 1) {
            throw new \RuntimeException('Unable to persist the POS session.');
        }
        return (int)$this->db->insert_id;
    }
    public function update(POSSession $s): void {
        $updated = $this->db->update($this->t(), [
            'closing_cash' => $s->getClosingCash(),
            'total_sales_pkr' => $s->getTotalSalesPkr(),
            'total_cash_pkr' => $s->getTotalCashPkr(),
            'total_card_pkr' => $s->getTotalCardPkr(),
            'total_jazzcash' => $s->getTotalJazzcashPkr(),
            'total_easypaisa' => $s->getTotalEasypaisaPkr(),
            'status' => $s->getStatus()->value,
            'closed_at' => $s->getClosedAt()?->format('Y-m-d H:i:s'),
        ], ['id' => $s->id]);
        if ($updated === false) {
            throw new \RuntimeException('Unable to persist POS session totals.');
        }
    }
    public function findById(int $id): ?POSSession { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d FOR UPDATE",$id),ARRAY_A); return $r?$this->h($r):null; }
    public function findOpenByLocation(int $locationId): ?POSSession { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE location_id=%d AND status='open' LIMIT 1",$locationId),ARRAY_A); return $r?$this->h($r):null; }
    public function findRecent(int $locationId, int $limit=10): array { $r=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE location_id=%d ORDER BY opened_at DESC LIMIT %d",$locationId,$limit),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$r); }
    private function h(array $r): POSSession { return new POSSession((int)$r['id'],(int)$r['location_id'],(int)$r['cashier_id'],(int)$r['opening_cash'],$r['closing_cash']?(int)$r['closing_cash']:null,(int)$r['total_sales_pkr'],(int)$r['total_cash_pkr'],(int)$r['total_card_pkr'],(int)$r['total_jazzcash'],(int)$r['total_easypaisa'],SessionStatus::from($r['status']),new \DateTimeImmutable($r['opened_at']),$r['closed_at']?new \DateTimeImmutable($r['closed_at']):null); }
}
