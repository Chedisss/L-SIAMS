<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

/**
 * Ambient request facts (IP, user agent, device label) for services that log.
 *
 * Passing a Request object down through every service just so AuditService can
 * read an IP would clutter dozens of signatures. This is set once by the
 * middleware pipeline and read by the logging services; it holds no
 * authorisation state, so it cannot be abused to bypass a check.
 */
final class RequestContext
{
    private static ?Request $request = null;

    public static function set(Request $request): void
    {
        self::$request = $request;
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function ip(): string
    {
        return self::$request?->ip() ?? (php_sapi_name() === 'cli' ? 'cli' : '0.0.0.0');
    }

    public static function userAgent(): ?string
    {
        $agent = self::$request?->userAgent();

        return $agent === null || $agent === '' ? null : $agent;
    }

    public static function path(): ?string
    {
        return self::$request?->path();
    }

    public static function method(): ?string
    {
        return self::$request?->method();
    }

    /** Coarse "Chrome on Windows" style label for login history and session lists. */
    public static function deviceLabel(): ?string
    {
        $agent = self::userAgent();

        if ($agent === null) {
            return php_sapi_name() === 'cli' ? 'CLI' : null;
        }

        return self::browser($agent) . ' on ' . self::platform($agent);
    }

    public static function browser(?string $agent = null): string
    {
        $agent = $agent ?? self::userAgent() ?? '';

        return match (true) {
            str_contains($agent, 'Edg/')                                  => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/')                              => 'Firefox',
            str_contains($agent, 'Chrome/')                               => 'Chrome',
            str_contains($agent, 'Safari/')                               => 'Safari',
            str_contains($agent, 'ESP32') || str_contains($agent, 'L-SIAMS-Terminal') => 'ESP32 Terminal',
            str_contains($agent, 'curl')                                  => 'curl',
            default                                                        => 'Unknown browser',
        };
    }

    public static function platform(?string $agent = null): string
    {
        $agent = $agent ?? self::userAgent() ?? '';

        return match (true) {
            str_contains($agent, 'Windows NT') => 'Windows',
            str_contains($agent, 'Android')    => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X')   => 'macOS',
            str_contains($agent, 'Linux')      => 'Linux',
            str_contains($agent, 'ESP32')      => 'ESP32',
            default                             => 'Unknown platform',
        };
    }

    /**
     * IP prefix used for session binding (Part 18.8). /24 for IPv4 and /64 for
     * IPv6 tolerate normal NAT and mobile-network churn while still catching a
     * token replayed from an entirely different network.
     */
    public static function ipPrefix(?string $ip = null): string
    {
        $ip = $ip ?? self::ip();

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return implode('.', array_slice($parts, 0, 3)) . '.0/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $binary = inet_pton($ip);

            if ($binary !== false) {
                return inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
            }
        }

        return $ip;
    }

    public static function reset(): void
    {
        self::$request = null;
    }
}
