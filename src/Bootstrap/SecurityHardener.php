<?php
declare(strict_types=1);
namespace PkStore\Bootstrap;
final class SecurityHardener {
    public function register():void{
        remove_action('wp_head','wp_generator');remove_action('wp_head','wlwmanifest_link');remove_action('wp_head','rsd_link');remove_action('wp_head','rest_output_link_wp_head');remove_action('wp_head','wp_oembed_add_discovery_links');remove_action('template_redirect','rest_output_link_header',11);
        add_filter('script_loader_src',[$this,'removeVer']);add_filter('style_loader_src',[$this,'removeVer']);
        add_filter('rest_endpoints',[$this,'disableUsers']);add_filter('login_errors',fn()=>'Incorrect credentials.');
        add_filter('rest_authentication_errors',[$this,'restrictRest']);add_action('send_headers',[$this,'headers']);
        add_action('wp_login_failed',[$this,'loginFail']);add_filter('authenticate',[$this,'blockBrute'],30,3);
        if(!defined('DISALLOW_FILE_EDIT'))define('DISALLOW_FILE_EDIT',true);
    }
    public function removeVer(string $s):string{return str_contains($s,'ver=')?remove_query_arg('ver',$s):$s;}
    public function disableUsers(array $e):array{unset($e['/wp/v2/users'],$e['/wp/v2/users/(?P<id>[\d]+)']);return $e;}
    public function restrictRest(mixed $r):mixed{if(!empty($r)||is_user_logged_in())return $r;$uri=$_SERVER['REQUEST_URI']??'';foreach(['/pkstore/v1/cod/','/pkstore/v1/chat','/pkstore/v1/webhooks/','/pkstore/v1/tracking/']as $p){if(str_contains($uri,$p))return $r;}if(str_contains($uri,'/wp/v2/')||str_contains($uri,'/wc/'))return new \WP_Error('rest_forbidden','REST API restricted.',['status'=>401]);return $r;}
    public function headers():void{header_remove('X-Powered-By');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: strict-origin-when-cross-origin');}
    public function loginFail(string $u):void{$k='pkstore_lf_'.md5($_SERVER['REMOTE_ADDR']??'');set_transient($k,(int)get_transient($k)+1,15*MINUTE_IN_SECONDS);}
    public function blockBrute(mixed $u,string $n,string $p):mixed{if((int)get_transient('pkstore_lf_'.md5($_SERVER['REMOTE_ADDR']??''))>=5)return new \WP_Error('too_many','Too many failed attempts. Try again in 15 minutes.');return $u;}
}
