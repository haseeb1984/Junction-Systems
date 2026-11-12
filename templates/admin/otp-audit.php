<?php defined('ABSPATH')||exit;?>
<div class="wrap pkstore-admin"><h1>OTP Audit Log</h1>
<p style="color:#646970;">Phone numbers are masked for privacy. Search by order ID only.</p>
<form method="GET"><input type="hidden" name="page" value="pkstore-otp-audit">
<?php $table->search_box('Search by Order ID','s');$table->display();?></form></div>
