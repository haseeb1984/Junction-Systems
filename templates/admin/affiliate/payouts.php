<?php defined('ABSPATH')||exit; global $wpdb;
$t=$wpdb->prefix.'pkstore_affiliate_payouts'; $at=$wpdb->prefix.'pkstore_affiliates';
$rows=$wpdb->get_results("SELECT p.*,a.name AS affiliate_name,a.email FROM {$t} p LEFT JOIN {$at} a ON p.affiliate_id=a.id ORDER BY p.created_at DESC LIMIT 200",ARRAY_A)?:[];
?><div class="wrap pkstore-admin"><h1>Affiliate Payouts</h1>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>Affiliate</th><th>Amount</th><th>Method</th><th>Account</th><th>Status</th><th>Transaction ID</th><th>Date</th></tr></thead>
<tbody>
<?php foreach($rows as $r):?>
<tr>
  <td><strong><?=esc_html($r['affiliate_name']??'—')?></strong><br><small><?=esc_html($r['email']??'')?></small></td>
  <td style="font-weight:700;">PKR <?=number_format((int)$r['amount_pkr'])?></td>
  <td><?=esc_html(strtoupper($r['payment_method']))?></td>
  <?php
  $storedAccount=(string)($r['payment_account']??'');
  $accountKey='AFFILIATE_PAYOUT_ACCOUNT_'.(int)$r['id'];
  $paymentAccount='';
  if($storedAccount==='__encrypted__') {
    $paymentAccount=\\PkStore\\Settings\\SettingsManager::getInstance()->get($accountKey,'');
  } elseif($storedAccount!=='') {
    $settings=\\PkStore\\Settings\\SettingsManager::getInstance();
    $encrypted=$settings->setSecret($accountKey,$storedAccount)||hash_equals($storedAccount,$settings->get($accountKey,''));
    $updated=$encrypted?$wpdb->update($t,['payment_account'=>'__encrypted__'],['id'=>(int)$r['id']]):false;
    $paymentAccount=$updated===1?$storedAccount:'••••'.substr($storedAccount,-4);
    if($updated!==1) error_log('[PK Store] Affiliate payout destination migration failed for payout '.(int)$r['id'].'.');
  }
?>
  <td><code><?=esc_html($paymentAccount!==''?$paymentAccount:'Unavailable — review encryption state')?></code></td>
  <td><?php $sc=match($r['status']){'completed'=>'#00a32a','failed'=>'#d63638','processing'=>'#0073aa',default=>'#dba617'};?><span style="color:<?=$sc?>;font-weight:600;"><?=esc_html(ucfirst($r['status']))?></span></td>
  <td><code><?=esc_html($r['transaction_id']??'—')?></code></td>
  <td><?=esc_html(date('M j, Y',strtotime($r['created_at'])))?></td>
</tr>
<?php endforeach;?>
<?php if(empty($rows)):?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No payouts yet.</td></tr><?php endif;?>
</tbody></table></div>
