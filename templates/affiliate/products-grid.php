<?php defined('ABSPATH')||exit;
/** @var \JunctionStore\Domain\Affiliate\Entity\AffiliateProduct[] $items */ ?>
<div class="pk-affiliate-products-grid">
<?php foreach($items as $product):?>
<div class="pk-affiliate-product-card">
  <?php if($product->imageUrl):?><div class="pk-afp-image"><img src="<?=esc_url($product->imageUrl)?>" alt="<?=esc_attr($product->title)?>" loading="lazy"></div><?php endif;?>
  <div class="pk-afp-body">
    <span class="pk-afp-platform"><?=esc_html(ucfirst($product->platform))?></span>
    <h3 class="pk-afp-title"><?=esc_html($product->title)?></h3>
    <p class="pk-afp-desc"><?=esc_html(substr($product->description,0,120))?>...</p>
    <div class="pk-afp-footer">
      <span class="pk-afp-price">PKR <?=number_format($product->displayPricePkr)?></span>
      <a href="<?=esc_url($product->getTrackedRedirectUrl(home_url()))?>" class="pk-afp-btn" target="_blank" rel="noopener sponsored">Buy Now ↗</a>
    </div>
  </div>
</div>
<?php endforeach;?>
</div>
<style>
.pk-affiliate-products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin:20px 0}
.pk-affiliate-product-card{border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;transition:box-shadow .2s}
.pk-affiliate-product-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.1)}
.pk-afp-image img{width:100%;aspect-ratio:4/3;object-fit:cover}
.pk-afp-body{padding:14px}
.pk-afp-platform{font-size:10px;text-transform:uppercase;font-weight:700;color:#646970;letter-spacing:.05em}
.pk-afp-title{font-size:15px;font-weight:600;margin:6px 0;line-height:1.3}
.pk-afp-desc{font-size:13px;color:#646970;margin:0 0 12px;line-height:1.4}
.pk-afp-footer{display:flex;align-items:center;justify-content:space-between}
.pk-afp-price{font-size:16px;font-weight:700;color:#0073aa}
.pk-afp-btn{background:#0073aa;color:#fff;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none}
.pk-afp-btn:hover{background:#005177;color:#fff}
</style>
