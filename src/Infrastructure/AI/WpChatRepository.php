<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\AI;

use PkStore\Domain\AI\ChatRepositoryInterface;
use PkStore\Domain\AI\ValueObject\{ChatMessage, MessageRole};

final class WpChatRepository implements ChatRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}

    private function t(): string {
        return $this->db->prefix . 'pkstore_chat_history';
    }

    public function getHistory(string $uuid, int $limit = 10): array {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT role,content,created_at FROM {$this->t()} WHERE session_uuid=%s AND role!='system' ORDER BY created_at DESC,id DESC LIMIT %d",
                $uuid,
                max(1, min($limit, 30))
            ),
            ARRAY_A
        );
        if ($this->db->last_error !== '') {
            throw new \RuntimeException('Unable to load this chat session history.');
        }
        if (!$rows) return [];

        return array_map(
            static fn(array $row) => new ChatMessage(
                MessageRole::from($row['role']),
                $row['content'],
                new \DateTimeImmutable($row['created_at'])
            ),
            array_reverse($rows)
        );
    }

    public function appendMessage(string $uuid, ChatMessage $message): void {
        $inserted = $this->db->insert($this->t(), [
            'session_uuid' => $uuid,
            'role' => $message->role->value,
            'content' => $message->content,
            'created_at' => $message->createdAt->format('Y-m-d H:i:s'),
        ]);
        if ($inserted === false) {
            throw new \RuntimeException('Unable to save this chat session history.');
        }
    }

    public function clearSession(string $uuid): void {
        $deleted = $this->db->delete($this->t(), ['session_uuid' => $uuid], ['%s']);
        if ($deleted === false) {
            throw new \RuntimeException('Unable to clear this chat session.');
        }
    }

    public function purgeExpiredSessions(int $ttl = 7200): int {
        $cutoff = date('Y-m-d H:i:s', time() - max(60, $ttl));
        $deleted = $this->db->query(
            $this->db->prepare("DELETE FROM {$this->t()} WHERE created_at<%s", $cutoff)
        );
        return $deleted === false ? 0 : (int) $this->db->rows_affected;
    }
}
