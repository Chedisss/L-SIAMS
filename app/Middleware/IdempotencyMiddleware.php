<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\IdempotencyService;

/**
 * Part 17.3 — replays the stored response for a repeated request_id instead of
 * reprocessing it.
 *
 * Sitting in front of the controller means a retried tap cannot even reach the
 * attendance engine a second time, which is what makes network-timeout retries
 * from the offline queue safe by construction rather than by luck.
 */
final class IdempotencyMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $header    = (string) Config::get('security.request.headers.request_id', 'X-LSIAMS-Request-Id');
        $requestId = $request->header($header) ?? $request->string('request_id', '');

        if ($requestId === '' || !$this->isUuid($requestId)) {
            return $next($request);
        }

        $stored = IdempotencyService::replay($requestId);

        if ($stored !== null) {
            return Response::json($stored['body'], $stored['code'])
                ->withHeader('X-Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Only successful and business-rule outcomes are remembered. A 500 is a
        // transient fault the device *should* be able to retry into a different
        // result, so it is deliberately not cached.
        if ($response->status() < 500) {
            /** @var array<string,mixed> $body */
            $body = json_decode($response->content(), true) ?? [];

            IdempotencyService::remember(
                $requestId,
                Auth::deviceRowId(),
                $request->path(),
                $response->status(),
                $body
            );
        }

        return $response;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $value
        ) === 1;
    }
}
