<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Verification\Exception;
final class SmsProviderException extends OtpException { public function __construct(string $message, public readonly string $providerCode='', ?\Throwable $previous=null) { parent::__construct($message,0,$previous); } }
