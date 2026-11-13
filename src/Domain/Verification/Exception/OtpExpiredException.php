<?php
declare(strict_types=1);
namespace PkStore\Domain\Verification\Exception;
final class OtpExpiredException extends OtpException { public function __construct() { parent::__construct('OTP has expired. Please request a new code.'); } }
