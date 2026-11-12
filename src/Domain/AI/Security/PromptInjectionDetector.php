<?php
declare(strict_types=1);
namespace JunctionStore\Domain\AI\Security;
use JunctionStore\Domain\AI\Exception\PromptInjectionDetected;
use JunctionStore\Domain\AI\Exception\PromptInjectionException;
final class PromptInjectionDetector {
    private const P=['/ignore\s+(all\s+)?(previous|prior|above)\s+(instructions?|rules?)/i','/forget\s+(everything|all|your\s+instructions?)/i','/you\s+are\s+now\s+(?!dukaan)/i','/act\s+as\s+(a\s+)?(different|new|another|real)/i','/pretend\s+(to\s+be|you\s+are)/i','/\bDAN\b/','/developer\s+mode/i','/jailbreak/i','/no\s+restrictions?/i','/repeat\s+(your|the)\s+system\s+prompt/i','/reveal\s+(your|the)\s+(instructions?|api\s+key)/i','/```\s*system/i','/\[SYSTEM\]/i','/pahle\s+wale\s+ignore/i','/naye\s+hukum/i'];
    public function assertSafe(string $m):void { $n=$this->normalize($m); foreach(self::P as $p){if(preg_match($p,$n))throw new PromptInjectionException($p);} }
    public function isSuspicious(string $m):bool { try{$this->assertSafe($m);return false;}catch(PromptInjectionException){return true;} }
    private function normalize(string $i):string { $i=preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u','',$i)??$i; $i=preg_replace('/\s+/',' ',$i)??$i; return strtr(strtolower(trim($i)),['3'=>'e','0'=>'o','1'=>'i','@'=>'a','$'=>'s']); }
}
