<?php
declare(strict_types=1);
if(version_compare(PHP_VERSION,'8.2.0','<')){wp_die('<h1>PK Store requires PHP 8.2+</h1><p>Your server runs PHP '.PHP_VERSION.'</p>');return;}
if(!function_exists('WC')&&!defined('PKSTORE_TESTING')){add_action('admin_notices',function(){echo '<div class="notice notice-error"><p><strong>PK Store</strong> requires WooCommerce to be installed and active.</p></div>';});return;}
define('PKSTORE_VERSION','1.0.1');
define('PKSTORE_DIR',get_template_directory());
define('PKSTORE_URI',get_template_directory_uri());
define('PKSTORE_SRC',PKSTORE_DIR.'/src');
define('PKSTORE_ASSETS',PKSTORE_URI.'/assets');
require_once PKSTORE_SRC.'/Bootstrap/Autoloader.php';
\PkStore\Bootstrap\Autoloader::register(PKSTORE_SRC,'PkStore');
\PkStore\Bootstrap\Autoloader::register(PKSTORE_SRC,'Psr');
add_action('after_setup_theme',function():void{
    $c=\PkStore\Container\ServiceContainer::getInstance();
    $b=require_once PKSTORE_DIR.'/config/services.php';
    $b($c);
    (new \PkStore\Bootstrap\ThemeBootstrapper($c))->boot();
},10);
add_action('after_switch_theme',function():void{
    \PkStore\Infrastructure\Database\Installer::run();
    \PkStore\Infrastructure\Database\Installer::createCustomOrderStatuses();
    flush_rewrite_rules();
});
