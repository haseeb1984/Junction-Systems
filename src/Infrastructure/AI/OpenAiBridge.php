<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\AI;
use JunctionStore\Domain\AI\AiProviderInterface;
use JunctionStore\Domain\AI\Exception\AiProviderException;
use JunctionStore\Domain\AI\ValueObject\{ChatRequest,ChatResponse};
use JunctionStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
final class OpenAiBridge implements AiProviderInterface {
    private const API='https://api.openai.com/v1';
    public function __construct(private readonly LoggerInterface $log, private readonly SettingsManager $cfg){}
    public function getProviderName():string{return 'OpenAI';}
    public function getModelName():string{return 'gpt-4o-mini';}
    public function estimateTokens(string $t):int{return(int)ceil(mb_strlen($t)/4);}
    public function chat(ChatRequest $req):ChatResponse {
        $key=$this->cfg->get('OPENAI_API_KEY');
        if(!$key)throw new AiProviderException('OpenAI key not configured. Go to Junction Store > Setup Wizard > AI Chat.');
        $r=wp_remote_post(self::API.'/chat/completions',['timeout'=>25,'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$key],'body'=>wp_json_encode(['model'=>$req->model,'messages'=>array_map(fn($m)=>$m->toArray(),$req->messages),'max_tokens'=>$req->maxTokens,'temperature'=>$req->temperature,'stream'=>false])]);
        if(is_wp_error($r))throw new AiProviderException('OpenAI failed: '.$r->get_error_message());
        $s=wp_remote_retrieve_response_code($r);$b=json_decode(wp_remote_retrieve_body($r),true);
        if($s!==200)throw new AiProviderException("OpenAI [{$s}]: ".($b['error']['message']??'Unknown'));
        return new ChatResponse(trim($b['choices'][0]['message']['content']??''),(int)($b['usage']['prompt_tokens']??0),(int)($b['usage']['completion_tokens']??0),$b['choices'][0]['finish_reason']??'unknown',$b['model']??'gpt-4o-mini');
    }
    public function streamChat(ChatRequest $req):\Generator {
        $key=$this->cfg->get('OPENAI_API_KEY');if(!$key)throw new AiProviderException('OpenAI key not configured.');
        $buf='';$curl=curl_init(self::API.'/chat/completions');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>wp_json_encode(['model'=>$req->model,'messages'=>array_map(fn($m)=>$m->toArray(),$req->messages),'max_tokens'=>$req->maxTokens,'temperature'=>$req->temperature,'stream'=>true]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_TIMEOUT=>25,CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$buf){$buf.=$chunk;return strlen($chunk);}]);
        curl_exec($curl);$err=curl_error($curl);curl_close($curl);
        if($err)throw new AiProviderException("Stream error: {$err}");
        foreach(explode("\n",$buf)as $line){if(!str_starts_with($line,'data: '))continue;$d=substr($line,6);if($d==='[DONE]')break;$p=json_decode($d,true);$delta=$p['choices'][0]['delta']['content']??'';if($delta!=='')yield $delta;}
    }
}
