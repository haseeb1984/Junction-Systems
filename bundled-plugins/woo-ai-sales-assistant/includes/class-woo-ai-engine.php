<?php
defined("ABSPATH") || exit;

class Woo_AI_Engine {

    private const GROQ_RPM   = 30;
    private const GROQ_TPM   = 6000;
    private const GROQ_RPD   = 14400;
    private const TK_SESSION  = "woo_ai_sess_";
    private const TK_RPM      = "woo_ai_rpm";
    private const TK_RPD      = "woo_ai_rpd";
    private const TK_TPM      = "woo_ai_tpm";
    private const TK_BACKOFF  = "woo_ai_backoff";

    /**
     * Encrypt newly saved Groq credentials with authenticated encryption.
     * Legacy plaintext options are read once and transparently migrated.
     */
    public static function encrypt_api_key(string $api_key): string {
        $salt = function_exists("wp_salt") ? wp_salt("auth") . wp_salt("secure_auth") : "";
        if ($salt === "") return "";

        $key = hash("sha256", $salt, true);
        $iv = random_bytes(12);
        $tag = "";
        $ciphertext = openssl_encrypt($api_key, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        if ($ciphertext === false || strlen($tag) !== 16) return "";
        return "enc:v2:" . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decrypt_api_key(string $stored): string {
        if (!str_starts_with($stored, "enc:v2:")) return $stored;

        $salt = function_exists("wp_salt") ? wp_salt("auth") . wp_salt("secure_auth") : "";
        if ($salt === "") return "";
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) < 28) return "";

        $key = hash("sha256", $salt, true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? "" : $plain;
    }

    private static function get_api_key(array $opts): string {
        $stored = trim((string)($opts["api_key"] ?? ""));
        if ($stored === "") return "";

        if (str_starts_with($stored, "enc:v2:")) {
            return self::decrypt_api_key($stored);
        }

        // Migrate existing plaintext keys without interrupting a working store.
        $encrypted = self::encrypt_api_key($stored);
        if ($encrypted === "") {
            // Do not continue using a plaintext credential if encryption cannot
            // be performed (for example, missing WordPress salts).
            return "";
        }

        $current = get_option("woo_ai_settings", []);
        if (is_array($current) && ($current["api_key"] ?? null) === $stored) {
            $current["api_key"] = $encrypted;
            update_option("woo_ai_settings", $current, false);
        }
        return $stored;
    }

    public static function process(string $user_message): array {
        $opts = get_option("woo_ai_settings", []);
        $wa   = self::get_whatsapp();

        if (self::get_api_key($opts) === "") {
            return self::err("AI assistant is not configured. Contact the store admin.", $wa);
        }

        $sess = self::check_session_limit($opts, $wa);
        if ($sess !== null) return $sess;

        if (!self::check_rpm($opts)) return self::handoff($wa, "RPM limit");
        if (!self::check_rpd($opts)) return self::handoff($wa, "RPD limit");

        $backoff = (int) get_transient(self::TK_BACKOFF);
        if ($backoff && time() < $backoff) return self::handoff($wa, "In backoff");

        $system  = self::build_system_prompt($opts, $wa);
        $est_tok = self::estimate_tokens($system . $user_message) + WOO_AI_MAX_TOKENS;
        if (((int)get_transient(self::TK_TPM) + $est_tok) > self::GROQ_TPM && empty($opts["allow_paid"])) {
            return self::handoff($wa, "TPM exceeded");
        }

        return self::call_groq(sanitize_text_field($user_message), $system,
            $opts["model"] ?? WOO_AI_MODEL_FAST, $opts, $est_tok, $wa);
    }

    private static function call_groq(string $msg, string $sys, string $model,
                                       array $opts, int $est, string $wa): array {
        $response = wp_remote_post(WOO_AI_GROQ_ENDPOINT, [
            "timeout"     => WOO_AI_TIMEOUT,
            "data_format" => "body",
            "headers"     => [
                "Authorization" => "Bearer " . self::get_api_key($opts),
                "Content-Type"  => "application/json",
                "User-Agent"    => "WooCommerce-AI-Assistant/" . WOO_AI_VERSION,
            ],
            "body" => wp_json_encode([
                "model"       => $model,
                "max_tokens"  => WOO_AI_MAX_TOKENS,
                "temperature" => 0.65,
                "messages"    => [
                    ["role" => "system", "content" => $sys],
                    ["role" => "user",   "content" => $msg],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return self::err("Sales assistant offline. Please try again.", $wa);
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $body    = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 429) {
            $retry = max(5, min((int)($headers["retry-after"] ?? $headers["x-ratelimit-reset-tokens"] ?? 60), 300));
            set_transient(self::TK_BACKOFF, time() + $retry, $retry + 10);
            return self::handoff($wa, "429 backoff {$retry}s");
        }
        if ($status >= 500) return self::err("AI service offline. Please try again.", $wa);
        if ($status === 401 || $status === 403) return self::err("AI configuration error.", $wa);

        if ($status === 200 && isset($body["choices"][0]["message"]["content"])) {
            $reply = trim($body["choices"][0]["message"]["content"]);
            self::increment_rpm(); self::increment_rpd();
            self::increment_tpm($est); self::increment_session($opts);
            return ["success" => true, "reply" => $reply, "whatsapp" => $wa];
        }

        return self::err("Unexpected error. Please try again.", $wa);
    }

    public static function get_product_context(): string {
        if (!function_exists("wc_get_products")) return "";
        $cached = get_transient("woo_ai_product_context");
        if ($cached !== false) return $cached;
        $products = wc_get_products(["status"=>"publish","stock_status"=>"instock","limit"=>40,"orderby"=>"popularity"]);
        if (empty($products)) return "No in-stock products.";
        $lines = [];
        foreach ($products as $p) {
            $cats  = implode(", ", array_filter(array_map(fn($id) => get_term($id)?->name ?? "", $p->get_category_ids())));
            $price = $p->is_on_sale()
                ? "PKR " . number_format((float)$p->get_sale_price()) . " (was PKR " . number_format((float)$p->get_regular_price()) . ")"
                : "PKR " . number_format((float)$p->get_price());
            $desc  = mb_substr(wp_strip_all_tags($p->get_short_description()), 0, 80);
            $lines[] = "[{$p->get_id()}] {$p->get_name()} | {$price} | " . ($cats ?: "General") . ($desc ? " | $desc" : "");
        }
        $ctx = implode("\n", $lines);
        set_transient("woo_ai_product_context", $ctx, 10 * MINUTE_IN_SECONDS);
        return $ctx;
    }

    private static function build_system_prompt(array $opts, string $wa): string {
        $persona  = trim($opts["persona"] ?? self::default_persona());
        $products = self::get_product_context();
        $store    = get_bloginfo("name");
        return "{$persona}\n\nSTORE: {$store} | WHATSAPP: +{$wa}\n\nLIVE CATALOGUE (only these):\n{$products}\n\nPOLICIES: Free delivery PKR 2,000+. Same-day Karachi/Lahore/ISB/RWP. JazzCash, EasyPaisa, CoD(OTP), Card. 7-day returns.\n\nHANDOFF: If customer is frustrated or asks for human — say: 'Seedha team se WhatsApp karein: +{$wa}' and they can click the WhatsApp button.";
    }

    public static function default_persona(): string {
        return "You are Dukaan AI — friendly bilingual (Urdu/English) sales assistant.\nLANGUAGE: detect and match — Roman Urdu reply, English reply, mixed reply.\nSTYLE: 2-4 lines, 1-2 emojis, gentle urgency on sale items.\nRULES: Only use catalogue products and prices. Never invent. If customer wants human agent, recommend WhatsApp.";
    }

    public static function get_whatsapp(): string {
        $wa = get_option("woocommerce_store_phone", "") ?: get_option("woo_ai_whatsapp", "923001234567");
        return preg_replace("/[^0-9]/", "", $wa) ?: "923001234567";
    }

    private static function handoff(string $wa, string $log = ""): array {
        if ($log) error_log("[WooAI] Handoff: {$log}");
        return [
            "success"  => true,
            "reply"    => "Hamara AI assistant is waqt limit par hai 🙏 Humara team WhatsApp pe available hai — ek click mein connect karein!",
            "handoff"  => true,
            "whatsapp" => $wa,
        ];
    }

    private static function err(string $msg, string $wa = ""): array {
        return ["success" => false, "reply" => $msg, "whatsapp" => $wa ?: self::get_whatsapp()];
    }

    private static function check_session_limit(array $opts, string $wa): ?array {
        $limit = (int)($opts["session_limit"] ?? 10);
        $key   = self::TK_SESSION . md5(self::get_ip());
        if ((int) get_transient($key) >= $limit) {
            return [
                "success"  => false,
                "reply"    => "Is ghante {$limit} messages ka limit ho gaya 🙏 Ek ghante baad try karein ya WhatsApp karein!",
                "handoff"  => true,
                "whatsapp" => $wa,
            ];
        }
        return null;
    }

    private static function increment_session(array $opts): void {
        $key = self::TK_SESSION . md5(self::get_ip());
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    }

    private static function check_rpm(array $opts): bool { return (int)get_transient(self::TK_RPM) < self::GROQ_RPM || !empty($opts["allow_paid"]); }
    private static function increment_rpm(): void { set_transient(self::TK_RPM, (int)get_transient(self::TK_RPM) + 1, 60); }
    private static function check_rpd(array $opts): bool { return (int)get_transient(self::TK_RPD) < self::GROQ_RPD || !empty($opts["allow_paid"]); }
    private static function increment_rpd(): void { set_transient(self::TK_RPD, (int)get_transient(self::TK_RPD) + 1, strtotime("tomorrow midnight") - time()); }
    private static function increment_tpm(int $t): void { set_transient(self::TK_TPM, (int)get_transient(self::TK_TPM) + $t, 60); }
    private static function estimate_tokens(string $text): int { return (int)ceil(mb_strlen($text) / 4); }

    private static function get_ip(): string {
        foreach (["HTTP_CF_CONNECTING_IP","HTTP_X_FORWARDED_FOR","REMOTE_ADDR"] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(",", sanitize_text_field(wp_unslash($_SERVER[$k])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return "0.0.0.0";
    }
}
