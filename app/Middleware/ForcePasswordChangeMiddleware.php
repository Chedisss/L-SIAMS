<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Part 19.2 — a first-login password change is enforced by redirecting every
 * route to the change-password page until it is completed.
 *
 * Only the change-password endpoints and sign-out are reachable in that state,
 * so an administrator-issued temporary password cannot survive as a working
 * credential.
 */
final class ForcePasswordChangeMiddleware extends Middleware
{
    private const ALLOWED_PATHS = [
        '/password/change',
        '/logout',
        '/api/auth/keepalive',
        '/api/auth/session-status',
        '/api/auth/logout',
    ];

    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();

        if ($user === null || (int) ($user['must_change_password'] ?? 0) !== 1) {
            return $next($request);
        }

        foreach (self::ALLOWED_PATHS as $path) {
            if (str_starts_with($request->path(), $path)) {
                return $next($request);
            }
        }

        if ($request->wantsJson()) {
            return Response::fail(
                'PASSWORD_CHANGE_REQUIRED',
                'You must change your password before continuing.',
                403,
                ['redirect' => '/password/change']
            );
        }

        return Response::redirect('/password/change');
    }
}
