<?php
declare(strict_types=1);
namespace PkStore\Domain\Verification;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
use PkStore\Domain\Verification\Exception\SmsProviderException;
interface SmsProviderInterface { /** @throws SmsProviderException */ public function send(PakistanPhoneNumber $to, string $message):void; public function getProviderName():string; public function isConfigured():bool; }
