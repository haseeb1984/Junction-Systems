<?php
declare(strict_types=1);
namespace PkStore\Settings;
final class SettingsManager{
    private const ENC='pk_store_enc_';private const PLAIN='pk_store_';private const CIPHER='AES-256-GCM';
    private array $cache=[];private static ?self $i=null;
    private function __construct(){}
    public static function getInstance():self{if(self::$i===null)self::$i=new self();return self::$i;}
    public function get(string $k,string $default=''):string{
        if(isset($this->cache[$k]))return $this->cache[$k];
        $env=getenv($k);if($env!==false&&$env!==''){$this->cache[$k]=$env;return $env;}
        $enc=get_option(self::ENC.strtolower($k),'');
        if($enc!==''){
            $d=$this->decrypt($enc);
            if($d===false){
                // A present but unauthentic encrypted value must fail closed;
                // never fall back to a legacy plaintext copy.
                $this->cache[$k]=$default;
                return $default;
            }
            $this->cache[$k]=$d;
            return $d;
        }

        $plainOption=self::PLAIN.strtolower($k);
        $v=(string)get_option($plainOption,$default);
        if($v!==$default && self::isSensitiveKey($k)){
            try {
                $migrated=$this->encrypt($v);
                if($migrated!=='' && update_option(self::ENC.strtolower($k),$migrated,false)){
                    delete_option($plainOption);
                } else {
                    error_log('[PK Store] Could not migrate a legacy integration secret; refusing plaintext use.');
                    $this->cache[$k]=$default;
                    return $default;
                }
            } catch (\Throwable) {
                error_log('[PK Store] Could not encrypt a legacy integration secret; refusing plaintext use.');
                $this->cache[$k]=$default;
                return $default;
            }
        }
        $this->cache[$k]=$v;
        return $v;
    }
    public function setSecret(string $k,string $v):bool{
        unset($this->cache[$k]);
        try { $encrypted = $this->encrypt($v); }
        catch (\Throwable) { return false; }
        if ($encrypted === '') return false;
        $saved=update_option(self::ENC.strtolower($k),$encrypted,false);
        if($saved) delete_option(self::PLAIN.strtolower($k));
        return $saved;
    }
    public function set(string $k,string $v):bool{unset($this->cache[$k]);return update_option(self::PLAIN.strtolower($k),$v,false);}
    public function isConfigured(string $k):bool{return $this->get($k)!=='';}
    public function getSetupStatus():array{
        $req=['JAZZCASH_MERCHANT_ID','JAZZCASH_PASSWORD','JAZZCASH_INTEGRITY_KEY','TCS_API_KEY','TCS_ACCOUNT_NUMBER'];
        $missing=array_filter($req,fn($k)=>!$this->isConfigured($k));
        return['complete'=>empty($missing),'missing'=>array_values($missing),'percent'=>(int)round((count($req)-count($missing))/count($req)*100)];
    }
    public function saveFromPost(array $post,string $section):bool{
        foreach($this->getSectionKeys($section) as $k=>$sensitive){
            $pk=strtolower($k);if(!isset($post[$pk]))continue;
            $v=sanitize_text_field((string)$post[$pk]);
            if($sensitive&&str_starts_with($v,'●●●●●●●●'))continue;
            if($v==='')continue;
            if($sensitive){if(!$this->setSecret($k,$v))return false;}else{$this->set($k,$v);}
        }
        return true;
    }
    private function getSectionKeys(string $s):array{return match($s){
        'jazzcash'=>['JAZZCASH_MERCHANT_ID'=>false,'JAZZCASH_PASSWORD'=>true,'JAZZCASH_INTEGRITY_KEY'=>true,'JAZZCASH_SANDBOX'=>false],
        'easypaisa'=>['EASYPAISA_USERNAME'=>true,'EASYPAISA_PASSWORD'=>true,'EASYPAISA_STORE_ID'=>false,'EASYPAISA_ACCOUNT_NUMBER'=>true,'EASYPAISA_HASH_KEY'=>true,'EASYPAISA_SANDBOX'=>false],
        'tcs'=>['TCS_API_KEY'=>true,'TCS_ACCOUNT_NUMBER'=>false,'TCS_WEBHOOK_SECRET'=>true,'TCS_SANDBOX_MODE'=>false],
        'leopards'=>['LEOPARDS_API_KEY'=>true,'LEOPARDS_API_PASSWORD'=>true,'LEOPARDS_SANDBOX_MODE'=>false],
        'trax'=>['TRAX_API_TOKEN'=>true,'TRAX_SENDER_ID'=>false,'TRAX_WEBHOOK_SECRET'=>true,'TRAX_SANDBOX_MODE'=>false],
        'whatsapp'=>['WHATSAPP_TOKEN'=>true,'WHATSAPP_PHONE_ID'=>false,'WHATSAPP_VERIFY_TOKEN'=>true,'WHATSAPP_APP_SECRET'=>true,'WHATSAPP_ADMIN_PHONE'=>false,'WHATSAPP_TEMPLATE_ORDER_CONFIRMATION'=>false,'WHATSAPP_TEMPLATE_SHIPPED'=>false,'WHATSAPP_TEMPLATE_DELIVERED'=>false,'WHATSAPP_TEMPLATE_LOW_STOCK'=>false,'WHATSAPP_TEMPLATE_LANGUAGE'=>false,'WHATSAPP_GRAPH_API_VERSION'=>false],
        'sms'=>['TWILIO_ACCOUNT_SID'=>false,'TWILIO_AUTH_TOKEN'=>true,'TWILIO_FROM_NUMBER'=>false,'LOCAL_SMS_API_URL'=>false,'LOCAL_SMS_API_KEY'=>true,'LOCAL_SMS_SENDER_ID'=>false],
        'ai'=>['GROQ_API_KEY'=>true],
        'quickbooks'=>['QUICKBOOKS_CLIENT_ID'=>false,'QUICKBOOKS_CLIENT_SECRET'=>true,'QUICKBOOKS_REFRESH_TOKEN'=>true,'QUICKBOOKS_ACCESS_TOKEN'=>true,'QUICKBOOKS_ACCESS_TOKEN_EXPIRES'=>false,'QUICKBOOKS_REALM_ID'=>false,'QUICKBOOKS_SANDBOX'=>false],
        'daraz'=>['DARAZ_APP_KEY'=>false,'DARAZ_APP_SECRET'=>true,'DARAZ_ACCESS_TOKEN'=>true,'DARAZ_REFRESH_TOKEN'=>true],
        'postex'=>['POSTEX_TOKEN'=>true,'POSTEX_STORE_ID'=>false,'POSTEX_SANDBOX'=>false],
        'rider'=>['RIDER_API_KEY'=>true],
        'mailchimp'=>['MAILCHIMP_API_KEY'=>true,'MAILCHIMP_LIST_ID'=>false,'MAILCHIMP_STORE_ID'=>false],
        'klaviyo'=>['KLAVIYO_PRIVATE_KEY'=>true,'KLAVIYO_LIST_ID'=>false],
        'google_analytics'=>['GA4_MEASUREMENT_ID'=>false,'GA4_API_SECRET'=>true],
        'facebook_pixel'=>['FACEBOOK_PIXEL_ID'=>false,'FACEBOOK_CAPI_TOKEN'=>true],
        'general'=>['DEFAULT_PAYMENT_GATEWAY'=>false,'DEFAULT_COURIER'=>false,'DEFAULT_SMS_PROVIDER'=>false,'STORE_HELPLINE'=>false,'STORE_WHATSAPP'=>false],
        default=>[],
    };}
    private static function isSensitiveKey(string $key): bool {
        static $keys = [
            'JAZZCASH_PASSWORD','JAZZCASH_INTEGRITY_KEY',
            'EASYPAISA_USERNAME','EASYPAISA_PASSWORD','EASYPAISA_ACCOUNT_NUMBER','EASYPAISA_HASH_KEY','TCS_API_KEY','TCS_WEBHOOK_SECRET',
            'LEOPARDS_API_KEY','LEOPARDS_API_PASSWORD','TRAX_API_TOKEN',
            'TRAX_WEBHOOK_SECRET','WHATSAPP_TOKEN','WHATSAPP_VERIFY_TOKEN','WHATSAPP_APP_SECRET','TWILIO_AUTH_TOKEN','LOCAL_SMS_API_KEY',
            'GROQ_API_KEY','GEMINI_API_KEY',
            'QUICKBOOKS_CLIENT_SECRET','QUICKBOOKS_REFRESH_TOKEN','QUICKBOOKS_ACCESS_TOKEN',
            'DARAZ_APP_SECRET','DARAZ_ACCESS_TOKEN','DARAZ_REFRESH_TOKEN',
            'POSTEX_TOKEN','RIDER_API_KEY',
            'MAILCHIMP_API_KEY','KLAVIYO_PRIVATE_KEY',
            'GA4_API_SECRET','FACEBOOK_CAPI_TOKEN',
        ];
        return in_array($key,$keys,true);
    }

    private function legacyKey(): string {
        return substr(hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'pkstore') . get_site_url()), 0, 32);
    }

    private function key(): string {
        $salt = function_exists('wp_salt') ? wp_salt('auth') . wp_salt('secure_auth') : '';
        if ($salt === '') {
            throw new \RuntimeException('WordPress salts are required to encrypt integration credentials.');
        }
        return hash('sha256', $salt, true);
    }

    private function encrypt(string $value): string {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false || strlen($tag) !== 16) return '';
        return 'v2:' . base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $encoded): string|false {
        // Version 2 uses authenticated AES-GCM. Older CBC values remain readable
        // so existing stores can migrate without losing saved provider credentials.
        if (str_starts_with($encoded, 'v2:')) {
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) < 28) return false;
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ciphertext = substr($raw, 28);
            return openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) return false;
        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $this->legacyKey(), OPENSSL_RAW_DATA, $iv);
    }
}
