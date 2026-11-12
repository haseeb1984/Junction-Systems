<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Verification\Exception;
final class OtpAlreadyVerifiedException extends OtpException { public function __construct(public readonly string $orderId) { parent::__construct("OTP for order [{$this->orderId}] already verified."); } }
