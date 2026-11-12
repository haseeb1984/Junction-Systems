<?php defined('ABSPATH')||exit;?>
<div class="wrap pkstore-admin">
<h1>🛒 Store Operations <a href="<?=esc_url(add_query_arg('bust_cache','1'))?>" class="page-title-action">Refresh Stats</a></h1>
<div class="pkstore-stat-grid">
<div class="card pkstore-stat-card <?=($logistics['booking_failures_24h']>5)?'pkstore-stat-card--alert':''?>">
<h3>Orders Today</h3><span class="pkstore-stat-number"><?=number_format($logistics['total_today'])?></span>
<?php if($logistics['pending_booking']>0):?><p class="pkstore-stat-warning">⚠ <?=$logistics['pending_booking']?> pending booking</p><?php endif;?>
</div>
<div class="card pkstore-stat-card"><h3>API Failures (24h)</h3><span class="pkstore-stat-number" style="color:<?=$logistics['booking_failures_24h']>5?'#d63638':'inherit'?>"><?=$logistics['booking_failures_24h']?></span><p class="pkstore-stat-sub">Avg: <?=$logistics['avg_api_response_ms']?>ms</p></div>
<div class="card pkstore-stat-card"><h3>OTP Verification Rate</h3><span class="pkstore-stat-number"><?=$otp['verification_rate']?>%</span><p class="pkstore-stat-sub"><?=$otp['verified_24h']?> / <?=$otp['total_24h']?> today</p></div>
<div class="card pkstore-stat-card"><h3>AI Sessions (24h)</h3><span class="pkstore-stat-number"><?=$ai['total_sessions_24h']?></span><p class="pkstore-stat-sub">Avg <?=$ai['avg_messages_per_session']?> msgs/session</p></div>
</div>
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
