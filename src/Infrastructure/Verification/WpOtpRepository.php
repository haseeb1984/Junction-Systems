<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Verification;
use PkStore\Domain\Verification\Entity\OtpAttempt;
use PkStore\Domain\Verification\Repository\OtpRepositoryInterface;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
final class WpOtpRepository implements OtpRepositoryInterface {
    public function __construct(private readonly \wpdb $db){}
    private function t():string{return $this->db->prefix.'pkstore_otp_attempts';}
    public function save(OtpAttempt $a):void{$r=$this->db->insert($this->t(),['id'=>$a->id,'phone_e164'=>$a->phone->e164,'hashed_code'=>$a->getHashedCode(),'order_id'=>$a->orderId,'is_verified'=>0,'attempt_count'=>0,'created_at'=>$a->createdAt->format('Y-m-d H:i:s'),'expires_at'=>$a->expiresAt->format('Y-m-d H:i:s')]);if($r===false)throw new \RuntimeException('Failed to save OTP: '.$this->db->last_error);}
    public function update(OtpAttempt $a):void{$prev=$a->getAttemptCount()-1;$this->db->query($this->db->prepare("UPDATE {$this->t()} SET is_verified=%d,attempt_count=%d WHERE id=%s AND attempt_count=%d AND is_verified=0",$a->getIsVerified()?1:0,$a->getAttemptCount(),$a->id,$prev));if($this->db->rows_affected===0)throw new \RuntimeException("OTP update conflict on [{$a->id}].");}
    public function findActiveByPhoneAndOrder(PakistanPhoneNumber $p,string $oid):?OtpAttempt{$r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE phone_e164=%s AND order_id=%s AND is_verified=0 AND expires_at>NOW() ORDER BY created_at DESC LIMIT 1",$p->e164,$oid),ARRAY_A);if($r===null)return null;return OtpAttempt::reconstitute($r['id'],new PakistanPhoneNumber($r['phone_e164']),$r['hashed_code'],$r['order_id'],new \DateTimeImmutable($r['created_at']),new \DateTimeImmutable($r['expires_at']),(int)$r['attempt_count'],(bool)$r['is_verified']);}
    public function countRecentSendsByPhone(PakistanPhoneNumber $p,\DateTimeImmutable $since):int{return(int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t()} WHERE phone_e164=%s AND created_at>=%s",$p->e164,$since->format('Y-m-d H:i:s')));}
    public function consumeVerifiedAttempt(string $attemptId, PakistanPhoneNumber $phone, string $sessionRef): bool {
        $result = $this->db->query($this->db->prepare(
            "UPDATE {$this->t()} SET consumed_at=%s WHERE id=%s AND phone_e164=%s AND order_id=%s AND is_verified=1 AND consumed_at IS NULL AND expires_at>NOW()",
            current_time('mysql'),
            $attemptId,
            $phone->e164,
            $sessionRef
        ));
        if ($result === false) {
            throw new \RuntimeException('Unable to consume verified COD OTP proof.');
        }
        return $this->db->rows_affected === 1;
    }

    public function bindConsumedAttemptToOrder(string $attemptId, string $sessionRef, string $orderId): bool {
        $result = $this->db->query($this->db->prepare(
            "UPDATE {$this->t()} SET consumed_order_id=%s WHERE id=%s AND order_id=%s AND consumed_at IS NOT NULL AND consumed_order_id IS NULL",
            $orderId,
            $attemptId,
            $sessionRef
        ));
        if ($result === false) {
            throw new \RuntimeException('Unable to bind consumed COD OTP proof to the order.');
        }
        return $this->db->rows_affected === 1;
    }

    public function invalidatePrevious(PakistanPhoneNumber $p,string $oid):void{$this->db->query($this->db->prepare("UPDATE {$this->t()} SET expires_at=%s WHERE phone_e164=%s AND order_id=%s AND is_verified=0",(new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s'),$p->e164,$oid));}
}
