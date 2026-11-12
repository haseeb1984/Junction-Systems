<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Http;
use JunctionStore\Application\AI\HandleAiChatUseCase;
use JunctionStore\Container\ServiceContainer;
use JunctionStore\Domain\AI\Exception\{AiProviderException,PromptInjectionException};
final class AiChatController {
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_action('rest_api_init',function(){$args=['session_uuid'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'],'message'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_textarea_field'],'_nonce'=>['required'=>true,'type'=>'string']];register_rest_route('pkstore/v1','/chat',['methods'=>'POST','callback'=>[$this,'handleChat'],'permission_callback'=>[$this,'nonce'],'args'=>$args]);register_rest_route('pkstore/v1','/chat/stream',['methods'=>'POST','callback'=>[$this,'handleStream'],'permission_callback'=>[$this,'nonce'],'args'=>$args]);});}
    public function nonce(\WP_REST_Request $r):bool{return(bool)wp_verify_nonce($r->get_param('_nonce'),'pkstore_ai_chat');}
    private function rl(string $u):bool{$k='pkstore_ai_rl_'.md5($u);$c=(int)get_transient($k);if($c>=20)return false;set_transient($k,$c+1,HOUR_IN_SECONDS);return true;}
    public function handleChat(\WP_REST_Request $r):\WP_REST_Response{
        $u=$r->get_param('session_uuid');$m=$r->get_param('message');
        if(!$this->rl($u))return new \WP_REST_Response(['success'=>false,'code'=>'rate_limited','message'=>'Too many questions. Please wait.'],429);
        try{$resp=$this->c->make(HandleAiChatUseCase::class)->execute($u,$m);return new \WP_REST_Response(['success'=>true,'reply'=>$resp->content,'meta'=>['tokens_used'=>$resp->totalTokensUsed(),'finish_reason'=>$resp->finishReason]],200);}
        catch(PromptInjectionException){return new \WP_REST_Response(['success'=>true,'reply'=>'میں صرف آپ کی شاپنگ میں مدد کر سکتا ہوں۔ How can I help you shop today?'],200);}
        catch(AiProviderException){return new \WP_REST_Response(['success'=>false,'code'=>'ai_error','message'=>'Assistant temporarily unavailable.'],503);}
    }
    public function handleStream(\WP_REST_Request $r):void{
        $u=$r->get_param('session_uuid');$m=$r->get_param('message');
        if(ob_get_level()>0)ob_end_clean();header('Content-Type: text/event-stream');header('Cache-Control: no-cache');header('X-Accel-Buffering: no');
        if(!$this->rl($u)){echo "data: ".json_encode(['error'=>'rate_limited'])."\n\ndata: [DONE]\n\n";flush();exit;}
        try{foreach($this->c->make(HandleAiChatUseCase::class)->executeStream($u,$m)as $d){echo "data: ".json_encode(['delta'=>$d])."\n\n";flush();}echo "data: [DONE]\n\n";flush();}
        catch(PromptInjectionException){echo "data: ".json_encode(['delta'=>'میں صرف آپ کی شاپنگ میں مدد کر سکتا ہوں۔'])."\n\ndata: [DONE]\n\n";flush();}
        catch(\Throwable){echo "data: [DONE]\n\n";flush();}
        exit;
    }
}
