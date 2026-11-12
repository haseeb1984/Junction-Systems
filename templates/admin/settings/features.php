<?php defined('ABSPATH')||exit;
$groups = [];
foreach ($features as $key => $def) {
    $groups[$def['group']][$key] = $def;
}
$groupColors = ['Payments'=>'#0073aa','Checkout'=>'#8b5cf6','Couriers'=>'#f39c12','Marketing'=>'#e74c3c','Analytics'=>'#00a32a','Operations'=>'#1d2327','Integrations'=>'#646970'];
?>
<div class="wrap pkstore-admin">
<h1>⚙️ Feature Settings</h1>
<p style="color:#646970;margin-bottom:20px;">Enable or disable any module. Disabled modules are completely unloaded — zero performance cost. Each client installation can have a different set of features.</p>
<?php if($saved):?><div class="notice notice-success is-dismissible"><p>✅ Features saved successfully.</p></div><?php endif;?>

<form method="POST">
<?php wp_nonce_field('pkstore_settings_save');?>
<input type="hidden" name="pkstore_settings_action" value="save_features">

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;margin-bottom:24px;">
<?php foreach($groups as $groupName => $groupFeatures):
  $color = $groupColors[$groupName] ?? '#646970';
?>
<div class="card" style="padding:0;overflow:hidden;border-top:4px solid <?=esc_attr($color)?>;">
  <div style="background:<?=esc_attr($color)?>;padding:12px 16px;">
    <h2 style="color:#fff;margin:0;font-size:15px;"><?=esc_html($groupName)?></h2>
  </div>
  <div style="padding:16px;">
    <?php foreach($groupFeatures as $key => $def):
      $enabled = $fm->isEnabled($key);
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;">
      <div>
        <label for="feat_<?=esc_attr($key)?>" style="font-weight:600;font-size:13px;cursor:pointer;"><?=esc_html($def['label'])?></label>
        <?php if($def['default']):?><span style="font-size:10px;background:#e8f5e9;color:#00a32a;padding:1px 6px;border-radius:10px;margin-left:6px;">DEFAULT ON</span><?php endif;?>
      </div>
      <label class="pkstore-toggle">
        <input type="checkbox" id="feat_<?=esc_attr($key)?>" name="feature_<?=esc_attr($key)?>" value="1" <?=checked($enabled,true,false)?>>
        <span class="pkstore-toggle-slider"></span>
      </label>
    </div>
    <?php endforeach;?>
  </div>
</div>
<?php endforeach;?>
</div>

<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
<h3 style="margin-top:0;">💡 How Feature Toggles Work</h3>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;font-size:13px;color:#646970;">
<div>🔌 <strong>Payments:</strong> Disabled gateways are completely removed from WooCommerce checkout. Customers only see enabled payment methods.</div>
<div>🚚 <strong>Couriers:</strong> Disabled couriers are excluded from the circuit breaker failover chain. Shipping methods are removed from cart.</div>
<div>📦 <strong>Integrations:</strong> Disabled integrations stop all API calls and background syncs. No API keys needed for disabled features.</div>
</div>
</div>

<?php submit_button('Save Feature Settings','primary large');?>
</form>
</div>

<style>
.pkstore-toggle{position:relative;display:inline-block;width:44px;height:24px}
.pkstore-toggle input{opacity:0;width:0;height:0}
.pkstore-toggle-slider{position:absolute;cursor:pointer;inset:0;background:#ccc;border-radius:24px;transition:.3s}
.pkstore-toggle-slider:before{content:"";position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.pkstore-toggle input:checked+.pkstore-toggle-slider{background:#0073aa}
.pkstore-toggle input:checked+.pkstore-toggle-slider:before{transform:translateX(20px)}
</style>
