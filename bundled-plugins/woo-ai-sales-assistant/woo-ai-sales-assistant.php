<?php
/**
 * Plugin Name:       WooCommerce AI Sales Assistant
 * Description:       Floating Dukaan AI chat powered by Groq LPU. Bilingual Urdu/English with WhatsApp human handoff on limit or request.
 * Version:           1.1.0
 * Author:            Junction Systems
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * WC requires at least: 8.0
 */
defined("ABSPATH") || exit;
define("WOO_AI_VERSION",       "1.1.0");
define("WOO_AI_PLUGIN_DIR",    plugin_dir_path(__FILE__));
define("WOO_AI_PLUGIN_URL",    plugin_dir_url(__FILE__));
define("WOO_AI_GROQ_ENDPOINT","https://api.groq.com/openai/v1/chat/completions");
define("WOO_AI_MODEL_FAST",   "llama-3.1-8b-instant");
define("WOO_AI_MODEL_SMART",  "llama-3.3-70b-versatile");
define("WOO_AI_MAX_TOKENS",    420);
define("WOO_AI_TIMEOUT",       8);

require_once WOO_AI_PLUGIN_DIR . "includes/class-woo-ai-engine.php";
require_once WOO_AI_PLUGIN_DIR . "includes/class-woo-ai-admin.php";
require_once WOO_AI_PLUGIN_DIR . "includes/class-woo-ai-widget.php";

add_action("plugins_loaded", function () {
    if (!class_exists("WooCommerce")) {
        add_action("admin_notices", fn() => print("<div class=\"notice notice-error\"><p><strong>WooCommerce AI Sales Assistant</strong> requires WooCommerce.</p></div>"));
        return;
    }
    Woo_AI_Admin::init();
    Woo_AI_Widget::init();
});

register_activation_hook(__FILE__, function () {
    if (!get_option("woo_ai_settings")) {
        update_option("woo_ai_settings", [
            "api_key"         => "",
            "model"           => WOO_AI_MODEL_FAST,
            "session_limit"   => 10,
            "allow_paid"      => false,
            "widget_enabled"  => true,
            "widget_title"    => "Dukaan AI",
            "widget_subtitle" => "Ask me anything — Urdu ya English mein!",
            "persona"         => Woo_AI_Engine::default_persona(),
        ]);
    }
});
