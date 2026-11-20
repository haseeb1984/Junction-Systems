<?php
defined("ABSPATH") || exit;

class Woo_AI_Admin {

    private const OPTION_KEY   = "woo_ai_settings";
    private const OPTION_GROUP = "woo_ai_settings_group";
    private const MENU_SLUG    = "woo-ai-assistant";

    public static function init(): void {
        add_action("admin_menu",            [self::class, "add_menu"]);
        add_action("admin_init",            [self::class, "register_settings"]);
        add_action("admin_enqueue_scripts", [self::class, "enqueue_admin_assets"]);
        add_action("admin_post_woo_ai_clear_cache", [self::class, "handle_clear_cache"]);
    }

    public static function add_menu(): void {
        add_submenu_page("woocommerce","AI Sales Assistant","🤖 AI Assistant",
            "manage_options", self::MENU_SLUG, [self::class, "render_page"]);
    }

    public static function register_settings(): void {
        register_setting(self::OPTION_GROUP, self::OPTION_KEY, ["sanitize_callback" => [self::class, "sanitize"]]);
        add_settings_section("woo_ai_main", "", "__return_false", self::MENU_SLUG);
        $fields = [
            ["api_key","Groq API Key","render_api_key"],
            ["model","AI Model","render_model"],
            ["session_limit","Max Messages / Hour / User","render_session_limit"],
            ["allow_paid","Allow Paid Overflow","render_allow_paid"],
            ["widget_enabled","Enable Chat Widget","render_widget_enabled"],
            ["widget_title","Widget Title","render_widget_title"],
            ["widget_subtitle","Widget Subtitle","render_widget_subtitle"],
            ["persona","System Persona","render_persona"],
        ];
        foreach ($fields as [$id, $label, $cb])
            add_settings_field($id, $label, [self::class, $cb], self::MENU_SLUG, "woo_ai_main");
    }

    public static function sanitize(array $input): array {
        $opts = get_option(self::OPTION_KEY, []);
        $submitted_key = sanitize_text_field($input["api_key"] ?? "");
        if (!empty($submitted_key) && $submitted_key !== str_repeat("•", 16)) {
            $encrypted_key = Woo_AI_Engine::encrypt_api_key($submitted_key);
            if ($encrypted_key === "") {
                add_settings_error(self::OPTION_KEY, "woo_ai_key_encrypt", "Unable to protect the API key. Check WordPress security salts.", "error");
            } else {
                $opts["api_key"] = $encrypted_key;
            }
        }
        $opts["model"]          = in_array($input["model"] ?? "", [WOO_AI_MODEL_FAST, WOO_AI_MODEL_SMART], true) ? $input["model"] : WOO_AI_MODEL_FAST;
        $opts["session_limit"]  = max(1, min(100, (int)($input["session_limit"] ?? 10)));
        $opts["allow_paid"]     = !empty($input["allow_paid"]);
        $opts["widget_enabled"] = !empty($input["widget_enabled"]);
        $opts["widget_title"]   = sanitize_text_field($input["widget_title"]    ?? "Dukaan AI");
        $opts["widget_subtitle"]= sanitize_text_field($input["widget_subtitle"] ?? "");
        $opts["persona"]        = sanitize_textarea_field($input["persona"]     ?? Woo_AI_Engine::default_persona());
        delete_transient("woo_ai_product_context");
        return $opts;
    }

    public static function render_api_key(): void {
        $opts = get_option(self::OPTION_KEY, []);
        $has  = !empty($opts["api_key"]);
        echo '<input type="password" name="woo_ai_settings[api_key]" class="regular-text" value="' . ($has ? esc_attr(str_repeat("•", 16)) : "") . '" placeholder="gsk_xxxx" autocomplete="new-password">';
        echo '<p class="description">Free key from <a href="https://console.groq.com" target="_blank">console.groq.com</a>. Free tier: <strong>30 RPM / 6,000 TPM / 14,400 RPD</strong>.' . ($has ? ' <span style="color:#16a34a">✓ Saved</span>' : "") . "</p>";
    }

    public static function render_model(): void {
        $opts = get_option(self::OPTION_KEY, []); $model = $opts["model"] ?? WOO_AI_MODEL_FAST;
        echo '<select name="woo_ai_settings[model]">';
        echo '<option value="' . WOO_AI_MODEL_FAST  . '"' . selected($model, WOO_AI_MODEL_FAST,  false) . '>llama-3.1-8b-instant — Fast (14,400 RPD) ✓ Recommended</option>';
        echo '<option value="' . WOO_AI_MODEL_SMART . '"' . selected($model, WOO_AI_MODEL_SMART, false) . '>llama-3.3-70b-versatile — Smarter (1,000 RPD)</option>';
        echo "</select>";
    }

    public static function render_session_limit(): void {
        $opts = get_option(self::OPTION_KEY, []);
        echo '<input type="number" name="woo_ai_settings[session_limit]" value="' . esc_attr($opts["session_limit"] ?? 10) . '" min="1" max="100" class="small-text"> messages per hour per user';
        echo '<p class="description">When limit hit → bot shows WhatsApp handoff button automatically.</p>';
    }

    public static function render_allow_paid(): void {
        $opts = get_option(self::OPTION_KEY, []);
        echo '<label><input type="checkbox" name="woo_ai_settings[allow_paid]" value="1"' . checked(!empty($opts["allow_paid"]), true, false) . '> Continue after free limits (uses Groq billing)</label>';
        echo '<p class="description" style="color:#d97706">⚠ If unchecked (default): WhatsApp handoff shown when limits hit.</p>';
    }

    public static function render_widget_enabled(): void {
        $opts = get_option(self::OPTION_KEY, []);
        echo '<label class="woo-ai-toggle"><input type="checkbox" name="woo_ai_settings[widget_enabled]" value="1"' . checked(!empty($opts["widget_enabled"]), true, false) . '><span class="woo-ai-toggle-slider"></span></label>';
        echo '<span style="margin-left:10px;font-size:13px">Show floating chat widget on all store pages</span>';
    }

    public static function render_widget_title(): void {
        $opts = get_option(self::OPTION_KEY, []);
        echo '<input type="text" name="woo_ai_settings[widget_title]" value="' . esc_attr($opts["widget_title"] ?? "Dukaan AI") . '" class="regular-text">';
    }

    public static function render_widget_subtitle(): void {
        $opts = get_option(self::OPTION_KEY, []);
        echo '<input type="text" name="woo_ai_settings[widget_subtitle]" value="' . esc_attr($opts["widget_subtitle"] ?? "") . '" class="regular-text">';
    }

    public static function render_persona(): void {
        $opts = get_option(self::OPTION_KEY, []);
        echo '<textarea name="woo_ai_settings[persona]" rows="10" class="large-text code">' . esc_textarea($opts["persona"] ?? Woo_AI_Engine::default_persona()) . "</textarea>";
        echo '<p class="description">Product catalogue and store policies are appended automatically.</p>';
    }

    public static function render_page(): void {
        if (!current_user_can("manage_options")) wp_die("Forbidden");
        $rpm = (int)get_transient("woo_ai_rpm"); $rpd = (int)get_transient("woo_ai_rpd");
        $tpm = (int)get_transient("woo_ai_tpm"); $bo  = (int)get_transient("woo_ai_backoff");
        ?>
        <div class="wrap">
        <h1>🤖 WooCommerce AI Sales Assistant</h1>
        <div class="woo-ai-meters">
            <?php foreach ([["RPM",$rpm,30],["Today",$rpd,14400],["TPM tokens",$tpm,6000]] as [$l,$v,$max]): ?>
            <div class="woo-ai-meter-card<?php echo $v>$max*.9?" warn":""; ?>">
                <div class="woo-ai-meter-val"><?php echo number_format($v)." / ".number_format($max); ?></div>
                <div class="woo-ai-meter-label"><?php echo $l; ?></div>
                <div class="woo-ai-meter-bar"><div style="width:<?php echo min(100,round($v/$max*100)); ?>%"></div></div>
            </div>
            <?php endforeach; ?>
            <div class="woo-ai-meter-card<?php echo ($bo&&time()<$bo)?" warn":""; ?>">
                <div class="woo-ai-meter-val"><?php echo ($bo&&time()<$bo)?"⏳ ".($bo-time())."s":"✓ OK"; ?></div>
                <div class="woo-ai-meter-label">429 Backoff</div>
                <div class="woo-ai-meter-bar"><div style="width:<?php echo ($bo&&time()<$bo)?100:0; ?>%;background:<?php echo ($bo&&time()<$bo)?"#ef4444":"#10b981"; ?>"></div></div>
            </div>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>" style="display:inline">
            <?php wp_nonce_field("woo_ai_clear_cache","woo_ai_nonce"); ?>
            <input type="hidden" name="action" value="woo_ai_clear_cache">
            <button class="button">🔄 Refresh Product Cache</button>
        </form>
        <span class="description" style="margin-left:8px">WhatsApp number comes from WooCommerce → Settings → General → Store phone.</span>
        <form method="post" action="options.php" style="margin-top:16px">
            <?php settings_fields(self::OPTION_GROUP); do_settings_sections(self::MENU_SLUG); submit_button("Save Settings"); ?>
        </form>
        </div>
        <?php
    }

    public static function handle_clear_cache(): void {
        check_admin_referer("woo_ai_clear_cache","woo_ai_nonce");
        if (!current_user_can("manage_options")) wp_die("Forbidden");
        delete_transient("woo_ai_product_context");
        wp_redirect(add_query_arg(["page"=>self::MENU_SLUG,"cache_cleared"=>1], admin_url("admin.php"))); exit;
    }

    public static function enqueue_admin_assets(string $hook): void {
        if ($hook !== "woocommerce_page_woo-ai-assistant") return;
        wp_add_inline_style("wp-admin","
.woo-ai-meters{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:20px 0}
.woo-ai-meter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.woo-ai-meter-card.warn{border-color:#f59e0b;background:#fffbeb}
.woo-ai-meter-val{font-size:20px;font-weight:700;color:#111;margin-bottom:4px}
.woo-ai-meter-label{font-size:12px;color:#6b7280;margin-bottom:10px}
.woo-ai-meter-bar{height:5px;background:#f3f4f6;border-radius:99px;overflow:hidden}
.woo-ai-meter-bar div{height:100%;background:#10b981;border-radius:99px}
.woo-ai-toggle{position:relative;display:inline-block;width:46px;height:24px}
.woo-ai-toggle input{opacity:0;width:0;height:0}
.woo-ai-toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:24px;cursor:pointer;transition:.3s}
.woo-ai-toggle-slider::before{content:\"\";position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.woo-ai-toggle input:checked+.woo-ai-toggle-slider{background:#10b981}
.woo-ai-toggle input:checked+.woo-ai-toggle-slider::before{transform:translateX(22px)}
");
    }
}
