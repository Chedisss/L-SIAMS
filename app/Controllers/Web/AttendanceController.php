<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\AttendanceQueryService;
use App\Services\AttendanceService;
use App\Services\AttendanceSessionService;
use App\Services\AttendanceStatusResolver;
use App\Services\ReportService;
use App\Services\TeacherService;

final class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $pagination = $this->pagination($request);
        $filters    = $this->filters($request);

        $result = AttendanceQueryService::paginate(
            $filters,
            $pagination['page'],
            $pagination['per_page'],
            $request->string('sort', 'time_in'),
            $request->string('direction', 'DESC')
        );

        $meta = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('admin.attendance.index', [
            'pageTitle'     => 'Attendance',
            'records'       => $result['rows'],
            'pagination'    => $meta,
            'filters'       => $filters,
            'sections'      => AcademicStructureService::sections(['status' => 'active']),
            'subjects'      => AcademicStructureService::subjects(['status' => 'active']),
            'classrooms'    => AcademicStructureService::classrooms(true),
            'gradeLevels'   => AcademicStructureService::gradeLevels(),
            'teachers'      => TeacherService::paginate(['status' => 'active'], 1, 500)['rows'],
            'statuses'      => AttendanceStatusResolver::allFinalStatuses(),
            'openSessions'  => AttendanceSessionService::openSessions(),
            'sort'          => $request->string('sort', 'time_in'),
            'direction'     => $request->string('direction', 'DESC'),
        ]);
    }

    public function show(Request $request): Response
    {
        $attendanceId = $request->routeInt('id');
        $record       = AttendanceQueryService::find($attendanceId);

        if ($record === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Attendance record not found.');
        }

        return $this->json([
            'record'        => $record,
            'modifications' => AttendanceQueryService::modificationHistory($attendanceId),
            'taps'          => AttendanceQueryService::tapHistoryForRecord($attendanceId),
            'arrival_statuses'   => AttendanceStatusResolver::allArrivalStatuses(),
            'departure_statuses' => AttendanceStatusResolver::allDepartureStatuses(),
        ]);
    }

    /**
     * Administrator correction. Teachers never reach this route — it is gated
     * on `role:administrator` and every change demands a reason.
     */
    public function correct(Request $request): Response
    {
        $data = $this->validate($request, [
            'reason'           => 'required|string|min:5|max:500|no_html',
            'arrival_status'   => 'nullable|in:' . implode(',', AttendanceStatusResolver::allArrivalStatuses()),
            'departure_status' => 'nullable|in:' . implode(',', AttendanceStatusResolver::allDepartureStatuses()),
            'time_in'          => 'nullable|datetime',
            'time_out'         => 'nullable|datetime',
        ], ['reason' => 'Correction reason']);

        $attendanceId = $request->routeInt('id');

        $changes = array_filter(
            [
                'arrival_status'   => $data['arrival_status'] ?? null,
                'departure_status' => $data['departure_status'] ?? null,
            ],
            static fn ($value): bool => $value !== null
        );

        // Timestamps are passed through even when blank, because clearing a
        // time-out is a legitimate correction.
        foreach (['time_in', 'time_out'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $data[$field] ?? null;
            }
        }

        if ($changes === []) {
            return $this->fail('NO_CHANGES', 'Nothing was changed.', 422);
        }

        AttendanceQueryService::correct(
            $attendanceId,
            $changes,
            (string) $data['reason'],
            $this->requireUserId()
        );

        return $this->json(
            ['record' => AttendanceQueryService::find($attendanceId)],
            'Attendance corrected. The original values are preserved in the audit trail.'
        );
    }

    public function sessions(Request $request): Response
    {
        return $this->view('admin.attendance.sessions', [
            'pageTitle' => 'Attendance Sessions',
            'sessions'  => AttendanceSessionService::openSessions(),
        ]);
    }

    public function sessionDetail(Request $request): Response
    {
        $sessionId = $request->routeInt('id');
        $session   = AttendanceSessionService::find($sessionId);

        if ($session === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Attendance session not found.');
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

    /** Force-close a session from the admin side (a terminal lost power, say). */
    public function closeSession(Request $request): Response
    {
        $summary = AttendanceSessionService::close(
            $request->routeInt('id'),
            'administrator',
            $this->requireUserId()
        );

        return $this->json($summary, sprintf(
            'Session closed. Present %d, late %d, absent %d, incomplete %d.',
            $summary['present_count'],
            $summary['late_count'],
            $summary['absent_count'],
            $summary['incomplete_count']
        ));
    }

    /**
     * Release a student from an open session's room.
     *
     * The same control the teacher has on their own roster. An administrator
     * gets it for the case the feature exists to cover from the office side —
     * a parent collecting a child while the teacher is mid-lesson and nowhere
     * near a screen. The record carries whichever account did it either way.
     */
    public function releaseStudent(Request $request): Response
    {
        $result = AttendanceService::releaseEarly(
            $request->routeInt('id'),
            (int) $request->input('attendance_id'),
            (string) $request->input('reason', ''),
            $request->input('note') === null ? null : (string) $request->input('note'),
            $this->requireUserId()
        );

        return $this->json($result, sprintf(
            '%s was released at %s — %s. Recorded as Left Early.',
            $result['student'],
            $result['time_out'],
            strtolower((string) $result['reason_label'])
        ));
    }

    public function export(Request $request): Response
    {
        // 'pdf' rather than 'csv'. CSV was withdrawn, and it was the default
        // here — so a bare /admin/attendance/export threw a validation error
        // instead of exporting anything. PDF is the right fallback because it
        // is the one format that needs no PHP extension, so a bare URL works
        // on a server where Excel cannot.
        $format  = $request->string('format', 'pdf');

        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            $format = 'pdf';
        }

        $filters = $this->filters($request);

        $report = ReportService::build('daily', $filters + [
            'date_from' => $filters['date_from'] ?: Clock::now()->modify('-30 days')->format('Y-m-d'),
            'date_to'   => $filters['date_to'] ?: Clock::today(),
        ]);

        $rendered = ReportService::export($report, $format);

        return Response::attachment($rendered['content'], $rendered['filename'], $rendered['mime']);
    }

    /**
     * Live feed poller — the Tier 3 fallback from Part 17.4.
     *
     * Marked passive by the front-end, so it never extends the session.
     */
    public function live(Request $request): Response
    {
        $sinceId = $request->int('since', 0);

        return $this->json([
            'records'    => AttendanceQueryService::liveFeed($sinceId, 50),
            'rejections' => AttendanceQueryService::recentRejections(10),
            'sessions'   => AttendanceSessionService::openSessions(),
            'server_time' => Clock::atom(),
        ]);
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return [
            'date'           => $request->string('date', ''),
            'date_from'      => $request->string('date_from', ''),
            'date_to'        => $request->string('date_to', ''),
            'section_id'     => $request->int('section_id', 0) ?: null,
            'grade_level_id' => $request->int('grade_level_id', 0) ?: null,
            'subject_id'     => $request->int('subject_id', 0) ?: null,
            'teacher_id'     => $request->int('teacher_id', 0) ?: null,
            'classroom_id'   => $request->int('classroom_id', 0) ?: null,
            'student_id'     => $request->int('student_id', 0) ?: null,
            'session_id'     => $request->int('session_id', 0) ?: null,
            'final_status'   => $request->string('final_status', ''),
            'search'         => $request->string('search', ''),
            'auto_closed_only' => $request->bool('auto_closed_only', false),
            'offline_only'   => $request->bool('offline_only', false),
        ];
    }
}
