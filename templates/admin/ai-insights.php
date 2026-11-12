<?php defined('ABSPATH')||exit;?>
<div class="wrap pkstore-admin"><h1>AI Chat Insights</h1>
<div class="pkstore-stat-grid">
<div class="card pkstore-stat-card"><h3>Sessions (24h)</h3><span class="pkstore-stat-number"><?=$ai['total_sessions_24h']?></span></div>
<div class="card pkstore-stat-card"><h3>Messages (24h)</h3><span class="pkstore-stat-number"><?=$ai['total_messages_24h']?></span></div>
<div class="card pkstore-stat-card"><h3>Avg Msgs/Session</h3><span class="pkstore-stat-number"><?=$ai['avg_messages_per_session']?></span></div>
</div>
<div class="card" style="margin-top:20px;padding:20px;">
<p>Configure AI Chat in <a href="<?=esc_url(admin_url('admin.php?page=pkstore-setup&tab=ai'))?>">Setup Wizard → AI Chat</a></p>
</div></div>
