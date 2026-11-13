<?php
declare(strict_types=1);
namespace PkStore\Domain\AI\ValueObject;
final readonly class ChatResponse { public function __construct(public readonly string $content, public readonly int $promptTokensUsed, public readonly int $completionTokensUsed, public readonly string $finishReason, public readonly string $modelUsed) {} public function totalTokensUsed():int{return $this->promptTokensUsed+$this->completionTokensUsed;} }
