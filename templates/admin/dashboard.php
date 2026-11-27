<?php defined('ABSPATH')||exit;?>
<div class="wrap pkstore-admin">
<h1>🛒 Store Operations</h1>
<form method="post" action="<?=esc_url(admin_url('admin-post.php'))?>" style="display:inline-block;margin:0 0 16px;">
<input type="hidden" name="action" value="pkstore_bust_dashboard_cache">
<?php wp_nonce_field('pkstore_bust_dashboard_cache'); ?>
<button type="submit" class="page-title-action">Refresh Stats</button>
</form>
<div class="pkstore-stat-grid">
<div class="card pkstore-stat-card <?=($logistics['booking_failures_24h']>5)?'pkstore-stat-card--alert':''?>">
<h3>Orders Today</h3><span class="pkstore-stat-number"><?=number_format($logistics['total_today'])?></span>
<?php if($logistics['pending_booking']>0):?><p class="pkstore-stat-warning">⚠ <?=$logistics['pending_booking']?> pending booking</p><?php endif;?>
</div>
<div class="card pkstore-stat-card"><h3>API Failures (24h)</h3><span class="pkstore-stat-number" style="color:<?=$logistics['booking_failures_24h']>5?'#d63638':'inherit'?>"><?=$logistics['booking_failures_24h']?></span><p class="pkstore-stat-sub">Avg: <?=$logistics['avg_api_response_ms']?>ms</p></div>
<div class="card pkstore-stat-card <?=(!$posSync['available'] || $posSync['failed']>0 || $posSync['pending']>0)?'pkstore-stat-card--alert':''?>">
<h3>POS → WooCommerce Sync</h3>
<?php if(!$posSync['available']):?><span class="pkstore-stat-number">Unavailable</span><p class="pkstore-stat-sub">POS sync table could not be read.</p>
<?php else:?>
<span class="pkstore-stat-number"><?=number_format($posSync['pending'])?></span>
<p class="pkstore-stat-sub">Pending · <?=number_format($posSync['synced'])?> synced · <?=number_format($posSync['failed'])?> failed</p>
<?php if($posSync['oldest_pending_minutes']!==null):?><p class="pkstore-stat-warning">Oldest pending: <?=number_format($posSync['oldest_pending_minutes'])?> min</p><?php endif;?>
<?php endif;?>
</div>
<div class="card pkstore-stat-card"><h3>OTP Verification Rate</h3><span class="pkstore-stat-number"><?=$otp['verification_rate']?>%</span><p class="pkstore-stat-sub"><?=$otp['verified_24h']?> / <?=$otp['total_24h']?> in last 24h</p></div>
<div class="card pkstore-stat-card"><h3>AI Sessions (24h)</h3><span class="pkstore-stat-number"><?=$ai['total_sessions_24h']?></span><p class="pkstore-stat-sub">Avg <?=$ai['avg_messages_per_session']?> msgs/session</p></div>
</div>
<?php if(!empty($_GET['pos_sync_retry'])):?>
<div class="notice <?=sanitize_key(wp_unslash($_GET['pos_sync_retry']))==='success'?'notice-success':'notice-error'?> is-dismissible"><p><?=sanitize_key(wp_unslash($_GET['pos_sync_retry']))==='success'?'POS order sync retry completed.':'POS order sync retry failed; the order remains available for investigation.'?></p></div>
<?php endif;?>
<?php if(!empty($failedPosSyncs)):?>
<h2>Failed POS Syncs — Manual Recovery</h2>
<table class="widefat striped"><thead><tr><th>POS Reference</th><th>Session</th><th>Location</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php foreach($failedPosSyncs as $failedOrder):?>
<tr><td><code><?=esc_html($failedOrder['order_ref'])?></code></td><td><?=number_format((int)$failedOrder['session_id'])?></td><td><?=number_format((int)$failedOrder['location_id'])?></td><td><?=esc_html($failedOrder['created_at'])?></td><td>
<form method="post" action="<?=esc_url(admin_url('admin-post.php'))?>">
<input type="hidden" name="action" value="pkstore_retry_pos_sync"><input type="hidden" name="pos_order_id" value="<?=absint($failedOrder['id'])?>">
<?php wp_nonce_field('pkstore_retry_pos_sync_'.absint($failedOrder['id']));?>
<button type="submit" class="button button-secondary">Retry safely</button></form></td></tr>
<?php endforeach;?>
</tbody></table>
<?php endif;?>
<h2>Delivery Health (Last 30 Days)</h2>
<div class="pkstore-courier-grid">
<?php foreach($health as $code=>$data):?>
<div class="card pkstore-courier-card"><h3><?=esc_html($data['courier'])?></h3>
<canvas id="chart-<?=esc_attr($code)?>" width="120" height="120"></canvas>
<table class="pkstore-courier-stats">
<tr><td>Total</td><td><strong><?=number_format($data['total'])?></strong></td></tr>
<tr style="color:#00a32a"><td>Delivered</td><td><?=$data['delivered']?> (<?=$data['delivery_rate']?>%)</td></tr>
<tr style="color:#d63638"><td>RTO</td><td><?=$data['rto']?> (<?=$data['rto_rate']?>%)</td></tr>
<tr><td>In Transit</td><td><?=$data['in_transit']?></td></tr>
</table></div>
<?php endforeach;?>
</div>
<br><a href="<?=esc_url(admin_url('admin.php?page=pkstore-setup'))?>" class="button button-primary button-large">⚙ Open Setup Wizard</a>
</div>
<script>document.addEventListener('DOMContentLoaded',function(){var d=<?=wp_json_encode(array_map(fn($k,$v)=>['id'=>'chart-'.$k,'delivered'=>$v['delivered'],'rto'=>$v['rto'],'transit'=>$v['in_transit']],array_keys($health),$health))?>;d.forEach(function(c){var el=document.getElementById(c.id);if(!el||!window.Chart)return;new Chart(el,{type:'doughnut',data:{labels:['Delivered','RTO','In Transit'],datasets:[{data:[c.delivered,c.rto,c.transit],backgroundColor:['#00a32a','#d63638','#dba617'],borderWidth:2}]},options:{responsive:false,plugins:{legend:{display:false}},cutout:'65%'}});});});</script>
