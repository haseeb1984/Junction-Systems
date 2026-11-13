<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
final class LogisticsLogger {
    public function __construct(private readonly \wpdb $db){}
    private function t():string{return $this->db->prefix.'pkstore_logistics_log';}
    public function logRequest(string $cc,string $url,array $payload):int{$this->db->insert($this->t(),['log_type'=>'request','courier_code'=>$cc,'endpoint'=>$url,'payload'=>wp_json_encode($this->redact($payload),JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql')]);return(int)$this->db->insert_id;}
    public function logResponse(int $rid,int $status,array $data,int $ms):void{$this->db->insert($this->t(),['log_type'=>'response','courier_code'=>'','endpoint'=>"request_id:{$rid}",'payload'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'http_status'=>$status,'duration_ms'=>$ms,'created_at'=>current_time('mysql')]);}
    public function logFailure(int $rid,string $reason,int $ms):void{$this->db->insert($this->t(),['log_type'=>'failure','courier_code'=>'','endpoint'=>"request_id:{$rid}",'payload'=>wp_json_encode(['failure_reason'=>$reason]),'http_status'=>0,'duration_ms'=>$ms,'created_at'=>current_time('mysql')]);}
    private function redact(array $p):array{foreach($p as $k=>$v){foreach(['api_key','password','secret','token','integrity_key']as $s){if(stripos($k,$s)!==false){$p[$k]='[REDACTED]';break;}}}return $p;}
}
