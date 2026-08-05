<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\SecurityLogService;

/**
 * Fixed-window rate limiting backed by the `rate_limits` table.
 *
 * Usage: `rate-limit:device`, `rate-limit:login`, `rate-limit:api_user`.
 *
 * Exceeding a limit produces a 429 with a machine-readable code and a security
 * log entry — never a silent drop (Part 17.8), because a device that is being
 * throttled needs to know to back off rather than retry into the wall.
 */
final class RateLimitMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $bucket = $this->parameters[0] ?? 'web';

        /** @var array{limit:int,window:int,burst?:int}|null $config */
        $config = Config::get('security.rate_limit.' . $bucket);

        if ($config === null) {
            return $next($request);
        }

        $key = $this->bucketKey($bucket, $request);
        $now = Clock::now();
        $db  = Database::instance();

        $state = $db->selectOne(
            'SELECT hits, window_start, blocked_until FROM rate_limits WHERE bucket_key = :key',
            ['key' => $key]
        );

        if ($state !== null
            && $state['blocked_until'] !== null
            && Clock::parse((string) $state['blocked_until']) > $now
        ) {
            $retryAfter = Clock::parse((string) $state['blocked_until'])->getTimestamp() - $now->getTimestamp();

            throw new HttpException(429, 'RATE_LIMIT_EXCEEDED', sprintf(
                'Too many requests. Try again in %d second(s).',
                max(1, $retryAfter)
            ), ['retry_after' => max(1, $retryAfter)]);
        }

        $windowStart = $state === null ? null : Clock::parse((string) $state['window_start']);
        $expired     = $windowStart === null
            || $now->getTimestamp() - $windowStart->getTimestamp() >= (int) $config['window'];

        if ($expired) {
            $db->execute(
                'INSERT INTO rate_limits (bucket_key, hits, window_start, blocked_until)
                      VALUES (:key, 1, :now, NULL)
                 ON DUPLICATE KEY UPDATE hits = 1, window_start = VALUES(window_start), blocked_until = NULL',
                ['key' => $key, 'now' => $now->format('Y-m-d H:i:s')]
            );

            return $next($request);
        }

        $hits = (int) $state['hits'] + 1;

        if ($hits > (int) $config['limit']) {
            $blockedUntil = $now->modify('+' . (int) $config['window'] . ' seconds');

            $db->update('rate_limits', [
                'hits'          => $hits,
                'blocked_until' => $blockedUntil->format('Y-m-d H:i:s'),
            ], ['bucket_key' => $key]);

            SecurityLogService::log(
                SecurityLogService::RATE_LIMIT_EXCEEDED,
                'medium',
                sprintf('Rate limit "%s" exceeded (%d requests) for %s.', $bucket, $hits, $this->subject($bucket, $request)),
                ['bucket' => $bucket, 'hits' => $hits, 'limit' => $config['limit']]
            );

            throw new HttpException(429, 'RATE_LIMIT_EXCEEDED', sprintf(
                'Too many requests. Try again in %d second(s).',
                (int) $config['window']
            ), ['retry_after' => (int) $config['window']]);
        }

        $db->update('rate_limits', ['hits' => $hits], ['bucket_key' => $key]);

        return $next($request);
    }

    private function bucketKey(string $bucket, Request $request): string
    {
        return $bucket . ':' . hash('sha256', $this->subject($bucket, $request));
    }

    private function subject(string $bucket, Request $request): string
    {
        return match ($bucket) {
            // Devices are limited per terminal, not per IP: several terminals
            // may legitimately share a NAT address on a school LAN.
            'device'   => (string) ($request->header((string) Config::get('security.request.headers.device_id', 'X-LSIAMS-Device-Id')) ?? $request->ip()),
            'login'    => $request->ip() . '|' . $request->string('username', ''),
            'api_user' => (string) (Auth::id() ?? $request->ip()),
            default    => $request->ip(),
        };
    }
}
