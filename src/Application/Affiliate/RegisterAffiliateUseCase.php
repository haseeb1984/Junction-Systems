<?php
declare(strict_types=1);
namespace JunctionStore\Application\Affiliate;
use JunctionStore\Domain\Affiliate\Entity\Affiliate;
use JunctionStore\Domain\Affiliate\Exception\AffiliateAlreadyExistsException;
use JunctionStore\Domain\Affiliate\Repository\AffiliateRepositoryInterface;
use Psr\Log\LoggerInterface;
final class RegisterAffiliateUseCase {
    public function __construct(private readonly AffiliateRepositoryInterface $repo, private readonly LoggerInterface $log) {}
    public function execute(int $userId, string $name, string $email, string $paymentMethod, string $paymentAccount): Affiliate {
        if ($this->repo->findByUserId($userId) !== null) throw new AffiliateAlreadyExistsException($email);
        $affiliate = Affiliate::create($userId, $name, $email, $paymentMethod, $paymentAccount);
        $id = $this->repo->save($affiliate);
        $this->log->info('Affiliate registered.', ['user_id' => $userId, 'code' => (string)$affiliate->code]);
        return $this->repo->findById($id);
    }
}
