<?php
declare(strict_types=1);
namespace PkStore\Domain\Verification\Exception;
final class RateLimitExceededException extends OtpException { public function __construct(public readonly \DateTimeImmutable $unlocksAt) { $w=max(1,(int)(new \DateTimeImmutable())->diff($this->unlocksAt)->i+1); parent::__construct("Too many OTP requests. Please wait {$w} minutes."); } }
