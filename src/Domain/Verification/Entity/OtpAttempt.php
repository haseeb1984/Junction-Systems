<?php
declare(strict_types=1);
namespace PkStore\Domain\Verification\Entity;
use DateTimeImmutable;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
final class OtpAttempt {
    private const MAX=3; private const MINS=5;
    private bool $isVerified=false; private int $attemptCount=0;
    public function __construct(public readonly string $id, public readonly PakistanPhoneNumber $phone, private readonly string $hashedCode, public readonly string $orderId, public readonly DateTimeImmutable $createdAt, public readonly DateTimeImmutable $expiresAt) {}
    public static function create(PakistanPhoneNumber $phone, string $code, string $orderId):self {
        $now=new DateTimeImmutable(); $exp=$now->modify('+'.self::MINS.' minutes');
        $h=password_hash($code,PASSWORD_BCRYPT,['cost'=>10]); if($h===false)throw new \RuntimeException('OTP hashing failed.');
        return new self(self::uuid(),$phone,$h,$orderId,$now,$exp);
    }
    public static function reconstitute(string $id, PakistanPhoneNumber $phone, string $hashedCode, string $orderId, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt, int $attemptCount, bool $isVerified):self {
        $i=new self($id,$phone,$hashedCode,$orderId,$createdAt,$expiresAt); $i->attemptCount=$attemptCount; $i->isVerified=$isVerified; return $i;
    }
    public function isExpired():bool{return new DateTimeImmutable()>$this->expiresAt;}
    public function isExhausted():bool{return $this->attemptCount>=self::MAX;}
    public function isAlreadyVerified():bool{return $this->isVerified;}
    public function getAttemptCount():int{return $this->attemptCount;}
    public function getIsVerified():bool{return $this->isVerified;}
    public function getRemainingAttempts():int{return max(0,self::MAX-$this->attemptCount);}
    public function getHashedCode():string{return $this->hashedCode;}
    public function verify(string $code):bool {
        if($this->isExpired())throw new \LogicException('Cannot verify expired OTP.');
        if($this->isExhausted())throw new \LogicException('Cannot verify exhausted OTP.');
        if($this->isAlreadyVerified())throw new \LogicException('OTP already consumed.');
        $this->attemptCount++;
        $ok=password_verify($code,$this->hashedCode);
        if($ok)$this->isVerified=true;
        return $ok;
    }
    private static function uuid():string{$d=random_bytes(16);$d[6]=chr(ord($d[6])&0x0f|0x40);$d[8]=chr(ord($d[8])&0x3f|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
