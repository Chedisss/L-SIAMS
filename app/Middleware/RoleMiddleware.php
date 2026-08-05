<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\SecurityLogService;

/**
 * Role gate. Usage: `role:administrator` or `role:administrator,teacher`.
 *
 * Every protected route declares its roles explicitly. A route that reaches a
 * controller without passing through here is public by declaration, not by an
 * omission a reviewer has to notice.
 */
final class RoleMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            throw new AuthorizationException('Sign in to continue.');
        }

        $allowed = array_map('strtolower', $this->parameters);
        $role    = Auth::role() ?? '';

        if ($allowed !== [] && !in_array($role, $allowed, true)) {
            SecurityLogService::log(
                SecurityLogService::PERMISSION_VIOLATION,
                'medium',
                sprintf(
                    'User "%s" (%s) attempted %s %s, which requires: %s.',
                    Auth::username() ?? '?',
                    $role,
                    $request->method(),
                    $request->path(),
                    implode(', ', $allowed)
                ),
                ['user_id' => Auth::id(), 'required_roles' => $allowed]
            );

            throw new AuthorizationException(
                'You do not have permission to access this area.'
            );
        }

        return $next($request);
    }
}
