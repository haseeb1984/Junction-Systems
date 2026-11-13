<?php
declare(strict_types=1);
namespace PkStore\License;

final class LicenseAdminUI {

    public function __construct(private readonly LicenseManager $license) {}

    public function register(): void {
        add_action('admin_notices',       [$this, 'showNotices']);
        add_action('admin_post_pkstore_activate_license',   [$this, 'handleActivate']);
        add_action('admin_post_pkstore_deactivate_license', [$this, 'handleDeactivate']);
    }

    public function showNotices(): void {
        // Hard lockout
        if ($this->license->isLockedOut()) {
            $reason = get_option('pkstore_lockout_reason', '');
            echo '<div class="notice notice-error"><p>'
                . '<strong>PK Store — License Locked:</strong> '
                . esc_html($this->getLockoutMessage($reason))
                . ' <a href="' . esc_url(admin_url('admin.php?page=pkstore-setup&tab=license')) . '">Manage License</a>'
                . '</p></div>';
            return;
        }

        if (!$this->license->isValid()) {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . '&#128274; <strong>PK Store requires a valid license.</strong> '
                . 'Enter your license key to activate all features. '
                . '<a href="' . esc_url(admin_url('admin.php?page=pkstore-setup&tab=license')) . '">Enter License Key &rarr;</a>'
                . '</p></div>';
            return;
        }

        // Expiry warning — show 14 days before
        $expiry = $this->license->getExpiry();
        if ($expiry) {
            $daysLeft = ceil((strtotime($expiry) - time()) / 86400);
            if ($daysLeft <= 14 && $daysLeft > 0) {
                echo '<div class="notice notice-warning is-dismissible"><p>'
                    . "&#9888; <strong>PK Store license expires in {$daysLeft} days</strong> ("
                    . esc_html(date('d M Y', strtotime($expiry)))
                    . '). <a href="https://junctionsystems.pk/renew" target="_blank">Renew now</a>'
                    . '</p></div>';
            }
        }
    }

    public function handleActivate(): void {
        check_admin_referer('pkstore_license_activate');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $key = sanitize_text_field($_POST['license_key'] ?? '');
        try {
            $this->license->activate($key);
            wp_redirect(admin_url('admin.php?page=pkstore-setup&tab=license&activated=1'));
        } catch (\Exception $e) {
            set_transient('pkstore_license_error', $e->getMessage(), 60);
            wp_redirect(admin_url('admin.php?page=pkstore-setup&tab=license&error=1'));
        }
        exit;
    }

    public function handleDeactivate(): void {
        check_admin_referer('pkstore_license_deactivate');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $this->license->deactivate();
        wp_redirect(admin_url('admin.php?page=pkstore-setup&tab=license&deactivated=1'));
        exit;
    }

    private function getLockoutMessage(string $reason): string {
        return match($reason) {
            'domain_mismatch' => 'This license is registered to a different domain. Contact hello@junctionsystems.pk to transfer.',
            'grace_expired'   => 'License server was unreachable for 7 days. Contact hello@junctionsystems.pk immediately.',
            default           => 'License invalid. Contact hello@junctionsystems.pk.',
        };
    }

    /** Renders the License tab inside SetupWizard */
    public function renderTab(): void {
        $isValid   = $this->license->isValid();
        $tier      = $this->license->getTier();
        $expiry    = $this->license->getExpiry();
        $key       = $this->license->getKey();
        $error     = get_transient('pkstore_license_error');
        $activated = isset($_GET['activated']);
        $deactivated = isset($_GET['deactivated']);
        ?>
        <h2>&#128274; License</h2>

        <?php if ($error): delete_transient('pkstore_license_error'); ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <?php if ($activated): ?>
        <div class="notice notice-success inline"><p>&#10003; License activated successfully! All features are now unlocked.</p></div>
        <?php endif; ?>
        <?php if ($deactivated): ?>
        <div class="notice notice-info inline"><p>License deactivated. You can now activate it on another domain.</p></div>
        <?php endif; ?>

        <?php if ($isValid): ?>
        <!-- Active license panel -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:20px;margin:16px 0">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <span style="font-size:28px">&#10003;</span>
            <div>
              <strong style="color:#166534;font-size:16px">License Active</strong>
              <div style="font-size:13px;color:#166534">
                Tier: <strong><?php echo esc_html(ucfirst($tier ?? 'unknown')); ?></strong>
                &nbsp;|&nbsp;
                Expires: <strong><?php echo $expiry ? esc_html(date('d M Y', strtotime($expiry))) : 'Never'; ?></strong>
                &nbsp;|&nbsp;
                Domain: <strong><?php echo esc_html(home_url()); ?></strong>
              </div>
            </div>
          </div>
          <div style="font-size:13px;color:#166534;margin-bottom:16px">
            Key: <code style="background:#dcfce7;padding:2px 8px;border-radius:4px"><?php echo esc_html($key ?? ''); ?></code>
          </div>
          <form method="post" action="<?php echo admin_url('admin-post.php'); ?>"
                onsubmit="return confirm('Deactivate this license? The store features will stop working on this domain.')">
            <?php wp_nonce_field('pkstore_license_deactivate'); ?>
            <input type="hidden" name="action" value="pkstore_deactivate_license">
            <button type="submit" class="button button-secondary">Deactivate License (to move to another domain)</button>
          </form>
        </div>

        <!-- Features unlocked -->
        <h3 style="margin-top:20px">Features on your <?php echo esc_html(ucfirst($tier ?? '')); ?> license</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px">
        <?php foreach (LicenseFeatures::ALL as $feat): ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 0">
            <?php if ($this->license->hasFeature($feat)): ?>
              <span style="color:#16a34a;font-weight:700">&#10003;</span>
              <span style="color:#166534"><?php echo esc_html(str_replace('_', ' ', $feat)); ?></span>
            <?php else: ?>
              <span style="color:#9ca3af">&#8212;</span>
              <span style="color:#9ca3af;text-decoration:line-through"><?php echo esc_html(str_replace('_', ' ', $feat)); ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Activation form -->
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:20px;margin:16px 0">
          <strong>&#9888; No active license found</strong>
          <p style="font-size:14px;margin-top:6px;color:#92400e">
            PK Store requires a valid license key to activate features.
            Purchase at <a href="https://junctionsystems.pk" target="_blank">junctionsystems.pk</a>
            then enter your key below.
          </p>
        </div>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
          <?php wp_nonce_field('pkstore_license_activate'); ?>
          <input type="hidden" name="action" value="pkstore_activate_license">
          <table class="form-table"><tbody>
          <tr>
            <th><label for="license_key">License Key</label></th>
            <td>
              <input type="text" id="license_key" name="license_key"
                value="" class="regular-text" placeholder="JUNC-XXXX-XXXX-XXXX-XXXX"
                pattern="JUNC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"
                style="font-family:monospace;letter-spacing:.05em">
              <p class="description">
                Your license key was emailed when you purchased. Format: JUNC-XXXX-XXXX-XXXX-XXXX<br>
                Each key can only be active on one domain at a time.
              </p>
            </td>
          </tr>
          </tbody></table>
          <p class="submit">
            <button type="submit" class="button button-primary" style="background:#10B981;border-color:#059669">
              Activate License
            </button>
          </p>
        </form>
        <?php endif; ?>
        <?php
    }
}
