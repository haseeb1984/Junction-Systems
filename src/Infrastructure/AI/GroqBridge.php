<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\AI;

use PkStore\Domain\AI\AiProviderInterface;
use PkStore\Domain\AI\Exception\AiProviderException;
use PkStore\Domain\AI\ValueObject\{ChatRequest,ChatResponse};
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

/**
 * Groq-compatible Chat Completions provider.
 * API credentials are read only on the server from SettingsManager.
 */
final class GroqBridge implements AiProviderInterface {
    private const API = 'https://api.groq.com/openai/v1/chat/completions';
    private const DEFAULT_MODEL = 'openai/gpt-oss-20b';

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly SettingsManager $cfg
    ) {}

    public function getProviderName(): string { return 'Groq'; }
    public function getModelName(): string { return self::DEFAULT_MODEL; }
    public function estimateTokens(string $text): int { return (int) ceil(mb_strlen($text) / 4); }

    public function chat(ChatRequest $request): ChatResponse {
        $key = trim($this->cfg->get('GROQ_API_KEY'));
        if ($key === '') {
            throw new AiProviderException('missing_api_key: Add your Groq API key in PK Store > Setup Wizard > Dukaan AI.');
        }

        $model = trim($request->model) ?: self::DEFAULT_MODEL;
        $response = wp_remote_post(self::API, [
            'timeout' => 30,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => array_map(static fn($message) => $message->toArray(), $request->messages),
                'max_completion_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'stream' => false,
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->log->error('Groq transport error: ' . $response->get_error_message());
            throw new AiProviderException('provider_unavailable: Groq could not be reached. Please try WhatsApp support.');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status === 401 || $status === 403) {
            throw new AiProviderException('invalid_api_key: Groq rejected the API key. Check the key in Setup Wizard.');
        }
        if ($status === 429) {
            throw new AiProviderException('rate_limited: Groq quota or rate limit reached. Please continue on WhatsApp.');
        }
        if ($status < 200 || $status >= 300) {
            $this->log->error('Groq API returned HTTP ' . $status);
            throw new AiProviderException('provider_unavailable: Groq is temporarily unavailable. Please continue on WhatsApp.');
        }

        $content = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            throw new AiProviderException('empty_response: Groq returned an empty response. Please try again or use WhatsApp.');
        }

        return new ChatResponse(
            $content,
            (int) ($body['usage']['prompt_tokens'] ?? 0),
            (int) ($body['usage']['completion_tokens'] ?? 0),
            (string) ($body['choices'][0]['finish_reason'] ?? 'unknown'),
            (string) ($body['model'] ?? $model)
        );
    }

    public function streamChat(ChatRequest $request): \Generator {
        // Keep the same provider/error contract as the non-streaming path.
        // WordPress HTTP API does not expose a portable incremental SSE reader.
        // The controller still frames the completed answer as SSE for clients.
        $response = $this->chat(new ChatRequest(
            $request->messages,
            $request->maxTokens,
            $request->temperature,
            false,
            $request->model ?: self::DEFAULT_MODEL
        ));
        $text = $response->content;
        $length = mb_strlen($text);
        for ($offset = 0; $offset < $length; $offset += 48) {
            yield mb_substr($text, $offset, 48);
        }
    }
}
