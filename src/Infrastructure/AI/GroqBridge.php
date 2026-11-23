<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\AI;

use PkStore\Domain\AI\AiProviderInterface;
use PkStore\Domain\AI\Exception\AiProviderException;
use PkStore\Domain\AI\ValueObject\{ChatRequest, ChatResponse};
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

/**
 * Groq OpenAI-compatible chat bridge.
 *
 * Each store installation supplies its own encrypted Groq API key.
 * License entitlement is checked independently by the application layer.
 */
final class GroqBridge implements AiProviderInterface {
    private const API = 'https://api.groq.com/openai/v1/chat/completions';
    private const NETWORK_COOLDOWN = 30;
    private const SERVER_COOLDOWN = 60;
    private const INVALID_KEY_COOLDOWN = 21600;

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly SettingsManager $settings
    ) {}

    public function getProviderName(): string {
        return 'Groq';
    }

    public function getModelName(): string {
        return 'openai/gpt-oss-20b';
    }

    public function estimateTokens(string $text): int {
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        return (int) ceil($length / 4);
    }

    public function chat(ChatRequest $request): ChatResponse {
        $config = $this->configuration();
        $keys = $this->orderedKeys($config['keys']);
        $lastStatus = 0;

        foreach ($keys as $key) {
            if ($this->isCoolingDown($key)) continue;

            $response = wp_remote_post(self::API, [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $key,
                ],
                'body' => wp_json_encode([
                    'model' => $config['model'],
                    'messages' => array_map(static fn($message) => $message->toArray(), $request->messages),
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                    'stream' => false,
                ]),
            ]);

            if (is_wp_error($response)) {
                $this->coolDown($key, self::NETWORK_COOLDOWN);
                $this->log->warning('Groq key temporarily skipped after a transport failure.', [
                    'key_fingerprint' => $this->fingerprint($key),
                ]);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $lastStatus = $status;
            if ($status === 429) {
                $this->coolDown($key, $this->retryAfter($response));
                continue;
            }
            if ($status === 401 || $status === 403) {
                $this->coolDown($key, self::INVALID_KEY_COOLDOWN);
                $this->log->warning('Groq rejected a provisioned key; it has been temporarily disabled.', [
                    'key_fingerprint' => $this->fingerprint($key),
                    'http_status' => $status,
                ]);
                continue;
            }
            if ($status >= 500) {
                $this->coolDown($key, self::SERVER_COOLDOWN);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new AiProviderException('Groq rejected the chat request (HTTP ' . $status . ').');
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || !is_array($body['choices'] ?? null)) {
                throw new AiProviderException('Groq returned an invalid chat response.');
            }

            $content = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
            if ($content === '') {
                throw new AiProviderException('Groq returned an empty chat response.');
            }

            $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
            return new ChatResponse(
                $content,
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                (string) ($body['choices'][0]['finish_reason'] ?? 'unknown'),
                (string) ($body['model'] ?? $config['model'])
            );
        }

        $this->log->warning('No provisioned Groq key is currently available.', [
            'configured_keys' => count($keys),
            'last_http_status' => $lastStatus,
        ]);
        throw new AiProviderException('All configured Groq keys are temporarily unavailable, rate-limited or invalid. Please try again shortly.');
    }

    public function streamChat(ChatRequest $request): \Generator {
        $config = $this->configuration();
        $keys = $this->orderedKeys($config['keys']);

        if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
            $response = $this->chat($request);
            yield $response->content;
            return;
        }

        $payload = wp_json_encode([
            'model' => $config['model'],
            'messages' => array_map(static fn($message) => $message->toArray(), $request->messages),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'stream' => true,
        ]);

        foreach ($keys as $key) {
            if ($this->isCoolingDown($key)) continue;

            $pending = '';
            $chunks = [];
            $retryAfter = 300;
            $providerError = '';
            $yielded = false;
            $multi = curl_multi_init();
            $curl = curl_init(self::API);

            $processLine = static function (string $line) use (&$chunks, &$providerError): void {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) return;
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') return;

                $decoded = json_decode($data, true);
                if (!is_array($decoded)) return;
                if (isset($decoded['error'])) {
                    $providerError = 'Groq returned a streaming provider error.';
                    return;
                }

                $delta = $decoded['choices'][0]['delta']['content'] ?? '';
                if (is_string($delta) && $delta !== '') $chunks[] = $delta;
            };

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key,
                    'Accept: text/event-stream',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                    if (stripos($header, 'Retry-After:') === 0) {
                        $value = trim(substr($header, strlen('Retry-After:')));
                        $retryAt = is_numeric($value) ? null : strtotime($value);
                        $seconds = is_numeric($value) ? (int) $value : ($retryAt !== false && $retryAt !== null ? $retryAt - time() : 300);
                        $retryAfter = max(30, min($seconds, 3600));
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$pending, &$chunks, $processLine): int {
                    $pending .= $data;
                    while (($newline = strpos($pending, "\n")) !== false) {
                        $line = substr($pending, 0, $newline);
                        $pending = substr($pending, $newline + 1);
                        $processLine($line);
                    }
                    return strlen($data);
                },
            ]);

            curl_multi_add_handle($multi, $curl);
            $running = 0;
            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

                while ($chunks) {
                    $yielded = true;
                    yield array_shift($chunks);
                }

                if ($running > 0) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1) usleep(100000);
                }
            } while ($running > 0 && $multiStatus === CURLM_OK);

            if ($pending !== '') $processLine($pending);

            while ($chunks) {
                $yielded = true;
                yield array_shift($chunks);
            }

            $error = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
            curl_close($curl);

            if ($error !== '' || $multiStatus !== CURLM_OK) {
                if ($yielded) {
                    throw new AiProviderException('Groq stream was interrupted after response data began. Please retry the chat message.');
                }
                $this->coolDown($key, self::NETWORK_COOLDOWN);
                continue;
            }
            if ($status === 429) {
                if ($yielded) throw new AiProviderException('Groq rate-limited an active stream. Please retry the chat message.');
                $this->coolDown($key, $retryAfter);
                continue;
            }
            if ($status === 401 || $status === 403) {
                if ($yielded) throw new AiProviderException('Groq rejected an active stream. Please retry the chat message.');
                $this->coolDown($key, self::INVALID_KEY_COOLDOWN);
                continue;
            }
            if ($status >= 500) {
                if ($yielded) throw new AiProviderException('Groq became unavailable during an active stream. Please retry the chat message.');
                $this->coolDown($key, self::SERVER_COOLDOWN);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new AiProviderException('Groq rejected the streaming request (HTTP ' . $status . ').');
            }
            if ($providerError !== '') {
                throw new AiProviderException($providerError);
            }
            if (!$yielded) {
                throw new AiProviderException('Groq returned an empty streaming response.');
            }
            return;
        }

        throw new AiProviderException('All configured Groq keys are temporarily unavailable, rate-limited or invalid. Please try again shortly.');
    }

    /** @return array{keys: string[], model: string} */
    private function configuration(): array {
        $key = trim($this->settings->get('GROQ_API_KEY'));
        if ($key === '' || strlen($key) > 512) {
            throw new AiProviderException('Groq is not configured for this store. Add the store API key in Setup Wizard → Dukaan AI.');
        }
        return ['keys' => [$key], 'model' => 'openai/gpt-oss-20b'];
    }

    /** @param string[] $keys
     *  @return string[]
     */
    private function orderedKeys(array $keys): array {
        if (count($keys) > 1) {
            $offset = wp_rand(0, count($keys) - 1);
            $keys = array_merge(array_slice($keys, $offset), array_slice($keys, 0, $offset));
        }
        return $keys;
    }

    private function fingerprint(string $key): string {
        return substr(hash('sha256', $key), 0, 24);
    }

    private function cooldownKey(string $key): string {
        return 'pkstore_groq_key_cooldown_' . $this->fingerprint($key);
    }

    private function isCoolingDown(string $key): bool {
        return get_transient($this->cooldownKey($key)) !== false;
    }

    private function coolDown(string $key, int $seconds): void {
        set_transient($this->cooldownKey($key), 1, max(30, min($seconds, 21600)));
    }

    private function retryAfter(array $response): int {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        $retryAt = is_numeric($header) ? null : strtotime((string) $header);
        $seconds = is_numeric($header) ? (int) $header : ($retryAt !== false && $retryAt !== null ? $retryAt - time() : 300);
        return max(30, min($seconds, 3600));
    }
}
