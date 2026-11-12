<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Verification;
use JunctionStore\Domain\Verification\{SmsProviderInterface};
use JunctionStore\Domain\Verification\Exception\SmsProviderException;
use JunctionStore\Domain\Verification\ValueObject\PakistanPhoneNumber;
use JunctionStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
final class TwilioSmsProvider implements SmsProviderInterface {
    public function __construct(private readonly LoggerInterface $log, private readonly SettingsManager $cfg){}
    public function getProviderName():string{return 'Twilio';}
    public function isConfigured():bool{return $this->cfg->isConfigured('TWILIO_ACCOUNT_SID')&&$this->cfg->isConfigured('TWILIO_AUTH_TOKEN');}
    public function send(PakistanPhoneNumber $to,string $msg):void{
        $sid=$this->cfg->get('TWILIO_ACCOUNT_SID');$tok=$this->cfg->get('TWILIO_AUTH_TOKEN');$from=$this->cfg->get('TWILIO_FROM_NUMBER');
        if(!$sid||!$tok||!$from)throw new SmsProviderException('Twilio not configured. Go to Junction Store > Setup Wizard > SMS.');
        $r=wp_remote_post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",['timeout'=>10,'headers'=>['Authorization'=>'Basic '.base64_encode("{$sid}:{$tok}"),'Content-Type'=>'application/x-www-form-urlencoded'],'body'=>['To'=>$to->e164,'From'=>$from,'Body'=>$msg]]);
        if(is_wp_error($r))throw new SmsProviderException('Twilio connection failed: '.$r->get_error_message());
        if(wp_remote_retrieve_response_code($r)!==201){$b=json_decode(wp_remote_retrieve_body($r),true);throw new SmsProviderException('Twilio error: '.($b['message']??'Unknown'));}
    }
}
