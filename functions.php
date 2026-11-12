<?php
declare(strict_types=1);
if(version_compare(PHP_VERSION,'8.2.0','<')){wp_die('<h1>Junction Store requires PHP 8.2+</h1><p>Your server runs PHP '.PHP_VERSION.'</p>');return;}
if(!function_exists('WC')&&!defined('PKSTORE_TESTING')){add_action('admin_notices',function(){echo '<div class="notice notice-error"><p><strong>Junction Store</strong> requires WooCommerce to be installed and active.</p></div>';});return;}
define('PKSTORE_VERSION','1.0.1');
define('PKSTORE_DIR',get_template_directory());
define('PKSTORE_URI',get_template_directory_uri());
define('PKSTORE_SRC',PKSTORE_DIR.'/src');
define('PKSTORE_ASSETS',PKSTORE_URI.'/assets');
require_once PKSTORE_SRC.'/Bootstrap/Autoloader.php';
\JunctionStore\Bootstrap\Autoloader::register(PKSTORE_SRC,'JunctionStore');
\JunctionStore\Bootstrap\Autoloader::register(PKSTORE_SRC,'Psr');
add_action('after_setup_theme',function():void{
    $c=\JunctionStore\Container\ServiceContainer::getInstance();
    $b=require_once PKSTORE_DIR.'/config/services.php';
    $b($c);
    (new \JunctionStore\Bootstrap\ThemeBootstrapper($c))->boot();
},10);
add_action('after_switch_theme',function():void{
    \JunctionStore\Infrastructure\Database\Installer::run();
    \JunctionStore\Infrastructure\Database\Installer::createCustomOrderStatuses();
    flush_rewrite_rules();
});

// Dukaan AI: AI chat is now handled by WooCommerce AI Sales Assistant plugin.
// The GeminiBridge class is kept for legacy compatibility but the plugin
// should be installed for all new deployments.
add_action('admin_notices', function () {
    if (is_plugin_active('woo-ai-sales-assistant/woo-ai-sales-assistant.php')) return;
    if (!current_user_can('manage_options')) return;
    echo '<div class="notice notice-warning is-dismissible"><p>'
        . '<strong>Junction Store:</strong> Install the <strong>WooCommerce AI Sales Assistant</strong> plugin for Dukaan AI chat. '
        . '<a href="' . esc_url(admin_url('plugin-install.php')) . '">Install &rarr;</a>'
        . '</p></div>';
});

