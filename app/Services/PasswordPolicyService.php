<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Hash;
use SensitiveParameter;

/**
 * Password strength policy (Part 19.2).
 *
 * Complexity rules alone are weak — "Password123!" satisfies every character
 * class — so this also rejects the common-password blocklist and anything
 * containing the user's own identifiers.
 */
final class PasswordPolicyService
{
    private const SYMBOLS = '!@#$%^&*()-_=+[]{};:,.?';

    /**
     * @param  list<string> $forbiddenSubstrings username, email, real name …
     * @throws ValidationException
     */
    public static function assertAcceptable(#[SensitiveParameter] string $password, array $forbiddenSubstrings = []): void
    {
        $errors = self::check($password, $forbiddenSubstrings);

        if ($errors !== []) {
            throw new ValidationException(['password' => $errors]);
        }
    }

    /**
     * @param  list<string> $forbiddenSubstrings
     * @return list<string>
     */
    public static function check(#[SensitiveParameter] string $password, array $forbiddenSubstrings = []): array
    {
        $errors    = [];
        $minLength = (int) Config::get('security.password.min_length', 12);

        if (mb_strlen($password) < $minLength) {
            $errors[] = sprintf('Password must be at least %d characters.', $minLength);
        }

        if (mb_strlen($password) > 200) {
            $errors[] = 'Password may not exceed 200 characters.';
        }

        if (Config::get('security.password.require_upper', true) && preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'Password must contain an uppercase letter.';
        }

        if (Config::get('security.password.require_lower', true) && preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'Password must contain a lowercase letter.';
        }

        if (Config::get('security.password.require_digit', true) && preg_match('/\d/', $password) !== 1) {
            $errors[] = 'Password must contain a number.';
        }

        if (Config::get('security.password.require_symbol', true)
            && preg_match('/[^A-Za-z0-9]/', $password) !== 1
        ) {
            $errors[] = 'Password must contain a symbol.';
        }

        $lower = mb_strtolower($password);

        foreach ($forbiddenSubstrings as $forbidden) {
            $forbidden = mb_strtolower(trim($forbidden));

            if ($forbidden !== '' && mb_strlen($forbidden) >= 4 && str_contains($lower, $forbidden)) {
                $errors[] = 'Password may not contain your name, username or email address.';
                break;
            }
        }

        if (self::isCommon($password)) {
            $errors[] = 'This password appears on the list of commonly used passwords. Choose something less predictable.';
        }

        if (preg_match('/^(.)\1+$/u', $password) === 1) {
            $errors[] = 'Password may not be a single repeated character.';
        }

        return $errors;
    }

    private static function isCommon(#[SensitiveParameter] string $password): bool
    {
        $file = (string) Config::get('security.password.blocklist_file', '');

        if ($file === '' || !is_readable($file)) {
            return false;
        }

        $needle = mb_strtolower(trim($password));
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (mb_strtolower(trim($line)) === $needle) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /** @throws ValidationException */
    public static function assertNotReused(int $userId, #[SensitiveParameter] string $password): void
    {
        $depth = (int) Config::get('security.password.history_depth', 5);

        if ($depth < 1) {
            return;
        }

        $rows = Database::instance()->select(
            'SELECT password_hash FROM password_history
              WHERE user_id = :user ORDER BY history_id DESC LIMIT ' . $depth,
            ['user' => $userId]
        );

        foreach ($rows as $row) {
            if (Hash::verify($password, (string) $row['password_hash'])) {
                throw new ValidationException([
                    'password' => [sprintf('You may not reuse any of your last %d passwords.', $depth)],
                ]);
            }
        }
    }

    /**
     * Generate a password that satisfies the policy by construction.
     *
     * One character is drawn from each required class first, then the rest is
     * filled from the full alphabet and shuffled with a CSPRNG — so the result
     * is both compliant and uniformly random, not "compliant because the first
     * four characters are predictable".
     */
    public static function generate(?int $length = null): string
    {
        $length = max((int) Config::get('security.password.min_length', 12), $length ?? 16);

        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // I and O omitted for legibility
        $lower  = 'abcdefghijkmnopqrstuvwxyz';  // l omitted
        $digits = '23456789';                   // 0 and 1 omitted
        $all    = $upper . $lower . $digits . self::SYMBOLS;

        $characters = [
            self::pick($upper),
            self::pick($lower),
            self::pick($digits),
            self::pick(self::SYMBOLS),
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = self::pick($all);
        }

        // Fisher–Yates driven by random_int, not shuffle().
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    /**
     * 0–100 strength score for the UI meter. Advisory only — assertAcceptable()
     * is the control.
     */
    public static function score(#[SensitiveParameter] string $password): int
    {
        $score  = 0;
        $length = mb_strlen($password);

        $score += min(40, $length * 3);
        $score += preg_match('/[A-Z]/', $password) === 1 ? 12 : 0;
        $score += preg_match('/[a-z]/', $password) === 1 ? 12 : 0;
        $score += preg_match('/\d/', $password) === 1 ? 12 : 0;
        $score += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 14 : 0;

        $uniqueRatio = $length > 0 ? count(array_unique(mb_str_split($password))) / $length : 0;
        $score      += (int) round($uniqueRatio * 10);

        if (preg_match('/(.)\1{2,}/u', $password) === 1) {
            $score -= 10;
        }

        return max(0, min(100, $score));
    }

    /** Random token for provisioning slips and claim tokens. */
    public static function randomToken(int $bytes = 12): string
    {
        return Crypto::randomBase62($bytes);
    }
}
