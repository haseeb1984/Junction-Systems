<?php
declare(strict_types=1);
namespace JunctionStore\Domain\AI\ValueObject;
final readonly class ChatRequest { public function __construct(public readonly array $messages, public readonly int $maxTokens=512, public readonly float $temperature=0.3, public readonly bool $stream=false, public readonly string $model='gemini-1.5-flash') {} }
