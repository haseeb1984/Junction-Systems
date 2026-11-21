<?php
declare(strict_types=1);
namespace PkStore\License;

use PkStore\License\Exception\{
    LicenseException, LicenseExpiredException,
    LicenseDomainMismatch, LicenseInvalidException,
    LicenseFeatureNotAvailable
};

/**
 * LicenseManager
 *
 * Flow:
 *   1. On every WP request, load cached license from wp_options (encrypted).
 *   2. If cache is older than 24h, re-validate against license server.
 *   3. If license invalid/expired → log, show admin notice, block features.
 *   4. If domain mismatch → hard block (license in use on another domain).
 *   5. Grace period: 7 days offline validation before hard lockout.
 */
final class LicenseManager {

    private const OPT_LICENSE_KEY   = 'pkstore_license_key';
    private const OPT_LICENSE_CACHE = 'pkstore_license_cache';
    private const OPT_LICENSE_GRACE = 'pkstore_license_grace_start';
    private const CACHE_TTL         = 86400;          // 24 hours
    private const GRACE_PERIOD      = 7 * 86400;      // 7 days
    private const SERVER_URL        = 'https://license.junctionsystems.pk/api/validate';
    private const ENCRYPT_KEY_OPT   = 'pkstore_lk';   // stored in wp_options on first install

    private ?array $cache = null;

    public function __construct() {}

    // ── Public API ──────────────────────────────────────────────

    /** Returns true if any valid license is active */
    public function isValid(): bool {
        try { return (bool) $this->getLicenseData(); }
        catch (LicenseException) { return false; }
    }

    /** Check if a specific feature is unlocked by the current license */
    public function hasFeature(string $feature): bool {
        try {
            $data = $this->getLicenseData();
            return in_array($feature, $data['features'] ?? [], true);
        } catch (LicenseException) {
            return false;
        }
    }

    /** Throw if feature not available — use at the top of any feature handler */
    public function requireFeature(string $feature): void {
        if (!$this->hasFeature($feature)) {
            throw new LicenseFeatureNotAvailable(
                "Feature [{$feature}] is not available on your current license tier. " .
                "Please upgrade at junctionsystems.pk/upgrade."
            );
        }
    }

    /** Activate a new license key for this domain */
    public function activate(string $licenseKey): array {
        $licenseKey = trim($licenseKey);
        if (!preg_match('/^JUNC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $licenseKey)) {
            throw new LicenseInvalidException('Invalid license key format. Expected: JUNC-XXXX-XXXX-XXXX-XXXX');
        }

        $result = $this->remoteCall('activate', $licenseKey);

        if (!($result['success'] ?? false)) {
            throw new LicenseInvalidException($result['message'] ?? 'Activation failed.');
        }

        // Save key + cache result
        update_option(self::OPT_LICENSE_KEY, $this->encrypt($licenseKey), false);
        $this->saveCache($result['data']);
        delete_option(self::OPT_LICENSE_GRACE);

        return $result['data'];
    }

    /** Deactivate license (e.g. before moving to another domain) */
    public function deactivate(): bool {
        $key = $this->getStoredKey();
        if (!$key) return false;
        $result = $this->remoteCall('deactivate', $key);
        if (!($result['success'] ?? false)) {
            throw new LicenseException($result['message'] ?? 'License deactivation was not confirmed by the license server.');
        }
        delete_option(self::OPT_LICENSE_KEY);
        delete_option(self::OPT_LICENSE_CACHE);
        delete_option(self::OPT_LICENSE_GRACE);
        $this->cache = null;
        return true;
    }

    /** Current license tier (basic|standard|enterprise) or null */
    public function getTier(): ?string {
        try { return $this->getLicenseData()['tier'] ?? null; }
        catch (LicenseException) { return null; }
    }

    /** Expiry date string or null */
    public function getExpiry(): ?string {
        try { return $this->getLicenseData()['expires_at'] ?? null; }
        catch (LicenseException) { return null; }
    }

    /** Stored license key (decrypted, for display) */
    public function getKey(): ?string {
        return $this->getStoredKey();
    }

    // ── Core validation ─────────────────────────────────────────

    private function getLicenseData(): array {
        // Return in-memory cache first
        if ($this->cache !== null) {
            $this->assertValid($this->cache);
            return $this->cache;
        }

        // Load from WP options
        $raw = get_option(self::OPT_LICENSE_CACHE, '');
        if ($raw) {
            $data = $this->decryptJson($raw);
            if ($data) {
                $this->cache = $data;
                // If cache is fresh enough, validate locally and return
                if ((time() - ($data['cached_at'] ?? 0)) < self::CACHE_TTL) {
                    $this->assertValid($data);
                    return $data;
                }
            }
        }

        // Cache stale or missing — re-validate remotely
        $key = $this->getStoredKey();
        if (!$key) {
            throw new LicenseInvalidException('No license key found. Please enter your license key in PK Store → Setup Wizard → License.');
        }

        try {
            $result = $this->remoteCall('validate', $key);
            if (!($result['success'] ?? false)) {
                throw new LicenseInvalidException($result['message'] ?? 'License validation failed.');
            }
            $data = $result['data'];
            $this->saveCache($data);
            delete_option(self::OPT_LICENSE_GRACE);
            $this->cache = $data;
            $this->assertValid($data);
            return $data;
        } catch (LicenseDomainMismatch $e) {
            // Hard fail — domain mismatch cannot be graced
            $this->lockout('domain_mismatch');
            throw $e;
        } catch (LicenseExpiredException $e) {
            throw $e;
        } catch (LicenseInvalidException $e) {
            // A definitive invalid/revoked response is not an outage and must
            // never be converted into an offline grace-period entitlement.
            throw $e;
        } catch (LicenseException $e) {
            // Transport/service outage only: use the last known-good cache.
            return $this->handleGrace($e);
        }
    }

    private function assertValid(array $data): void {
        // Domain check — always hard
        $currentDomain = $this->getCurrentDomain();
        if (!empty($data['domain']) && $data['domain'] !== $currentDomain) {
            throw new LicenseDomainMismatch(
                "This license is registered to [{$data['domain']}], " .
                "not [{$currentDomain}]. " .
                "Contact support to transfer your license."
            );
        }

        // Expiry check
        if (!empty($data['expires_at'])) {
            $expiry = strtotime($data['expires_at']);
            if ($expiry && $expiry < time()) {
                throw new LicenseExpiredException(
                    "Your license expired on " . date('d M Y', $expiry) . ". " .
                    "Renew at junctionsystems.pk/renew"
                );
            }
        }
    }

    private function handleGrace(\Throwable $e): array {
        // Use last known good cache during grace period
        $raw  = get_option(self::OPT_LICENSE_CACHE, '');
        $data = $raw ? $this->decryptJson($raw) : null;

        if (!$data) {
            throw new LicenseInvalidException('No valid license found and server unreachable.');
        }

        // Start or check grace period
        $graceStart = (int) get_option(self::OPT_LICENSE_GRACE, 0);
        if (!$graceStart) {
            $graceStart = time();
            update_option(self::OPT_LICENSE_GRACE, $graceStart, false);
        }

        if ((time() - $graceStart) > self::GRACE_PERIOD) {
            $this->lockout('grace_expired');
            throw new LicenseInvalidException(
                'License server unreachable for 7 days. Store features locked. ' .
                'Contact support: hello@junctionsystems.pk'
            );
        }

        // Offline grace is not an exemption from expiry or domain binding.
        // A previously cached entitlement must remain valid for this exact store.
        $this->assertValid($data);

        // Still within grace — log warning and continue
        error_log('[PkStore License] Using grace period cache. Days remaining: ' .
            ceil((self::GRACE_PERIOD - (time() - $graceStart)) / 86400));

        return $data;
    }

    // ── Remote call ─────────────────────────────────────────────

    private function remoteCall(string $action, string $key): array {
        $domain = $this->getCurrentDomain();
        $body   = ['action' => $action, 'license_key' => $key, 'domain' => $domain,
                   'product' => 'pk-store', 'version' => PKSTORE_VERSION,
                   'sig' => hash_hmac('sha256', $action.$key.$domain, $this->getEncryptKey())];

        $response = wp_remote_post(self::SERVER_URL, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json',
                          'X-PK-Product' => 'pk-store'],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new LicenseException('License server unreachable: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 403) {
            throw new LicenseDomainMismatch(is_array($data) ? ($data['message'] ?? 'Domain mismatch') : 'Domain mismatch');
        }
        if ($code === 402) {
            throw new LicenseExpiredException(is_array($data) ? ($data['message'] ?? 'License expired') : 'License expired');
        }
        if ($code === 401 || $code === 410) {
            throw new LicenseInvalidException(is_array($data) ? ($data['message'] ?? 'License is invalid or revoked') : 'License is invalid or revoked');
        }
        if ($code < 200 || $code >= 300) {
            throw new LicenseException("License server returned HTTP {$code}.");
        }
        if (!is_array($data) || !array_key_exists('success', $data) || !is_bool($data['success'])) {
            throw new LicenseInvalidException('License server returned an invalid response.');
        }
        if (!$data['success']) {
            return $data;
        }

        if ($action !== 'deactivate') {
            $license = $data['data'] ?? null;
            if (!is_array($license)
                || !in_array($license['tier'] ?? null, ['basic', 'standard', 'enterprise'], true)
                || !is_array($license['features'] ?? null)
                || !array_is_list($license['features'])
                || (isset($license['domain']) && !is_string($license['domain']))
                || (isset($license['expires_at']) && !is_string($license['expires_at']))) {
                throw new LicenseInvalidException('License server returned incomplete entitlement data.');
            }

            foreach ($license['features'] as $feature) {
                if (!is_string($feature) || !in_array($feature, LicenseFeatures::ALL, true)) {
                    throw new LicenseInvalidException('License server returned an unknown feature entitlement.');
                }
            }
        }

        return $data;
    }

    // ── Lockout ─────────────────────────────────────────────────

    private function lockout(string $reason): void {
        update_option('pkstore_lockout_reason', $reason, false);
        update_option('pkstore_locked_at', time(), false);
    }

    public function isLockedOut(): bool {
        return (bool) get_option('pkstore_lockout_reason', '');
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function getStoredKey(): ?string {
        $enc = get_option(self::OPT_LICENSE_KEY, '');
        return $enc ? $this->decrypt($enc) : null;
    }

    private function getCurrentDomain(): string {
        return strtolower(parse_url(home_url(), PHP_URL_HOST) ?? '');
    }

    private function saveCache(array $data): void {
        $data['cached_at'] = time();
        update_option(self::OPT_LICENSE_CACHE, $this->encryptJson($data), false);
    }

    // ── Encryption (AES-256-CBC) ─────────────────────────────────
    private function getEncryptKey(): string {
        $key = get_option(self::ENCRYPT_KEY_OPT, '');
        if (!$key) {
            $key = bin2hex(random_bytes(32));
            update_option(self::ENCRYPT_KEY_OPT, $key, false);
        }
        return $key;
    }

    private function encryptionKey(): string {
        $salt = function_exists('wp_salt') ? wp_salt('auth') . wp_salt('secure_auth') : '';
        if ($salt === '') {
            throw new LicenseException('WordPress salts are required to protect the license cache.');
        }
        return hash('sha256', $salt, true);
    }

    private function encrypt(string $plain): string {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new LicenseException('Unable to encrypt license data.');
        }
        return 'v2:' . base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $ciphertext): string {
        if (str_starts_with($ciphertext, 'v2:')) {
            $raw = base64_decode(substr($ciphertext, 3), true);
            if ($raw === false || strlen($raw) < 28) return '';
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $encrypted = substr($raw, 28);
            $plain = openssl_decrypt($encrypted, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }

        // Backward compatibility for existing AES-CBC license options.
        $key = substr(hash('sha256', $this->getEncryptKey(), true), 0, 32);
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) <= 16) return '';
        $iv = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv) ?: '';
    }

    private function encryptJson(array $data): string {
        return $this->encrypt(wp_json_encode($data));
    }

    private function decryptJson(string $ciphertext): ?array {
        $json = $this->decrypt($ciphertext);
        return $json ? json_decode($json, true) : null;
    }
}
