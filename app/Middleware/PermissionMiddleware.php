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
 * Fine-grained permission gate, e.g. `permission:students.create`.
 *
 * Administrators hold every permission implicitly (see Auth::can), so this is
 * really about the future roles the schema already supports — Registrar,
 * Principal, IT Administrator — without needing authorisation call sites to be
 * rewritten when they arrive.
 */
final class PermissionMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        foreach ($this->parameters as $permission) {
            if (Auth::can($permission)) {
                continue;
            }

            SecurityLogService::log(
                SecurityLogService::PERMISSION_VIOLATION,
                'medium',
                sprintf(
                    'User "%s" lacks permission "%s" for %s %s.',
                    Auth::username() ?? '?',
                    $permission,
                    $request->method(),
                    $request->path()
                ),
                ['user_id' => Auth::id(), 'permission' => $permission]
            );

            throw new AuthorizationException('You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
