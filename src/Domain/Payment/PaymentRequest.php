<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Payment;
final readonly class PaymentRequest {
    public function __construct(public readonly string $orderId, public readonly int $amountPaisas, public readonly string $customerPhone, public readonly string $customerName, public readonly string $returnUrl, public readonly string $callbackUrl, public readonly string $currency='PKR', public readonly array $metadata=[]) {
        if($this->amountPaisas<=0)throw new \InvalidArgumentException('Amount must be positive.');
    }
    public function getAmountPkr():float{return $this->amountPaisas/100;}
}
