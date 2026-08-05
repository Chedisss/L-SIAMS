<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\SessionService;

/**
 * Keeps signed-in users off the login page by bouncing them to their own
 * dashboard rather than showing a second sign-in form.
 */
final class GuestMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $token = SessionService::tokenFromRequest($request);

        if ($token !== null) {
            $session = SessionService::findByToken($token);

            if ($session !== null
                && $session['terminated_at'] === null
                && !SessionService::evaluate($session)['expired']
            ) {
                $context = AuthService::loadUserContext((int) $session['user_id']);

                if ($context !== null) {
                    $role = strtolower((string) $context['user']['role_slug']);

                    return Response::redirect($role === 'teacher' ? '/teacher' : '/admin');
                }
            }
        }

        return $next($request);
    }
}
