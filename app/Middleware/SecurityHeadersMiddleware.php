<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\RequestContext;

/**
 * First middleware in every pipeline: publishes the request into
 * RequestContext (so the logging services have an IP to record) and enforces
 * HTTPS for anything that carries credentials or attendance data.
 *
 * The response headers themselves are applied centrally in Response::send();
 * this middleware owns the transport-level decision.
 */
final class SecurityHeadersMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        RequestContext::set($request);

        if ($this->shouldEnforceHttps($request)) {
            // Attendance APIs must never be reachable over plaintext (Part 6).
            if ($request->wantsJson()) {
                throw new HttpException(
                    426,
                    'HTTPS_REQUIRED',
                    'This endpoint requires HTTPS. Plaintext requests are refused.'
                );
            }

            return Response::redirect(
                'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $request->fullPathWithQuery(),
                301
            );
        }

        return $next($request);
    }

    private function shouldEnforceHttps(Request $request): bool
    {
        if (!Config::get('security.network.enforce_https', true)) {
            return false;
        }

        if ($request->isSecure()) {
            return false;
        }

        // Local development over the PHP built-in server would otherwise be
        // impossible; production runs with APP_ENV=production and gets the
        // redirect.
        if (Config::get('app.env') !== 'production') {
            return false;
        }

        return true;
    }
}
