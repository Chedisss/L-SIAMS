<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\RequestContext;

/**
 * Request logging.
 *
 * Records every device-API call (Part 4, "Every request is logged") and any
 * request that ends in an error, with timing. Successful web page views are not
 * logged here — they would drown the signal, and the audit trail already covers
 * every action that changes state.
 */
final class AuditContextMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        RequestContext::set($request);
        Logger::setChannel(str_starts_with($request->path(), '/api/') ? 'api' : 'web');

        $started = microtime(true);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Logger::warning('Request failed', [
                'method'      => $request->method(),
                'path'        => $request->path(),
                'ip'          => $request->ip(),
                'duration_ms' => round((microtime(true) - $started) * 1000, 1),
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
            ]);

            throw $e;
        }

        $durationMs = round((microtime(true) - $started) * 1000, 1);
        $isDeviceApi = str_starts_with($request->path(), '/api/device')
            || str_starts_with($request->path(), '/api/attendance')
            || str_starts_with($request->path(), '/api/rfid')
            || str_starts_with($request->path(), '/api/fingerprint');

        if ($isDeviceApi || $response->status() >= 400) {
            Logger::info('Request', [
                'method'      => $request->method(),
                'path'        => $request->path(),
                'status'      => $response->status(),
                'ip'          => $request->ip(),
                'device'      => $request->header((string) Config::get('security.request.headers.device_id', 'X-LSIAMS-Device-Id')),
                'duration_ms' => $durationMs,
            ]);
        }

        // Surfaced in the dashboard's "System Response Time" widget and useful
        // when validating the p95 targets in Part 17.1.
        return $response->withHeader('X-Processing-Time', $durationMs . 'ms');
    }
}
