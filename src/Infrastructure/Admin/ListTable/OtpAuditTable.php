<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin\ListTable;
if(!class_exists('WP_List_Table'))require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
final class OtpAuditTable extends \WP_List_Table {
    public function __construct(private readonly \wpdb $db){parent::__construct(['singular'=>'OTP Attempt','plural'=>'OTP Attempts','ajax'=>false]);}
    public function get_columns():array{return['phone_e164'=>'Phone','order_id'=>'Order','attempt_count'=>'Attempts','is_verified'=>'Status','created_at'=>'Requested At'];}
    public function get_sortable_columns():array{return['created_at'=>['created_at',true],'attempt_count'=>['attempt_count',false],'is_verified'=>['is_verified',false]];}
    public function column_phone_e164($i):string{$p=(string)($i['phone_e164']??'');return'<code>'.esc_html(strlen($p)>7?substr($p,0,5).'*****'.substr($p,-3):'***').'</code>';}
    public function column_order_id($i):string{$id=(string)($i['order_id']??'');return $id?'<a href="'.esc_url(admin_url('post.php?post='.(int)$id.'&action=edit')).'" target="_blank">#'.esc_html($id).'</a>':'<em>—</em>';}
    public function column_attempt_count($i):string{$c=(int)($i['attempt_count']??0);$col=$c>=3?'#d63638':($c>=2?'#dba617':'#00a32a');return'<span style="color:'.$col.';font-weight:700;">'.esc_html($c).' / 3</span>';}
    public function column_is_verified($i):string{$v=(bool)($i['is_verified']??false);$c=(int)($i['attempt_count']??0);try{$exp=new \DateTimeImmutable($i['expires_at']??'now');$expired=new \DateTimeImmutable()>$exp;}catch(\Throwable){$expired=true;}if($v)return'<span style="color:#00a32a;font-weight:600;">✓ Verified</span>';if($c>=3)return'<span style="color:#d63638;">✗ Exhausted</span>';if($expired)return'<span style="color:#646970;">⏱ Expired</span>';return'<span style="color:#0073aa;">◷ Pending</span>';}
    public function column_created_at($i):string{try{$dt=new \DateTimeImmutable($i['created_at']??'now');return'<span title="'.esc_attr($dt->format('Y-m-d H:i:s')).'">'.esc_html(human_time_diff($dt->getTimestamp()).' ago').'</span>';}catch(\Throwable){return esc_html($i['created_at']??'');}}
    public function column_default($i,$col):string{return esc_html((string)($i[$col]??'—'));}
    public function prepare_items():void{
        $t=$this->db->prefix.'pkstore_otp_attempts';$al=['created_at','attempt_count','is_verified','order_id'];
        $by=in_array($_GET['orderby']??'',$al,true)?sanitize_key($_GET['orderby']):'created_at';$dir=strtoupper($_GET['order']??'DESC')==='ASC'?'ASC':'DESC';
        $total=(int)$this->db->get_var("SELECT COUNT(*) FROM {$t}");
        $this->items=$this->db->get_results($this->db->prepare("SELECT * FROM {$t} ORDER BY {$by} {$dir} LIMIT %d OFFSET %d",25,($this->get_pagenum()-1)*25),ARRAY_A)?:[];
        $this->set_pagination_args(['total_items'=>$total,'per_page'=>25,'total_pages'=>ceil($total/25)]);
        $this->_column_headers=[$this->get_columns(),[],[$this->get_sortable_columns()]];
    }
}
