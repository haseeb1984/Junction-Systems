<?php defined('ABSPATH')||exit; ?>
<div class="wrap pkstore-admin">
<h1>🏷 Barcode Label Printer</h1>
<p style="color:#646970;">Select products and print A4 label sheets or export ZPL for Zebra thermal label printers.</p>

<form method="POST">
<?php wp_nonce_field('pkstore_print_labels');?>
<input type="hidden" name="pkstore_print_labels" value="1">

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;">
<div>
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden;">
<div style="background:#f6f7f7;padding:12px 16px;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:12px;">
<strong>Select Products</strong>
<button type="button" onclick="document.querySelectorAll('.prod-check').forEach(c=>c.checked=true)" class="button button-small">Select All</button>
<button type="button" onclick="document.querySelectorAll('.prod-check').forEach(c=>c.checked=false)" class="button button-small">Deselect All</button>
</div>
<table class="wp-list-table widefat fixed" style="border:none;">
<thead><tr><th style="width:40px;"><input type="checkbox" onchange="document.querySelectorAll('.prod-check').forEach(c=>c.checked=this.checked)"></th><th>Product</th><th>SKU</th><th>Barcode</th><th>Price</th></tr></thead>
<tbody>
<?php foreach($products as $p):?>
<tr>
<td><input type="checkbox" class="prod-check" name="product_ids[]" value="<?=$p->id?>" <?=$p->hasBarcode()?'':' style="accent-color:#f39c12;"'?>></td>
<td><strong><?=esc_html($p->name)?></strong></td>
<td><code><?=esc_html($p->sku)?></code></td>
<td><?php if($p->hasBarcode()):?><code style="font-size:11px;"><?=esc_html($p->getBarcode())?></code><?php else:?><span style="color:#f39c12;">⚠ No barcode</span><?php endif;?></td>
<td>PKR <?=number_format($p->salePricePkr)?></td>
</tr>
<?php endforeach;?>
</tbody></table>
</div>
</div>

<div class="card" style="padding:20px;">
<h3 style="margin-top:0;">Print Settings</h3>
<table class="form-table">
<tr><th>Copies per product</th><td><input type="number" name="copies_per_label" value="1" min="1" max="100" class="small-text"> <small>How many labels to print per selected product</small></td></tr>
<tr><th>Format</th><td>
  <label><input type="radio" name="format" value="a4" checked> A4 Sheet (3 columns, browser print)</label><br>
  <label><input type="radio" name="format" value="zpl"> ZPL File (Zebra thermal printer)</label>
</td></tr>
</table>
<div style="background:#f6f7f7;border-radius:6px;padding:12px;margin-bottom:16px;font-size:13px;">
<strong>A4 Sheet:</strong> Prints 3 labels per row on any printer. Needs Libre Barcode 128 font (loaded from Google Fonts).<br><br>
<strong>ZPL File:</strong> Download and send to Zebra ZD220, ZD420, or any ZPL-compatible label printer via USB or network.
</div>
<button type="submit" class="button button-primary button-large" style="width:100%;">🖨 Print / Download Labels</button>
</div>
</div>
</form>
</div>
