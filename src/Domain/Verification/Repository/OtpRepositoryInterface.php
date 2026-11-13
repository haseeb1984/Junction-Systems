<?php
declare(strict_types=1);
namespace PkStore\Domain\Verification\Repository;
use PkStore\Domain\Verification\Entity\OtpAttempt;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
interface OtpRepositoryInterface {
    public function save(OtpAttempt $a):void;
    public function update(OtpAttempt $a):void;
    public function findActiveByPhoneAndOrder(PakistanPhoneNumber $p, string $orderId):?OtpAttempt;
    public function countRecentSendsByPhone(PakistanPhoneNumber $p, \DateTimeImmutable $since):int;
    public function invalidatePrevious(PakistanPhoneNumber $p, string $orderId):void;
}
