<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceQueryService;
use App\Services\AttendanceSessionService;
use App\Services\DashboardService;
use App\Services\FingerprintService;
use App\Services\NotificationService;
use App\Services\ScheduleService;
use App\Services\TeacherService;

/**
 * The Teacher module (Part 3).
 *
 * Teachers see only their own data. Every read is scoped by teacher_id from the
 * session — never from a request parameter — so there is no identifier a
 * teacher could change to see a colleague's class.
 *
 * Note what is absent: no attendance-correction action, no device management,
 * no user administration. Teachers cannot modify attendance by any route.
 */
final class TeacherPortalController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();
        $overview  = DashboardService::teacherOverview($teacherId);

        return $this->view('teacher.dashboard', [
            'pageTitle'  => 'Dashboard',
            'overview'   => $overview,
            'recent'     => AttendanceQueryService::liveFeed(0, 15, $teacherId),
            'rejections' => AttendanceQueryService::recentRejections(5, $teacherId),
            'trend'      => DashboardService::attendanceTrend(7, $teacherId),
            'notifications' => NotificationService::forUser(
                $this->requireUserId(),
                'teacher',
                10,
                true
            ),
        ]);
    }

    /**
     * The classes this teacher takes, as classes rather than as timeslots.
     *
     * The schedule answers when, the sessions answer what happened; neither
     * answers who is in the room, which is the question asked when no class is
     * running.
     */
    /**
     * Live state of this teacher's attempt to open a session.
     *
     * Polled by the dashboard panel while somebody stands at the reader. It
     * opens nothing and asks the terminal for nothing — the terminal is already
     * scanning — it reports what the server has been told, so the teacher sees
     * the outcome of a scan without walking back to a serial monitor.
     *
     * Scoped to the teacher in the session, so the `since` parameter is the
     * only input and it can only narrow what a teacher already sees about
     * themselves.
     */
    public function sessionState(Request $request): Response
    {
        $since = $request->string('since', '');

        return $this->json(TeacherService::sessionStartState(
            $this->requireTeacherId(),
            $since === '' ? null : $since
        ));
    }

    public function sections(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();

        return $this->view('teacher.sections', [
            'pageTitle' => 'My Sections',
            'sections'  => TeacherService::sectionsForTeacher($teacherId),
        ]);
    }

    /**
     * One section's roster.
     *
     * The section id arrives from the URL, so it is never trusted: the service
     * resolves it against this teacher's own schedules and answers null for
     * anything else. That is reported as 404 rather than 403 — a teacher has
     * no business learning which section ids exist by probing for the
     * difference between "not yours" and "not there".
     */
    public function sectionRoster(Request $request): Response
    {
        $roster = TeacherService::sectionRoster(
            $this->requireTeacherId(),
            $request->routeInt('id')
        );

        if ($roster === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'NOT_FOUND', 'Section not found.');
        }

        return $this->view('teacher.section-roster', [
            'pageTitle' => $roster['section']['section_code'] . ' — Roster',
            'section'   => $roster['section'],
            'students'  => $roster['students'],
        ]);
    }

    public function schedule(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();
        $view      = $request->string('view', 'weekly');

        $schedules = ScheduleService::forTeacher(
            $teacherId,
            $view === 'daily' ? Clock::now()->format('l') : null
        );

        if ($request->wantsJson()) {
            return $this->json(['rows' => $schedules]);
        }

        return $this->view('teacher.schedule', [
            'pageTitle' => 'My Schedule',
            'schedules' => $schedules,
            'view'      => $view,
            'days'      => ScheduleService::DAYS,
            'today'     => Clock::now()->format('l'),
        ]);
    }

    public function attendance(Request $request): Response
    {
        $teacherId  = $this->requireTeacherId();
        $pagination = $this->pagination($request);

        // teacher_id is forced, not taken from input.
        $filters = [
            'teacher_id'   => $teacherId,
            'date_from'    => $request->string('date_from', ''),
            'date_to'      => $request->string('date_to', ''),
            'section_id'   => $request->int('section_id', 0) ?: null,
            'subject_id'   => $request->int('subject_id', 0) ?: null,
            'final_status' => $request->string('final_status', ''),
            'search'       => $request->string('search', ''),
        ];

        $result = AttendanceQueryService::paginate($filters, $pagination['page'], $pagination['per_page']);
        $meta   = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('teacher.attendance', [
            'pageTitle'  => 'Attendance',
            'records'    => $result['rows'],
            'pagination' => $meta,
            'filters'    => $filters,
            'sections'   => $this->teacherSections($teacherId),
            'subjects'   => $this->teacherSubjects($teacherId),
            'statuses'   => \App\Services\AttendanceStatusResolver::allFinalStatuses(),
        ]);
    }

    public function sessions(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();

        $sessions = \App\Core\Database::instance()->select(
            "SELECT s.*, sec.section_code, sub.subject_code, sub.subject_name, c.room_number, d.device_id
               FROM attendance_sessions s
               JOIN sections sec ON sec.section_id = s.section_id
               JOIN subjects sub ON sub.subject_id = s.subject_id
               JOIN classrooms c ON c.classroom_id = s.classroom_id
               JOIN devices d    ON d.id = s.device_row_id
              WHERE s.teacher_id = :teacher
              ORDER BY s.session_date DESC, s.opened_at DESC
              LIMIT 100",
            ['teacher' => $teacherId]
        );

        return $this->view('teacher.sessions', [
            'pageTitle' => 'My Sessions',
            'sessions'  => $sessions,
        ]);
    }

    public function sessionDetail(Request $request): Response
    {
        $sessionId = $request->routeInt('id');
        $session   = AttendanceSessionService::find($sessionId);

        if ($session === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'NOT_FOUND', 'Session not found.');
        }

        // Ownership check: a teacher may only open their own sessions, even
        // with a valid session ID from elsewhere.
        if ((int) $session['teacher_id'] !== $this->requireTeacherId()) {
            throw new AuthorizationException('This attendance session belongs to another teacher.');
        }

        $payload = [
            'session' => $session,
            'roster'  => AttendanceSessionService::roster($sessionId),
        ];

        if ($request->wantsJson()) {
            return $this->json($payload);
        }

        return $this->view('shared.session-detail', $payload + [
            'pageTitle' => (string) $session['session_code'],
        ]);
    }

    /**
     * Close an open session from the browser.
     *
     * A convenience only — the classroom terminal remains the primary control,
     * and the session would auto-close at the end of its window regardless.
     */
    public function closeSession(Request $request): Response
    {
        $sessionId = $request->routeInt('id');
        $session   = AttendanceSessionService::find($sessionId);

        if ($session === null) {
            return $this->fail('NOT_FOUND', 'Session not found.', 404);
        }

        if ((int) $session['teacher_id'] !== $this->requireTeacherId()) {
            throw new AuthorizationException('This attendance session belongs to another teacher.');
        }

        $summary = AttendanceSessionService::close($sessionId, 'teacher', $this->requireUserId());

        return $this->json($summary, sprintf(
            'Session closed. Present %d, late %d, absent %d.',
            $summary['present_count'],
            $summary['late_count'],
            $summary['absent_count']
        ));
    }

    public function profile(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();
        $teacher   = TeacherService::find($teacherId);

        return $this->view('teacher.profile', [
            'pageTitle'    => 'My Profile',
            'teacher'      => $teacher,
            'constraints'  => TeacherService::constraints($teacherId),
            'schedules'    => ScheduleService::forTeacher($teacherId),
            'loginHistory' => $teacher['user_id'] === null
                ? []
                : \App\Services\AuthService::loginHistory((int) $teacher['user_id'], 10),
            'fingerprintLogs' => FingerprintService::logsForTeacher($teacherId, 10),
            'statistics'   => $this->teacherStatistics($teacherId),
        ]);
    }

    /**
     * Teachers may update contact details and their photo only.
     *
     * Employee number, fingerprint template, department, assigned subjects and
     * schedules are all administrator-controlled (Part 3) and are simply not
     * accepted here.
     */
    public function updateProfile(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email',
            'phone' => 'nullable|phone',
        ]);

        $teacherId = $this->requireTeacherId();
        $userId    = $this->requireUserId();

        \App\Core\Database::instance()->update('teachers', [
            'email'      => mb_strtolower((string) $data['email']),
            'phone'      => $data['phone'] ?? null,
            'updated_at' => Clock::nowString(),
        ], ['teacher_id' => $teacherId]);

        \App\Services\UserRegistrationService::updateUser($userId, ['email' => $data['email']], $userId);

        \App\Services\AuditService::log(
            'TEACHER_PROFILE_UPDATED',
            'profile',
            'teacher',
            $teacherId,
            null,
            ['email' => $data['email'], 'phone' => $data['phone'] ?? null],
            'Teacher updated their own contact details.'
        );

        if ($request->wantsJson()) {
            return $this->json([], 'Profile updated.');
        }

        return $this->redirect('/teacher/profile', 'Profile updated.');
    }

    public function reports(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();

        return $this->view('teacher.reports', [
            'pageTitle' => 'Reports',
            'sections'  => $this->teacherSections($teacherId),
            'subjects'  => $this->teacherSubjects($teacherId),
            'types'     => [
                'daily'           => 'Daily Attendance',
                'weekly'          => 'Weekly Attendance',
                'monthly'         => 'Monthly Attendance',
                'teacher'         => 'My Session Summary',
                'section_daily'   => 'Section Daily Attendance Sheet',
                'section_summary' => 'Section Attendance Summary',
            ],
            'defaultFrom' => Clock::now()->modify('-30 days')->format('Y-m-d'),
            'defaultTo'   => Clock::today(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function teacherSections(int $teacherId): array
    {
        return \App\Core\Database::instance()->select(
            "SELECT DISTINCT sec.section_id, sec.section_code, sec.section_name, gl.grade_level_code
               FROM schedules sch
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
              WHERE sch.teacher_id = :teacher AND sch.status = 'active' AND sch.deleted_at IS NULL
              ORDER BY gl.numeric_level, sec.section_code",
            ['teacher' => $teacherId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function teacherSubjects(int $teacherId): array
    {
        return \App\Core\Database::instance()->select(
            "SELECT DISTINCT s.subject_id, s.subject_code, s.subject_name
               FROM schedules sch
               JOIN subjects s ON s.subject_id = sch.subject_id
              WHERE sch.teacher_id = :teacher AND sch.status = 'active' AND sch.deleted_at IS NULL
              ORDER BY s.subject_code",
            ['teacher' => $teacherId]
        );
    }

    /** @return array<string,mixed> */
    private function teacherStatistics(int $teacherId): array
    {
        $row = \App\Core\Database::instance()->selectOne(
            "SELECT
                COUNT(DISTINCT s.session_id) AS sessions,
                COUNT(ar.attendance_id) AS records,
                SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END) AS attended,
                AVG(ar.duration_minutes) AS avg_dwell
               FROM attendance_sessions s
               LEFT JOIN attendance_records ar ON ar.session_id = s.session_id
              WHERE s.teacher_id = :teacher
                AND s.session_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
            ['teacher' => $teacherId]
        ) ?? [];

        $records = (int) ($row['records'] ?? 0);

        return [
            'sessions'   => (int) ($row['sessions'] ?? 0),
            'records'    => $records,
            'percentage' => $records === 0 ? 0.0 : round((int) $row['attended'] / $records * 100, 1),
            'average_dwell_minutes' => $row['avg_dwell'] === null ? null : (int) round((float) $row['avg_dwell']),
        ];
    }
}
