<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Admin\ListTable;
if(!class_exists('WP_List_Table'))require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
final class LogisticsListTable extends \WP_List_Table {
    public function __construct(private readonly \wpdb $db){parent::__construct(['singular'=>'Log Entry','plural'=>'Log Entries','ajax'=>false]);}
    public function get_columns():array{return['created_at'=>'Time','courier_code'=>'Courier','log_type'=>'Type','endpoint'=>'Endpoint','http_status'=>'HTTP','duration_ms'=>'Duration'];}
    public function get_sortable_columns():array{return['created_at'=>['created_at',true],'duration_ms'=>['duration_ms',false],'http_status'=>['http_status',false]];}
    public function column_courier_code($i):string{$c=strtoupper((string)($i['courier_code']??'')); if(!$c)return'<em>—</em>'; $cols=['TCS'=>'#1d6fa4','LEOPARDS'=>'#d63638','TRAX'=>'#00a32a']; return'<span style="background:'.($cols[$c]??'#646970').';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">'.esc_html($c).'</span>';}
    public function column_log_type($i):string{return match($i['log_type']??''){ 'request'=>'<span style="color:#0073aa;">→ Request</span>', 'response'=>'<span style="color:#00a32a;">← Response</span>', 'failure'=>'<span style="color:#d63638;">✗ Failure</span>', default=>'<span>—</span>'};}
    public function column_http_status($i):string{$s=(int)($i['http_status']??0);if(!$s)return'<em>N/A</em>';$c=$s>=500?'#d63638':($s>=400?'#dba617':'#00a32a');return'<span style="color:'.$c.';font-weight:600;">'.$s.'</span>';}
    public function column_duration_ms($i):string{$ms=(int)($i['duration_ms']??0);if(!$ms)return'<em>—</em>';$c=$ms>10000?'color:#d63638;':($ms>5000?'color:#dba617;':'');return'<span style="'.$c.'">'.number_format($ms).' ms</span>';}
    public function column_endpoint($i):string{$ep=(string)($i['endpoint']??'');$path=parse_url($ep,PHP_URL_PATH)?:$ep;return'<code title="'.esc_attr($ep).'">'.esc_html(substr($path,-40)).'</code>';}
    public function column_created_at($i):string{try{$dt=new \DateTimeImmutable($i['created_at']??'now');return'<span title="'.esc_attr($dt->format('Y-m-d H:i:s')).'">'.esc_html(wp_date('M j, H:i',$dt->getTimestamp())).'</span>';}catch(\Throwable){return esc_html($i['created_at']??'');}}
    public function column_default($i,$col):string{return esc_html((string)($i[$col]??'—'));}
    public function prepare_items():void{
        $t=$this->db->prefix.'pkstore_logistics_log'; $al=['created_at','duration_ms','http_status']; $by=in_array($_GET['orderby']??'',$al,true)?sanitize_key($_GET['orderby']):'created_at'; $dir=strtoupper($_GET['order']??'DESC')==='ASC'?'ASC':'DESC';
        $w='1=1';$args=[];if($c=sanitize_key($_GET['courier']??'')){$w.=' AND courier_code=%s';$args[]=$c;}if($ty=sanitize_key($_GET['log_type']??'')){$w.=' AND log_type=%s';$args[]=$ty;}
        $total=(int)$this->db->get_var(!empty($args)?$this->db->prepare("SELECT COUNT(*) FROM {$t} WHERE {$w}",...$args):"SELECT COUNT(*) FROM {$t} WHERE {$w}");
        $this->items=$this->db->get_results($this->db->prepare("SELECT * FROM {$t} WHERE {$w} ORDER BY {$by} {$dir} LIMIT %d OFFSET %d",...array_merge($args,[30,($this->get_pagenum()-1)*30])),ARRAY_A)?:[];
        $this->set_pagination_args(['total_items'=>$total,'per_page'=>30,'total_pages'=>ceil($total/30)]);
        $this->_column_headers=[$this->get_columns(),[],[$this->get_sortable_columns()]];
    }
}
