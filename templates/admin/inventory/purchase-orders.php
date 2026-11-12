<?php defined('ABSPATH')||exit; ?>
<div class="wrap pkstore-admin">
<h1>🚚 Purchase Orders</h1>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>PO Number</th><th>Supplier</th><th>Location</th><th>Status</th><th>Total</th><th>Created</th><th>Received</th></tr></thead>
<tbody>
<?php foreach($pos as $po):
$sc=match($po['status']){'received'=>'#00a32a','partial'=>'#dba617','cancelled'=>'#d63638',default=>'#646970'};
?>
<tr>
<td><strong><code><?=esc_html($po['po_number'])?></code></strong></td>
<td><?=esc_html($po['supplier_name']??'—')?></td>
<td><?=esc_html($po['location_name']??'—')?></td>
<td><span style="color:<?=$sc?>;font-weight:600;"><?=esc_html(ucfirst($po['status']))?></span></td>
<td>PKR <?=number_format((int)$po['total_pkr'])?></td>
<td><?=esc_html(date('M j, Y',strtotime($po['created_at'])))?></td>
<td><?=$po['received_at']?esc_html(date('M j, Y',strtotime($po['received_at']))):'<em style="color:#646970;">Pending</em>'?></td>
</tr>
<?php endforeach;?>
<?php if(empty($pos)):?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No purchase orders yet.</td></tr><?php endif;?>
</tbody></table>
</div>
