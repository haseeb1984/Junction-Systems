<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Verification;
use PkStore\Domain\Verification\{SmsProviderInterface};
use PkStore\Domain\Verification\Exception\SmsProviderException;
use PkStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
final class LocalSmsProvider implements SmsProviderInterface {
    public function __construct(private readonly LoggerInterface $log, private readonly SettingsManager $cfg){}
    public function getProviderName():string{return 'LocalPakistanSMS';}
    public function isConfigured():bool{return $this->cfg->isConfigured('LOCAL_SMS_API_URL')&&$this->cfg->isConfigured('LOCAL_SMS_API_KEY');}
    public function send(PakistanPhoneNumber $to,string $msg):void{
        $url=$this->cfg->get('LOCAL_SMS_API_URL');$key=$this->cfg->get('LOCAL_SMS_API_KEY');$sid=$this->cfg->get('LOCAL_SMS_SENDER_ID','PKSTORE');
        if(!$url||!$key)throw new SmsProviderException('Local SMS not configured. Go to PK Store > Setup Wizard > SMS.');
        $r=wp_remote_post($url,['timeout'=>8,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['api_key'=>$key,'sender_id'=>$sid,'to'=>$to->e164,'message'=>$msg,'type'=>'plain'])]);
        if(is_wp_error($r))throw new SmsProviderException('Local SMS unreachable: '.$r->get_error_message());
        $b=json_decode(wp_remote_retrieve_body($r),true);
        if(wp_remote_retrieve_response_code($r)>=400||($b['status']??'')==='error')throw new SmsProviderException('Local SMS error: '.($b['message']??'Unknown'));
    }
}
