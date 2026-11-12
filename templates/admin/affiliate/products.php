<?php defined('ABSPATH')||exit; global $wpdb;
$t=$wpdb->prefix.'pkstore_affiliate_products'; $at=$wpdb->prefix.'pkstore_affiliates';
if(isset($_POST['pkstore_toggle_product'])&&current_user_can('manage_options')){check_admin_referer('pkstore_toggle_product');$pid=(int)$_POST['product_id'];$ns=sanitize_key($_POST['new_status']);if(in_array($ns,['active','inactive'],true))$wpdb->update($t,['status'=>$ns],['id'=>$pid]);wp_safe_redirect(admin_url('admin.php?page=pkstore-affiliate-products'));exit;}
$rows=$wpdb->get_results("SELECT p.*,a.name AS affiliate_name FROM {$t} p LEFT JOIN {$at} a ON p.affiliate_id=a.id ORDER BY p.created_at DESC LIMIT 200",ARRAY_A)?:[];
?><div class="wrap pkstore-admin"><h1>Affiliate Products (Inbound)</h1>
<p style="color:#646970;">External products submitted by affiliates. "Buy Now" redirects to their affiliate link.</p>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>Title</th><th>Affiliate</th><th>Price</th><th>Platform</th><th>Clicks</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php foreach($rows as $r):?>
<tr>
  <td><strong><?=esc_html($r['title'])?></strong><br><small style="color:#646970;"><?=esc_html(substr($r['description'],0,80))?>...</small><br><a href="<?=esc_url($r['external_url'])?>" target="_blank" style="font-size:11px;">View external link ↗</a></td>
  <td><?=esc_html($r['affiliate_name']??'—')?></td>
  <td>PKR <?=number_format((int)$r['display_price_pkr'])?></td>
  <td><?=esc_html($r['platform'])?></td>
  <td><?=number_format((int)$r['click_count'])?></td>
  <td><span style="color:<?=$r['status']=='active'?'#00a32a':'#d63638'?>;font-weight:600;"><?=esc_html(ucfirst($r['status']))?></span></td>
  <td><form method="POST" style="display:inline;"><?php wp_nonce_field('pkstore_toggle_product');?><input type="hidden" name="pkstore_toggle_product" value="1"><input type="hidden" name="product_id" value="<?=(int)$r['id']?>"><input type="hidden" name="new_status" value="<?=$r['status']=='active'?'inactive':'active'?>"><button type="submit" class="button button-small"><?=$r['status']=='active'?'Deactivate':'Activate'?></button></form></td>
</tr>
<?php endforeach;?>
<?php if(empty($rows)):?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No affiliate products submitted yet.</td></tr><?php endif;?>
</tbody></table></div>
