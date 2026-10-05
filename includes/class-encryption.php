<?php
/**
 * Security & Credential Encryption
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
     * Get encryption key derived from WordPress security salts
     *
     * @return string
     */
    private static function get_key() {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'r2_by_grisma_default_secure_salt';
        if (function_exists('wp_salt')) {
            $salt .= wp_salt('auth') . wp_salt('secure_auth');
        }
        return hash('sha256', $salt, true);
    }

    /**
     * Encrypt sensitive string
     *
     * @param string $plaintext
     * @return string Base64-encoded encrypted string with IV
     */
    public static function encrypt($plaintext) {
        if (empty($plaintext)) {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            // Fallback base64 encoding if OpenSSL extension is missing
            return 'b64:' . base64_encode($plaintext);
        }

        $key = self::get_key();
        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($iv_length);

        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, 0, $iv);
        if ($encrypted === false) {
            return '';
        }

        return 'enc:' . base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Decrypt encrypted string
     *
     * @param string $ciphertext
     * @return string
     */
    public static function decrypt($ciphertext) {
        if (empty($ciphertext)) {
            return '';
        }

        // Handle unencrypted or legacy raw strings
        if (strpos($ciphertext, 'enc:') !== 0 && strpos($ciphertext, 'b64:') !== 0) {
            return $ciphertext;
        }

        // Fallback base64 decoding
        if (strpos($ciphertext, 'b64:') === 0) {
            $raw = substr($ciphertext, 4);
            $decoded = base64_decode($raw);
            return $decoded !== false ? $decoded : '';
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($ciphertext, 4));
        if ($raw === false || strpos($raw, '::') === false) {
            return '';
        }

        list($iv, $encrypted_data) = explode('::', $raw, 2);
        $key = self::get_key();

        $decrypted = openssl_decrypt($encrypted_data, self::CIPHER, $key, 0, $iv);
        return $decrypted !== false ? $decrypted : '';
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
