<?php defined('ABSPATH')||exit; ?>
<div id="pk-affiliate-dashboard" class="pk-affiliate-wrap">
<?php if(!is_user_logged_in()):?>
  <div style="text-align:center;padding:40px 20px;">
    <h2>🤝 Affiliate Program</h2>
    <p>Please <a href="<?=esc_url(wp_login_url(get_permalink()))?>">log in</a> or <a href="<?=esc_url(wp_registration_url())?>">create an account</a> to join our affiliate program.</p>
  </div>
<?php else:?>
  <div id="pk-affiliate-app" data-rest-url="<?=esc_url(rest_url('pkstore/v1'))?>" data-nonce="<?=esc_attr(wp_create_nonce('wp_rest'))?>">
    <div id="pk-aff-loading" style="text-align:center;padding:40px;">Loading your dashboard...</div>
  </div>
<?php endif;?>
</div>
<style>
.pk-affiliate-wrap{max-width:900px;margin:0 auto;padding:20px}
.pk-aff-tabs{display:flex;gap:4px;border-bottom:2px solid #e0e0e0;margin-bottom:24px;flex-wrap:wrap}
.pk-aff-tab{padding:10px 16px;cursor:pointer;border:none;background:none;font-size:13px;font-weight:600;color:#646970;border-bottom:3px solid transparent;margin-bottom:-2px}
.pk-aff-tab.active{color:#0073aa;border-bottom-color:#0073aa}
.pk-aff-panel{display:none}.pk-aff-panel.active{display:block}
.pk-aff-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:24px}
.pk-aff-stat{background:#f6f7f7;border-radius:8px;padding:16px;text-align:center}
.pk-aff-stat-number{font-size:22px;font-weight:700;color:#0073aa;display:block}
.pk-aff-stat-label{font-size:11px;color:#646970;margin-top:4px}
.pk-aff-code-box{background:#f0f8ff;border:2px solid #0073aa;border-radius:8px;padding:20px;margin-bottom:24px}
.pk-aff-code{font-size:26px;font-weight:700;letter-spacing:4px;color:#0073aa;margin:8px 0}
.pk-aff-url{font-size:12px;color:#646970;word-break:break-all;background:#fff;padding:8px;border-radius:4px;border:1px solid #ddd;margin-top:8px}
.pk-aff-table{width:100%;border-collapse:collapse;font-size:13px}
.pk-aff-table th{text-align:left;padding:8px 6px;border-bottom:2px solid #e0e0e0;font-size:11px;color:#646970;text-transform:uppercase}
.pk-aff-table td{padding:10px 6px;border-bottom:1px solid #f0f0f0}
.pk-aff-form{display:grid;gap:12px;max-width:480px}
.pk-aff-form label{font-weight:600;font-size:13px}
.pk-aff-form input,.pk-aff-form select,.pk-aff-form textarea{width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px}
.pk-aff-btn{background:#0073aa;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:600;cursor:pointer;font-size:14px}
.pk-aff-btn:hover{background:#005177}.pk-aff-btn:disabled{opacity:.5;cursor:not-allowed}
.pk-aff-notice{padding:12px 16px;border-radius:6px;margin:12px 0}
.pk-aff-notice--success{background:#edfaef;color:#00a32a;border-left:4px solid #00a32a}
.pk-aff-notice--error{background:#fce8e8;color:#d63638;border-left:4px solid #d63638}
.pk-aff-register{max-width:460px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px}
</style>
