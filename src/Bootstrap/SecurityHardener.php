<?php
declare(strict_types=1);
namespace PkStore\Bootstrap;
final class SecurityHardener {
    public function register():void{
        remove_action('wp_head','wp_generator');remove_action('wp_head','wlwmanifest_link');remove_action('wp_head','rsd_link');remove_action('wp_head','rest_output_link_wp_head');remove_action('wp_head','wp_oembed_add_discovery_links');remove_action('template_redirect','rest_output_link_header',11);
        add_filter('script_loader_src',[$this,'removeVer']);add_filter('style_loader_src',[$this,'removeVer']);
        add_filter('rest_endpoints',[$this,'disableUsers']);add_filter('login_errors',fn()=>'Incorrect credentials.');
        add_filter('rest_authentication_errors',[$this,'restrictRest']);add_action('send_headers',[$this,'headers']);
        add_action('wp_login',[$this,'loginSuccess'],10,2);add_filter('authenticate',[$this,'blockBrute'],30,3);
        if(!defined('DISALLOW_FILE_EDIT'))define('DISALLOW_FILE_EDIT',true);
    }
    public function removeVer(string $s):string{return str_contains($s,'ver=')?remove_query_arg('ver',$s):$s;}
    public function disableUsers(array $e):array{unset($e['/wp/v2/users'],$e['/wp/v2/users/(?P<id>[\d]+)']);return $e;}
    public function restrictRest(mixed $r):mixed{if(!empty($r)||is_user_logged_in())return $r;$uri=$_SERVER['REQUEST_URI']??'';foreach(['/pkstore/v1/cod/','/pkstore/v1/chat','/pkstore/v1/webhooks/','/pkstore/v1/tracking/']as $p){if(str_contains($uri,$p))return $r;}if(str_contains($uri,'/wp/v2/')||str_contains($uri,'/wc/'))return new \WP_Error('rest_forbidden','REST API restricted.',['status'=>401]);return $r;}
    public function headers():void{header_remove('X-Powered-By');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: strict-origin-when-cross-origin');}
    private function loginKeys(string $username): array {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
        $ipHash = substr(hash_hmac('sha256', $ip, wp_salt('auth')), 0, 32);
        $userHash = substr(hash_hmac('sha256', strtolower(trim($username)), wp_salt('auth')), 0, 32);
        return [
            'ip' => 'pkstore_lf_ip_' . $ipHash,
            'user' => 'pkstore_lf_user_' . $userHash,
            'pair' => 'pkstore_lf_pair_' . $ipHash . '_' . $userHash,
            'locks' => [
                'pkstore_login_ip_' . $ipHash,
                'pkstore_login_user_' . $userHash,
                'pkstore_login_pair_' . substr(hash('sha256', $ipHash . '|' . $userHash), 0, 32),
            ],
        ];
    }

    public function loginFail(string $username): bool {
        global $wpdb;
        $keys = $this->loginKeys($username);
        if (!$wpdb instanceof \wpdb) {
            set_transient($keys['ip'], 5, 15 * MINUTE_IN_SECONDS);
            return true;
        }

        $locks = $keys['locks'];
        sort($locks, SORT_STRING);
        $acquired = [];
        try {
            foreach ($locks as $lockName) {
                $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 1));
                if ($wpdb->last_error !== '' || (int) $locked !== 1) {
                    // Fail closed if concurrent failure accounting cannot be
                    // serialized; do not let a lock race erase the attempt.
                    set_transient($keys['ip'], 5, 15 * MINUTE_IN_SECONDS);
                    set_transient($keys['user'], 10, 15 * MINUTE_IN_SECONDS);
                    set_transient($keys['pair'], 5, 15 * MINUTE_IN_SECONDS);
                    return true;
                }
                $acquired[] = $lockName;
            }

            foreach (['ip', 'user', 'pair'] as $type) {
                $count = (int) get_transient($keys[$type]);
                if (!set_transient($keys[$type], $count + 1, 15 * MINUTE_IN_SECONDS)) {
                    set_transient($keys['ip'], 5, 15 * MINUTE_IN_SECONDS);
                    set_transient($keys['user'], 10, 15 * MINUTE_IN_SECONDS);
                    set_transient($keys['pair'], 5, 15 * MINUTE_IN_SECONDS);
                    error_log('[PK Store] Login failure counter persistence failed; temporary lockout applied.');
                    return true;
                }
            }
            return (int) get_transient($keys['ip']) >= 5
                || (int) get_transient($keys['user']) >= 10
                || (int) get_transient($keys['pair']) >= 5;
        } finally {
            foreach (array_reverse($acquired) as $lockName) {
                $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
                if ($wpdb->last_error !== '' || (int) $released !== 1) {
                    error_log('[PK Store] Login rate-limit lock release could not be confirmed.');
                }
            }
        }
    }

    public function blockBrute(mixed $user, string $username, string $password): mixed {
        // Core username/password authentication runs before this priority. A
        // valid user must pass even when previous attempts reached the limit;
        // wp_login then clears the temporary counters.
        if ($user instanceof \WP_User) return $user;
        if (!($user instanceof \WP_Error)) return $user;

        $keys = $this->loginKeys($username);
        if ((int) get_transient($keys['ip']) >= 5
            || (int) get_transient($keys['user']) >= 10
            || (int) get_transient($keys['pair']) >= 5) {
            return new \WP_Error('too_many', 'Too many failed attempts. Try again in 15 minutes.');
        }
        // Count failed authentication outcomes inside this filter so parallel
        // attempts are serialized before another request can pass the limit.
        if ($this->loginFail($username)) {
            return new \WP_Error('too_many', 'Too many failed attempts. Try again in 15 minutes.');
        }
        return $user;
    }

    public function loginSuccess(string $username, \WP_User $user): void {
        $keys = $this->loginKeys($username);
        delete_transient($keys['ip']);
        delete_transient($keys['user']);
        delete_transient($keys['pair']);
    }
}
