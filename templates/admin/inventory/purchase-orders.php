<?php defined('ABSPATH') || exit; ?>
<div class="wrap pkstore-admin">
<h1>🚚 Purchase Orders</h1>
<?php if ($msg !== ''): ?><div class="notice <?= $status === 'error' ? 'notice-error' : 'notice-success' ?> is-dismissible"><p><?= esc_html($msg) ?></p></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin:20px 0;">
<section class="postbox" style="padding:16px;">
<h2>Create Supplier</h2>
<form method="post">
<?php wp_nonce_field('pkstore_inventory_action'); ?>
<input type="hidden" name="pkstore_inventory_action" value="create_supplier">
<p><label>Supplier name<br><input class="regular-text" required name="supplier_name" maxlength="150"></label></p>
<p><label>Contact person<br><input class="regular-text" name="supplier_contact" maxlength="100"></label></p>
<p><label>Phone<br><input class="regular-text" name="supplier_phone" maxlength="20"></label></p>
<p><label>Email<br><input class="regular-text" type="email" name="supplier_email" maxlength="150"></label></p>
<p><label>Address<br><textarea class="large-text" name="supplier_address" rows="2"></textarea></label></p>
<?php submit_button('Save Supplier'); ?>
</form>
</section>

<section class="postbox" style="padding:16px;">
<h2>Create Purchase Order</h2>
<?php if (empty($suppliers) || empty($locations) || empty($products)): ?>
<p class="description">Add at least one active supplier, location and inventory product before creating a purchase order.</p>
<?php else: ?>
<form method="post">
<?php wp_nonce_field('pkstore_inventory_action'); ?>
<input type="hidden" name="pkstore_inventory_action" value="create_purchase_order">
<p><label>Supplier<br><select required name="supplier_id"><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= esc_html($supplier['name']) ?></option><?php endforeach; ?></select></label></p>
<p><label>Receiving location<br><select required name="location_id"><option value="">Select location</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location->id ?>"><?= esc_html($location->name) ?></option><?php endforeach; ?></select></label></p>
<div id="pkstore-po-items">
<div class="pkstore-po-item" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:10px 0;">
<label>Product<br><select required name="items[0][product_id]"><option value="">Select product</option><?php foreach ($products as $product): ?><option value="<?= (int) $product->id ?>"><?= esc_html($product->name . ' (' . $product->sku . ')') ?></option><?php endforeach; ?></select></label>
<label>Quantity<br><input type="number" min="1" step="1" required name="items[0][quantity]" style="width:100px;"></label>
<label>Unit cost (PKR)<br><input type="number" min="0" step="1" required name="items[0][unit_cost_pkr]" style="width:130px;"></label>
</div>
</div>
<p><button type="button" class="button" id="pkstore-add-po-item">+ Add another product</button></p>
<p><label>Notes<br><textarea class="large-text" name="notes" rows="2"></textarea></label></p>
<?php submit_button('Create Purchase Order'); ?>
</form>
<script>
(function () {
 const wrap = document.getElementById('pkstore-po-items');
 const add = document.getElementById('pkstore-add-po-item');
 if (!wrap || !add) return;
 add.addEventListener('click', function () {
   const index = wrap.querySelectorAll('.pkstore-po-item').length;
   const row = wrap.querySelector('.pkstore-po-item').cloneNode(true);
   row.querySelectorAll('select,input').forEach(function (el) {
     el.name = el.name.replace(/items\[\d+\]/, 'items[' + index + ']');
     if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
   });
   wrap.appendChild(row);
 });
})();
</script>
<?php endif; ?>
</section>
</div>

<h2>Purchase Order Register</h2>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>PO Number</th><th>Supplier</th><th>Location</th><th>Status</th><th>Total</th><th>Created</th><th>Received</th></tr></thead>
<tbody>
<?php foreach ($pos as $po):
$statusColor = match ($po['status']) { 'received' => '#00a32a', 'partial' => '#dba617', 'cancelled' => '#d63638', default => '#646970' };
?>
<tr>
<td><strong><code><?= esc_html($po['po_number']) ?></code></strong></td>
<td><?= esc_html($po['supplier_name'] ?? '—') ?></td>
<td><?= esc_html($po['location_name'] ?? '—') ?></td>
<td><span style="color:<?= esc_attr($statusColor) ?>;font-weight:600;"><?= esc_html(ucfirst($po['status'])) ?></span></td>
<td>PKR <?= number_format((int) $po['total_pkr']) ?></td>
<td><?= !empty($po['created_at']) ? esc_html(date_i18n('M j, Y', strtotime($po['created_at']))) : '—' ?></td>
<td><?= !empty($po['received_at']) ? esc_html(date_i18n('M j, Y', strtotime($po['received_at']))) : '<em style="color:#646970;">Pending</em>' ?></td>
</tr>
<?php if (!in_array($po['status'], ['received', 'cancelled'], true)): ?>
<tr><td colspan="7">
<form method="post" style="padding:12px 0;">
<?php wp_nonce_field('pkstore_inventory_action'); ?>
<input type="hidden" name="pkstore_inventory_action" value="receive_purchase_order">
<input type="hidden" name="purchase_order_id" value="<?= (int) $po['id'] ?>">
<input type="hidden" name="receipt_reference" value="<?= esc_attr(wp_generate_uuid4()) ?>">
<strong>Receive stock for <?= esc_html($po['po_number']) ?></strong>
<table class="widefat striped" style="margin:8px 0;max-width:900px;">
<thead><tr><th>Product</th><th>Ordered</th><th>Already received</th><th>Remaining</th><th>Receive now</th></tr></thead>
<tbody>
<?php foreach (($poItems[(int) $po['id']] ?? []) as $item):
$remaining = max(0, (int) $item['quantity_ordered'] - (int) $item['quantity_received']);
?>
<tr>
<td><?= esc_html(($item['product_name'] ?? ('Product #' . $item['product_id'])) . ' (' . ($item['sku'] ?? '') . ')') ?></td>
<td><?= (int) $item['quantity_ordered'] ?></td>
<td><?= (int) $item['quantity_received'] ?></td>
<td><?= $remaining ?></td>
<td><input type="number" min="0" max="<?= $remaining ?>" step="1" value="0" name="receive_qty[<?= (int) $item['item_id'] ?>]" style="width:100px;" <?= $remaining === 0 ? 'disabled' : '' ?>></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php submit_button('Record Receipt', 'secondary', 'submit', false); ?>
</form>
</td></tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if (empty($pos)): ?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No purchase orders yet.</td></tr><?php endif; ?>
</tbody></table>
<p class="description">Partial receipts are supported. Each submission carries a unique receipt reference, making a repeated submission safe against duplicate stock additions.</p>
</div>
