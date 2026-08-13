<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;

/**
 * Dashboard aggregates for both roles.
 *
 * Part 7 requires the dashboard to render in under two seconds. The queries
 * here are deliberately narrow and index-aligned (idx_att_final_status,
 * idx_att_section_date, idx_session_status), and the live tables load their
 * first page only — everything after that arrives by push rather than by
 * re-querying.
 */
final class DashboardService
{
    /** @return array<string,mixed> */
    public static function adminOverview(): array
    {
        $db    = Database::instance();
        $today = Clock::today();

        $counts = $db->selectOne(
            "SELECT
                (SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND status = 'active') AS total_students,
                (SELECT COUNT(*) FROM teachers WHERE deleted_at IS NULL AND status = 'active') AS total_teachers,
                (SELECT COUNT(*) FROM classrooms WHERE deleted_at IS NULL AND status = 'active') AS total_classrooms,
                (SELECT COUNT(*) FROM devices WHERE deleted_at IS NULL AND status <> 'decommissioned') AS total_devices,
                (SELECT COUNT(*) FROM sections WHERE deleted_at IS NULL AND status = 'active') AS total_sections,
                (SELECT COUNT(*) FROM subjects WHERE deleted_at IS NULL AND status = 'active') AS total_subjects,
                (SELECT COUNT(*) FROM attendance_sessions WHERE status = 'open') AS active_sessions"
        ) ?? [];

        $todayAttendance = $db->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN final_status = 'Left Early' THEN 1 ELSE 0 END) AS left_early,
                SUM(CASE WHEN final_status = 'Incomplete' THEN 1 ELSE 0 END) AS incomplete,
                SUM(CASE WHEN final_status = 'Excused' THEN 1 ELSE 0 END) AS excused,
                AVG(duration_minutes) AS avg_dwell
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date = :today",
            ['today' => $today]
        ) ?? [];

        $total    = (int) ($todayAttendance['total'] ?? 0);
        $attended = (int) ($todayAttendance['present'] ?? 0)
            + (int) ($todayAttendance['late'] ?? 0)
            + (int) ($todayAttendance['left_early'] ?? 0)
            + (int) ($todayAttendance['incomplete'] ?? 0);

        // Tap-to-acknowledgement is what Part 17.1 sets a p95 target for; the
        // proxy here is the spread between session open and each record.
        $processing = $db->selectOne(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, s.opened_at, ar.created_at)) AS avg_seconds
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date = :today AND ar.time_in IS NOT NULL",
            ['today' => $today]
        ) ?? [];

        return [
            'totals' => [
                'students'        => (int) ($counts['total_students'] ?? 0),
                'teachers'        => (int) ($counts['total_teachers'] ?? 0),
                'classrooms'      => (int) ($counts['total_classrooms'] ?? 0),
                'devices'         => (int) ($counts['total_devices'] ?? 0),
                'sections'        => (int) ($counts['total_sections'] ?? 0),
                'subjects'        => (int) ($counts['total_subjects'] ?? 0),
                'active_sessions' => (int) ($counts['active_sessions'] ?? 0),
            ],
            'today' => [
                'records'     => $total,
                'present'     => (int) ($todayAttendance['present'] ?? 0),
                'late'        => (int) ($todayAttendance['late'] ?? 0),
                'absent'      => (int) ($todayAttendance['absent'] ?? 0),
                'left_early'  => (int) ($todayAttendance['left_early'] ?? 0),
                'incomplete'  => (int) ($todayAttendance['incomplete'] ?? 0),
                'excused'     => (int) ($todayAttendance['excused'] ?? 0),
                'percentage'  => $total === 0 ? 0.0 : round($attended / $total * 100, 1),
                'average_dwell_minutes' => $todayAttendance['avg_dwell'] === null
                    ? null
                    : (int) round((float) $todayAttendance['avg_dwell']),
            ],
            'devices'  => DeviceService::fleetSummary(),
            'security' => SecurityLogService::dashboardSummary(),
            'average_processing_seconds' => $processing['avg_seconds'] === null
                ? null
                : round((float) $processing['avg_seconds'], 1),
        ];
    }

    /** @return array<string,mixed> */
    public static function teacherOverview(int $teacherId): array
    {
        $db    = Database::instance();
        $today = Clock::today();
        $day   = Clock::now()->format('l');

        $todayStats = $db->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ar.final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.teacher_id = :teacher AND s.session_date = :today",
            ['teacher' => $teacherId, 'today' => $today]
        ) ?? [];

        $total    = (int) ($todayStats['total'] ?? 0);
        $attended = (int) ($todayStats['present'] ?? 0) + (int) ($todayStats['late'] ?? 0);

        $weekSessions = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_sessions
              WHERE teacher_id = :teacher AND session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
            ['teacher' => $teacherId]
        );

        $current = $db->selectOne(
            "SELECT s.*, sec.section_code, sec.section_name, sub.subject_code, sub.subject_name,
                    c.room_number, d.device_id,
                    sch.start_time, sch.end_time, sch.late_threshold_minutes, sch.minimum_dwell_minutes
               FROM attendance_sessions s
               JOIN sections sec  ON sec.section_id = s.section_id
               JOIN subjects sub  ON sub.subject_id = s.subject_id
               JOIN classrooms c  ON c.classroom_id = s.classroom_id
               JOIN devices d     ON d.id = s.device_row_id
               JOIN schedules sch ON sch.schedule_id = s.schedule_id
              WHERE s.teacher_id = :teacher AND s.status = 'open'
              ORDER BY s.opened_at DESC LIMIT 1",
            ['teacher' => $teacherId]
        );

        $currentCounters = null;

        if ($current !== null) {
            $row = $db->selectOne(
                "SELECT
                    SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) AS timed_in,
                    SUM(CASE WHEN time_out IS NOT NULL THEN 1 ELSE 0 END) AS timed_out,
                    SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN 1 ELSE 0 END) AS in_room,
                    AVG(duration_minutes) AS avg_dwell
                   FROM attendance_records WHERE session_id = :id",
                ['id' => (int) $current['session_id']]
            ) ?? [];

            $currentCounters = [
                'timed_in'      => (int) ($row['timed_in'] ?? 0),
                'timed_out'     => (int) ($row['timed_out'] ?? 0),
                'in_room'       => (int) ($row['in_room'] ?? 0),
                'roster'        => (int) $current['roster_count'],
                'average_dwell' => $row['avg_dwell'] === null ? null : (int) round((float) $row['avg_dwell']),
            ];
        }

        $upcoming = $db->select(
            "SELECT sch.schedule_id, sch.start_time, sch.end_time,
                    sub.subject_code, sub.subject_name, sec.section_code, c.room_number,
                    d.device_id, d.status AS device_status,
                    (SELECT s2.status FROM attendance_sessions s2
                      WHERE s2.schedule_id = sch.schedule_id AND s2.session_date = :today
                      LIMIT 1) AS session_status
               FROM schedules sch
               JOIN subjects sub  ON sub.subject_id = sch.subject_id
               JOIN sections sec  ON sec.section_id = sch.section_id
               JOIN classrooms c  ON c.classroom_id = sch.classroom_id
               LEFT JOIN devices d ON d.classroom_id = c.classroom_id AND d.deleted_at IS NULL
                    AND d.device_role IN ('both','entry')
              WHERE sch.teacher_id = :teacher
                AND sch.day_of_week = :day
                AND sch.status = 'active'
                AND sch.deleted_at IS NULL
              ORDER BY sch.start_time",
            ['teacher' => $teacherId, 'day' => $day, 'today' => $today]
        );

        // What the teacher is told here has to match what the sensor will do,
        // and the sensor matches on the template row. Reporting the cached
        // column instead would tell a teacher they are ready to open a session
        // that the terminal then refuses, with nothing on screen to explain it.
        $teacher = $db->selectOne(
            "SELECT CASE
                        WHEN fp.fingerprint_id IS NULL THEN 'not_enrolled'
                        WHEN fp.status <> 'active'     THEN 'disabled'
                        ELSE 'enrolled'
                    END AS fingerprint_status
               FROM teachers t
          LEFT JOIN fingerprint_templates fp ON fp.teacher_id = t.teacher_id
              WHERE t.teacher_id = :id",
            ['id' => $teacherId]
        ) ?? [];

        return [
            'today' => [
                'classes'    => count($upcoming),
                'records'    => $total,
                'present'    => (int) ($todayStats['present'] ?? 0),
                'late'       => (int) ($todayStats['late'] ?? 0),
                'absent'     => (int) ($todayStats['absent'] ?? 0),
                'percentage' => $total === 0 ? 0.0 : round($attended / $total * 100, 1),
            ],
            'week_sessions'      => $weekSessions,
            'current_session'    => $current,
            'current_counters'   => $currentCounters,
            'todays_schedule'    => $upcoming,
            'fingerprint_status' => (string) ($teacher['fingerprint_status'] ?? 'not_enrolled'),
            'assigned_classrooms' => (int) $db->scalar(
                'SELECT COUNT(DISTINCT classroom_id) FROM schedules
                  WHERE teacher_id = :teacher AND status = \'active\' AND deleted_at IS NULL',
                ['teacher' => $teacherId]
            ),
        ];
    }

    /**
     * Attendance trend for the dashboard line chart.
     *
     * @return list<array<string,mixed>>
     */
    public static function attendanceTrend(int $days = 14, ?int $teacherId = null): array
    {
        $where    = ['s.session_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)'];
        $bindings = ['days' => max(1, min($days, 365))];

        if ($teacherId !== null) {
            $where[]             = 's.teacher_id = :teacher';
            $bindings['teacher'] = $teacherId;
        }

        return Database::instance()->select(
            "SELECT s.session_date AS date,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN ar.final_status = 'Left Early' THEN 1 ELSE 0 END) AS left_early,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE " . implode(' AND ', $where) . '
              GROUP BY s.session_date
              ORDER BY s.session_date',
            $bindings
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recentDeviceActivity(int $limit = 15): array
    {
        return Database::instance()->select(
            'SELECT dl.log_id, dl.event, dl.severity, dl.message, dl.created_at,
                    dl.device_id_text, d.device_name, c.room_number
               FROM device_logs dl
               LEFT JOIN devices d    ON d.id = dl.device_row_id
               LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              ORDER BY dl.log_id DESC
              LIMIT ' . max(1, min($limit, 100))
        );
    }

    /**
     * Items an administrator should act on. Surfacing these on the dashboard is
     * what turns "the system knows" into "somebody fixes it".
     *
     * @return list<array<string,mixed>>
     */
    public static function actionItems(): array
    {
        $db    = Database::instance();
        $items = [];

        $noGradeLevel = TeacherService::withoutGradeLevel();

        if ($noGradeLevel !== []) {
            $items[] = [
                'severity' => 'warning',
                'title'    => sprintf('%d teacher(s) have no assigned grade level', count($noGradeLevel)),
                'detail'   => 'They cannot be placed on any schedule until a grade level is assigned.',
                'link'     => '/admin/teachers?filter=no_grade_level',
            ];
        }

        // Counted from the template table rather than teachers.fingerprint_status,
        // for the same reason the Fingerprints page lists from it: the column is
        // a cache of that table, and a dashboard warning that disagrees with the
        // page it links to sends people looking for a teacher who is not there.
        $noFingerprint = (int) $db->scalar(
            "SELECT COUNT(*) FROM teachers t
          LEFT JOIN fingerprint_templates fp ON fp.teacher_id = t.teacher_id
              WHERE t.deleted_at IS NULL AND t.status = 'active' AND fp.fingerprint_id IS NULL"
        );

        if ($noFingerprint > 0) {
            $items[] = [
                'severity' => 'warning',
                'title'    => sprintf('%d teacher(s) have no fingerprint enrolled', $noFingerprint),
                'detail'   => 'They cannot open attendance sessions until enrolment is complete.',
                'link'     => '/admin/fingerprints',
            ];
        }

        $noCard = (int) $db->scalar(
            "SELECT COUNT(*) FROM students st
              WHERE st.deleted_at IS NULL AND st.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM rfid_cards rc
                                 WHERE rc.student_id = st.student_id AND rc.status = 'active')"
        );

        if ($noCard > 0) {
            $items[] = [
                'severity' => 'warning',
                'title'    => sprintf('%d student(s) have no active RFID card', $noCard),
                'detail'   => 'They cannot be marked present until a card is issued.',
                'link'     => '/admin/rfid',
            ];
        }

        $unknownCards = (int) $db->scalar("SELECT COUNT(*) FROM unknown_rfid_logs WHERE resolution = 'pending'");

        if ($unknownCards > 0) {
            $items[] = [
                'severity' => 'info',
                'title'    => sprintf('%d unknown card(s) awaiting triage', $unknownCards),
                'detail'   => 'Assign, ignore or blacklist each one.',
                'link'     => '/admin/rfid/unknown',
            ];
        }

        $offlineDevices = (int) $db->scalar("SELECT COUNT(*) FROM v_device_status WHERE health = 'offline'");

        if ($offlineDevices > 0) {
            $items[] = [
                'severity' => 'danger',
                'title'    => sprintf('%d terminal(s) offline', $offlineDevices),
                'detail'   => 'Attendance cannot be taken in those rooms until they reconnect.',
                'link'     => '/admin/devices',
            ];
        }

        $unclaimed = (int) $db->scalar(
            "SELECT COUNT(*) FROM devices WHERE claim_status = 'unclaimed' AND deleted_at IS NULL AND status <> 'decommissioned'"
        );

        if ($unclaimed > 0) {
            $items[] = [
                'severity' => 'info',
                'title'    => sprintf('%d terminal(s) never completed activation', $unclaimed),
                'detail'   => 'Flash the provisioning file or regenerate the claim token.',
                'link'     => '/admin/devices',
            ];
        }

        $agingKeys = count(ApiKeyService::agingKeys());

        if ($agingKeys > 0) {
            $items[] = [
                'severity' => 'info',
                'title'    => sprintf('%d API key(s) are over a year old', $agingKeys),
                'detail'   => 'Rotation is recommended. The old key stays valid during the grace window.',
                'link'     => '/admin/devices',
            ];
        }

        $openSecurity = (int) $db->scalar(
            "SELECT COUNT(*) FROM security_logs
              WHERE resolution_status = 'open' AND severity IN ('high','critical')"
        );

        if ($openSecurity > 0) {
            $items[] = [
                'severity' => 'danger',
                'title'    => sprintf('%d unresolved high-severity security event(s)', $openSecurity),
                'detail'   => 'Review and mark each one resolved or a false positive.',
                'link'     => '/admin/security',
            ];
        }

        return $items;
    }
}
