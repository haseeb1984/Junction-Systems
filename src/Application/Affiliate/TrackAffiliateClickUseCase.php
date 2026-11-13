<?php
declare(strict_types=1);
namespace PkStore\Application\Affiliate;
use PkStore\Domain\Affiliate\Repository\AffiliateRepositoryInterface;
use PkStore\Domain\Affiliate\ValueObject\AffiliateCode;
final class TrackAffiliateClickUseCase {
    private const COOKIE = 'pkstore_affiliate';
    private const DAYS = 30;
    public function __construct(private readonly AffiliateRepositoryInterface $repo) {}
    public function execute(string $rawCode): void {
        try {
            $code = new AffiliateCode($rawCode);
            $a = $this->repo->findByCode($code);
            if ($a === null || !$a->isActive()) return;
            setcookie(self::COOKIE, $code->value, ['expires'=>time()+(self::DAYS*86400),'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        } catch (\InvalidArgumentException) {}
    }
    public static function getTrackedAffiliateCode(): ?string {
        $val = $_COOKIE[self::COOKIE] ?? null;
        if ($val === null) return null;
        try { return (new AffiliateCode($val))->value; } catch (\Throwable) { return null; }
    }
    public static function clearCookie(): void { setcookie(self::COOKIE, '', time() - 3600, '/'); unset($_COOKIE[self::COOKIE]); }
}
