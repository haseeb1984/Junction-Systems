<?php
declare(strict_types=1);
namespace PkStore\Domain\AI;
use PkStore\Domain\AI\ValueObject\{ChatRequest,ChatResponse};
interface AiProviderInterface { public function chat(ChatRequest $r):ChatResponse; public function streamChat(ChatRequest $r):\Generator; public function estimateTokens(string $t):int; public function getProviderName():string; public function getModelName():string; }
