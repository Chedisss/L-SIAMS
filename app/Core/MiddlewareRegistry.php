<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Maps the short middleware names used in routes/*.php to their classes.
 *
 * `role:administrator` style parameters are parsed here so route files stay
 * readable.
 */
final class MiddlewareRegistry
{
    /** @var array<string,class-string<Middleware>> */
    private static array $map = [
        'security-headers'  => \App\Middleware\SecurityHeadersMiddleware::class,
        'network-allowlist' => \App\Middleware\NetworkAllowlistMiddleware::class,
        'rate-limit'        => \App\Middleware\RateLimitMiddleware::class,
        'auth'              => \App\Middleware\AuthMiddleware::class,
        'guest'             => \App\Middleware\GuestMiddleware::class,
        'session-timeout'   => \App\Middleware\SessionTimeoutMiddleware::class,
        'role'              => \App\Middleware\RoleMiddleware::class,
        'permission'        => \App\Middleware\PermissionMiddleware::class,
        'csrf'              => \App\Middleware\CsrfMiddleware::class,
        'device-auth'       => \App\Middleware\DeviceAuthMiddleware::class,
        'force-password'    => \App\Middleware\ForcePasswordChangeMiddleware::class,
        'idempotency'       => \App\Middleware\IdempotencyMiddleware::class,
        'audit-context'     => \App\Middleware\AuditContextMiddleware::class,
    ];

    /** @var array<string,Middleware> */
    private static array $instances = [];

    public static function resolve(string $name): Middleware
    {
        $parameters = [];

        if (str_contains($name, ':')) {
            [$name, $rawParams] = explode(':', $name, 2);
            $parameters         = explode(',', $rawParams);
        }

        if (!isset(self::$map[$name])) {
            throw new HttpException(500, 'MIDDLEWARE_MISSING', 'Unknown middleware: ' . $name);
        }

        $class    = self::$map[$name];
        $cacheKey = $class . '|' . implode(',', $parameters);

        if (!isset(self::$instances[$cacheKey])) {
            self::$instances[$cacheKey] = new $class($parameters);
        }

        return self::$instances[$cacheKey];
    }

    /** @param class-string<Middleware> $class */
    public static function register(string $name, string $class): void
    {
        self::$map[$name] = $class;
    }
}
