<?php
/**
 * Security & Credential Encryption
 *
 * Storage formats:
 *  - "enc2:" AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC), fixed-length IV extraction. Current default.
 *  - "sod:"  libsodium secretbox (XSalsa20-Poly1305). Used when OpenSSL is unavailable.
 *            WordPress 5.2+ bundles sodium_compat, so this is always available as a fallback.
 *  - "enc:"  Legacy AES-256-CBC with "::" delimiter. Decrypt-only, upgraded on next admin load.
 *  - "b64:"  Legacy reversible base64. Decrypt-only, upgraded on next admin load.
 *
 * Secrets are never stored in a reversible, unencrypted format.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Encryption {
    /**
     * Cipher method
     */
    const CIPHER = 'aes-256-cbc';

    /**
     * Format prefixes
     */
    const PREFIX_OPENSSL = 'enc2:';
    const PREFIX_SODIUM  = 'sod:';
    const PREFIX_LEGACY  = 'enc:';
    const PREFIX_BASE64  = 'b64:';

    /**
     * Default placeholder value shipped in wp-config-sample.php
     */
    const WP_DEFAULT_PHRASE = 'put your unique phrase here';

    /**
     * Check whether wp-config.php defines unique, non-default auth salts.
     *
     * When these constants are missing, WordPress falls back to random salts stored
     * in the database (still site-unique), but a database leak then also leaks the key.
     *
     * @return bool
     */
    public static function has_strong_salts() {
        foreach (array('AUTH_KEY', 'SECURE_AUTH_KEY') as $const) {
            if (!defined($const)) {
                return false;
            }
            $value = constant($const);
            if (!is_string($value) || strlen($value) < 32 || $value === self::WP_DEFAULT_PHRASE) {
                return false;
            }
        }
        return true;
    }

    /**
     * Derive the master key material from WordPress security salts.
     *
     * wp_salt() never returns a shared/hardcoded value: if constants are missing or
     * left at their default, it generates and persists random site-specific salts.
     *
     * @return string Raw 32-byte master key
     */
    private static function get_master_key() {
        $material = wp_salt('auth') . wp_salt('secure_auth');
        return hash('sha256', 'r2g-master|' . $material, true);
    }

    /**
     * Derive a purpose-specific subkey (separate keys for encryption and MAC).
     *
     * @param string $purpose
     * @return string Raw 32-byte key
     */
    private static function get_subkey($purpose) {
        return hash_hmac('sha256', 'r2g|' . $purpose, self::get_master_key(), true);
    }

    /**
     * Legacy key derivation used by the "enc:" format (decrypt-only).
     *
     * @return string
     */
    private static function get_legacy_key() {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'r2_by_grisma_default_secure_salt';
        if (function_exists('wp_salt')) {
            $salt .= wp_salt('auth') . wp_salt('secure_auth');
        }
        return hash('sha256', $salt, true);
    }

    /**
     * Whether OpenSSL AES-256-CBC is available
     *
     * @return bool
     */
    private static function openssl_available() {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && function_exists('openssl_cipher_iv_length');
    }

    /**
     * Whether libsodium secretbox (native or sodium_compat) is available
     *
     * @return bool
     */
    private static function sodium_available() {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
    }

    /**
     * Whether any secure encryption backend is available
     *
     * @return bool
     */
    public static function is_available() {
        return self::openssl_available() || self::sodium_available();
    }

    /**
     * Encrypt sensitive string
     *
     * @param string $plaintext
     * @return string Prefixed, base64-encoded ciphertext. Empty string on failure
     *                (never falls back to a reversible encoding).
     */
    public static function encrypt($plaintext) {
        if ($plaintext === '' || $plaintext === null) {
            return '';
        }
        $plaintext = (string) $plaintext;

        if (self::openssl_available()) {
            $iv_length = openssl_cipher_iv_length(self::CIPHER);
            $iv = random_bytes($iv_length);
            $cipher_raw = openssl_encrypt($plaintext, self::CIPHER, self::get_subkey('enc'), OPENSSL_RAW_DATA, $iv);
            if ($cipher_raw === false) {
                return '';
            }
            $mac = hash_hmac('sha256', $iv . $cipher_raw, self::get_subkey('mac'), true);
            return self::PREFIX_OPENSSL . base64_encode($iv . $mac . $cipher_raw);
        }

        if (self::sodium_available()) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $box = sodium_crypto_secretbox($plaintext, $nonce, self::get_subkey('sodium'));
                return self::PREFIX_SODIUM . base64_encode($nonce . $box);
            } catch (\Throwable $e) {
                return '';
            }
        }

        // No secure backend: refuse to store the secret rather than leaking it.
        return '';
    }

    /**
     * Decrypt encrypted string
     *
     * @param string $ciphertext
     * @return string
     */
    public static function decrypt($ciphertext) {
        if (empty($ciphertext) || !is_string($ciphertext)) {
            return '';
        }

        if (strpos($ciphertext, self::PREFIX_OPENSSL) === 0) {
            return self::decrypt_openssl_v2(substr($ciphertext, strlen(self::PREFIX_OPENSSL)));
        }

        if (strpos($ciphertext, self::PREFIX_SODIUM) === 0) {
            return self::decrypt_sodium(substr($ciphertext, strlen(self::PREFIX_SODIUM)));
        }

        if (strpos($ciphertext, self::PREFIX_LEGACY) === 0) {
            return self::decrypt_legacy(substr($ciphertext, strlen(self::PREFIX_LEGACY)));
        }

        if (strpos($ciphertext, self::PREFIX_BASE64) === 0) {
            $decoded = base64_decode(substr($ciphertext, strlen(self::PREFIX_BASE64)), true);
            return $decoded !== false ? $decoded : '';
        }

        // Unencrypted legacy raw string
        return $ciphertext;
    }

    /**
     * Decrypt "enc2:" payload: IV || HMAC(32) || ciphertext
     *
     * @param string $encoded
     * @return string
     */
    private static function decrypt_openssl_v2($encoded) {
        if (!self::openssl_available()) {
            return '';
        }
        $raw = base64_decode($encoded, true);
        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        if ($raw === false || strlen($raw) <= $iv_length + 32) {
            return '';
        }

        $iv = substr($raw, 0, $iv_length);
        $mac = substr($raw, $iv_length, 32);
        $cipher_raw = substr($raw, $iv_length + 32);

        $expected = hash_hmac('sha256', $iv . $cipher_raw, self::get_subkey('mac'), true);
        if (!hash_equals($expected, $mac)) {
            return '';
        }

        $plain = openssl_decrypt($cipher_raw, self::CIPHER, self::get_subkey('enc'), OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    /**
     * Decrypt "sod:" payload: nonce || secretbox
     *
     * @param string $encoded
     * @return string
     */
    private static function decrypt_sodium($encoded) {
        if (!self::sodium_available()) {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        try {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($box, $nonce, self::get_subkey('sodium'));
            return $plain !== false ? $plain : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Decrypt legacy "enc:" payload: IV || "::" || base64(ciphertext)
     *
     * Uses fixed-length IV extraction so binary IVs containing "::" bytes decrypt correctly.
     *
     * @param string $encoded
     * @return string
     */
    private static function decrypt_legacy($encoded) {
        if (!self::openssl_available()) {
            return '';
        }
        $raw = base64_decode($encoded);
        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        if ($raw === false || strlen($raw) <= $iv_length + 2 || substr($raw, $iv_length, 2) !== '::') {
            return '';
        }

        $iv = substr($raw, 0, $iv_length);
        $encrypted_data = substr($raw, $iv_length + 2);

        $decrypted = openssl_decrypt($encrypted_data, self::CIPHER, self::get_legacy_key(), 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Whether a stored value uses an outdated or insecure format
     *
     * @param string $stored
     * @return bool
     */
    public static function needs_upgrade($stored) {
        if (empty($stored) || !is_string($stored)) {
            return false;
        }
        return strpos($stored, self::PREFIX_OPENSSL) !== 0 && strpos($stored, self::PREFIX_SODIUM) !== 0;
    }

    /**
     * Re-encrypt a stored option that uses a legacy/insecure format (b64:, enc:, plaintext).
     *
     * @param string $option_name
     * @return void
     */
    public static function maybe_upgrade_option($option_name) {
        $stored = get_option($option_name, '');
        if (!self::needs_upgrade($stored)) {
            return;
        }

        $plain = self::decrypt($stored);
        if ($plain === '') {
            return;
        }

        $encrypted = self::encrypt($plain);
        if ($encrypted !== '') {
            update_option($option_name, $encrypted, false);
        }
    }

    /**
     * Mask secret key for display in settings UI
     *
     * @param string $secret
     * @return string
     */
    public static function mask($secret) {
        if (empty($secret)) {
            return '';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', 12);
        }
        return substr($secret, 0, 4) . str_repeat('•', 16) . substr($secret, -4);
    }
}
