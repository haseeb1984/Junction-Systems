<?php
declare(strict_types=1);
namespace PkStore\Domain\AI\ValueObject;
enum MessageRole: string { case System='system'; case User='user'; case Assistant='assistant'; }
final readonly class ChatMessage {
    public function __construct(public readonly MessageRole $role, public readonly string $content, public readonly \DateTimeImmutable $createdAt=new \DateTimeImmutable()) {}
    public function toArray():array{return['role'=>$this->role->value,'content'=>$this->content];}
    public static function system(string $c):self{return new self(MessageRole::System,$c);}
    public static function user(string $c):self{return new self(MessageRole::User,$c);}
    public static function assistant(string $c):self{return new self(MessageRole::Assistant,$c);}
}
