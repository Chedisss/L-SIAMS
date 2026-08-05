<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Single source of "now".
 *
 * Attendance timestamps must be server time (Part 4, "Attendance timestamps
 * should use server time whenever possible"), and tests need to freeze the
 * clock to assert window boundaries. Both requirements are met by routing every
 * time read through here rather than calling time() or date() ad hoc.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        return new DateTimeImmutable('now', self::timezone());
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) Config::get('app.timezone', 'UTC'));
    }

    public static function nowString(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    public static function atom(): string
    {
        return self::now()->format(DATE_ATOM);
    }

    public static function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::timezone());
    }

    /** Combines a date with a HH:MM[:SS] time in the application timezone. */
    public static function combine(string $date, string $time): DateTimeImmutable
    {
        $time = strlen($time) === 5 ? $time . ':00' : $time;

        return new DateTimeImmutable($date . ' ' . $time, self::timezone());
    }

    /** Test seam only — production code never calls this. */
    public static function freeze(string|DateTimeImmutable $moment): void
    {
        self::$frozen = $moment instanceof DateTimeImmutable
            ? $moment
            : new DateTimeImmutable($moment, self::timezone());
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }

    public static function isFrozen(): bool
    {
        return self::$frozen !== null;
    }
}
