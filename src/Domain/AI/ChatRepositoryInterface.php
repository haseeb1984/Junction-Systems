<?php
declare(strict_types=1);
namespace PkStore\Domain\AI;
use PkStore\Domain\AI\ValueObject\ChatMessage;
interface ChatRepositoryInterface { /** @return ChatMessage[] */ public function getHistory(string $uuid, int $limit=10):array; public function appendMessage(string $uuid, ChatMessage $m):void; public function clearSession(string $uuid):void; public function purgeExpiredSessions(int $ttl=7200):int; }
