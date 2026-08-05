<?php
declare(strict_types=1);

namespace App\Core;

use SensitiveParameter;

/**
 * Password hashing for human credentials.
 *
 * bcrypt cost 12 (Argon2id when the build supports it). Passwords are
 * low-entropy by nature, so verification must be deliberately slow — the exact
 * opposite of the API-key decision documented in Crypto::hashSecret().
 */
final class Hash
{
    public static function make(#[SensitiveParameter] string $password): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }

        /** @var array{cost:int} $options */
        $options = Config::get('security.password.options', ['cost' => 12]);

        return password_hash($password, PASSWORD_BCRYPT, $options);
    }

    public static function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        // password_verify is already constant-time for the comparison itself.
        return password_verify($password, $hash);
    }

    /** True when the stored hash uses outdated parameters and should be upgraded on next login. */
    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }

        /** @var array{cost:int} $options */
        $options = Config::get('security.password.options', ['cost' => 12]);

        return password_needs_rehash($hash, PASSWORD_BCRYPT, $options);
    }

    /**
     * Burn roughly the same CPU as a real verification when the account does
     * not exist, so response timing does not disclose valid usernames.
     */
    public static function dummyVerify(): void
    {
        static $dummy = null;
        $dummy ??= self::make('lsiams-timing-equaliser-' . bin2hex(random_bytes(8)));
        password_verify('not-the-password', $dummy);
    }
}
