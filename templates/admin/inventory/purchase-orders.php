<?php defined('ABSPATH')||exit; ?>
<div class="wrap pkstore-admin">
<h1>🚚 Purchase Orders</h1>
<?php if(!empty($msg)): ?><div class="notice <?= $status==='error'?'notice-error':'notice-success' ?> is-dismissible"><p><?=esc_html($msg)?></p></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin:20px 0;">
<section class="postbox" style="padding:16px;">
<h2>Create Supplier</h2>
<form method="post">
<?php wp_nonce_field('pkstore_inventory_action'); ?>
<input type="hidden" name="pkstore_inventory_action" value="create_supplier">
<p><label>Supplier name<br><input class="regular-text" required name="supplier_name"></label></p>
<p><label>Contact person<br><input class="regular-text" name="supplier_contact"></label></p>
<p><label>Phone<br><input class="regular-text" name="supplier_phone"></label></p>
<p><label>Email<br><input class="regular-text" type="email" name="supplier_email"></label></p>
<p><label>Address<br><textarea class="large-text" name="supplier_address" rows="2"></textarea></label></p>
<?php submit_button('Save Supplier'); ?>
</form>
</section>
<section class="postbox" style="padding:16px;">
<h2>Create Purchase Order</h2>
<form method="post">
<?php wp_nonce_field('pkstore_inventory_action'); ?>
<input type="hidden" name="pkstore_inventory_action" value="create_purchase_order">
<p><label>Supplier<br><select required name="supplier_id"><option value="">Select supplier</option><?php foreach($suppliers as $supplier): ?><option value="<?=(int)$supplier['id']?>"><?=esc_html($supplier['name'])?></option><?php endforeach; ?></select></label></p>
<p><label>Receiving location<br><select required name="location_id"><option value="">Select location</option><?php foreach($locations as $location): ?><option value="<?=(int)$location->id?>"><?=esc_html($location->name)?></option><?php endforeach; ?></select></label></p>
<p><label>Product<br><select required name="product_id"><option value="">Select product</option><?php foreach($products as $product): ?><option value="<?=(int)$product->id?>"><?=esc_html($product->name.' ('.$product->sku.')')?></option><?php endforeach; ?></select></label></p>
<p><label>Ordered quantity<br><input type="number" min="1" step="1" required name="quantity"></label></p>
<p><label>Unit cost (PKR)<br><input type="number" min="0" step="1" required name="unit_cost_pkr"></label></p>
<p><label>Notes<br><textarea class="large-text" name="notes" rows="2"></textarea></label></p>
<?php submit_button('Create Purchase Order'); ?>
</form>
</section>
</div>

<h2>Purchase Order Register</h2>
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
<?php if(!in_array($po['status'],['received','cancelled'],true)): ?>
<tr><td colspan="7">
<form method="post" style="padding:12px 0;">
<?php wp_nonce_field('pkstore_inventory_action'); ?>
<input type="hidden" name="pkstore_inventory_action" value="receive_purchase_order">
<input type="hidden" name="purchase_order_id" value="<?=(int)$po['id']?>">
<input type="hidden" name="receipt_reference" value="<?=esc_attr(wp_generate_uuid4())?>">
<strong>Receive stock for <?=esc_html($po['po_number'])?></strong>
<table class="widefat striped" style="margin:8px 0;max-width:900px;">
<thead><tr><th>Product</th><th>Ordered</th><th>Already received</th><th>Remaining</th><th>Receive now</th></tr></thead>
<tbody>
<?php foreach(($poItems[(int)$po['id']]??[]) as $item): $remaining=max(0,(int)$item['quantity_ordered']-(int)$item['quantity_received']); ?>
<tr>
<td><?=esc_html($item['product_name']??('Product #'.$item['product_id']))?></td>
<td><?=(int)$item['quantity_ordered']?></td>
<td><?=(int)$item['quantity_received']?></td>
<td><?=$remaining?></td>
<td><input type="number" min="0" max="<?=$remaining?>" step="1" value="0" name="receive_qty[<?=(int)$item['item_id']?>]" style="width:100px;" <?=$remaining===0?'disabled':''?>></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php submit_button('Record Receipt','secondary','submit',false); ?>
</form>
</td></tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if(empty($pos)): ?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No purchase orders yet.</td></tr><?php endif; ?>
</tbody></table>
<p class="description">Partial receipts are supported. The receipt reference makes a repeated form submission safe against duplicate stock additions.</p>
</div>
