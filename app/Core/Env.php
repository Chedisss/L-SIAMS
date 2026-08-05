<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env reader.
 *
 * Values live in process memory only — they are never echoed, never written to
 * a log, and never exposed through a route. `.env` itself sits outside the web
 * root (project root, while `public/` is the document root).
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = true;

        if (!is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip matching surrounding quotes, preserving inner content.
            $len = strlen($value);
            if ($len >= 2
                && (($value[0] === '"' && $value[$len - 1] === '"')
                    || ($value[0] === "'" && $value[$len - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        $fromServer = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return ($fromServer === false || $fromServer === null || $fromServer === '')
            ? $default
            : (string) $fromServer;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $parts = array_map('trim', explode(',', $value));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
