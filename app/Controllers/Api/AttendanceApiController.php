<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceQueryService;
use App\Services\AttendanceService;
use App\Services\AttendanceSessionService;
use App\Services\FingerprintService;

/**
 * Attendance endpoints for the classroom terminal.
 *
 * `tap` is the primary path: the device reports a card read and the server
 * decides whether it means arrival or departure (Part 16.2). The explicit
 * time-in/time-out endpoints exist for dedicated entry/exit readers.
 */
final class AttendanceApiController extends Controller
{
    /** Fingerprint verification opens the session — nothing else can. */
    public function start(Request $request): Response
    {
        $device = Auth::device();

        $data = $this->validate($request, [
            'fingerprint_id' => 'required|int|between:1,999',
            'confidence'     => 'nullable|int|between:0,65535',
            'schedule_id'    => 'nullable|int',
        ], [
            'fingerprint_id' => 'Sensor slot',
        ]);

        $result = FingerprintService::verifyAndOpenSession(
            $device,
            (int) $data['fingerprint_id'],
            isset($data['confidence']) ? (int) $data['confidence'] : null,
            empty($data['schedule_id']) ? null : (int) $data['schedule_id']
        );

        return Response::json([
            'success' => true,
            'code'    => 'SESSION_OPENED',
            'message' => 'Attendance session opened.',
            'data'    => $result,
        ] + $result, 201);
    }

    /**
     * Record a tap. The server resolves the intent.
     *
     * The response is deliberately flat as well as enveloped: the ESP32 parses
     * a small fixed set of keys straight off the top level to drive the OLED,
     * the LED and the buzzer without walking into `data`.
     */
    public function tap(Request $request): Response
    {
        $device = Auth::device();

        $data = $this->validate($request, [
            'rfid_uid'   => 'required|uid',
            'request_id' => 'nullable|uuid',
            'intent'     => 'nullable|in:time_in,time_out',
            'timestamp'  => 'nullable|datetime',
        ], [
            'rfid_uid' => 'Card UID',
        ]);

        // A device-supplied timestamp is honoured only for queued offline
        // records; a live tap always uses server time.
        $occurredAt = null;

        if ($request->bool('offline', false) && !empty($data['timestamp'])) {
            $occurredAt = Clock::parse((string) $data['timestamp']);
        }

        $result = AttendanceService::tap(
            $device,
            (string) $data['rfid_uid'],
            $data['request_id'] ?? null,
            $data['intent'] ?? null,
            $occurredAt
        );

        return Response::json([
            'success' => true,
            'code'    => $result['code'],
            'message' => sprintf('%s recorded for %s.', ucfirst(str_replace('_', ' ', (string) $result['intent'])), $result['student']),
            'data'    => $result,
        ] + $result, 201);
    }

    /** Explicit arrival, for a reader whose role is `entry`. */
    public function timeIn(Request $request): Response
    {
        $request->setRouteParams($request->routeParams());

        return $this->tapWithIntent($request, AttendanceService::INTENT_TIME_IN);
    }

    /** Explicit departure, for a reader whose role is `exit`. */
    public function timeOut(Request $request): Response
    {
        return $this->tapWithIntent($request, AttendanceService::INTENT_TIME_OUT);
    }

    private function tapWithIntent(Request $request, string $intent): Response
    {
        $device = Auth::device();

        $data = $this->validate($request, [
            'rfid_uid'   => 'required|uid',
            'request_id' => 'nullable|uuid',
        ]);

        $result = AttendanceService::tap(
            $device,
            (string) $data['rfid_uid'],
            $data['request_id'] ?? null,
            $intent
        );

        return Response::json([
            'success' => true,
            'code'    => $result['code'],
            'message' => 'Recorded.',
            'data'    => $result,
        ] + $result, 201);
    }

    /** Teacher closes attendance from the terminal. */
    public function end(Request $request): Response
    {
        $device = Auth::device();

        $session = \App\Core\Database::instance()->selectOne(
            "SELECT session_id FROM attendance_sessions WHERE device_row_id = :id AND status = 'open'",
            ['id' => (int) $device['id']]
        );

        if ($session === null) {
            return $this->fail('SESSION_NOT_OPEN', 'No attendance session is open on this terminal.', 409);
        }

        $summary = AttendanceSessionService::close((int) $session['session_id'], 'teacher', null);

        return Response::json([
            'success' => true,
            'code'    => 'SESSION_CLOSED',
            'message' => 'Attendance session closed.',
            'data'    => $summary,
            'display_line_1' => 'SESSION CLOSED',
            'display_line_2' => sprintf('P%d L%d A%d', $summary['present_count'], $summary['late_count'], $summary['absent_count']),
            'led'            => 'blue',
            'buzzer'         => 'double_short',
        ]);
    }

    /**
     * Live feed for dashboards (Tier 3 fallback, Part 17.4).
     *
     * Marked passive by the front-end so polling it never keeps a session alive.
     */
    public function live(Request $request): Response
    {
        $teacherId = Auth::isTeacher() ? Auth::teacherId() : null;

        return $this->json([
            'records'     => AttendanceQueryService::liveFeed($request->int('since', 0), 50, $teacherId),
            'rejections'  => AttendanceQueryService::recentRejections(10, $teacherId),
            'sessions'    => AttendanceSessionService::openSessions(),
            'server_time' => Clock::atom(),
        ]);
    }

    public function sessionSummary(Request $request): Response
    {
        $sessionId = $request->routeInt('id');
        $session   = AttendanceSessionService::find($sessionId);

        if ($session === null) {
            return $this->fail('NOT_FOUND', 'Session not found.', 404);
        }

        // Teachers may only inspect their own sessions.
        if (Auth::isTeacher() && (int) $session['teacher_id'] !== Auth::teacherId()) {
            return $this->fail('FORBIDDEN', 'This session belongs to another teacher.', 403);
        }

        return $this->json([
            'session' => $session,
            'roster'  => AttendanceSessionService::roster($sessionId),
        ]);
    }
}
