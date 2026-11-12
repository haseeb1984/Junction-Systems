<?php
declare(strict_types=1);
namespace JunctionStore\Application\Affiliate;
use JunctionStore\Domain\Affiliate\Entity\AffiliateProduct;
use JunctionStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface, AffiliateProductRepositoryInterface};
use Psr\Log\LoggerInterface;
final class SubmitAffiliateProductUseCase {
    public function __construct(private readonly AffiliateRepositoryInterface $affiliateRepo, private readonly AffiliateProductRepositoryInterface $productRepo, private readonly LoggerInterface $log) {}
    public function execute(int $affiliateId, array $data): AffiliateProduct {
        $a = $this->affiliateRepo->findById($affiliateId);
        if ($a === null || !$a->isActive()) throw new \RuntimeException('Affiliate account not found or inactive.');
        $title = sanitize_text_field($data['title'] ?? '');
        $externalUrl = esc_url_raw($data['external_url'] ?? '');
        if (empty($title) || empty($externalUrl)) throw new \InvalidArgumentException('Title and external URL are required.');
        if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('Invalid external URL.');
        $p = AffiliateProduct::create($affiliateId, $title, sanitize_textarea_field($data['description'] ?? ''), max(0, (int)($data['price'] ?? 0)), $externalUrl, esc_url_raw($data['image_url'] ?? ''), sanitize_key($data['platform'] ?? 'other'), sanitize_text_field($data['category'] ?? ''));
        $id = $this->productRepo->save($p);
        return $this->productRepo->findById($id);
    }
}
