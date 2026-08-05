<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\SessionService;

/**
 * Part 18.2 — the authoritative idle-timeout check.
 *
 * Runs on every authenticated request, before any controller. The order below
 * is exactly the specified sequence:
 *
 *   1. terminated?        → 401 SESSION_TERMINATED
 *   2. past absolute?     → terminate, 401
 *   3. past idle?         → terminate, 401
 *   4. counts as activity → extend the deadline
 *
 * Step 4 is where the security property actually lives. A teacher who opens the
 * live attendance dashboard and walks away is not present, so passive refreshes
 * — the live feed, chart polling, notification badges, WebSocket traffic — must
 * not extend the session. Those requests carry `X-Passive-Request: true` and
 * are honoured here. If they reset the timer instead, the session would never
 * expire and the entire control would be defeated.
 */
final class SessionTimeoutMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $session = Auth::session();

        if ($session === null) {
            return $next($request);
        }

        $verdict = SessionService::evaluate($session);

        if ($verdict['expired']) {
            $reason = $verdict['reason'] ?? SessionService::REASON_IDLE;

            SessionService::terminate((int) $session['session_id'], $reason);
            Auth::logout();

            throw new AuthenticationException(
                $reason === SessionService::REASON_ABSOLUTE
                    ? 'Your session reached its maximum duration. Please sign in again.'
                    : 'You were signed out after a period of inactivity.',
                'SESSION_EXPIRED',
                ['reason' => $reason]
            );
        }

        if ($this->countsAsActivity($request)) {
            Auth::refreshSession(SessionService::touch($session));
        }

        $response = $next($request);

        // Let the client's countdown re-synchronise with the server on every
        // response, so a tampered or drifted browser timer is corrected rather
        // than trusted.
        $current = Auth::session();

        if ($current !== null) {
            $response->withHeader('X-Session-Expires-In', (string) SessionService::secondsRemaining($current));
        }

        return $response;
    }

    /**
     * Part 18.4. Everything user-initiated counts; everything the page does on
     * its own does not.
     */
    private function countsAsActivity(Request $request): bool
    {
        if ($request->isPassive()) {
            return false;
        }

        // Belt and braces: even if the front-end forgets the header on one of
        // these, they are structurally passive and must never extend a session.
        $passivePaths = [
            '/api/attendance/live',
            '/api/realtime/replay',
            '/api/realtime/ticket',
            '/api/notifications/unread-count',
            '/api/dashboard/stream',
            '/api/auth/session-status',
        ];

        foreach ($passivePaths as $path) {
            if (str_starts_with($request->path(), $path)) {
                return false;
            }
        }

        return true;
    }
}
