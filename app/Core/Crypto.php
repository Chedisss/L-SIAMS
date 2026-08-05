<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use SensitiveParameter;

/**
 * Cryptographic primitives.
 *
 * Every random value in L-SIAMS originates here, and this class only ever calls
 * random_bytes(). rand(), mt_rand() and uniqid() are not used anywhere in the
 * key path — see tests/unit/CryptoTest.php and the repository grep check in
 * bin/console security:audit-keys.
 */
final class Crypto
{
    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const AEAD_CIPHER = 'aes-256-gcm';

    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('randomBytes() length must be positive.');
        }

        return random_bytes($length);
    }

    /** Cryptographically-strong base62 string derived from $bytes of entropy. */
    public static function randomBase62(int $bytes): string
    {
        // ext-gmp is optional and is not in this project's required extension
        // list, so availability must be tested before the call, not after it.
        // Checking gmp_import()'s return value cannot work: on a host without
        // the extension the call itself is a fatal "undefined function", and
        // the fallback below is never reached. That made key generation fail
        // outright on exactly the stock installs the fallback exists to serve.
        if (!function_exists('gmp_import')) {
            return self::base62FromRandom($bytes);
        }

        $raw    = self::randomBytes($bytes);
        $number = gmp_import($raw);
        $out    = '';

        if ($number === false) {
            return self::base62FromRandom($bytes);
        }

        $base = gmp_init(62);
        while (gmp_cmp($number, 0) > 0) {
            $remainder = gmp_intval(gmp_mod($number, $base));
            $out       = self::BASE62[$remainder] . $out;
            $number    = gmp_div_q($number, $base);
        }

        // Pad to a deterministic length so key material is uniform in shape.
        $target = (int) ceil($bytes * 8 / log(62, 2));

        return str_pad($out, $target, '0', STR_PAD_LEFT);
    }

    /** Unbiased base62 via rejection sampling; used when ext-gmp is absent. */
    private static function base62FromRandom(int $bytes): string
    {
        $target = (int) ceil($bytes * 8 / log(62, 2));
        $out    = '';

        while (strlen($out) < $target) {
            foreach (str_split(self::randomBytes(64)) as $byte) {
                $value = ord($byte);
                // 248 = 4 * 62; values at or above it would bias the modulus.
                if ($value >= 248) {
                    continue;
                }
                $out .= self::BASE62[$value % 62];
                if (strlen($out) >= $target) {
                    break;
                }
            }
        }

        return $out;
    }

    public static function randomHex(int $bytes): string
    {
        return bin2hex(self::randomBytes($bytes));
    }

    /** RFC 4122 version 4 UUID from CSPRNG bytes. */
    public static function uuid4(): string
    {
        $data    = self::randomBytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Fast keyed hash for high-entropy secrets (API keys).
     *
     * Deliberately NOT bcrypt: devices authenticate on every tap and bcrypt at
     * cost 12 would add ~250 ms per request, which collapses under the
     * 100 taps/minute target in Part 17.1. The input is a 256-bit CSPRNG value
     * with no dictionary structure, so brute force is infeasible regardless of
     * hash speed, and the server-side pepper means a leaked database alone is
     * not enough to forge a key. Human passwords stay on bcrypt (see Hash).
     */
    public static function hashSecret(#[SensitiveParameter] string $secret, string $pepper): string
    {
        return hash('sha256', $secret . $pepper);
    }

    public static function verifySecret(#[SensitiveParameter] string $secret, string $expectedHash, string $pepper): bool
    {
        return hash_equals($expectedHash, self::hashSecret($secret, $pepper));
    }

    public static function hmac(string $message, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $message, $key);
    }

    public static function verifyHmac(string $message, string $presented, #[SensitiveParameter] string $key): bool
    {
        return hash_equals(self::hmac($message, $key), $presented);
    }

    /**
     * AES-256-GCM encryption for secrets the server must be able to read back
     * (HMAC secrets used to verify device signatures). Output is
     * base64(nonce || tag || ciphertext).
     */
    public static function encrypt(#[SensitiveParameter] string $plaintext, ?string $key = null): string
    {
        $key   = $key ?? self::appKey();
        $nonce = self::randomBytes(12);
        $tag   = '';

        $ciphertext = openssl_encrypt($plaintext, self::AEAD_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $payload, ?string $key = null): string
    {
        $key = $key ?? self::appKey();
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Malformed ciphertext.');
        }

        $nonce      = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, self::AEAD_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            // Authentication failure means tampering or a wrong key. Both are
            // security events, not application errors.
            throw new RuntimeException('Decryption failed: authentication tag mismatch.');
        }

        return $plaintext;
    }

    public static function appKey(): string
    {
        $encoded = (string) Env::get('APP_KEY', '');

        if ($encoded === '') {
            throw new RuntimeException('APP_KEY is not configured. Run: php bin/console key:generate');
        }

        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be 32 base64-encoded random bytes.');
        }

        return $key;
    }

    public static function apiKeyPepper(): string
    {
        $encoded = (string) Env::get('API_KEY_PEPPER', '');

        if ($encoded === '') {
            throw new RuntimeException('API_KEY_PEPPER is not configured. Run: php bin/console key:generate');
        }

        $pepper = base64_decode($encoded, true);

        if ($pepper === false || strlen($pepper) < 32) {
            throw new RuntimeException('API_KEY_PEPPER must be at least 32 base64-encoded random bytes.');
        }

        return $pepper;
    }

    public static function realtimeTicketSecret(): string
    {
        $encoded = (string) Env::get('REALTIME_TICKET_SECRET', '');

        if ($encoded === '') {
            throw new RuntimeException('REALTIME_TICKET_SECRET is not configured.');
        }

        $secret = base64_decode($encoded, true);

        if ($secret === false || strlen($secret) < 32) {
            throw new RuntimeException('REALTIME_TICKET_SECRET must be at least 32 base64-encoded random bytes.');
        }

        return $secret;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');
        $raw    = base64_decode($padded, true);

        return $raw === false ? '' : $raw;
    }
}
