<?php
declare(strict_types=1);
namespace PkStore\Application\Verification;
use PkStore\Domain\Verification\{SmsProviderInterface};
use PkStore\Domain\Verification\Entity\OtpAttempt;
use PkStore\Domain\Verification\Exception\{RateLimitExceededException,SmsProviderException};
use PkStore\Domain\Verification\Repository\OtpRepositoryInterface;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
use Psr\Log\LoggerInterface;
final class SendCodOtpUseCase {
    private const MAX=3;private const MINS=15;private const LEN=6;
    private const TPL="Your PK Store code: %s\nValid 5 minutes. Order #%s\nDO NOT share.";
    public function __construct(private readonly OtpRepositoryInterface $repo, private readonly SmsProviderInterface $sms, private readonly LoggerInterface $log) {}
    public function execute(string $rawPhone, string $orderId):void {
        if ($orderId === '' || strlen($orderId) > 32) {
            throw new \\InvalidArgumentException('Invalid checkout verification session. Refresh checkout and try again.');
        }
        $phone=new PakistanPhoneNumber($rawPhone);
        $since=new \DateTimeImmutable('-'.self::MINS.' minutes');
        if($this->repo->countRecentSendsByPhone($phone,$since)>=self::MAX)
            throw new RateLimitExceededException($since->modify('+'.self::MINS.' minutes'));
        $this->repo->invalidatePrevious($phone,$orderId);
        $code=$this->otp();
        $attempt=OtpAttempt::create($phone,$code,$orderId);
        $this->repo->save($attempt);
        try { $this->sms->send($phone,sprintf(self::TPL,$code)); }
        catch(SmsProviderException $e){ $this->repo->invalidatePrevious($phone,$orderId); throw $e; }
        finally { $code=str_repeat('0',self::LEN); unset($code); }
    }
    private function otp():string { return str_pad((string)random_int((int)('1'.str_repeat('0',self::LEN-1)),(int)str_repeat('9',self::LEN)),self::LEN,'0',STR_PAD_LEFT); }
}
