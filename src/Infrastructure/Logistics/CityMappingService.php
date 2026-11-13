<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
final class CityMappingService {
    public function __construct(private readonly \wpdb $db){}
    private function t():string{return $this->db->prefix.'pkstore_city_mappings';}
    public function getTcsCityId(string $c):int{return(int)$this->lookup($c)['tcs_id'];}
    public function getLeopardsCityCode(string $c):string{return(string)$this->lookup($c)['leopards_code'];}
    public function getTraxCityName(string $c):string{return(string)$this->lookup($c)['trax_name'];}
    public function getZone(string $c):string{try{return(string)$this->lookup($c)['zone'];}catch(\InvalidArgumentException){return 'C';}}
    public function isServiceable(string $c):bool{try{$this->lookup($c);return true;}catch(\InvalidArgumentException){return false;}}
    private function lookup(string $c):array{
        $n=strtolower(trim(preg_replace('/[^a-zA-Z\s]/','',  $c)??''));
        $r=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->t()} WHERE wc_city=%s AND is_active=1",$n),ARRAY_A);
        if($r)return $r;
        $all=$this->db->get_results("SELECT * FROM {$this->t()} WHERE is_active=1",ARRAY_A)?:[];
        foreach($all as $row){if(str_contains($n,$row['wc_city'])||str_contains($row['wc_city'],$n))return $row;}
        throw new \InvalidArgumentException("City [{$c}] not in mapping table. Add via Store Operations > City Mapping.");
    }
}
