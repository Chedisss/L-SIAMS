<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Flash;

/**
 * CSRF tokens.
 *
 * One token per browser session, compared with hash_equals. Combined with
 * SameSite=Strict cookies this is belt and braces, which is the correct posture
 * for a system where a forged request can alter attendance records.
 */
final class CsrfService
{
    private const SESSION_KEY = '_lsiams_csrf';

    public static function token(): string
    {
        Flash::start();

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = Crypto::randomHex(32);
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }

        Flash::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /** Called on login and privilege change so a pre-auth token cannot be reused. */
    public static function rotate(): string
    {
        Flash::start();
        $_SESSION[self::SESSION_KEY] = Crypto::randomHex(32);

        return $_SESSION[self::SESSION_KEY];
    }

    public static function clear(): void
    {
        Flash::start();
        unset($_SESSION[self::SESSION_KEY]);
    }
}
