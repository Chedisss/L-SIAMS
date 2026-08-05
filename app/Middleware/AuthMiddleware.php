<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\RequestContext;
use App\Services\SecurityLogService;
use App\Services\SessionService;

/**
 * Resolves the session cookie to a verified identity.
 *
 * Also performs the session-binding checks from Part 18.8: a token presented
 * with a different user agent or from a different network prefix than the one
 * it was issued to is treated as a hijack, terminated, and logged at High.
 */
final class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $token = SessionService::tokenFromRequest($request);

        if ($token === null) {
            throw new AuthenticationException('Please sign in to continue.');
        }

        $session = SessionService::findByToken($token);

        if ($session === null) {
            throw new AuthenticationException('Your session is no longer valid. Please sign in again.', 'SESSION_INVALID');
        }

        if ($session['terminated_at'] !== null) {
            throw new AuthenticationException(
                'Your session has ended. Please sign in again.',
                'SESSION_TERMINATED'
            );
        }

        $this->assertBindingIntact($request, $session);

        $context = AuthService::loadUserContext((int) $session['user_id']);

        if ($context === null) {
            // The account was disabled or archived while the session was live.
            SessionService::terminate((int) $session['session_id'], SessionService::REASON_ADMIN);

            throw new AuthenticationException('This account is no longer active.', 'ACCOUNT_INACTIVE');
        }

        Auth::login($context['user'], $session, $context['permissions']);

        return $next($request);
    }

    /**
     * @param array<string,mixed> $session
     */
    private function assertBindingIntact(Request $request, array $session): void
    {
        $bindAgent = (bool) Config::get('security.session.bind_user_agent', true);
        $bindIp    = (bool) Config::get('security.session.bind_ip_prefix', true);

        $reasons = [];

        if ($bindAgent && $session['user_agent_hash'] !== null) {
            if (!hash_equals((string) $session['user_agent_hash'], hash('sha256', $request->userAgent()))) {
                $reasons[] = 'user agent changed';
            }
        }

        // A /24 (or /64) prefix tolerates ordinary NAT and DHCP churn while
        // still catching a token replayed from a completely different network.
        if ($bindIp && $session['ip_prefix'] !== null) {
            if ((string) $session['ip_prefix'] !== RequestContext::ipPrefix($request->ip())) {
                $reasons[] = 'network address changed';
            }
        }

        if ($reasons === []) {
            return;
        }

        SecurityLogService::log(
            SecurityLogService::SESSION_HIJACK_SUSPECTED,
            'high',
            sprintf(
                'Session #%d presented from a different context (%s). The session was terminated.',
                (int) $session['session_id'],
                implode(', ', $reasons)
            ),
            [
                'session_id'      => (int) $session['session_id'],
                'user_id'         => (int) $session['user_id'],
                'expected_prefix' => $session['ip_prefix'],
                'actual_prefix'   => RequestContext::ipPrefix($request->ip()),
            ]
        );

        SessionService::terminate((int) $session['session_id'], SessionService::REASON_HIJACK);

        throw new AuthenticationException(
            'Your session was ended for security reasons. Please sign in again.',
            'SESSION_TERMINATED'
        );
    }
}
