<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Payment;
interface PaymentGatewayInterface { public function initiate(PaymentRequest $r):PaymentResult; public function verifyCallback(array $payload):PaymentResult; public function getGatewayId():string; public function getDisplayName():string; }
