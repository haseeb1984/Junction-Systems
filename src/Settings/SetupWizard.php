<?php
declare(strict_types=1);
namespace PkStore\Settings;

final class SetupWizard {

    private const SLUG = 'pkstore-setup';
    private const TABS = [
        'general'   => 'General',
        'jazzcash'  => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'tcs'       => 'TCS',
        'leopards'  => 'Leopards',
        'trax'      => 'Trax',
        'sms'       => 'OTP / SMS',
        'ai'        => 'Dukaan AI',
    ];

    public function __construct(private readonly SettingsManager $cfg) {}

    public function register(): void {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_pkstore_save_settings', [$this, 'handleSave']);
    }

    public function addPage(): void {
        add_submenu_page(
            'pkstore-operations',
            'Setup Wizard',
            'Setup Wizard',
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function handleSave(): void {
        check_admin_referer('pkstore_save_settings');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $section = sanitize_key($_POST['section'] ?? 'general');
        $this->cfg->saveFromPost($_POST, $section);
        wp_redirect(admin_url('admin.php?page=' . self::SLUG . '&tab=' . $section . '&saved=1'));
        exit;
    }

    public function render(): void {
        $tab    = sanitize_key($_GET['tab'] ?? 'general');
        $saved  = isset($_GET['saved']);
        $status = $this->cfg->getSetupStatus();
        ?>
        <div class="wrap pkstore-admin">
            <h1>PK Store — Setup Wizard</h1>
            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>
            <?php endif; ?>
            <div style="display:flex;gap:24px;margin-top:20px">
                <!-- Sidebar tabs -->
                <div style="width:200px;flex-shrink:0">
                    <?php foreach (self::TABS as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&tab=' . $slug)); ?>"
                       class="pkstore-tab-link <?php echo $tab === $slug ? 'active' : ''; ?>"
                       style="display:block;padding:10px 16px;margin-bottom:4px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;color:<?php echo $tab===$slug?'#fff':'#1d2327';?>;background:<?php echo $tab===$slug?'#0B1426':'#f0f0f1';?>">
                        <?php echo esc_html($label); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <!-- Content -->
                <div style="flex:1">
                    <div class="card" style="padding:28px;max-width:720px">
                        <?php $this->renderTab($tab); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderTab(string $tab): void {
        // AI tab — special treatment
        if ($tab === 'ai') {
            $this->renderAiTab();
            return;
        }
        // Generic tab
        $fields = $this->getTabFields($tab);
        if (empty($fields)) { echo '<p>No settings for this tab.</p>'; return; }

        echo '<h2>' . esc_html(self::TABS[$tab] ?? $tab) . ' Settings</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pkstore_save_settings');
        echo '<input type="hidden" name="action"  value="pkstore_save_settings">';
        echo '<input type="hidden" name="section" value="' . esc_attr($tab) . '">';
        echo '<table class="form-table"><tbody>';

        foreach ($fields as $key => $config) {
            $val     = $this->cfg->get($key);
            $display = ($config['sensitive'] && $val) ? str_repeat('●', 8) : esc_attr($val);
            echo '<tr><th scope="row"><label for="' . esc_attr(strtolower($key)) . '">' . esc_html($config['label']) . '</label></th>';
            echo '<td><input type="' . ($config['sensitive'] ? 'password' : 'text') . '" id="' . esc_attr(strtolower($key)) . '" name="' . esc_attr(strtolower($key)) . '" value="' . $display . '" class="regular-text">';
            if (!empty($config['desc'])) {
                echo '<p class="description">' . wp_kses_post($config['desc']) . '</p>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p class="submit"><input type="submit" class="button-primary" value="Save Settings"></p>';
        echo '</form>';
    }

    private function renderAiTab(): void {
        // Check if the plugin is active
        if (is_plugin_active('woo-ai-sales-assistant/woo-ai-sales-assistant.php')) {
            echo '<h2>&#129302; Dukaan AI</h2>';
            echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin:16px 0">';
            echo '<strong style="color:#166534">&#10003; WooCommerce AI Sales Assistant plugin is active</strong>';
            echo '<p style="color:#166534;margin-top:8px;font-size:14px">Configure Dukaan AI in <a href="' . admin_url("admin.php?page=woo-ai-assistant") . '">WooCommerce &#8594; AI Assistant</a>. The plugin handles the floating chat widget, Groq API, and WooCommerce product context automatically.</p>';
            echo '</div>';
            return;
        }
        
        $key         = $this->cfg->get('GEMINI_API_KEY');
        $isSet       = !empty($key);
        $displayKey  = $isSet ? str_repeat('●', 8) : '';
        ?>
        <h2>&#129302; Dukaan AI — Google Gemini Flash</h2>

        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin-bottom:24px">
            <strong style="color:#166534">&#10003; FREE API — No credit card required</strong>
            <p style="color:#166534;margin:6px 0 0;font-size:14px">
                Google Gemini Flash gives you <strong>1,500 free requests per day</strong>.
                That is enough for a busy store with hundreds of customer chats daily.
            </p>
        </div>

        <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:24px">
            <strong>How to get your free API key (30 seconds):</strong>
            <ol style="margin:10px 0 0 20px;line-height:2">
                <li>Go to <a href="https://aistudio.google.com" target="_blank"><strong>aistudio.google.com</strong></a></li>
                <li>Sign in with your Google account</li>
                <li>Click <strong>"Get API Key"</strong></li>
                <li>Click <strong>"Create API Key"</strong></li>
                <li>Copy the key and paste it below</li>
            </ol>
        </div>

        <?php if ($isSet): ?>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-bottom:20px">
            &#10003; <strong>Dukaan AI is active</strong> — Gemini Flash is configured and ready.
        </div>
        <?php else: ?>
        <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:12px;margin-bottom:20px">
            &#9888; Dukaan AI is not configured. Add your free API key below to activate it.
        </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pkstore_save_settings'); ?>
            <input type="hidden" name="action"  value="pkstore_save_settings">
            <input type="hidden" name="section" value="ai">
            <table class="form-table"><tbody>
            <tr>
                <th scope="row"><label for="gemini_api_key">Google Gemini API Key</label></th>
                <td>
                    <input type="password" id="gemini_api_key" name="gemini_api_key"
                           value="<?php echo esc_attr($displayKey); ?>" class="regular-text"
                           placeholder="AIzaSy...">
                    <p class="description">
                        Free key from <a href="https://aistudio.google.com" target="_blank">aistudio.google.com</a>.
                        Stored encrypted in WordPress options. Never exposed to customers.
                    </p>
                </td>
            </tr>
            </tbody></table>
            <p class="submit"><input type="submit" class="button-primary" value="Save Gemini API Key"></p>
        </form>

        <hr style="margin:28px 0">
        <h3>What Dukaan AI can do</h3>
        <ul style="margin-left:20px;line-height:2;font-size:14px">
            <li>Answer product questions in <strong>Urdu and English</strong> — auto-detects language</li>
            <li>Tell customers exact prices, stock status, and sale prices from your WooCommerce catalogue</li>
            <li>Explain delivery options, payment methods (JazzCash, EasyPaisa, CoD), and return policy</li>
            <li>Detect and block prompt injection attacks (22 patterns, English + Urdu)</li>
            <li>Rate limited to 20 messages per session per hour</li>
            <li>Full chat history stored in <code>pkstore_chat_history</code> table for review</li>
        </ul>

        <h3 style="margin-top:20px">Free tier limits</h3>
        <table class="widefat" style="max-width:500px">
            <thead><tr><th>Limit</th><th>Value</th></tr></thead>
            <tbody>
                <tr><td>Requests per day</td><td><strong>1,500</strong></td></tr>
                <tr><td>Requests per minute</td><td><strong>15</strong></td></tr>
                <tr><td>Tokens per minute</td><td><strong>1,000,000</strong></td></tr>
                <tr><td>Cost</td><td><strong>PKR 0 — completely free</strong></td></tr>
            </tbody>
        </table>
        <?php
    }

    private function getTabFields(string $tab): array {
        return match ($tab) {
            'general' => [
                'DEFAULT_PAYMENT_GATEWAY' => ['label' => 'Default Payment Gateway', 'sensitive' => false, 'desc' => 'e.g. jazzcash'],
                'DEFAULT_COURIER'         => ['label' => 'Default Courier',          'sensitive' => false, 'desc' => 'e.g. tcs'],
                'DEFAULT_SMS_PROVIDER'    => ['label' => 'Default SMS Provider',     'sensitive' => false, 'desc' => 'twilio or local'],
                'STORE_HELPLINE'          => ['label' => 'Store Helpline Number',    'sensitive' => false, 'desc' => ''],
                'STORE_WHATSAPP'          => ['label' => 'WhatsApp Number',          'sensitive' => false, 'desc' => 'With country code e.g. 923001234567'],
            ],
            'jazzcash' => [
                'JAZZCASH_MERCHANT_ID'    => ['label' => 'Merchant ID',              'sensitive' => false, 'desc' => 'From JazzCash merchant portal'],
                'JAZZCASH_PASSWORD'       => ['label' => 'API Password',             'sensitive' => true,  'desc' => ''],
                'JAZZCASH_INTEGRITY_KEY'  => ['label' => 'Integrity Key',            'sensitive' => true,  'desc' => 'For HMAC-SHA256 signature'],
                'JAZZCASH_SANDBOX'        => ['label' => 'Sandbox Mode',             'sensitive' => false, 'desc' => '1 = sandbox, 0 = live'],
            ],
            'easypaisa' => [
                'EASYPAISA_STORE_ID'      => ['label' => 'Store ID',                 'sensitive' => false, 'desc' => 'From EasyPaisa merchant portal'],
                'EASYPAISA_HASH_KEY'      => ['label' => 'Hash Key',                 'sensitive' => true,  'desc' => 'For SHA-256 signature'],
                'EASYPAISA_SANDBOX'       => ['label' => 'Sandbox Mode',             'sensitive' => false, 'desc' => '1 = sandbox, 0 = live'],
            ],
            'tcs' => [
                'TCS_API_KEY'             => ['label' => 'API Key',                  'sensitive' => true,  'desc' => 'From TCS merchant portal'],
                'TCS_ACCOUNT_NUMBER'      => ['label' => 'Account Number',           'sensitive' => false, 'desc' => ''],
                'TCS_WEBHOOK_SECRET'      => ['label' => 'Webhook Secret',           'sensitive' => true,  'desc' => ''],
                'TCS_SANDBOX_MODE'        => ['label' => 'Sandbox Mode',             'sensitive' => false, 'desc' => '1 = sandbox'],
            ],
            'leopards' => [
                'LEOPARDS_API_KEY'        => ['label' => 'API Key',                  'sensitive' => true,  'desc' => 'From Leopards merchant portal'],
                'LEOPARDS_API_PASSWORD'   => ['label' => 'API Password',             'sensitive' => true,  'desc' => ''],
                'LEOPARDS_SANDBOX_MODE'   => ['label' => 'Sandbox Mode',             'sensitive' => false, 'desc' => '1 = sandbox'],
            ],
            'trax' => [
                'TRAX_API_TOKEN'          => ['label' => 'API Token',                'sensitive' => true,  'desc' => 'From Trax/Sonic merchant portal'],
                'TRAX_SENDER_ID'          => ['label' => 'Sender ID',               'sensitive' => false, 'desc' => ''],
                'TRAX_WEBHOOK_SECRET'     => ['label' => 'Webhook Secret',           'sensitive' => true,  'desc' => ''],
                'TRAX_SANDBOX_MODE'       => ['label' => 'Sandbox Mode',             'sensitive' => false, 'desc' => '1 = sandbox'],
            ],
            'sms' => [
                'TWILIO_ACCOUNT_SID'      => ['label' => 'Twilio Account SID',       'sensitive' => false, 'desc' => 'From twilio.com console'],
                'TWILIO_AUTH_TOKEN'       => ['label' => 'Twilio Auth Token',        'sensitive' => true,  'desc' => ''],
                'TWILIO_FROM_NUMBER'      => ['label' => 'Twilio From Number',       'sensitive' => false, 'desc' => '+1234567890'],
                'LOCAL_SMS_API_URL'       => ['label' => 'Local SMS API URL',        'sensitive' => false, 'desc' => 'For Pakistan SMS providers'],
                'LOCAL_SMS_API_KEY'       => ['label' => 'Local SMS API Key',        'sensitive' => true,  'desc' => ''],
                'LOCAL_SMS_SENDER_ID'     => ['label' => 'SMS Sender ID',            'sensitive' => false, 'desc' => ''],
            ],
            default => [],
        };
    }
}
