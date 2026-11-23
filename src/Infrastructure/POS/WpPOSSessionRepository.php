<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\POS;
use PkStore\Domain\POS\Entity\{POSSession, SessionStatus};
use PkStore\Domain\POS\Repository\POSSessionRepositoryInterface;
final class WpPOSSessionRepository implements POSSessionRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_pos_sessions'; }
    public function save(POSSession $s): int {
        $this->db->insert($this->t(),['location_id'=>$s->locationId,'cashier_id'=>$s->cashierId,'opening_cash'=>$s->openingCash,'closing_cash'=>null,'total_sales_pkr'=>0,'total_cash_pkr'=>0,'total_card_pkr'=>0,'total_jazzcash'=>0,'total_easypaisa'=>0,'status'=>'open','opened_at'=>$s->openedAt->format('Y-m-d H:i:s'),'closed_at'=>null]);
        return (int)$this->db->insert_id;
    }
    public function update(POSSession $s): void {
        $this->db->update($this->t(),[
            'closing_cash'=>$s->getClosingCash(),
            'total_sales_pkr'=>$s->getTotalSalesPkr(),
            'total_cash_pkr'=>$s->getTotalCashPkr(),
            'total_card_pkr'=>$s->getTotalCardPkr(),
            'total_jazzcash'=>$s->getTotalJazzcashPkr(),
            'total_easypaisa'=>$s->getTotalEasypaisaPkr(),
            'status'=>$s->getStatus()->value,
            'closed_at'=>$s->getClosedAt()?$s->getClosedAt()->format('Y-m-d H:i:s'):null,
        ],['id'=>$s->id]);
    }
    public function recordSaleAtomic(int $sessionId, int $total, string $paymentMethod, ?array $paymentSplit = null): void {
        if ($total < 0) throw new \InvalidArgumentException('Sale total cannot be negative.');
        $amounts = ['cash'=>0,'card'=>0,'jazzcash'=>0,'easypaisa'=>0];
        if ($paymentSplit !== null) {
            foreach ($paymentSplit as $method=>$amount) {
                if (!array_key_exists((string)$method,$amounts) || (int)$amount < 0) throw new \InvalidArgumentException('Invalid split payment entry.');
                $amounts[$method] += (int)$amount;
            }
            if (array_sum($amounts) !== $total) throw new \InvalidArgumentException('Split payment amounts must equal the sale total.');
        } else {
            if (!array_key_exists($paymentMethod,$amounts)) throw new \InvalidArgumentException('Unsupported POS payment method.');
            $amounts[$paymentMethod] = $total;
        }
        $sql = $this->db->prepare("UPDATE {$this->t()} SET total_sales_pkr=total_sales_pkr+%d,total_cash_pkr=total_cash_pkr+%d,total_card_pkr=total_card_pkr+%d,total_jazzcash=total_jazzcash+%d,total_easypaisa=total_easypaisa+%d WHERE id=%d AND status='open'",
            $total,$amounts['cash'],$amounts['card'],$amounts['jazzcash'],$amounts['easypaisa'],$sessionId
        );
        $changed = $this->db->query($sql);
        if ($changed === false) throw new \RuntimeException('Unable to record POS session sale: '.$this->db->last_error);
        if ($changed === 0) {
            $stillOpen = (int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t()} WHERE id=%d AND status='open'", $sessionId));
            if ($stillOpen === 0) throw new \RuntimeException('POS session is closed or does not exist.');
        }
    }
    public function findById(int $id): ?POSSession {
        $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A);
        return $r?$this->h($r):null;
    }
    public function findOpenByLocation(int $locationId): ?POSSession {
        $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE location_id=%d AND status='open' LIMIT 1",$locationId),ARRAY_A);
        return $r?$this->h($r):null;
    }
    public function findRecent(int $locationId, int $limit=10): array {
        $r=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE location_id=%d ORDER BY opened_at DESC LIMIT %d",$locationId,$limit),ARRAY_A)?:[];
        return array_map(fn($r)=>$this->h($r),$r);
    }
    private function h(array $r): POSSession {
        return new POSSession((int)$r['id'],(int)$r['location_id'],(int)$r['cashier_id'],(int)$r['opening_cash'],$r['closing_cash']!==null?(int)$r['closing_cash']:null,(int)$r['total_sales_pkr'],(int)$r['total_cash_pkr'],(int)$r['total_card_pkr'],(int)$r['total_jazzcash'],(int)$r['total_easypaisa'],SessionStatus::from($r['status']),new \DateTimeImmutable($r['opened_at']),$r['closed_at']?new \DateTimeImmutable($r['closed_at']):null);
    }
}
