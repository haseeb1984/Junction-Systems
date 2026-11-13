<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Affiliate;
use PkStore\Domain\Affiliate\Entity\AffiliateProduct;
use PkStore\Domain\Affiliate\Repository\AffiliateProductRepositoryInterface;
final class WpAffiliateProductRepository implements AffiliateProductRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}
    private function t(): string { return $this->db->prefix . 'pkstore_affiliate_products'; }
    public function save(AffiliateProduct $p): int {
        $this->db->insert($this->t(), ['affiliate_id'=>$p->affiliateId,'title'=>$p->title,'description'=>$p->description,'display_price_pkr'=>$p->displayPricePkr,'external_url'=>$p->externalUrl,'image_url'=>$p->imageUrl,'platform'=>$p->platform,'category'=>$p->category,'click_count'=>$p->getClickCount(),'status'=>$p->status,'created_at'=>$p->createdAt->format('Y-m-d H:i:s')]);
        return (int)$this->db->insert_id;
    }
    public function update(AffiliateProduct $p): void { $this->db->update($this->t(),['click_count'=>$p->getClickCount(),'status'=>$p->status],['id'=>$p->id]); }
    public function findById(int $id): ?AffiliateProduct { $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d",$id),ARRAY_A); return $r?$this->h($r):null; }
    public function findActive(int $limit=20, int $offset=0): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE status='active' ORDER BY created_at DESC LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function findByAffiliate(int $affiliateId): array { $rows=$this->db->get_results($this->db->prepare("SELECT * FROM {$this->t()} WHERE affiliate_id=%d ORDER BY created_at DESC",$affiliateId),ARRAY_A)?:[]; return array_map(fn($r)=>$this->h($r),$rows); }
    public function countActive(): int { return (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->t()} WHERE status='active'"); }
    public function recordClick(int $id): void { $this->db->query($this->db->prepare("UPDATE {$this->t()} SET click_count=click_count+1 WHERE id=%d",$id)); }
    private function h(array $r): AffiliateProduct { return new AffiliateProduct((int)$r['id'],(int)$r['affiliate_id'],$r['title'],$r['description'],(int)$r['display_price_pkr'],$r['external_url'],$r['image_url'],$r['platform'],$r['category'],(int)$r['click_count'],$r['status'],new \DateTimeImmutable($r['created_at'])); }
}
