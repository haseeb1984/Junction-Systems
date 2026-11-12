<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Verification\Exception;
final class InvalidOtpException extends OtpException { public function __construct(public readonly int $remainingAttempts, string $message='') { parent::__construct($message?:"Invalid OTP. {$this->remainingAttempts} attempt(s) remaining."); } }
