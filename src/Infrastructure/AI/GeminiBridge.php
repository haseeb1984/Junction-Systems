<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\AI;
use PkStore\Domain\AI\AiProviderInterface;
use PkStore\Domain\AI\Exception\AiProviderException;
use PkStore\Domain\AI\ValueObject\{ChatRequest, ChatResponse};
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

/**
 * Google Gemini Flash bridge — FREE tier: 1,500 req/day, no credit card needed.
 * API key: aistudio.google.com → Get API Key
 */
final class GeminiBridge implements AiProviderInterface {

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const MODEL     = 'gemini-1.5-flash';

    public function __construct(
        private readonly LoggerInterface $log,
        private readonly SettingsManager $cfg
    ) {}

    public function getProviderName(): string { return 'Google Gemini'; }
    public function getModelName(): string    { return self::MODEL; }
    public function estimateTokens(string $t): int { return (int) ceil(mb_strlen($t) / 4); }

    public function chat(ChatRequest $req): ChatResponse {
        $key = $this->cfg->get('GEMINI_API_KEY');
        if (!$key) {
            throw new AiProviderException(
                'Gemini API key not configured. Go to PK Store › Setup Wizard › AI Chat and add your free key from aistudio.google.com.'
            );
        }

        // Convert ChatMessage[] to Gemini format
        // Gemini uses role: "user" | "model" (not "assistant")
        $systemText = '';
        $contents   = [];

        foreach ($req->messages as $msg) {
            $arr = $msg->toArray(); // ['role'=>..., 'content'=>...]
            if ($arr['role'] === 'system') {
                $systemText = $arr['content'];
                continue;
            }
            $geminiRole = ($arr['role'] === 'assistant') ? 'model' : 'user';
            $contents[] = [
                'role'  => $geminiRole,
                'parts' => [['text' => $arr['content']]],
            ];
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $req->maxTokens,
                'temperature'     => $req->temperature,
            ],
        ];

        if ($systemText) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        $endpoint = self::API_BASE . self::MODEL . ':generateContent?key=' . urlencode($key);

        $response = wp_remote_post($endpoint, [
            'timeout' => 25,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new AiProviderException('Gemini request failed: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 400) {
            throw new AiProviderException('Invalid Gemini API key. Please check Setup Wizard › AI Chat.');
        }
        if ($status === 429) {
            throw new AiProviderException('Gemini rate limit reached. Free tier: 1,500 requests/day. Try again tomorrow.');
        }
        if ($status !== 200) {
            $msg = $body['error']['message'] ?? 'Unknown Gemini error';
            throw new AiProviderException("Gemini [{$status}]: {$msg}");
        }

        $text         = trim($body['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $promptTokens = (int) ($body['usageMetadata']['promptTokenCount']      ?? 0);
        $outTokens    = (int) ($body['usageMetadata']['candidatesTokenCount']  ?? 0);

        return new ChatResponse($text, $promptTokens, $outTokens, 'stop', self::MODEL);
    }

    /**
     * Gemini does support streaming but WP doesn't easily support SSE via wp_remote_post.
     * We use cURL directly — same pattern as the old OpenAI bridge.
     */
    public function streamChat(ChatRequest $req): \Generator {
        $key = $this->cfg->get('GEMINI_API_KEY');
        if (!$key) {
            throw new AiProviderException('Gemini API key not configured.');
        }

        $systemText = '';
        $contents   = [];

        foreach ($req->messages as $msg) {
            $arr = $msg->toArray();
            if ($arr['role'] === 'system') { $systemText = $arr['content']; continue; }
            $geminiRole = ($arr['role'] === 'assistant') ? 'model' : 'user';
            $contents[] = ['role' => $geminiRole, 'parts' => [['text' => $arr['content']]]];
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => ['maxOutputTokens' => $req->maxTokens, 'temperature' => $req->temperature],
        ];
        if ($systemText) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemText]]];
        }

        $endpoint = self::API_BASE . self::MODEL . ':streamGenerateContent?alt=sse&key=' . urlencode($key);

        $buf  = '';
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST        => true,
            CURLOPT_POSTFIELDS  => wp_json_encode($payload),
            CURLOPT_HTTPHEADER  => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT     => 25,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buf) {
                $buf .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new AiProviderException("Gemini stream error: {$err}");
        }

        // Parse SSE: each "data: {...}" line contains a JSON chunk
        foreach (explode("\n", $buf) as $line) {
            if (!str_starts_with($line, 'data: ')) continue;
            $json  = substr($line, 6);
            $chunk = json_decode($json, true);
            $delta = $chunk['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if ($delta !== '') {
                yield $delta;
            }
        }
    }
}
