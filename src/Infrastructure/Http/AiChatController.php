<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Http;

use PkStore\Application\AI\HandleAiChatUseCase;
use PkStore\Container\ServiceContainer;
use PkStore\Domain\AI\Exception\{AiProviderException,PromptInjectionException};

final class AiChatController {
    public function __construct(private readonly ServiceContainer $c) {}

    public function register(): void {
        add_action('rest_api_init', function (): void {
            $args = [
                'session_uuid' => ['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field','validate_callback'=>static fn($v): bool => is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v)],
                'message' => ['required'=>true,'type'=>'string','maxLength'=>600,'sanitize_callback'=>'sanitize_textarea_field'],
                '_nonce' => ['required'=>true,'type'=>'string'],
            ];
            register_rest_route('pkstore/v1','/chat',['methods'=>'POST','callback'=>[$this,'handleChat'],'permission_callback'=>[$this,'nonce'],'args'=>$args]);
            register_rest_route('pkstore/v1','/chat/stream',['methods'=>'POST','callback'=>[$this,'handleStream'],'permission_callback'=>[$this,'nonce'],'args'=>$args]);
        });
    }

    public function nonce(\WP_REST_Request $request): bool {
        return (bool) wp_verify_nonce($request->get_param('_nonce'), 'pkstore_ai_chat');
    }

    private function rl(string $uuid): bool {
        $sessionKey = 'pkstore_ai_rl_' . md5($uuid);
        $sessionCount = (int) get_transient($sessionKey);
        if ($sessionCount >= 20) return false;

        // Hash the direct peer address; do not trust forwarded headers supplied by clients.
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip !== '') {
            $ipKey = 'pkstore_ai_ip_rl_' . hash_hmac('sha256', $ip, wp_salt('auth'));
            $ipCount = (int) get_transient($ipKey);
            if ($ipCount >= 60) return false;
            set_transient($ipKey, $ipCount + 1, HOUR_IN_SECONDS);
        }
        set_transient($sessionKey, $sessionCount + 1, HOUR_IN_SECONDS);
        return true;
    }

    private function providerFailure(AiProviderException $exception): array {
        $raw = $exception->getMessage();
        $code = strstr($raw, ':', true) ?: 'provider_unavailable';
        $message = str_contains($raw, ':') ? trim(substr($raw, strpos($raw, ':') + 1)) : 'Assistant temporarily unavailable.';
        return [$code, $message, $code === 'rate_limited' ? 429 : 503];
    }

    public function handleChat(\WP_REST_Request $request): \WP_REST_Response {
        $uuid = (string) $request->get_param('session_uuid');
        $message = (string) $request->get_param('message');
        if (!$this->rl($uuid)) return new \WP_REST_Response(['success'=>false,'code'=>'rate_limited','message'=>'Too many questions. Please wait.','handoff'=>true],429);

        try {
            $response = $this->c->make(HandleAiChatUseCase::class)->execute($uuid, $message);
            return new \WP_REST_Response(['success'=>true,'reply'=>$response->content,'meta'=>['tokens_used'=>$response->totalTokensUsed(),'finish_reason'=>$response->finishReason]],200);
        } catch (PromptInjectionException) {
            return new \WP_REST_Response(['success'=>true,'reply'=>'میں صرف آپ کی شاپنگ میں مدد کر سکتا ہوں۔ How can I help you shop today?'],200);
        } catch (AiProviderException $exception) {
            [$code,$detail,$status] = $this->providerFailure($exception);
            return new \WP_REST_Response(['success'=>false,'code'=>$code,'message'=>$detail,'handoff'=>true],$status);
        } catch (\Throwable) {
            return new \WP_REST_Response(['success'=>false,'code'=>'provider_unavailable','message'=>'Assistant temporarily unavailable.','handoff'=>true],503);
        }
    }

    public function handleStream(\WP_REST_Request $request): void {
        $uuid = (string) $request->get_param('session_uuid');
        $message = (string) $request->get_param('message');
        if (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');

        if (!$this->rl($uuid)) {
            echo 'data: ' . wp_json_encode(['error'=>'rate_limited','message'=>'Too many questions. Please wait.','status'=>429]) . "\n\n";
            echo "data: [DONE]\n\n"; flush(); exit;
        }

        try {
            foreach ($this->c->make(HandleAiChatUseCase::class)->executeStream($uuid, $message) as $delta) {
                echo 'data: ' . wp_json_encode(['delta'=>$delta]) . "\n\n";
                flush();
            }
        } catch (PromptInjectionException) {
            echo 'data: ' . wp_json_encode(['delta'=>'میں صرف آپ کی شاپنگ میں مدد کر سکتا ہوں۔']) . "\n\n";
        } catch (AiProviderException $exception) {
            [$code,$detail,$status] = $this->providerFailure($exception);
            echo 'data: ' . wp_json_encode(['error'=>$code,'message'=>$detail,'status'=>$status]) . "\n\n";
        } catch (\Throwable) {
            echo 'data: ' . wp_json_encode(['error'=>'provider_unavailable','message'=>'Assistant temporarily unavailable.','status'=>503]) . "\n\n";
        }
        echo "data: [DONE]\n\n";
        flush();
        exit;
    }
}
