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

    /** Resolved once per request; the .env value does not change mid-request. */
    private static ?string $offset = null;

    public static function now(): DateTimeImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        $now = new DateTimeImmutable('now', self::timezone());

        $shift = self::offset();

        return $shift === '' ? $now : $now->modify($shift);
    }

    /**
     * A deliberate offset applied to every time the application reads.
     *
     * Testing a schedule means being inside its window, and a Monday 08:00
     * class is not a thing anyone can wait for on a Thursday afternoon.
     * Changing the machine's clock is the obvious alternative and does not
     * work: Windows re-synchronises it from an internet time server within
     * minutes, so the tests fail again halfway through for no visible reason.
     *
     * Applied here rather than to the operating system, so it moves the whole
     * application together — attendance windows, session expiry, audit
     * timestamps and the clock the terminals sync from — and moves nothing
     * else on the machine.
     *
     * Refused outright when APP_ENV is production. An attendance system
     * running on a shifted clock writes records at times that never happened,
     * and no amount of care during setup makes that safe to leave available.
     */
    public static function offset(): string
    {
        if (self::$offset !== null) {
            return self::$offset;
        }

        $configured = trim((string) Config::get('app.clock_offset', ''));

        if ($configured === '') {
            return self::$offset = '';
        }

        if ((string) Config::get('app.env', 'production') === 'production') {
            Logger::warning('APP_CLOCK_OFFSET is set but ignored: the environment is production.', [
                'offset' => $configured,
            ]);

            return self::$offset = '';
        }

        // Anything modify() rejects is a typo, and a typo that silently did
        // nothing would be worse than one that says so. Both spellings of the
        // failure are handled: modify() returned false before PHP 8.3 and
        // throws from 8.3 on.
        if (!self::isValidOffset($configured)) {
            Logger::warning('APP_CLOCK_OFFSET is not a relative time PHP understands; ignoring it.', [
                'offset' => $configured,
            ]);

            return self::$offset = '';
        }

        return self::$offset = $configured;
    }

    /**
     * Whether a string is an offset that actually moves the clock.
     *
     * Parsing is not enough. PHP accepts "+8h" and "+30m" without complaint and
     * then ignores them — the single-letter unit is not one it knows, so the
     * result comes back unchanged rather than failing. An offset like that
     * passed validation, was written to .env, and reported itself as active
     * while the application went on running at real time, which is a worse
     * outcome than refusing it: the shift appears to be in force and every
     * conclusion drawn from the test is wrong.
     *
     * So the test is not "did modify() survive" but "did the clock move".
     * "+8 hours" and "+2hours" both move it; "+8h" does not, and is refused.
     */
    public static function isValidOffset(string $spec): bool
    {
        if (trim($spec) === '') {
            return false;
        }

        try {
            $reference = new DateTimeImmutable('2026-01-01 00:00:00');
            $shifted   = $reference->modify($spec);

            return $shifted !== false && $shifted->getTimestamp() !== $reference->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }

    /** True when the application is deliberately not running at real time. */
    public static function isShifted(): bool
    {
        return self::offset() !== '';
    }

    /** Real time, whatever the offset says. For "is the shift still on?" checks. */
    public static function real(): DateTimeImmutable
    {
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
