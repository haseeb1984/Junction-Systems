<?php
declare(strict_types=1);
namespace PkStore\Settings;

use PkStore\License\LicenseAdminUI;
use PkStore\License\LicenseManager;

final class SetupWizard {

    private const SLUG = 'pkstore-setup';
    private const TABS = [
        'license'   => 'License',
        'general'   => 'General',
        'jazzcash'  => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'tcs'       => 'TCS',
        'leopards'  => 'Leopards',
        'trax'      => 'Trax',
        'whatsapp'  => 'WhatsApp Business API',
        'sms'       => 'OTP / SMS',
        'ai'        => 'Dukaan AI',
    ];

    private readonly LicenseAdminUI $licenseUI;

    public function __construct(private readonly SettingsManager $cfg, ?LicenseAdminUI $licenseUI = null) {
        $this->licenseUI = $licenseUI ?? new LicenseAdminUI(new LicenseManager());
    }

    public function register(): void {
        $this->licenseUI->register();
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
        if ($tab === 'license') {
            $this->licenseUI->renderTab();
            return;
        }
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
        if (is_plugin_active('woo-ai-sales-assistant/woo-ai-sales-assistant.php')) {
            echo '<h2>&#129302; Dukaan AI</h2>';
            echo '<div class="notice notice-info inline"><p>WooCommerce AI Sales Assistant plugin is active. Configure its separate provider settings in the plugin.</p></div>';
            return;
        }

        $key = $this->cfg->get('GROQ_API_KEY');
        $isSet = $key !== '';
        $displayKey = $isSet ? str_repeat('●', 8) : '';
        ?>
        <h2>&#129302; Dukaan AI — Groq</h2>
        <p>Connect this store to your own Groq account. Each customer installation stores and uses its own API key; Junction licensing controls feature access separately.</p>
        <?php if ($isSet): ?>
            <div class="notice notice-success inline"><p><strong>Groq API key is saved.</strong> The key is encrypted in this WordPress installation.</p></div>
        <?php else: ?>
            <div class="notice notice-warning inline"><p>Groq is not configured yet. Add this store's API key to enable AI responses.</p></div>
        <?php endif; ?>
        <p>Get an API key from <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">Groq Console</a>.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pkstore_save_settings'); ?>
            <input type="hidden" name="action" value="pkstore_save_settings">
            <input type="hidden" name="section" value="ai">
            <table class="form-table"><tbody>
                <tr>
                    <th scope="row"><label for="groq_api_key">Groq API Key</label></th>
                    <td>
                        <input type="password" id="groq_api_key" name="groq_api_key"
                            value="<?php echo esc_attr($displayKey); ?>" class="regular-text"
                            placeholder="gsk_..." autocomplete="new-password">
                        <p class="description">Stored encrypted using authenticated AES-256-GCM. Never sent to the browser or exposed to customers. Leave the masked value unchanged to keep the existing key.</p>
                    </td>
                </tr>
            </tbody></table>
            <p class="submit"><input type="submit" class="button-primary" value="Save Groq API Key"></p>
        </form>
        <h3>What Dukaan AI can do</h3>
        <ul style="margin-left:20px;line-height:2;font-size:14px">
            <li>Answer product questions in Urdu and English</li>
            <li>Use WooCommerce catalogue context for product prices and availability</li>
            <li>Explain delivery, payment methods and store policies</li>
            <li>Apply prompt-injection detection and server-side rate limits</li>
        </ul>
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
                'EASYPAISA_USERNAME'      => ['label' => 'Partner Username',         'sensitive' => true,  'desc' => 'From EasyPaisa merchant portal / Manage Partner Accounts'],
                'EASYPAISA_PASSWORD'      => ['label' => 'Partner Password',         'sensitive' => true,  'desc' => 'Encrypted at rest; never exposed to checkout customers'],
                'EASYPAISA_STORE_ID'      => ['label' => 'Store ID',                 'sensitive' => false, 'desc' => 'Must belong to the same EasyPaisa partner account'],
                'EASYPAISA_ACCOUNT_NUMBER'=> ['label' => 'EWP Account Number',       'sensitive' => true,  'desc' => 'Account number shown in the EasyPaisa merchant profile; used for status inquiry'],
                'EASYPAISA_SANDBOX'       => ['label' => 'Sandbox Mode',             'sensitive' => false, 'desc' => '1 = staging, 0 = live. Configure this IPN listener in the EasyPaisa Merchant Portal → Account Settings → IPN Attribute Configurations: <code>' . esc_url(add_query_arg('wc-api', 'easypaisa_ipn', home_url('/'))) . '</code>'],
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
            'whatsapp' => [
                'WHATSAPP_TOKEN' => ['label' => 'Permanent Access Token', 'sensitive' => true, 'desc' => 'Business System User token with WhatsApp messaging permission.'],
                'WHATSAPP_PHONE_ID' => ['label' => 'Phone Number ID', 'sensitive' => false, 'desc' => 'Meta WhatsApp Business phone number ID, not the visible phone number.'],
                'WHATSAPP_VERIFY_TOKEN' => ['label' => 'Webhook Verify Token', 'sensitive' => true, 'desc' => 'A private value you also enter in the Meta webhook configuration.'],
                'WHATSAPP_APP_SECRET' => ['label' => 'Meta App Secret', 'sensitive' => true, 'desc' => 'Used to verify X-Hub-Signature-256 on incoming webhooks.'],
                'WHATSAPP_ADMIN_PHONE' => ['label' => 'Admin Alert Phone', 'sensitive' => false, 'desc' => 'Pakistan number with country code, e.g. 923001234567.'],
                'WHATSAPP_TEMPLATE_ORDER_CONFIRMATION' => ['label' => 'Order Confirmation Template', 'sensitive' => false, 'desc' => 'Approved Meta template name; body parameters: name, order number, items, total.'],
                'WHATSAPP_TEMPLATE_SHIPPED' => ['label' => 'Shipment Update Template', 'sensitive' => false, 'desc' => 'Approved Meta template name; body parameters: order number, tracking number, courier.'],
                'WHATSAPP_TEMPLATE_DELIVERED' => ['label' => 'Delivery Confirmation Template', 'sensitive' => false, 'desc' => 'Approved Meta template name; body parameters: name, order number.'],
                'WHATSAPP_TEMPLATE_LOW_STOCK' => ['label' => 'Low Stock Template', 'sensitive' => false, 'desc' => 'Approved Meta template name; body parameters: product name, stock quantity.'],
                'WHATSAPP_TEMPLATE_LANGUAGE' => ['label' => 'Template Language Code', 'sensitive' => false, 'desc' => 'Meta template language code, e.g. en_US or ur.'],
                'WHATSAPP_GRAPH_API_VERSION' => ['label' => 'Graph API Version', 'sensitive' => false, 'desc' => 'Versioned Graph API path, e.g. v26.0.'],
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
