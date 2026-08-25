<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Runtime settings.
 *
 * Rows in the `settings` table are layered over the file-based config at the
 * start of every request, so an administrator changing the late threshold or
 * the idle timeout takes effect immediately without a deploy — and every
 * consumer still reads it through Config::get(), never from here directly.
 */
final class SettingsService
{
    private static bool $hydrated = false;
    /** @var array<string,mixed> */
    private static array $cache = [];

    /** Maps setting keys onto the config paths they override. */
    private const CONFIG_BINDINGS = [
        'attendance.time_in_window_open'    => 'attendance.windows.time_in_window_open',
        'attendance.late_threshold_minutes' => 'attendance.windows.late_threshold_minutes',
        'attendance.time_in_window_close'   => 'attendance.windows.time_in_window_close',
        'attendance.time_out_window_open'   => 'attendance.windows.time_out_window_open',
        'attendance.time_out_window_close'  => 'attendance.windows.time_out_window_close',
        'attendance.minimum_dwell_minutes'  => 'attendance.windows.minimum_dwell_minutes',
        'attendance.auto_timeout_on_close'  => 'attendance.auto_timeout_on_close',
        'attendance.generate_absent_on_close' => 'attendance.generate_absent_on_close',
        'attendance.carry_over_enabled'     => 'attendance.carry_over.enabled',
        'attendance.carry_over_max_gap_minutes' => 'attendance.carry_over.max_gap_minutes',
        'attendance.section_mismatch_alert_threshold' => 'attendance.section_mismatch_alert_threshold',
        'device.heartbeat_interval_sec'     => 'attendance.device.heartbeat_interval_sec',
        'device.offline_after_sec'          => 'attendance.device.offline_after_sec',
        'device.offline_queue_limit'        => 'attendance.device.offline_queue_limit',
        'device.queue_warning_depth'        => 'attendance.device.queue_warning_depth',
        'security.session_idle_timeout_minutes'   => 'security.session.idle_timeout_minutes',
        'security.session_absolute_timeout_hours' => 'security.session.absolute_timeout_hours',
        'security.max_concurrent_sessions_per_user' => 'security.session.max_concurrent_per_user',
        'security.login_max_attempts'       => 'security.login.max_attempts',
        'security.login_lockout_minutes'    => 'security.login.lockout_minutes',
        'security.api_key_rotation_grace_hours' => 'security.api_key.rotation_grace_hours',
        'security.password_min_length'      => 'security.password.min_length',
        'fingerprint.max_failures_before_lock' => 'attendance.fingerprint.max_failures_before_lock',
        'fingerprint.device_lock_minutes'   => 'attendance.fingerprint.device_lock_minutes',
    ];

    public static function hydrate(bool $force = false): void
    {
        if (self::$hydrated && !$force) {
            return;
        }

        self::$hydrated = true;

        try {
            $rows = Database::instance()->select('SELECT setting_key, setting_value, value_type FROM settings');
        } catch (Throwable $e) {
            // A missing settings table means the installer has not run yet.
            // File defaults are perfectly serviceable until it does.
            Logger::debug('Settings table unavailable; using file defaults.', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($rows as $row) {
            $key   = (string) $row['setting_key'];
            $value = self::castValue($row['setting_value'], (string) $row['value_type']);

            self::$cache[$key] = $value;

            if (isset(self::CONFIG_BINDINGS[$key])) {
                Config::set(self::CONFIG_BINDINGS[$key], $value);
            }
        }
    }

    private static function castValue(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'bool'  => in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true),
            'json'  => json_decode((string) $raw, true) ?? [],
            default => (string) $raw,
        };
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::hydrate();

        return self::$cache[$key] ?? $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param  array<string,mixed> $values
     * @return array{changed:array<string,array{old:mixed,new:mixed}>}
     */
    public static function updateMany(array $values, int $userId): array
    {
        $db      = Database::instance();
        $changed = [];

        $db->transaction(static function (Database $db) use ($values, $userId, &$changed): void {
            foreach ($values as $key => $newValue) {
                $existing = $db->selectOne(
                    'SELECT setting_key, setting_value, value_type, min_value, max_value
                       FROM settings WHERE setting_key = :key FOR UPDATE',
                    ['key' => $key]
                );

                if ($existing === null) {
                    continue;
                }

                $type      = (string) $existing['value_type'];
                $stringVal = self::stringify($newValue, $type);

                self::assertWithinBounds($key, $stringVal, $type, $existing);

                if ((string) $existing['setting_value'] === $stringVal) {
                    continue;
                }

                $changed[$key] = [
                    'old' => self::castValue($existing['setting_value'], $type),
                    'new' => self::castValue($stringVal, $type),
                ];

                $db->update(
                    'settings',
                    ['setting_value' => $stringVal, 'updated_by' => $userId],
                    ['setting_key' => $key]
                );
            }
        });

        if ($changed !== []) {
            self::$hydrated = false;
            self::$cache    = [];
            self::hydrate(true);
        }

        return ['changed' => $changed];
    }

    private static function stringify(mixed $value, string $type): string
    {
        return match ($type) {
            'bool'  => (is_bool($value) ? $value : in_array((string) $value, ['1', 'true', 'yes', 'on'], true)) ? '1' : '0',
            'json'  => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            'int'   => (string) (int) $value,
            'float' => (string) (float) $value,
            default => (string) $value,
        };
    }

    /** @param array<string,mixed> $definition */
    private static function assertWithinBounds(string $key, string $value, string $type, array $definition): void
    {
        if (!in_array($type, ['int', 'float'], true)) {
            return;
        }

        $min = $definition['min_value'];
        $max = $definition['max_value'];

        if ($min !== null && (float) $value < (float) $min) {
            throw new \App\Core\Exceptions\ValidationException(
                [$key => [sprintf('%s must be at least %s.', $key, $min)]]
            );
        }

        if ($max !== null && (float) $value > (float) $max) {
            throw new \App\Core\Exceptions\ValidationException(
                [$key => [sprintf('%s may not exceed %s.', $key, $max)]]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public static function grouped(): array
    {
        return Database::instance()->select(
            'SELECT * FROM settings ORDER BY group_name, label'
        );
    }

    public static function isSensitive(string $key): bool
    {
        $row = Database::instance()->selectOne(
            'SELECT is_sensitive FROM settings WHERE setting_key = :key',
            ['key' => $key]
        );

        return $row !== null && (int) $row['is_sensitive'] === 1;
    }

    public static function schoolName(): string
    {
        return self::string('school.name', 'L-SIAMS Academy');
    }
}
