<?php defined('ABSPATH')||exit;
global $wpdb; $table=$wpdb->prefix.'pkstore_city_mappings';
if(isset($_POST['pkstore_city_action'])&&$_POST['pkstore_city_action']==='save'&&current_user_can('manage_options')){
    check_admin_referer('pkstore_city_save');
    $data=['wc_city'=>strtolower(sanitize_text_field($_POST['wc_city']??'')),'tcs_id'=>(int)($_POST['tcs_id']??0),'leopards_code'=>strtoupper(sanitize_text_field($_POST['leopards_code']??'')),'trax_name'=>sanitize_text_field($_POST['trax_name']??''),'zone'=>in_array($_POST['zone']??'',['A','B','C'],true)?$_POST['zone']:'B','province'=>sanitize_text_field($_POST['province']??''),'is_active'=>1,'updated_at'=>current_time('mysql')];
    $cid=isset($_POST['city_id'])?(int)$_POST['city_id']:null;
    if($cid)$wpdb->update($table,$data,['id'=>$cid]);else $wpdb->insert($table,$data);
    wp_safe_redirect(admin_url('admin.php?page=pkstore-city-mapping&updated=1'));exit;
}
$saved=isset($_GET['updated']); $editId=isset($_GET['edit'])?(int)$_GET['edit']:null;
$editRow=$editId?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$editId),ARRAY_A):null;
$mappings=$wpdb->get_results("SELECT * FROM {$table} ORDER BY wc_city ASC",ARRAY_A)?:[];
?>
<div class="wrap pkstore-admin"><h1>City Mapping Manager</h1>
<?php if($saved):?><div class="notice notice-success is-dismissible"><p>City mapping saved.</p></div><?php endif;?>
<div style="display:grid;grid-template-columns:350px 1fr;gap:20px;margin-top:16px;">
<div class="card" style="padding:20px;"><h2><?=$editRow?'Edit: '.esc_html($editRow['wc_city']):'Add New City'?></h2>
<form method="POST"><?php wp_nonce_field('pkstore_city_save');?><input type="hidden" name="pkstore_city_action" value="save">
<?php if($editRow):?><input type="hidden" name="city_id" value="<?=$editRow['id']?>"><?php endif;?>
<table class="form-table">
<tr><th><label for="wc_city">WC City</label></th><td><input type="text" id="wc_city" name="wc_city" class="regular-text" value="<?=esc_attr($editRow['wc_city']??'')?>" placeholder="karachi" required></td></tr>
<tr><th><label for="tcs_id">TCS ID</label></th><td><input type="number" id="tcs_id" name="tcs_id" class="small-text" value="<?=esc_attr($editRow['tcs_id']??'')?>" required></td></tr>
<tr><th><label for="leopards_code">Leopards</label></th><td><input type="text" id="leopards_code" name="leopards_code" class="small-text" value="<?=esc_attr($editRow['leopards_code']??'')?>" placeholder="KHI" required></td></tr>
<tr><th><label for="trax_name">Trax Name</label></th><td><input type="text" id="trax_name" name="trax_name" class="regular-text" value="<?=esc_attr($editRow['trax_name']??'')?>" required></td></tr>
<tr><th>Zone</th><td><select name="zone"><?php foreach(['A'=>'Zone A (Major)','B'=>'Zone B (Secondary)','C'=>'Zone C (Remote)']as $v=>$l):?><option value="<?=$v?>" <?=selected($editRow['zone']??'B',$v,false)?>><?=$l?></option><?php endforeach;?></select></td></tr>
<tr><th>Province</th><td><select name="province"><?php foreach(['Sindh','Punjab','KPK','Balochistan','Federal','GB','AJK']as $p):?><option value="<?=$p?>" <?=selected($editRow['province']??'',$p,false)?>><?=$p?></option><?php endforeach;?></select></td></tr>
</table><?php submit_button($editRow?'Update':'Add City');?>
<?php if($editRow):?><a href="<?=esc_url(admin_url('admin.php?page=pkstore-city-mapping'))?>" class="button">Cancel</a><?php endif;?>
</form></div>
<div><table class="wp-list-table widefat fixed striped"><thead><tr><th>WC City</th><th>TCS</th><th>Leopards</th><th>Trax</th><th>Zone</th><th>Province</th><th>Edit</th></tr></thead><tbody>
<?php foreach($mappings as $r):?><tr><td><code><?=esc_html($r['wc_city'])?></code></td><td><?=esc_html($r['tcs_id'])?></td><td><code><?=esc_html($r['leopards_code'])?></code></td><td><?=esc_html($r['trax_name'])?></td><td><strong><?=esc_html($r['zone'])?></strong></td><td><?=esc_html($r['province'])?></td><td><a href="<?=esc_url(add_query_arg(['page'=>'pkstore-city-mapping','edit'=>$r['id']],admin_url('admin.php')))?>" class="button button-small">Edit</a></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
