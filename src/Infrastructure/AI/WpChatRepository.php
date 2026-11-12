<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\AI;
use JunctionStore\Domain\AI\ChatRepositoryInterface;
use JunctionStore\Domain\AI\ValueObject\{ChatMessage,MessageRole};
final class WpChatRepository implements ChatRepositoryInterface {
    public function __construct(private readonly \wpdb $db){}
    private function t():string{return $this->db->prefix.'pkstore_chat_history';}
    public function getHistory(string $uuid,int $limit=10):array{$rows=$this->db->get_results($this->db->prepare("SELECT role,content,created_at FROM {$this->t()} WHERE session_uuid=%s AND role!='system' ORDER BY created_at DESC LIMIT %d",$uuid,$limit),ARRAY_A);if(!$rows)return[];return array_map(fn($r)=>new ChatMessage(MessageRole::from($r['role']),$r['content'],new \DateTimeImmutable($r['created_at'])),array_reverse($rows));}
    public function appendMessage(string $uuid,ChatMessage $m):void{$this->db->insert($this->t(),['session_uuid'=>$uuid,'role'=>$m->role->value,'content'=>$m->content,'created_at'=>$m->createdAt->format('Y-m-d H:i:s')]);}
    public function clearSession(string $uuid):void{$this->db->delete($this->t(),['session_uuid'=>$uuid],['%s']);}
    public function purgeExpiredSessions(int $ttl=7200):int{$cut=date('Y-m-d H:i:s',time()-$ttl);$this->db->query($this->db->prepare("DELETE FROM {$this->t()} WHERE created_at<%s",$cut));return $this->db->rows_affected;}
}
