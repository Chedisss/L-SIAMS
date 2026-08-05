<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Request;
use App\Core\Response;
use App\Services\AnalyticsService;
use App\Services\AttendanceQueryService;
use App\Services\AttendanceSessionService;
use App\Services\DashboardService;
use App\Services\DeviceService;
use App\Services\SecurityLogService;

/**
 * Dashboard and analytics JSON, consumed by the charts and live widgets.
 *
 * Every route here is polled by the page, so the front-end sends
 * `X-Passive-Request: true` and the session timeout is not extended by a
 * dashboard left open on an empty desk.
 */
final class DashboardApiController extends Controller
{
    public function admin(Request $request): Response
    {
        return $this->json([
            'overview'    => DashboardService::adminOverview(),
            'trend'       => DashboardService::attendanceTrend($request->int('days', 14)),
            'devices'     => DeviceService::fleet(),
            'sessions'    => AttendanceSessionService::openSessions(),
            'live'        => AttendanceQueryService::liveFeed($request->int('since', 0), 25),
            'rejections'  => AttendanceQueryService::recentRejections(10),
            'security'    => SecurityLogService::dashboardSummary(),
            'actions'     => DashboardService::actionItems(),
            'server_time' => Clock::atom(),
        ]);
    }

    public function teacher(Request $request): Response
    {
        $teacherId = $this->requireTeacherId();

        return $this->json([
            'overview'    => DashboardService::teacherOverview($teacherId),
            'trend'       => DashboardService::attendanceTrend(7, $teacherId),
            'live'        => AttendanceQueryService::liveFeed($request->int('since', 0), 15, $teacherId),
            'rejections'  => AttendanceQueryService::recentRejections(5, $teacherId),
            'server_time' => Clock::atom(),
        ]);
    }

    /**
     * Every analytics dataset behind one endpoint, selected by `chart`, so the
     * page can lazy-load each card independently (Part 7: "Charts should load
     * asynchronously").
     */
    public function analytics(Request $request): Response
    {
        $from  = $request->string('date_from', Clock::now()->modify('-30 days')->format('Y-m-d'));
        $to    = $request->string('date_to', Clock::today());
        $chart = $request->string('chart', 'all');

        $datasets = [];

        $wanted = static fn (string $name): bool => $chart === 'all' || $chart === $name;

        if ($wanted('trend')) {
            $datasets['trend'] = DashboardService::attendanceTrend(30);
        }
        if ($wanted('monthly')) {
            $datasets['monthly'] = AnalyticsService::monthlyTrend(12);
        }
        if ($wanted('by_grade')) {
            $datasets['by_grade'] = AnalyticsService::attendanceByGradeLevel($from, $to);
        }
        if ($wanted('by_section')) {
            $datasets['by_section'] = AnalyticsService::attendanceBySection(
                $from,
                $to,
                $request->int('grade_level_id', 0) ?: null
            );
        }
        if ($wanted('by_subject')) {
            $datasets['by_subject'] = AnalyticsService::attendanceBySubject($from, $to);
        }
        if ($wanted('by_teacher')) {
            $datasets['by_teacher'] = AnalyticsService::attendanceByTeacher($from, $to);
        }
        if ($wanted('dwell')) {
            $datasets['dwell'] = AnalyticsService::averageDwellBySection($from, $to);
        }
        if ($wanted('left_early')) {
            $datasets['left_early'] = AnalyticsService::leftEarlyTrend($from, $to);
        }
        if ($wanted('incomplete')) {
            $datasets['incomplete'] = AnalyticsService::incompleteBySubject($from, $to);
        }
        if ($wanted('time_in_distribution')) {
            $datasets['time_in_distribution'] = AnalyticsService::timeInDistribution($from, $to);
        }
        if ($wanted('time_out_compliance')) {
            $datasets['time_out_compliance'] = AnalyticsService::timeOutCompliance($from, $to);
        }
        if ($wanted('device_uptime')) {
            $datasets['device_uptime'] = AnalyticsService::deviceUptime($request->int('days', 7));
        }
        if ($wanted('reliability')) {
            $datasets['reliability'] = AnalyticsService::reliabilityMetrics($request->int('days', 7));
        }
        if ($wanted('rejections')) {
            $datasets['rejections'] = AnalyticsService::rejectionBreakdown($request->int('days', 7));
        }
        if ($wanted('chronic_absence')) {
            $datasets['chronic_absence'] = AnalyticsService::chronicAbsence($from, $to);
        }

        return $this->json([
            'date_from' => $from,
            'date_to'   => $to,
            'datasets'  => $datasets,
        ]);
    }

    public function devices(Request $request): Response
    {
        return $this->json([
            'rows'    => DeviceService::fleet(),
            'summary' => DeviceService::fleetSummary(),
        ]);
    }

    public function security(Request $request): Response
    {
        return $this->json([
            'summary' => SecurityLogService::dashboardSummary(),
            'recent'  => SecurityLogService::recent(15),
            'trend'   => SecurityLogService::trend($request->int('hours', 24)),
        ]);
    }

    /**
     * Global search across every module (Part 7).
     *
     * Teachers get a narrowed result set — no device or user results, and only
     * their own attendance.
     */
    public function search(Request $request): Response
    {
        $query = trim($request->string('q', ''));

        if (mb_strlen($query) < 2) {
            return $this->json(['results' => []], 'Type at least two characters.');
        }

        $db      = \App\Core\Database::instance();
        $like    = '%' . $query . '%';
        $results = [];

        $students = $db->select(
            "SELECT st.student_id, st.student_number,
                    CONCAT(st.last_name, ', ', st.first_name) AS label,
                    sec.section_code
               FROM students st
               JOIN sections sec ON sec.section_id = st.section_id
              WHERE st.deleted_at IS NULL
                AND (st.student_number LIKE :q OR CONCAT(st.first_name, ' ', st.last_name) LIKE :q)
              LIMIT 6",
            ['q' => $like]
        );

        foreach ($students as $row) {
            $results[] = [
                'type'  => 'Student',
                'label' => $row['label'],
                'meta'  => $row['student_number'] . ' · ' . $row['section_code'],
                'url'   => Auth::isAdmin() ? '/admin/students/' . $row['student_id'] : '#',
            ];
        }

        if (Auth::isAdmin()) {
            $teachers = $db->select(
                "SELECT t.teacher_id, CONCAT(t.last_name, ', ', t.first_name) AS label,
                        t.employee_number, d.department_code
                   FROM teachers t
                   JOIN departments d ON d.department_id = t.department_id
                  WHERE t.deleted_at IS NULL
                    AND (t.employee_number LIKE :q OR CONCAT(t.first_name, ' ', t.last_name) LIKE :q)
                  LIMIT 5",
                ['q' => $like]
            );

            foreach ($teachers as $row) {
                $results[] = [
                    'type'  => 'Teacher',
                    'label' => $row['label'],
                    'meta'  => $row['employee_number'] . ' · ' . $row['department_code'],
                    'url'   => '/admin/teachers/' . $row['teacher_id'],
                ];
            }

            $devices = $db->select(
                'SELECT d.id, d.device_id, d.device_name, c.room_number
                   FROM devices d
                   LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
                  WHERE d.deleted_at IS NULL
                    AND (d.device_id LIKE :q OR d.device_name LIKE :q OR d.mac_address LIKE :q)
                  LIMIT 5',
                ['q' => $like]
            );

            foreach ($devices as $row) {
                $results[] = [
                    'type'  => 'Device',
                    'label' => $row['device_name'],
                    'meta'  => $row['device_id'] . ($row['room_number'] === null ? '' : ' · Room ' . $row['room_number']),
                    'url'   => '/admin/devices/' . $row['id'],
                ];
            }

            $sections = $db->select(
                'SELECT section_id, section_code, section_name
                   FROM sections
                  WHERE deleted_at IS NULL AND (section_code LIKE :q OR section_name LIKE :q)
                  LIMIT 5',
                ['q' => $like]
            );

            foreach ($sections as $row) {
                $results[] = [
                    'type'  => 'Section',
                    'label' => $row['section_code'],
                    'meta'  => $row['section_name'],
                    'url'   => '/admin/sections/' . $row['section_id'],
                ];
            }
        }

        $cards = $db->select(
            'SELECT rc.card_uid, s.student_number,
                    CONCAT(s.last_name, \', \', s.first_name) AS student_name
               FROM rfid_cards rc
               LEFT JOIN students s ON s.student_id = rc.student_id
              WHERE rc.card_uid LIKE :q
              LIMIT 4',
            ['q' => $like]
        );

        foreach ($cards as $row) {
            $results[] = [
                'type'  => 'RFID card',
                'label' => $row['card_uid'],
                'meta'  => $row['student_name'] ?? 'unassigned',
                'url'   => Auth::isAdmin() ? '/admin/rfid?search=' . urlencode((string) $row['card_uid']) : '#',
            ];
        }

        return $this->json(['results' => $results, 'query' => $query]);
    }
}
