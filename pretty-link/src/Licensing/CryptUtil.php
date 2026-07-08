<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

/**
 * Clearly-written AES-256-GCM encryption utility.
 *
 * Replaces the obfuscated `a99_f33_9c7` decrypt routine from 3.x
 * (see `current-version/pretty-link/app/models/PrliUtils.php::decrypt_string`).
 *
 * Layout of a base64-encoded encrypted blob (matches 3.x byte-for-byte):
 *   [ 16 bytes salt ][ N bytes IV ][ M bytes ciphertext ][ 16 bytes GCM tag ]
 *
 * - PBKDF2-SHA512 derives a 32-byte key from the hardcoded password + salt
 *   over 20,000 iterations. The password remains the 3.x literal
 *   'prettylinks' to preserve back-compat with existing encrypted strings.
 * - IV length comes from openssl_cipher_iv_length('aes-256-gcm') (12 bytes).
 * - Tag is always 16 bytes (GCM default).
 */
class CryptUtil
{
    public const CIPHER = 'aes-256-gcm';

    /**
     * Hardcoded password. Back-compat with v3.x encrypted blobs.
     * Do NOT change — existing data on upgrader sites is encrypted with this.
     */
    private const PASSWORD = 'prettylinks';

    private const ITERATIONS = 20000;

    private const KEY_LEN = 32;

    private const SALT_LEN = 16;

    private const TAG_LEN = 16;

    /**
     * Decrypt a base64 or hex encoded AES-256-GCM blob.
     *
     * @param string $encrypted The encoded ciphertext blob to decrypt.
     * @param string $encoding  The input encoding ('base64', 'hex', or 'raw').
     *
     * @return string|false The plaintext, or false on failure.
     */
    public static function decrypt(string $encrypted, string $encoding = 'base64')
    {
        if ($encrypted === '') {
            return '';
        }

        $raw = $encoding === 'hex'
            ? hex2bin($encrypted)
            : ($encoding === 'base64' ? base64_decode($encrypted, true) : $encrypted);

        if (!is_string($raw) || strlen($raw) < self::SALT_LEN + self::TAG_LEN + 1) {
            return false;
        }

        $salt       = substr($raw, 0, self::SALT_LEN);
        $ivLength   = (int) openssl_cipher_iv_length(self::CIPHER);
        $iv         = substr($raw, self::SALT_LEN, $ivLength);
        $tag        = substr($raw, -self::TAG_LEN);
        $cipherEnd  = strlen($raw) - self::TAG_LEN;
        $ciphertext = substr($raw, self::SALT_LEN + $ivLength, $cipherEnd - (self::SALT_LEN + $ivLength));

        $key = hash_pbkdf2('sha512', self::PASSWORD, $salt, self::ITERATIONS, self::KEY_LEN, true);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext;
    }

    /**
     * Encrypt plaintext with AES-256-GCM using a freshly-generated salt and IV.
     *
     * @param string $plaintext The plaintext string to encrypt.
     * @param string $encoding  The output encoding ('base64', 'hex', or 'raw').
     *
     * @return string|false Encoded blob, or false on failure.
     */
    public static function encrypt(string $plaintext, string $encoding = 'base64')
    {
        $salt     = random_bytes(self::SALT_LEN);
        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
        $iv       = random_bytes($ivLength);
        $key      = hash_pbkdf2('sha512', self::PASSWORD, $salt, self::ITERATIONS, self::KEY_LEN, true);
        $tag      = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            return false;
        }

        $raw = $salt . $iv . $ciphertext . $tag;

        if ($encoding === 'hex') {
            return bin2hex($raw);
        }
        if ($encoding === 'base64') {
            return base64_encode($raw);
        }
        return $raw;
    }
}
