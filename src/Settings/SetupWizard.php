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
        $key = $this->cfg->get('GROQ_API_KEY');
        $isSet = !empty($key);
        $displayKey = $isSet ? str_repeat('●', 8) : '';
        ?>
        <h2>&#129302; Dukaan AI — Groq</h2>
        <p>Each store owner supplies and manages their own Groq API key. The key is stored encrypted in WordPress options and is used only by the server; it is never sent to the storefront browser.</p>
        <?php if ($isSet): ?>
        <div class="notice notice-success inline"><p><strong>Groq API key is configured.</strong></p></div>
        <?php else: ?>
        <div class="notice notice-warning inline"><p><strong>Groq API key is missing.</strong> Add a key to enable AI chat.</p></div>
        <?php endif; ?>
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
                           autocomplete="new-password" placeholder="gsk_...">
                    <p class="description">Create/manage a key at <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">Groq Console</a>. The key is kept server-side and encrypted at rest.</p>
                </td>
            </tr>
            </tbody></table>
            <p class="submit"><input type="submit" class="button-primary" value="Save Groq API Key"></p>
        </form>
        <h3>WhatsApp handoff</h3>
        <p>Set the store WhatsApp number under Setup Wizard → General. Customers can use it when the AI key is missing, invalid, rate-limited, or the provider is temporarily unavailable.</p>
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
