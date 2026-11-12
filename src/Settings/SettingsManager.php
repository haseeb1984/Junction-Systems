<?php
declare(strict_types=1);
namespace JunctionStore\Settings;
final class SettingsManager{
    private const ENC='junction_store_enc_';private const PLAIN='junction_store_';private const CIPHER='AES-256-CBC';
    private array $cache=[];private static ?self $i=null;
    private function __construct(){}
    public static function getInstance():self{if(self::$i===null)self::$i=new self();return self::$i;}
    public function get(string $k,string $default=''):string{
        if(isset($this->cache[$k]))return $this->cache[$k];
        $env=getenv($k);if($env!==false&&$env!==''){$this->cache[$k]=$env;return $env;}
        $enc=get_option(self::ENC.strtolower($k),'');
        if($enc!==''){$d=$this->decrypt($enc);if($d!==false){$this->cache[$k]=$d;return $d;}}
        $v=(string)get_option(self::PLAIN.strtolower($k),$default);$this->cache[$k]=$v;return $v;
    }
    public function setSecret(string $k,string $v):bool{unset($this->cache[$k]);return update_option(self::ENC.strtolower($k),$this->encrypt($v),false);}
    public function set(string $k,string $v):bool{unset($this->cache[$k]);return update_option(self::PLAIN.strtolower($k),$v,false);}
    public function isConfigured(string $k):bool{return $this->get($k)!=='';}
    public function getSetupStatus():array{
        $req=['JAZZCASH_MERCHANT_ID','JAZZCASH_PASSWORD','JAZZCASH_INTEGRITY_KEY','TCS_API_KEY','TCS_ACCOUNT_NUMBER'];
        $missing=array_filter($req,fn($k)=>!$this->isConfigured($k));
        return['complete'=>empty($missing),'missing'=>array_values($missing),'percent'=>(int)round((count($req)-count($missing))/count($req)*100)];
    }
    public function saveFromPost(array $post,string $section):void{
        foreach($this->getSectionKeys($section) as $k=>$sensitive){
            $pk=strtolower($k);if(!isset($post[$pk]))continue;
            $v=sanitize_text_field((string)$post[$pk]);
            if($sensitive&&str_starts_with($v,'●●●●●●●●'))continue;
            if($v==='')continue;
            $sensitive?$this->setSecret($k,$v):$this->set($k,$v);
        }
    }
    private function getSectionKeys(string $s):array{return match($s){
        'jazzcash'=>['JAZZCASH_MERCHANT_ID'=>false,'JAZZCASH_PASSWORD'=>true,'JAZZCASH_INTEGRITY_KEY'=>true,'JAZZCASH_SANDBOX'=>false],
        'easypaisa'=>['EASYPAISA_STORE_ID'=>false,'EASYPAISA_HASH_KEY'=>true,'EASYPAISA_SANDBOX'=>false],
        'tcs'=>['TCS_API_KEY'=>true,'TCS_ACCOUNT_NUMBER'=>false,'TCS_WEBHOOK_SECRET'=>true,'TCS_SANDBOX_MODE'=>false],
        'leopards'=>['LEOPARDS_API_KEY'=>true,'LEOPARDS_API_PASSWORD'=>true,'LEOPARDS_SANDBOX_MODE'=>false],
        'trax'=>['TRAX_API_TOKEN'=>true,'TRAX_SENDER_ID'=>false,'TRAX_WEBHOOK_SECRET'=>true,'TRAX_SANDBOX_MODE'=>false],
        'sms'=>['TWILIO_ACCOUNT_SID'=>false,'TWILIO_AUTH_TOKEN'=>true,'TWILIO_FROM_NUMBER'=>false,'LOCAL_SMS_API_URL'=>false,'LOCAL_SMS_API_KEY'=>true,'LOCAL_SMS_SENDER_ID'=>false],
        'ai'=>['GEMINI_API_KEY'=>true],
        'general'=>['DEFAULT_PAYMENT_GATEWAY'=>false,'DEFAULT_COURIER'=>false,'DEFAULT_SMS_PROVIDER'=>false,'STORE_HELPLINE'=>false,'STORE_WHATSAPP'=>false],
        default=>[],
    };}
    private function key():string{return substr(hash('sha256',(defined('AUTH_KEY')?AUTH_KEY:'pkstore').get_site_url()),0,32);}
    private function encrypt(string $v):string{$iv=random_bytes(openssl_cipher_iv_length(self::CIPHER));$e=openssl_encrypt($v,self::CIPHER,$this->key(),OPENSSL_RAW_DATA,$iv);return $e===false?'':base64_encode($iv.$e);}
    private function decrypt(string $enc):string|false{$d=base64_decode($enc,true);if($d===false)return false;$l=openssl_cipher_iv_length(self::CIPHER);return openssl_decrypt(substr($d,$l),self::CIPHER,$this->key(),OPENSSL_RAW_DATA,substr($d,0,$l));}
}
