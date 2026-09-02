<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\NetworkService;
use App\Services\ReportService;
use App\Services\SecurityLogService;
use App\Services\SessionService;

/**
 * Security Center, audit log, active sessions and network blocks
 * (Parts 6, 7 and 18.7).
 */
final class SecurityController extends Controller
{
    public function center(Request $request): Response
    {
        $summary = SecurityLogService::dashboardSummary();

        if ($request->wantsJson()) {
            return $this->json([
                'summary' => $summary,
                'recent'  => SecurityLogService::recent(15),
                'trend'   => SecurityLogService::trend(24),
            ]);
        }

        return $this->view('admin.security.center', [
            'pageTitle'   => 'Security Center',
            'summary'     => $summary,
            'recent'      => SecurityLogService::recent(15),
            'trend'       => SecurityLogService::trend(24),
            'blockedIps'  => NetworkService::blockedIps(),
            'sessions'    => SessionService::activeSessions(),
        ]);
    }

    public function logs(Request $request): Response
    {
        $pagination = $this->pagination($request);

        $filters = [
            'event'             => $request->string('event', ''),
            'severity'          => $request->string('severity', ''),
            'resolution_status' => $request->string('resolution_status', ''),
            'date_from'         => $request->string('date_from', ''),
            'date_to'           => $request->string('date_to', ''),
            'search'            => $request->string('search', ''),
        ];

        $result = SecurityLogService::paginate($filters, $pagination['page'], $pagination['per_page']);
        $meta   = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('admin.security.logs', [
            'pageTitle'  => 'Security Logs',
            'logs'       => $result['rows'],
            'pagination' => $meta,
            'filters'    => $filters,
        ]);
    }

    public function resolveLog(Request $request): Response
    {
        $data = $this->validate($request, [
            'resolution_status' => 'required|in:open,investigating,resolved,false_positive',
            'admin_notes'       => 'nullable|string|max:1000|no_html',
        ]);

        SecurityLogService::resolve(
            $request->routeInt('id'),
            $this->requireUserId(),
            (string) $data['resolution_status'],
            $data['admin_notes'] ?? null
        );

        return $this->json([], 'Security event updated.');
    }

    public function auditLogs(Request $request): Response
    {
        $pagination = $this->pagination($request);

        $filters = [
            'user_id'   => $request->int('user_id', 0) ?: null,
            'action'    => $request->string('action', ''),
            'module'    => $request->string('module', ''),
            'date_from' => $request->string('date_from', ''),
            'date_to'   => $request->string('date_to', ''),
            'search'    => $request->string('search', ''),
        ];

        $result = AuditService::paginate($filters, $pagination['page'], $pagination['per_page']);
        $meta   = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('admin.security.audit', [
            'pageTitle'  => 'Audit Logs',
            'logs'       => $result['rows'],
            'pagination' => $meta,
            'filters'    => $filters,
            'actions'    => AuditService::distinctActions(),
            'modules'    => AuditService::distinctModules(),
        ]);
    }

    public function exportAudit(Request $request): Response
    {
        $report = ReportService::build('audit', [
            'date_from' => $request->string('date_from', ''),
            'date_to'   => $request->string('date_to', ''),
            'action'    => $request->string('action', ''),
            'module'    => $request->string('module', ''),
        ]);

        $rendered = ReportService::export($report, $request->string('format', 'xlsx'));

        return Response::attachment($rendered['content'], $rendered['filename'], $rendered['mime']);
    }

    // -------------------------------------------------- active sessions --

    public function sessions(Request $request): Response
    {
        $sessions = SessionService::activeSessions();

        if ($request->wantsJson()) {
            return $this->json(['rows' => $sessions, 'current_session_id' => Auth::sessionId()]);
        }

        return $this->view('admin.security.sessions', [
            'pageTitle'        => 'Active Sessions',
            'sessions'         => $sessions,
            'currentSessionId' => Auth::sessionId(),
            'idleTimeout'      => SessionService::idleTimeoutMinutes(),
        ]);
    }

    public function terminateSession(Request $request): Response
    {
        $sessionId = $request->routeInt('id');

        if ($sessionId === Auth::sessionId()) {
            return $this->fail(
                'CANNOT_TERMINATE_OWN',
                'Use Sign out to end your own session.',
                422
            );
        }

        SessionService::terminate($sessionId, SessionService::REASON_ADMIN);

        return $this->json([], 'Session terminated. That user has been signed out immediately.');
    }

    public function terminateUserSessions(Request $request): Response
    {
        $userId = $request->routeInt('id');
        $count  = SessionService::terminateAllForUser($userId, SessionService::REASON_ADMIN, Auth::sessionId());

        return $this->json(['terminated' => $count], sprintf('%d session(s) terminated.', $count));
    }

    /** Bulk termination is destructive enough to warrant re-authentication. */
    public function terminateAllSessions(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $sessions   = SessionService::activeSessions();
        $terminated = 0;
        $currentId  = Auth::sessionId();

        foreach ($sessions as $session) {
            if ((int) $session['session_id'] === $currentId) {
                continue;
            }

            SessionService::terminate((int) $session['session_id'], SessionService::REASON_ADMIN);
            $terminated++;
        }

        return $this->json(
            ['terminated' => $terminated],
            sprintf('%d session(s) terminated. Your own session was kept.', $terminated)
        );
    }

    // ------------------------------------------------------ ip blocking --

    public function blockIp(Request $request): Response
    {
        $data = $this->validate($request, [
            'ip_address' => 'required|ip',
            'reason'     => 'required|string|min:5|max:255|no_html',
            'minutes'    => 'nullable|int|between:1,525600',
        ], [
            'ip_address' => 'IP address',
        ]);

        // Blocking your own address would lock you out of the page you are
        // standing on, which is a mistake worth catching for the administrator.
        if ((string) $data['ip_address'] === $request->ip()) {
            return $this->fail('CANNOT_BLOCK_SELF', 'That is your own address — blocking it would lock you out.', 422);
        }

        NetworkService::block(
            (string) $data['ip_address'],
            (string) $data['reason'],
            $this->requireUserId(),
            isset($data['minutes']) ? (int) $data['minutes'] : null
        );

        return $this->json([], 'IP address blocked.');
    }

    public function unblockIp(Request $request): Response
    {
        NetworkService::unblock($request->string('ip_address', ''), $this->requireUserId());

        return $this->json([], 'IP block lifted.');
    }
}
