<?php defined('ABSPATH')||exit; global $wpdb;
$t=$wpdb->prefix.'pkstore_affiliate_commissions'; $at=$wpdb->prefix.'pkstore_affiliates';
$rows=$wpdb->get_results("SELECT c.*,a.name AS affiliate_name,a.affiliate_code FROM {$t} c LEFT JOIN {$at} a ON c.affiliate_id=a.id ORDER BY c.created_at DESC LIMIT 200",ARRAY_A)?:[];
?><div class="wrap pkstore-admin"><h1>Affiliate Commissions</h1>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>Affiliate</th><th>Order</th><th>Order Total</th><th>Commission</th><th>Rate</th><th>Status</th><th>Date</th></tr></thead>
<tbody>
<?php foreach($rows as $r):?>
<tr>
  <td><strong><?=esc_html($r['affiliate_name']??'—')?></strong><br><small><code><?=esc_html($r['affiliate_code']??'')?></code></small></td>
  <td><a href="<?=esc_url(admin_url('post.php?post='.(int)$r['order_id'].'&action=edit'))?>" target="_blank">#<?=(int)$r['order_id']?></a></td>
  <td>PKR <?=number_format((int)$r['order_total_pkr'])?></td>
  <td style="font-weight:700;color:#0073aa;">PKR <?=number_format((int)$r['commission_pkr'])?></td>
  <td><?=esc_html($r['rate_percent'])?>%</td>
  <td><?php $sc=match($r['status']){'paid'=>'#00a32a','approved'=>'#0073aa','cancelled'=>'#d63638',default=>'#dba617'};?><span style="color:<?=$sc?>;font-weight:600;"><?=esc_html(ucfirst($r['status']))?></span></td>
  <td><?=esc_html(date('M j, Y',strtotime($r['created_at'])))?></td>
</tr>
<?php endforeach;?>
<?php if(empty($rows)):?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No commissions yet.</td></tr><?php endif;?>
</tbody></table></div>
