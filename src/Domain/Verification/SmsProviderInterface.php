<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Verification;
use JunctionStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
use JunctionStore\Domain\Verification\Exception\SmsProviderException;
interface SmsProviderInterface { /** @throws SmsProviderException */ public function send(PakistanPhoneNumber $to, string $message):void; public function getProviderName():string; public function isConfigured():bool; }
