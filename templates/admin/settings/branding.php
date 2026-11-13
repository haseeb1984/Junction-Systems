<?php defined('ABSPATH')||exit; ?>
<div class="wrap pkstore-admin">
<h1>🎨 Branding & White-Label</h1>
<p style="color:#646970;margin-bottom:20px;">Customise the store for each client. Export and import config to clone settings across installations.</p>

<?php if($saved==='1'):?><div class="notice notice-success is-dismissible"><p>✅ Branding saved.</p></div><?php endif;?>
<?php if($saved==='imported'):?><div class="notice notice-success is-dismissible"><p>✅ Config imported successfully.</p></div><?php endif;?>
<?php if($saved==='import_failed'):?><div class="notice notice-error is-dismissible"><p>❌ Import failed. Invalid JSON.</p></div><?php endif;?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

<!-- Left: Branding Form -->
<div>
<form method="POST" enctype="multipart/form-data">
<?php wp_nonce_field('pkstore_settings_save');?>
<input type="hidden" name="pkstore_settings_action" value="save_branding">

<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">📋 Client Info</h2>
<table class="form-table">
<tr><th>Client Name</th><td><input type="text" name="client_name" value="<?=esc_attr($config['client_name'])?>" class="regular-text" placeholder="e.g. Ahmed Fashion Store"></td></tr>
<tr><th>License Key</th><td><input type="text" name="client_license_key" value="<?=esc_attr($config['client_license_key'])?>" class="regular-text"></td></tr>
<tr><th>Hide "PK Store" Brand</th><td><label><input type="checkbox" name="hide_pkstore_brand" value="1" <?=checked($config['hide_pkstore_brand'],'1',false)?>> Remove all PK Store branding from admin and frontend</label></td></tr>
</table>
</div>

<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">🏪 Store Identity</h2>
<table class="form-table">
<tr><th>Store Name Override</th><td><input type="text" name="store_name" value="<?=esc_attr($config['store_name'])?>" class="regular-text" placeholder="Overrides WordPress site title"></td></tr>
<tr><th>Store Tagline</th><td><input type="text" name="store_tagline" value="<?=esc_attr($config['store_tagline'])?>" class="regular-text" placeholder="Pakistan's Best Online Store"></td></tr>
<tr><th>Logo URL</th><td><input type="url" name="logo_url" value="<?=esc_attr($config['logo_url'])?>" class="regular-text" placeholder="https://...logo.png"></td></tr>
<tr><th>Favicon URL</th><td><input type="url" name="favicon_url" value="<?=esc_attr($config['favicon_url'])?>" class="regular-text"></td></tr>
</table>
</div>

<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">🎨 Colors</h2>
<table class="form-table">
<tr><th>Primary Color</th><td><input type="color" name="primary_color" value="<?=esc_attr($config['primary_color'])?>" style="width:60px;height:36px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;"> <small style="color:#646970;">Buttons, links, headers</small></td></tr>
<tr><th>Secondary Color</th><td><input type="color" name="secondary_color" value="<?=esc_attr($config['secondary_color'])?>" style="width:60px;height:36px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;"> <small style="color:#646970;">Dark elements, text</small></td></tr>
<tr><th>Accent Color</th><td><input type="color" name="accent_color" value="<?=esc_attr($config['accent_color'])?>" style="width:60px;height:36px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;"> <small style="color:#646970;">Success, confirmations</small></td></tr>
</table>
</div>

<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">🖥 POS Branding</h2>
<table class="form-table">
<tr><th>POS Logo URL</th><td><input type="url" name="pos_logo_url" value="<?=esc_attr($config['pos_logo_url'])?>" class="regular-text" placeholder="Leave blank to use Store Logo"></td></tr>
<tr><th>POS Theme</th><td>
  <select name="pos_theme">
    <option value="light" <?=selected($config['pos_theme'],'light',false)?>>Light (Default)</option>
    <option value="dark" <?=selected($config['pos_theme'],'dark',false)?>>Dark</option>
    <option value="custom" <?=selected($config['pos_theme'],'custom',false)?>>Custom (uses Primary Color)</option>
  </select>
</td></tr>
</table>
</div>

<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">🧾 Receipt Branding</h2>
<table class="form-table">
<tr><th>Receipt Header</th><td><input type="text" name="receipt_header" value="<?=esc_attr($config['receipt_header'])?>" class="regular-text" placeholder="Store address, phone, etc."></td></tr>
<tr><th>Receipt Footer</th><td><input type="text" name="receipt_footer" value="<?=esc_attr($config['receipt_footer'])?>" class="regular-text"></td></tr>
<tr><th>Show Logo on Receipt</th><td><label><input type="checkbox" name="receipt_show_logo" value="1" <?=checked($config['receipt_show_logo'],'1',false)?>> Print logo on thermal receipts</label></td></tr>
<tr><th>Email Header Color</th><td><input type="color" name="email_header_color" value="<?=esc_attr($config['email_header_color'])?>" style="width:60px;height:36px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;"></td></tr>
<tr><th>Email Logo URL</th><td><input type="url" name="email_logo_url" value="<?=esc_attr($config['email_logo_url'])?>" class="regular-text"></td></tr>
</table>
</div>

<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">💻 Custom CSS</h2>
<p style="color:#646970;font-size:13px;">Applied to storefront only. Use CSS variables: <code>--pkstore-primary</code>, <code>--pkstore-secondary</code>, <code>--pkstore-accent</code></p>
<textarea name="custom_css" rows="8" style="width:100%;font-family:monospace;font-size:13px;border:1px solid #ddd;border-radius:4px;padding:10px;"><?=esc_textarea($config['custom_css'])?></textarea>
</div>

<?php submit_button('Save Branding','primary large');?>
</form>
</div>

<!-- Right: Preview + Export/Import -->
<div>

<!-- Live Preview -->
<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;">👁 Live Preview</h2>
<div id="brand-preview" style="border:1px solid #ddd;border-radius:8px;overflow:hidden;font-family:sans-serif;">
  <div id="preview-header" style="background:<?=esc_attr($config['primary_color'])?>;padding:16px 20px;display:flex;align-items:center;gap:12px;">
    <?php if($config['logo_url']):?><img src="<?=esc_url($config['logo_url'])?>" style="height:32px;object-fit:contain;" alt="Logo"><?php else:?>
    <div style="width:32px;height:32px;background:rgba(255,255,255,.3);border-radius:4px;"></div><?php endif;?>
    <span style="color:#fff;font-weight:700;font-size:16px;"><?=esc_html($config['store_name']?:get_bloginfo('name'))?></span>
  </div>
  <div style="padding:16px;background:#f6f7f7;">
    <button style="background:<?=esc_attr($config['primary_color'])?>;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;">Add to Cart</button>
    <button style="background:<?=esc_attr($config['accent_color'])?>;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:700;margin-left:8px;cursor:pointer;">Buy Now</button>
  </div>
</div>
<p style="font-size:12px;color:#646970;margin-top:8px;">Save to update preview.</p>
</div>

<!-- Export Config -->
<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;">📤 Export Client Config</h2>
<p style="color:#646970;font-size:13px;">Export all branding + feature settings as JSON. Import on a new installation to replicate this client's setup instantly.</p>
<form method="POST">
<?php wp_nonce_field('pkstore_settings_save');?>
<input type="hidden" name="pkstore_settings_action" value="export_config">
<a href="<?=esc_url(admin_url('admin-ajax.php?action=pkstore_export_config&_wpnonce='.wp_create_nonce('pkstore_export_config')))?>" class="button button-large" download="pkstore-config.json">⬇ Download Config JSON</a>
</form>
</div>

<!-- Import Config -->
<div class="card" style="padding:20px;margin-bottom:20px;">
<h2 style="margin-top:0;">📥 Import Client Config</h2>
<p style="color:#646970;font-size:13px;">Paste a previously exported JSON config to instantly apply branding and feature settings.</p>
<form method="POST">
<?php wp_nonce_field('pkstore_settings_save');?>
<input type="hidden" name="pkstore_settings_action" value="import_config">
<textarea name="import_json" rows="6" style="width:100%;font-family:monospace;font-size:12px;border:1px solid #ddd;border-radius:4px;padding:8px;" placeholder='{"branding":{...},"features":{...}}'></textarea>
<br><button type="submit" class="button button-primary" style="margin-top:8px;">Import Config</button>
</form>
</div>

<!-- What branding affects -->
<div class="card" style="padding:20px;">
<h2 style="margin-top:0;">📋 What Branding Controls</h2>
<ul style="font-size:13px;color:#646970;line-height:2;padding-left:16px;">
<li>Store name on POS header, receipts, and emails</li>
<li>Logo on storefront, POS, receipts, and WooCommerce emails</li>
<li>Primary/secondary colors applied via CSS variables site-wide</li>
<li>POS theme (light/dark/custom) applied to cashier interface</li>
<li>Thermal receipt header and footer text</li>
<li>Email header color and logo</li>
<li>Custom CSS injected into storefront head</li>
<li>"PK Store" branding hidden when white-label is enabled</li>
</ul>
</div>

</div>
</div>
</div>
