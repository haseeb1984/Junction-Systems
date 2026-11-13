<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Logistics;
use PkStore\Domain\Logistics\CourierProviderInterface;
use PkStore\Domain\Logistics\Exception\CourierApiException;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
abstract class AbstractCourier implements CourierProviderInterface {
    public function __construct(protected readonly LoggerInterface $log, protected readonly CityMappingService $cm, protected readonly RateCalculator $rc, protected readonly LogisticsLogger $al, protected readonly SettingsManager $cfg){}
    final protected function cfg(string $k):string{$v=$this->cfg->get($k);if($v==='')throw new \RuntimeException("Setting [{$k}] not configured. Go to PK Store > Setup Wizard.");return $v;}
    final protected function dispatch(string $url,array $payload,array $headers=[],string $method='POST',int $timeout=15):array{
        $start=microtime(true);$aid=$this->al->logRequest($this->getCourierCode(),$url,$payload);
        $r=wp_remote_request($url,['method'=>$method,'headers'=>array_merge(['Content-Type'=>'application/json','Accept'=>'application/json'],$headers),'body'=>wp_json_encode($payload),'timeout'=>$timeout]);
        $ms=(int)round((microtime(true)-$start)*1000);
        if(is_wp_error($r)){$this->al->logFailure($aid,$r->get_error_message(),$ms);throw new CourierApiException('HTTP error: '.$r->get_error_message(),$this->getCourierCode(),0);}
        $s=wp_remote_retrieve_response_code($r);$d=json_decode(wp_remote_retrieve_body($r),true)??[];
        $this->al->logResponse($aid,(int)$s,$d,$ms);
        if($s>=400)throw new CourierApiException("HTTP {$s}",$this->getCourierCode(),(int)$s);
        return $d;
    }
    public function isHealthy():bool{try{$this->cfg(match($this->getCourierCode()){'tcs'=>'TCS_API_KEY','leopards'=>'LEOPARDS_API_KEY',default=>'TRAX_API_TOKEN'});return true;}catch(\Throwable){return false;}}
}
