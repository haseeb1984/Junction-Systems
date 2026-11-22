<?php
declare(strict_types=1);
namespace PkStore\Application\AI;
use PkStore\Domain\AI\{AiProviderInterface,ChatRepositoryInterface};
use PkStore\Domain\AI\Exception\PromptInjectionException;
use PkStore\Domain\AI\Security\PromptInjectionDetector;
use PkStore\Domain\AI\ValueObject\{ChatMessage,ChatRequest,ChatResponse};
use Psr\Log\LoggerInterface;
final class HandleAiChatUseCase {
    private const HIST=10; private const MAXCHARS=600;
    public function __construct(private readonly AiProviderInterface $ai, private readonly ChatRepositoryInterface $chat, private readonly ProductContextService $ctx, private readonly PromptInjectionDetector $det, private readonly LoggerInterface $log) {}
    public function execute(string $uuid, string $msg):ChatResponse {
        if(!$this->validUuid($uuid))throw new \InvalidArgumentException('Invalid session UUID.');
        $m=$this->sanitize($msg); $this->det->assertSafe($m);
        $sys=ChatMessage::system($this->ctx->buildSystemPrompt($m));
        $hist=$this->chat->getHistory($uuid,self::HIST);
        $user=ChatMessage::user($m);
        $msgs=$this->budget(array_merge([$sys],$hist,[$user]));
        $resp=$this->ai->chat(new ChatRequest($msgs,512,0.3,false,'gemini-1.5-flash'));
        $this->chat->appendMessage($uuid,$user);
        $this->chat->appendMessage($uuid,ChatMessage::assistant($resp->content));
        return $resp;
    }
    public function executeStream(string $uuid, string $msg):\Generator {
        if(!$this->validUuid($uuid))throw new \InvalidArgumentException('Invalid session UUID.');
        $m=$this->sanitize($msg); $this->det->assertSafe($m);
        $sys=ChatMessage::system($this->ctx->buildSystemPrompt($m));
        $hist=$this->chat->getHistory($uuid,self::HIST);
        $user=ChatMessage::user($m);
        $msgs=$this->budget(array_merge([$sys],$hist,[$user]));
        $full='';
        foreach($this->ai->streamChat(new ChatRequest($msgs,512,0.3,true,'gemini-1.5-flash')) as $d){$full.=$d;yield $d;}
        $this->chat->appendMessage($uuid,$user);
        $this->chat->appendMessage($uuid,ChatMessage::assistant($full));
    }
    private function budget(array $msgs):array {
        $total=array_sum(array_map(fn($m)=>strlen($m->content),$msgs));
        if($total<=24000)return $msgs;
        $sys=$msgs[0]; $cur=end($msgs); $hist=array_slice($msgs,1,-1);
        while(!empty($hist)&&$total>24000){$r=array_shift($hist);$total-=strlen($r->content);}
        return array_merge([$sys],$hist,[$cur]);
    }
    private function sanitize(string $r):string{$c=wp_strip_all_tags($r);$c=preg_replace('/\\s+/',' ',$c)??$c;$c=trim($c);return function_exists('mb_substr')?mb_substr($c,0,self::MAXCHARS):substr($c,0,self::MAXCHARS);}
    private function validUuid(string $u):bool{return(bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$u);}
}
