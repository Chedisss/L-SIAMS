<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\CsrfService;
use App\Services\SecurityLogService;

/**
 * CSRF protection for state-changing web requests.
 *
 * Device API routes do not use this: they authenticate with an HMAC-signed
 * request rather than a cookie, so there is no ambient authority for a forged
 * cross-site request to ride on.
 */
final class CsrfMiddleware extends Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->string('_csrf', '');

        if ($token === '') {
            $token = (string) ($request->header('x-csrf-token') ?? '');
        }

        if (!CsrfService::verify($token)) {
            SecurityLogService::log(
                SecurityLogService::CSRF_FAILURE,
                'high',
                sprintf('CSRF token missing or invalid for %s %s.', $request->method(), $request->path()),
                ['has_token' => $token !== '', 'referer' => $request->header('referer')]
            );

            throw new HttpException(
                419,
                'CSRF_TOKEN_INVALID',
                'Your session security token expired. Refresh the page and try again.'
            );
        }

        return $next($request);
    }
}
