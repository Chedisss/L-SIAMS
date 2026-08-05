<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuration registry with dot-notation access.
 *
 * File-based defaults are loaded once at boot. Runtime overrides coming from
 * the `settings` table are layered on top by SettingsService::hydrate(), so a
 * single lookup path serves both: nothing in the codebase reads config files
 * directly and nothing reads the settings table directly.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    /** @var array<string,mixed> */
    private static array $overrides = [];

    public static function loadDirectory(string $directory): void
    {
        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $data = require $file;

            if (is_array($data)) {
                self::$items[$name] = $data;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $segments = explode('.', $key);
        $cursor   = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__lsiams_missing__') !== '__lsiams_missing__';
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }

    public static function reset(): void
    {
        self::$overrides = [];
    }
}
