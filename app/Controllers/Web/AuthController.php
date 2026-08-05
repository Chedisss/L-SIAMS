<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\SessionService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        $reason = $request->string('reason', '');

        $banner = match ($reason) {
            'idle_timeout'     => 'You were signed out after ' . SessionService::idleTimeoutMinutes() . ' minutes of inactivity.',
            'absolute_timeout' => 'Your session reached its maximum duration and was signed out.',
            'logout'           => 'You have been signed out.',
            'terminated'       => 'Your session was ended by an administrator.',
            default            => null,
        };

        return $this->view('auth.login', [
            'pageTitle' => 'Sign in',
            'banner'    => $banner,
            'reason'    => $reason,
        ]);
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required|string|max:190',
            'password' => 'required|string|max:200',
        ], [
            'username' => 'Username or email',
            'password' => 'Password',
        ]);

        try {
            $result = AuthService::attempt(
                (string) $data['username'],
                (string) $data['password'],
                $request
            );
        } catch (AuthenticationException $e) {
            if ($request->wantsJson()) {
                return Response::fail($e->errorCode(), $e->getMessage(), 401);
            }

            Flash::error($e->getMessage());
            Flash::withInput(['username' => $data['username']]);

            return Response::redirect('/login', 303);
        }

        $role     = strtolower((string) $result['user']['role_slug']);
        $target   = $result['must_change_password']
            ? '/password/change'
            : ($role === 'teacher' ? '/teacher' : '/admin');

        if ($request->wantsJson()) {
            return SessionService::attachCookie(
                Response::ok([
                    'redirect'             => $target,
                    'role'                 => $role,
                    'must_change_password' => $result['must_change_password'],
                ], 'Signed in.'),
                $result['token']
            );
        }

        if ($result['must_change_password']) {
            Flash::info('Please choose a new password before continuing.');
        }

        return SessionService::attachCookie(Response::redirect($target, 303), $result['token']);
    }

    public function logout(Request $request): Response
    {
        AuthService::logout();

        if ($request->wantsJson()) {
            return SessionService::clearCookie(Response::ok(['redirect' => '/login'], 'Signed out.'));
        }

        return SessionService::clearCookie(Response::redirect('/login?reason=logout', 303));
    }

    public function showForgotPassword(Request $request): Response
    {
        return $this->view('auth.forgot-password', ['pageTitle' => 'Forgot password']);
    }

    public function forgotPassword(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email'], ['email' => 'Email address']);

        AuthService::requestPasswordReset((string) $data['email'], $request->ip());

        // Deliberately identical whether or not the address exists, so the form
        // cannot be used to enumerate accounts.
        $message = 'If that email address is registered, an administrator has been notified to arrange a reset.';

        if ($request->wantsJson()) {
            return Response::ok([], $message);
        }

        Flash::info($message);

        return Response::redirect('/login', 303);
    }

    public function showChangePassword(Request $request): Response
    {
        $user = Auth::user();

        return $this->view('auth.change-password', [
            'pageTitle' => 'Change password',
            'forced'    => (int) ($user['must_change_password'] ?? 0) === 1,
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $user   = Auth::user();
        $forced = (int) ($user['must_change_password'] ?? 0) === 1;

        $rules = [
            'password'              => 'required|string|min:12|max:200',
            'password_confirmation' => 'required|string',
        ];

        // A forced first-login change does not ask for the current password:
        // the administrator issued it and the user may only have it on paper.
        if (!$forced) {
            $rules['current_password'] = 'required|string';
        }

        $data = $this->validate($request, $rules, [
            'current_password'      => 'Current password',
            'password'              => 'New password',
            'password_confirmation' => 'Confirm password',
        ]);

        AuthService::changePassword(
            $this->requireUserId(),
            (string) ($data['current_password'] ?? ''),
            (string) $data['password'],
            (string) $data['password_confirmation'],
            !$forced
        );

        $target = Auth::isTeacher() ? '/teacher' : '/admin';

        if ($request->wantsJson()) {
            return Response::ok(['redirect' => $target], 'Password changed. Other sessions were signed out.');
        }

        return $this->redirect($target, 'Password changed successfully. All your other sessions were signed out.');
    }

    /**
     * Part 18.3 — the "Stay Signed In" button.
     *
     * This endpoint deliberately does NOT carry the passive header, so reaching
     * it counts as activity and SessionTimeoutMiddleware extends the deadline
     * before this method runs.
     */
    public function keepAlive(Request $request): Response
    {
        $session = Auth::session();

        if ($session === null) {
            return Response::fail('SESSION_EXPIRED', 'Your session has ended.', 401);
        }

        return Response::ok([
            'expires_in' => SessionService::secondsRemaining($session),
            'expires_at' => $session['idle_expires_at'],
            'server_time' => \App\Core\Clock::atom(),
        ], 'Session extended.');
    }

    /**
     * Passive status probe for the client-side countdown. Called with
     * `X-Passive-Request: true`, so it reports the remaining time without
     * extending it — which is the entire point.
     */
    public function sessionStatus(Request $request): Response
    {
        $session = Auth::session();

        if ($session === null) {
            return Response::fail('SESSION_EXPIRED', 'Your session has ended.', 401);
        }

        $remaining = SessionService::secondsRemaining($session);

        return Response::ok([
            'expires_in'    => $remaining,
            'expires_at'    => $session['idle_expires_at'],
            'warn_at'       => max(0, $remaining - (int) \App\Core\Config::get('security.session.warning_seconds_before', 60)),
            'idle_timeout_minutes' => SessionService::idleTimeoutMinutes(),
            'server_time'   => \App\Core\Clock::atom(),
        ], 'OK');
    }
}
