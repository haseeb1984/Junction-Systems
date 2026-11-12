<?php
declare(strict_types=1);
namespace JunctionStore\Domain\AI;
use JunctionStore\Domain\AI\ValueObject\{ChatRequest,ChatResponse};
interface AiProviderInterface { public function chat(ChatRequest $r):ChatResponse; public function streamChat(ChatRequest $r):\Generator; public function estimateTokens(string $t):int; public function getProviderName():string; public function getModelName():string; }
