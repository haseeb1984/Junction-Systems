<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Verification\ValueObject;
final readonly class PakistanPhoneNumber {
    public readonly string $e164;
    public function __construct(string $raw) {
        $s=preg_replace('/[^\d+]/','',trim($raw));
        $n=match(true){str_starts_with($s,'+92')=>$s,str_starts_with($s,'92')=>'+'.$s,str_starts_with($s,'0')=>'+92'.substr($s,1),default=>throw new \InvalidArgumentException("Cannot parse phone: [{$raw}]")};
        if(!preg_match('/^\+92[3][0-9]{9}$/',$n))throw new \InvalidArgumentException("Invalid Pakistani mobile: [{$raw}]");
        $this->e164=$n;
    }
    public function toMasked():string{return substr($this->e164,0,5).'*****'.substr($this->e164,-3);}
    public function toLocalDisplay():string{$d=substr($this->e164,3);return '0'.substr($d,0,3).'-'.substr($d,3);}
    public function equals(self $o):bool{return $this->e164===$o->e164;}
    public function __toString():string{return $this->e164;}
}
