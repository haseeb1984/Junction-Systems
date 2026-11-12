<?php
defined("ABSPATH") || exit;

class Woo_AI_Widget {

    public static function init(): void {
        add_action("wp_enqueue_scripts",         [self::class, "enqueue_assets"]);
        add_action("wp_footer",                  [self::class, "render_widget"]);
        add_action("wp_ajax_woo_ai_chat",        [self::class, "handle_ajax"]);
        add_action("wp_ajax_nopriv_woo_ai_chat", [self::class, "handle_ajax"]);
    }

    public static function enqueue_assets(): void {
        $opts = get_option("woo_ai_settings", []);
        if (empty($opts["widget_enabled"])) return;
        wp_enqueue_style("woo-ai-chat",  WOO_AI_PLUGIN_URL . "assets/css/chat-widget.css", [], WOO_AI_VERSION);
        wp_enqueue_script("woo-ai-chat", WOO_AI_PLUGIN_URL . "assets/js/chat-widget.js",  [], WOO_AI_VERSION, true);
        $wa = Woo_AI_Engine::get_whatsapp();
        wp_localize_script("woo-ai-chat", "WooAI", [
            "ajaxUrl"   => admin_url("admin-ajax.php"),
            "nonce"     => wp_create_nonce("woo_ai_nonce"),
            "title"     => esc_html($opts["widget_title"]    ?? "Dukaan AI"),
            "subtitle"  => esc_html($opts["widget_subtitle"] ?? "Ask me anything!"),
            "whatsapp"  => esc_js($wa),
            "storeName" => esc_js(get_bloginfo("name")),
        ]);
    }

    public static function render_widget(): void {
        $opts = get_option("woo_ai_settings", []);
        if (empty($opts["widget_enabled"])) return;
        $wa = Woo_AI_Engine::get_whatsapp();
        ?>
        <div id="woo-ai-widget" role="region" aria-label="AI Chat Assistant" style="display:none">
            <button id="woo-ai-launcher" aria-label="Open AI chat" aria-expanded="false">
                <svg id="woo-ai-icon-chat" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <svg id="woo-ai-icon-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" style="display:none"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <span id="woo-ai-badge" aria-live="polite" style="display:none"></span>
            </button>
            <div id="woo-ai-panel" aria-hidden="true">
                <div id="woo-ai-header">
                    <div id="woo-ai-header-left">
                        <div id="woo-ai-avatar" aria-hidden="true">🤖</div>
                        <div>
                            <div id="woo-ai-title"><?php echo esc_html($opts["widget_title"] ?? "Dukaan AI"); ?></div>
                            <div id="woo-ai-subtitle"><?php echo esc_html($opts["widget_subtitle"] ?? ""); ?></div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <!-- Talk to Human button — always visible -->
                        <a id="woo-ai-human-btn"
                           href="https://wa.me/<?php echo esc_attr($wa); ?>?text=<?php echo esc_attr(urlencode('Hi! I need help from a human agent.')); ?>"
                           target="_blank" rel="noopener"
                           title="Talk to a human on WhatsApp"
                           aria-label="Connect to WhatsApp support">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        </a>
                        <button id="woo-ai-minimize" aria-label="Minimize chat">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </button>
                    </div>
                </div>
                <div id="woo-ai-messages" role="log" aria-live="polite"></div>
                <div id="woo-ai-suggestions">
                    <button class="woo-ai-sugg" data-msg="Products kya hain?">Products? 🛒</button>
                    <button class="woo-ai-sugg" data-msg="Delivery kaise hogi?">Delivery? 🚚</button>
                    <button class="woo-ai-sugg" data-msg="Koi sale chal raha hai?">Sale? 🔥</button>
                    <button class="woo-ai-sugg" data-msg="Payment options kya hain?">Payments? 💳</button>
                    <button class="woo-ai-sugg woo-ai-sugg-human" data-human="1">👤 Talk to Human</button>
                </div>
                <div id="woo-ai-input-row">
                    <textarea id="woo-ai-input" placeholder="Apna sawal likhein..." rows="1" maxlength="500" aria-label="Chat input"></textarea>
                    <button id="woo-ai-send" aria-label="Send" disabled>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
                <div id="woo-ai-footer">Powered by Groq LPU · <?php echo esc_html(get_bloginfo("name")); ?></div>
            </div>
        </div>
        <?php
    }

    public static function handle_ajax(): void {
        check_ajax_referer("woo_ai_nonce", "nonce");
        $message = sanitize_text_field(wp_unslash($_POST["message"] ?? ""));
        if (empty($message)) wp_send_json_error(["reply" => "Empty message."]);
        if (mb_strlen($message) > 500) wp_send_json_error(["reply" => "Message too long."]);

        $result  = Woo_AI_Engine::process($message);
        $payload = [
            "reply"     => $result["reply"],
            "whatsapp"  => $result["whatsapp"] ?? Woo_AI_Engine::get_whatsapp(),
        ];
        if (!empty($result["handoff"])) $payload["handoff"] = true;

        if ($result["success"]) wp_send_json_success($payload);
        else                    wp_send_json_error($payload);
    }
}
