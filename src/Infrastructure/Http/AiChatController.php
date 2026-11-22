<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Http;

use PkStore\Application\AI\HandleAiChatUseCase;
use PkStore\Container\ServiceContainer;
use PkStore\Domain\AI\Exception\{AiProviderException, PromptInjectionException};

final class AiChatController {
    public function __construct(private readonly ServiceContainer $c) {}

    public function register(): void {
        add_action('rest_api_init', function (): void {
            $args = [
                'session_uuid' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'message' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                '_nonce' => ['required' => true, 'type' => 'string'],
            ];
            register_rest_route('pkstore/v1', '/chat', [
                'methods' => 'POST',
                'callback' => [$this, 'handleChat'],
                'permission_callback' => [$this, 'nonce'],
                'args' => $args,
            ]);
            register_rest_route('pkstore/v1', '/chat/stream', [
                'methods' => 'POST',
                'callback' => [$this, 'handleStream'],
                'permission_callback' => [$this, 'nonce'],
                'args' => $args,
            ]);
        });
    }

    public function nonce(\WP_REST_Request $r): bool {
        return (bool) wp_verify_nonce($r->get_param('_nonce'), 'pkstore_ai_chat');
    }

    private function validSessionUuid(string $uuid): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    private function rateLimited(string $sessionUuid): bool {
        global $wpdb;
        if (!($wpdb instanceof \wpdb)) return true;

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ipHash = substr(hash_hmac('sha256', $ip, wp_salt('auth')), 0, 32);
        $sessionHash = hash('sha256', $sessionUuid);
        $sessionKey = 'pkstore_chat_session_rl_' . $sessionHash;
        $ipKey = 'pkstore_chat_ip_rl_' . $ipHash;
        $lockNames = [
            'pkstore_chat_ip_' . $ipHash,
            'pkstore_chat_session_' . substr($sessionHash, 0, 32),
        ];
        sort($lockNames, SORT_STRING);
        $acquired = [];

        try {
            // Serialize both counters. Transient get/increment/set alone is
            // racy under concurrent requests and can exceed the provider-cost cap.
            foreach ($lockNames as $lockName) {
                $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 1));
                if ($wpdb->last_error !== '' || (int) $locked !== 1) return true;
                $acquired[] = $lockName;
            }

            $sessionCount = (int) get_transient($sessionKey);
            $ipCount = (int) get_transient($ipKey);
            if ($sessionCount >= 20 || $ipCount >= 100) return true;

            $sessionSaved = set_transient($sessionKey, $sessionCount + 1, HOUR_IN_SECONDS);
            $ipSaved = set_transient($ipKey, $ipCount + 1, HOUR_IN_SECONDS);
            if (!$sessionSaved || !$ipSaved) return true;
            return false;
        } finally {
            foreach (array_reverse($acquired) as $lockName) {
                $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
                if ($wpdb->last_error !== '' || (int) $released !== 1) {
                    error_log('[PK Store] AI chat rate-limit lock release could not be confirmed.');
                }
            }
        }
    }

    private function validMessage(string $message): bool {
        $length = function_exists('mb_strlen') ? mb_strlen(trim($message)) : strlen(trim($message));
        return $length > 0 && $length <= 2000;
    }

    public function handleChat(\WP_REST_Request $r): \WP_REST_Response {
        $u = (string) $r->get_param('session_uuid');
        $m = (string) $r->get_param('message');
        if (!$this->validSessionUuid($u) || !$this->validMessage($m)) {
            return new \WP_REST_Response(['success'=>false,'code'=>'invalid_request','message'=>'Refresh the chat and enter a message of up to 2,000 characters.'],422);
        }
        if ($this->rateLimited($u)) {
            return new \WP_REST_Response(['success'=>false,'code'=>'rate_limited','message'=>'Too many questions. Please wait.'],429);
        }

        try {
            $resp = $this->c->make(HandleAiChatUseCase::class)->execute($u, $m);
            return new \WP_REST_Response([
                'success'=>true,
                'reply'=>$resp->content,
                'meta'=>['tokens_used'=>$resp->totalTokensUsed(),'finish_reason'=>$resp->finishReason],
            ],200);
        } catch (PromptInjectionException) {
            return new \WP_REST_Response(['success'=>true,'reply'=>'میں صرف آپ کی شاپنگ میں مدد کر سکتا ہوں۔ How can I help you shop today?'],200);
        } catch (AiProviderException) {
            return new \WP_REST_Response(['success'=>false,'code'=>'ai_error','message'=>'Assistant temporarily unavailable.'],503);
        } catch (\Throwable $error) {
            error_log('[PK Store] AI chat request failed (' . get_class($error) . ').');
            return new \WP_REST_Response(['success'=>false,'code'=>'ai_error','message'=>'Assistant temporarily unavailable.'],503);
        }
    }

    public function handleStream(\WP_REST_Request $r): void {
        $u = (string) $r->get_param('session_uuid');
        $m = (string) $r->get_param('message');
        if (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');

        if (!$this->validSessionUuid($u) || !$this->validMessage($m)) {
            $this->streamError('invalid_request', 'Refresh the chat and enter a valid message.', 422);
            exit;
        }
        if ($this->rateLimited($u)) {
            $this->streamError('rate_limited', 'Too many questions. Wait a few minutes.', 429);
            exit;
        }

        try {
            foreach ($this->c->make(HandleAiChatUseCase::class)->executeStream($u, $m) as $delta) {
                echo 'data: ' . wp_json_encode(['delta'=>$delta]) . "\n\n";
                flush();
            }
            echo "data: [DONE]\n\n";
            flush();
        } catch (PromptInjectionException) {
            echo 'data: ' . wp_json_encode(['delta'=>'میں صرف آپ کی شاپنگ میں مدد کر سکتا ہوں۔']) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        } catch (\Throwable $error) {
            error_log('[PK Store] AI chat stream failed (' . get_class($error) . ').');
            $this->streamError('ai_error', 'Assistant temporarily unavailable. Please try again.', 503);
        }
        exit;
    }

    private function streamError(string $code, string $message, int $status): void {
        status_header($status);
        echo 'data: ' . wp_json_encode(['error'=>['code'=>$code,'message'=>$message,'status'=>$status]]) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
    }
}
