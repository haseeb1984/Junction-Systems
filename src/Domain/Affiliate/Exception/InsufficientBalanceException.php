<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Affiliate\Exception;
final class InsufficientBalanceException extends AffiliateException { public function __construct(int $balance) { parent::__construct("Balance PKR {$balance} is below minimum payout threshold of PKR 1,000."); } }
