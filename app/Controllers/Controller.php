<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\SessionService;

abstract class Controller
{
    /**
     * Render a page with the shell data every layout needs.
     *
     * @param array<string,mixed> $data
     */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return View::response($template, $this->withLayoutData($data), $status);
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function withLayoutData(array $data): array
    {
        $user = Auth::user();

        $shell = [
            'authUser'     => $user,
            'appName'      => (string) Config::get('app.name'),
            'schoolName'   => \App\Services\SettingsService::schoolName(),
            'flashes'      => Flash::pull(),
            'errors'       => Flash::errors(),
            'pageTitle'    => $data['pageTitle'] ?? (string) Config::get('app.name'),
            'idleTimeout'  => SessionService::idleTimeoutMinutes(),
            'warnSeconds'  => (int) Config::get('security.session.warning_seconds_before', 60),
            'realtimeUrl'  => (string) Config::get('realtime.public_url'),
            'pollInterval' => (int) Config::get('realtime.fallback.poll_interval_ms', 3000),
        ];

        if ($user !== null) {
            $shell['unreadNotifications'] = NotificationService::unreadCount(
                (int) $user['user_id'],
                (string) $user['role_slug']
            );
            $shell['sessionExpiresIn'] = Auth::session() === null
                ? null
                : SessionService::secondsRemaining(Auth::session());
        }

        // Errors and old input are single-use; clearing here means a later
        // successful render does not redisplay a stale validation message.
        // Flash keeps its own copy for the rest of this request, so the
        // template rendered below can still call field_error() and old().
        Flash::clearErrors();
        Flash::clearOld();

        return array_merge($shell, $data);
    }

    /**
     * @param  array<string,mixed> $data
     */
    protected function json(array $data = [], string $message = 'OK', int $status = 200): Response
    {
        return Response::ok($data, $message, $status);
    }

    protected function fail(string $code, string $message, int $status = 400): Response
    {
        return Response::fail($code, $message, $status);
    }

    protected function redirect(string $path, ?string $successMessage = null): Response
    {
        if ($successMessage !== null) {
            Flash::success($successMessage);
        }

        return Response::redirect($path, 303);
    }

    /**
     * Validate request input, throwing ValidationException on failure.
     *
     * @param  array<string,string|list<string>> $rules
     * @param  array<string,string>              $labels
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        return Validator::make($request->all())->labels($labels)->rules($rules)->validate();
    }

    /**
     * Pagination parameters, clamped to the configured allowlist so a crafted
     * `per_page=100000` cannot be used to exhaust memory.
     *
     * @return array{page:int,per_page:int,offset:int}
     */
    protected function pagination(Request $request): array
    {
        /** @var list<int> $allowed */
        $allowed = Config::get('app.pagination.allowed', [10, 25, 50, 100]);
        $default = (int) Config::get('app.pagination.default_per_page', 25);

        $perPage = $request->int('per_page', $default);

        if (!in_array($perPage, $allowed, true)) {
            $perPage = $default;
        }

        $page = max(1, $request->int('page', 1));

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * @param array<string,mixed> $pagination
     * @return array<string,mixed>
     */
    protected function paginationMeta(int $total, int $page, int $perPage): array
    {
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'last_page'   => $lastPage,
            'from'        => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to'          => min($total, $page * $perPage),
            'has_more'    => $page < $lastPage,
        ];
    }

    /** The signed-in teacher's ID, or a 403 for anyone else. */
    protected function requireTeacherId(): int
    {
        $teacherId = Auth::teacherId();

        if ($teacherId === null) {
            throw new AuthorizationException('This area is only available to teacher accounts.');
        }

        return $teacherId;
    }

    protected function requireUserId(): int
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new AuthorizationException('Sign in to continue.');
        }

        return $userId;
    }

    /**
     * Re-authenticate before a sensitive action (Part 2: "Sensitive settings
     * require re-authentication"). Used by bulk deletion, backup restore, key
     * revocation and cross-department exception grants.
     */
    protected function requirePasswordConfirmation(Request $request): void
    {
        $password = $request->string('confirm_password', '');

        if ($password === '') {
            throw new \App\Core\Exceptions\ValidationException(
                ['confirm_password' => ['Enter your password to confirm this action.']],
                'Password confirmation required.',
                'PASSWORD_CONFIRMATION_REQUIRED'
            );
        }

        if (!AuthService::verifyCurrentUserPassword($password)) {
            throw new \App\Core\Exceptions\ValidationException(
                ['confirm_password' => ['That password is incorrect.']],
                'Password confirmation failed.',
                'PASSWORD_CONFIRMATION_FAILED'
            );
        }
    }
}
