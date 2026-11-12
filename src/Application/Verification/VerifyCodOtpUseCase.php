<?php
declare(strict_types=1);
namespace JunctionStore\Application\Verification;
use JunctionStore\Domain\Verification\Exception\{InvalidOtpException,OtpAlreadyVerifiedException,OtpExpiredException};
use JunctionStore\Domain\Verification\Repository\OtpRepositoryInterface;
use JunctionStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
use Psr\Log\LoggerInterface;
final class VerifyCodOtpUseCase {
    public function __construct(private readonly OtpRepositoryInterface $repo, private readonly LoggerInterface $log) {}
    public function execute(string $rawPhone, string $orderId, string $submittedCode):true {
        $phone=new PakistanPhoneNumber($rawPhone);
        $code=preg_replace('/\D/','',$submittedCode);
        if(strlen($code)!==6)throw new InvalidOtpException(0,'OTP must be exactly 6 digits.');
        $a=$this->repo->findActiveByPhoneAndOrder($phone,$orderId);
        if($a===null)throw new \RuntimeException('No active OTP found. Please request a new code.');
        if($a->isAlreadyVerified())throw new OtpAlreadyVerifiedException($orderId);
        if($a->isExpired())throw new OtpExpiredException();
        if($a->isExhausted())throw new InvalidOtpException(0,'Maximum attempts exceeded.');
        $ok=$a->verify($code);
        $this->repo->update($a);
        if(!$ok)throw new InvalidOtpException($a->getRemainingAttempts());
        return true;
    }
}
