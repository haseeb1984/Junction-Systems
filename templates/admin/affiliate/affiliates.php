<?php defined('ABSPATH')||exit; global $wpdb;
$t=$wpdb->prefix.'pkstore_affiliates';
$affiliates=$wpdb->get_results("SELECT * FROM {$t} ORDER BY created_at DESC LIMIT 100",ARRAY_A)?:[];
?>
<div class="wrap pkstore-admin"><h1>Affiliate Members</h1>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>Name</th><th>Code</th><th>Rate</th><th>Total Earned</th><th>Pending</th><th>Paid</th><th>Method</th><th>Account</th><th>Status</th><th>Joined</th></tr></thead>
<tbody>
<?php foreach($affiliates as $a):?>
<tr>
  <td><strong><?=esc_html($a['name'])?></strong><br><small><?=esc_html($a['email'])?></small></td>
  <td><code><?=esc_html($a['affiliate_code'])?></code></td>
  <td><?=esc_html($a['commission_rate'])?>%</td>
  <td>PKR <?=number_format((int)$a['total_earned_pkr'])?></td>
  <td style="color:<?=(int)$a['pending_pkr']>=1000?'#00a32a':'inherit'?>;font-weight:600;">PKR <?=number_format((int)$a['pending_pkr'])?><?php if((int)$a['pending_pkr']>=1000):?><br><small>✓ Eligible</small><?php endif;?></td>
  <td>PKR <?=number_format((int)$a['total_paid_pkr'])?></td>
  <td><?=esc_html(strtoupper($a['payment_method']))?></td>
  <?php
  $storedAccount=(string)($a['payment_account']??'');
  $accountKey='AFFILIATE_PAYMENT_ACCOUNT_'.(int)$a['id'];
  $paymentAccount='';
  if($storedAccount==='__encrypted__') {
    $paymentAccount=\\PkStore\\Settings\\SettingsManager::getInstance()->get($accountKey,'');
  } elseif($storedAccount!=='') {
    $settings=\\PkStore\\Settings\\SettingsManager::getInstance();
    $encrypted=$settings->setSecret($accountKey,$storedAccount)||hash_equals($storedAccount,$settings->get($accountKey,''));
    $updated=$encrypted?$wpdb->update($t,['payment_account'=>'__encrypted__'],['id'=>(int)$a['id']]):false;
    $paymentAccount=$updated===1?$storedAccount:'••••'.substr($storedAccount,-4);
    if($updated!==1) error_log('[PK Store] Affiliate account encryption migration failed for affiliate '.(int)$a['id'].'.');
  }
?>
  <td><code><?=esc_html($paymentAccount!==''?$paymentAccount:'Unavailable — review encryption state')?></code></td>
  <td><span style="color:<?=$a['status']=='active'?'#00a32a':'#d63638'?>;font-weight:600;"><?=esc_html(ucfirst($a['status']))?></span></td>
  <td><?=esc_html(date('M j, Y',strtotime($a['created_at'])))?></td>
</tr>
<?php endforeach;?>
<?php if(empty($affiliates)):?><tr><td colspan="10" style="text-align:center;padding:24px;color:#646970;">No affiliates yet.</td></tr><?php endif;?>
</tbody></table></div>
