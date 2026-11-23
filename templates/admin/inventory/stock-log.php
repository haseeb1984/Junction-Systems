<?php defined('ABSPATH')||exit; ?>
<div class="wrap pkstore-admin">
<h1>📊 Stock Movement Log</h1>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>Date/Time</th><th>Product</th><th>Location</th><th>Type</th><th>Change</th><th>Before</th><th>After</th><th>Reference</th><th>Note</th></tr></thead>
<tbody>
<?php foreach($movements as $m):
$delta=(int)$m['quantity_delta'];$isPos=$delta>0;
?>
<tr>
<td style="font-size:12px;"><?=esc_html(date('M j H:i',strtotime($m['created_at'])))?></td>
<td><strong><?=esc_html($m['product_name']??'—')?></strong><br><small><code><?=esc_html($m['sku']??'')?></code></small></td>
<td><?=esc_html($m['location_name']??'—')?></td>
<td><span style="font-size:11px;text-transform:uppercase;font-weight:600;color:#646970;"><?=esc_html(str_replace('_',' ',$m['movement_type']))?></span></td>
<td style="font-weight:700;color:<?=$isPos?'#00a32a':'#d63638'?>;"><?=$isPos?'+':'' ?><?=$delta?></td>
<td><?=$m['quantity_before']?></td>
<td><strong><?=$m['quantity_after']?></strong></td>
<td style="font-size:11px;"><?=esc_html($m['reference_type'])?><?=$m['reference_id']?' #'.esc_html($m['reference_id']):'' ?></td>
<td style="font-size:12px;color:#646970;"><?=esc_html($m['note'])?></td>
</tr>
<?php endforeach;?>
<?php if(empty($movements)):?><tr><td colspan="9" style="text-align:center;padding:24px;color:#646970;">No stock movements recorded yet.</td></tr><?php endif;?>
</tbody></table>
</div>
