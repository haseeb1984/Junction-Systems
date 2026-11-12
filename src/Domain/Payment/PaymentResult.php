<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Payment;
final readonly class PaymentResult {
    public function __construct(public readonly bool $success, public readonly string $gatewayTransactionId, public readonly string $orderId, public readonly PaymentStatus $status, public readonly string $message, public readonly \DateTimeImmutable $processedAt, public readonly ?string $redirectUrl=null, public readonly array $rawGatewayResponse=[]) {}
    public static function successful(string $gatewayTxId, string $orderId, string $message, ?string $redirectUrl=null, array $raw=[]):self { return new self(true,$gatewayTxId,$orderId,PaymentStatus::Approved,$message,new \DateTimeImmutable(),$redirectUrl,$raw); }
    public static function failed(string $orderId, string $reason, array $raw=[]):self { return new self(false,'', $orderId,PaymentStatus::Failed,$reason,new \DateTimeImmutable(),null,$raw); }
}
