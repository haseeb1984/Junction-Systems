<?php defined('ABSPATH')||exit; ?>
<div class="wrap pkstore-admin">
<h1>📦 Inventory Manager
  <a href="<?=esc_url(admin_url('admin.php?page=pkstore-pos'))?>" class="page-title-action" target="_blank">🖥 Open POS</a>
</h1>
<?php if($msg):?><div class="notice notice-<?=$status==='error'?'error':'success'?> is-dismissible"><p><?=esc_html($msg)?></p></div><?php endif;?>

<!-- Low Stock Alert -->
<?php if(!empty($lowStock)):?>
<div class="notice notice-warning"><p>⚠ <strong><?=count($lowStock)?> products</strong> are at or below reorder point:</p>
<p><?=implode(', ',array_map(fn($p)=>esc_html($p->name).' ('.$p->getBarcode().')',$lowStock))?></p></div>
<?php endif;?>

<!-- Quick Actions -->
<div style="display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;">
<form method="POST" style="display:inline;">
<?php wp_nonce_field('pkstore_inventory_action');?>
<input type="hidden" name="pkstore_inventory_action" value="generate_barcodes">
<button class="button">Generate Missing Barcodes</button>
</form>
<form method="POST" style="display:inline;">
<?php wp_nonce_field('pkstore_inventory_action');?>
<input type="hidden" name="pkstore_inventory_action" value="sync_woocommerce">
<button class="button">Sync All Stock → WooCommerce</button>
</form>
<form method="GET" style="display:inline;">
<input type="hidden" name="page" value="pkstore-inventory">
<input type="search" name="s" value="<?=esc_attr($search)?>" placeholder="Search products..." style="padding:5px 10px;">
<button class="button">Search</button>
</form>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">
<!-- Product Table -->
<div>
<table class="wp-list-table widefat fixed striped">
<thead><tr><th>Name</th><th>SKU</th><th>Barcode</th><th>Sale Price</th><th>Stock</th><th>Reorder At</th><th>Adjust</th></tr></thead>
<tbody>
<?php foreach($products as $p):
  $totalStock=$stockRepo->getTotalStock($p->id);
  $isLow=$totalStock<=$p->reorderPoint;
?>
<tr>
<td><strong><?=esc_html($p->name)?></strong><?php if($p->wcProductId):?> <a href="<?=esc_url(get_edit_post_link($p->wcProductId))?>" target="_blank" style="font-size:11px;">WC#<?=$p->wcProductId?></a><?php endif;?></td>
<td><code><?=esc_html($p->sku)?></code></td>
<td>
  <?php if($p->hasBarcode()):?>
  <code style="font-size:11px;"><?=esc_html($p->getBarcode())?></code>
  <?php else:?>
  <form method="POST" style="display:inline;"><?php wp_nonce_field('pkstore_inventory_action');?><input type="hidden" name="pkstore_inventory_action" value="generate_barcodes"><button class="button button-small">Generate</button></form>
  <?php endif;?>
</td>
<td>PKR <?=number_format($p->salePricePkr)?></td>
<td style="font-weight:700;color:<?=$isLow?'#d63638':'#00a32a'?>;"><?=$totalStock?> <?=esc_html($p->unit)?><?=$isLow?' ⚠':'' ?></td>
<td><?=$p->reorderPoint?></td>
<td>
  <button class="button button-small" onclick="document.getElementById('adj-<?=$p->id?>').style.display='block'">Adjust</button>
  <div id="adj-<?=$p->id?>" style="display:none;margin-top:8px;">
  <form method="POST"><?php wp_nonce_field('pkstore_inventory_action');?>
  <input type="hidden" name="pkstore_inventory_action" value="adjust_stock">
  <input type="hidden" name="product_id" value="<?=$p->id?>">
  <select name="location_id" style="padding:4px;">
  <?php foreach($locations as $l):?><option value="<?=$l->id?>"><?=esc_html($l->name)?></option><?php endforeach;?>
  </select><br>
  <input type="number" name="quantity" placeholder="+5 or -3" style="width:80px;padding:4px;margin:4px 0;">
  <select name="movement_type" style="padding:4px;">
  <option value="adjustment">Adjustment</option>
  <option value="purchase_receive">Purchase Receive</option>
  <option value="damage">Damage/Loss</option>
  <option value="opening">Opening Stock</option>
  </select><br>
  <input type="text" name="note" placeholder="Note" style="width:100%;padding:4px;margin:4px 0;">
  <button class="button button-primary button-small" type="submit">Save</button>
  </form></div>
</td>
</tr>
<?php endforeach;?>
<?php if(empty($products)):?><tr><td colspan="7" style="text-align:center;padding:24px;color:#646970;">No products yet. Add your first product below.</td></tr><?php endif;?>
</tbody></table>
</div>

<!-- Add Product Form -->
<div class="card" style="padding:20px;">
<h2>Add Product</h2>
<form method="POST"><?php wp_nonce_field('pkstore_inventory_action');?>
<input type="hidden" name="pkstore_inventory_action" value="add_product">
<table class="form-table">
<tr><th><label for="sku">SKU *</label></th><td><input type="text" id="sku" name="sku" class="regular-text" required placeholder="SHIRT-RED-L"></td></tr>
<tr><th><label for="name">Name *</label></th><td><input type="text" id="name" name="name" class="regular-text" required placeholder="Red Shirt Large"></td></tr>
<tr><th><label for="barcode">Barcode</label></th><td><input type="text" id="barcode" name="barcode" class="regular-text" placeholder="Leave blank to auto-generate"></td></tr>
<tr><th><label for="category">Category</label></th><td><input type="text" id="category" name="category" class="regular-text" placeholder="Clothing"></td></tr>
<tr><th><label for="cost_price">Cost Price (PKR)</label></th><td><input type="number" id="cost_price" name="cost_price" class="small-text" min="0" value="0"></td></tr>
<tr><th><label for="sale_price">Sale Price (PKR) *</label></th><td><input type="number" id="sale_price" name="sale_price" class="small-text" min="0" value="0" required></td></tr>
<tr><th><label for="reorder_point">Reorder Point</label></th><td><input type="number" id="reorder_point" name="reorder_point" class="small-text" min="0" value="5"><p class="description">Alert when stock falls to this level</p></td></tr>
<tr><th><label for="unit">Unit</label></th><td><select id="unit" name="unit"><option value="piece">Piece</option><option value="kg">KG</option><option value="litre">Litre</option><option value="box">Box</option><option value="pair">Pair</option><option value="set">Set</option></select></td></tr>
<tr><th><label for="wc_product_id">WC Product ID</label></th><td><input type="number" id="wc_product_id" name="wc_product_id" class="small-text" min="0" value="0"><p class="description">Optional: link to WooCommerce product for online sync</p></td></tr>
</table>
<?php submit_button('Add Product');?>
</form>
</div>
</div>
</div>
