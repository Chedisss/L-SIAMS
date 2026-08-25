<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Chart datasets for the Analytics page (Part 7 and Part 16.10).
 *
 * Every method returns rows shaped for direct consumption by the front-end
 * chart renderer, so no reshaping logic is duplicated in JavaScript.
 */
final class AnalyticsService
{
    /** @return list<array<string,mixed>> */
    public static function attendanceByGradeLevel(string $from, string $to): array
    {
        return Database::instance()->select(
            "SELECT gl.grade_level_code AS label, gl.numeric_level,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM attendance_records ar
               JOIN grade_levels gl ON gl.grade_level_id = ar.grade_level_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to
              GROUP BY gl.grade_level_id, gl.grade_level_code, gl.numeric_level
              ORDER BY gl.numeric_level",
            ['from' => $from, 'to' => $to]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function attendanceBySection(
        string $from,
        string $to,
        ?int $gradeLevelId = null,
        ?int $sectionId = null
    ): array {
        $where    = ['s.session_date BETWEEN :from AND :to'];
        $bindings = ['from' => $from, 'to' => $to];

        if ($gradeLevelId !== null) {
            $where[]           = 'ar.grade_level_id = :grade';
            $bindings['grade'] = $gradeLevelId;
        }

        if ($sectionId !== null) {
            $where[]             = 'ar.section_id = :section';
            $bindings['section'] = $sectionId;
        }

        return Database::instance()->select(
            "SELECT sec.section_code AS label, sec.section_name, gl.grade_level_code,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM attendance_records ar
               JOIN sections sec    ON sec.section_id = ar.section_id
               JOIN grade_levels gl ON gl.grade_level_id = ar.grade_level_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE " . implode(' AND ', $where) . '
              GROUP BY sec.section_id, sec.section_code, sec.section_name, gl.grade_level_code
              ORDER BY percentage DESC',
            $bindings
        );
    }

    /** @return list<array<string,mixed>> */
    public static function attendanceBySubject(
        string $from,
        string $to,
        ?int $subjectId = null,
        ?int $gradeLevelId = null
    ): array {
        $where    = ['s.session_date BETWEEN :from AND :to'];
        $bindings = ['from' => $from, 'to' => $to];

        if ($subjectId !== null) {
            $where[]             = 'ar.subject_id = :subject';
            $bindings['subject'] = $subjectId;
        }

        if ($gradeLevelId !== null) {
            $where[]           = 'ar.grade_level_id = :grade';
            $bindings['grade'] = $gradeLevelId;
        }

        return Database::instance()->select(
            "SELECT sub.subject_code AS label, sub.subject_name, d.department_name,
                    COUNT(*) AS total,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage,
                    SUM(CASE WHEN ar.final_status = 'Incomplete' THEN 1 ELSE 0 END) AS incomplete
               FROM attendance_records ar
               JOIN subjects sub    ON sub.subject_id = ar.subject_id
               JOIN departments d   ON d.department_id = sub.department_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY sub.subject_id, sub.subject_code, sub.subject_name, d.department_name
              ORDER BY percentage ASC",
            $bindings
        );
    }

    /**
     * One subject, day by day.
     *
     * The summary above answers "how is Mathematics 10 doing this month" with a
     * single number. This answers the question anybody asks next — which days
     * were bad — and it is the only shape in which a report is genuinely per
     * date AND per subject rather than one or the other.
     *
     * @return list<array<string,mixed>>
     */
    public static function subjectByDate(
        string $from,
        string $to,
        int $subjectId,
        ?int $gradeLevelId = null,
        ?int $sectionId = null
    ): array {
        $where    = ['s.session_date BETWEEN :from AND :to', 'ar.subject_id = :subject'];
        $bindings = ['from' => $from, 'to' => $to, 'subject' => $subjectId];

        if ($gradeLevelId !== null) {
            $where[]           = 'ar.grade_level_id = :grade';
            $bindings['grade'] = $gradeLevelId;
        }

        if ($sectionId !== null) {
            $where[]             = 'ar.section_id = :section';
            $bindings['section'] = $sectionId;
        }

        return Database::instance()->select(
            "SELECT s.session_date AS attendance_date,
                    sec.section_code,
                    CONCAT(t.last_name, ', ', t.first_name) AS teacher_name,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN ar.final_status = 'Left Early' THEN 1 ELSE 0 END) AS left_early,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
               JOIN sections sec  ON sec.section_id = ar.section_id
               JOIN teachers t    ON t.teacher_id = ar.teacher_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY s.session_date, sec.section_id, sec.section_code, t.last_name, t.first_name
              ORDER BY s.session_date DESC, sec.section_code",
            $bindings
        );
    }

    /** @return list<array<string,mixed>> */
    public static function attendanceByTeacher(string $from, string $to): array
    {
        return Database::instance()->select(
            "SELECT CONCAT(t.last_name, ', ', t.first_name) AS label, d.department_name,
                    COUNT(DISTINCT s.session_id) AS sessions,
                    COUNT(*) AS total,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM attendance_records ar
               JOIN teachers t    ON t.teacher_id = ar.teacher_id
               JOIN departments d ON d.department_id = t.department_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to
              GROUP BY t.teacher_id, t.last_name, t.first_name, d.department_name
              ORDER BY percentage DESC",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Average dwell time by section (Part 16.10). @return list<array<string,mixed>> */
    public static function averageDwellBySection(string $from, string $to): array
    {
        return Database::instance()->select(
            'SELECT sec.section_code AS label, gl.grade_level_code,
                    ROUND(AVG(ar.duration_minutes), 1) AS average_dwell,
                    MIN(ar.duration_minutes) AS min_dwell,
                    MAX(ar.duration_minutes) AS max_dwell,
                    COUNT(*) AS samples
               FROM attendance_records ar
               JOIN sections sec    ON sec.section_id = ar.section_id
               JOIN grade_levels gl ON gl.grade_level_id = ar.grade_level_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to AND ar.duration_minutes IS NOT NULL
              GROUP BY sec.section_id, sec.section_code, gl.grade_level_code
              ORDER BY average_dwell ASC',
            ['from' => $from, 'to' => $to]
        );
    }

    /** Left-early trend (Part 16.10). @return list<array<string,mixed>> */
    public static function leftEarlyTrend(string $from, string $to): array
    {
        return Database::instance()->select(
            "SELECT s.session_date AS date,
                    SUM(CASE WHEN ar.final_status = 'Left Early' THEN 1 ELSE 0 END) AS left_early,
                    COUNT(*) AS total,
                    ROUND(SUM(CASE WHEN ar.final_status = 'Left Early' THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 2) AS percentage
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to
              GROUP BY s.session_date
              ORDER BY s.session_date",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Incomplete attendance by subject (Part 16.10). @return list<array<string,mixed>> */
    public static function incompleteBySubject(string $from, string $to): array
    {
        return Database::instance()->select(
            "SELECT sub.subject_code AS label, sub.subject_name,
                    SUM(CASE WHEN ar.final_status = 'Incomplete' THEN 1 ELSE 0 END) AS incomplete,
                    SUM(ar.auto_generated_time_out) AS auto_closed,
                    COUNT(*) AS total
               FROM attendance_records ar
               JOIN subjects sub ON sub.subject_id = ar.subject_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to
              GROUP BY sub.subject_id, sub.subject_code, sub.subject_name
             HAVING incomplete > 0
              ORDER BY incomplete DESC",
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Time-in distribution histogram — shows arrival clustering, which is the
     * quickest way to tell whether the late threshold is set sensibly.
     *
     * @return list<array<string,mixed>>
     */
    public static function timeInDistribution(string $from, string $to): array
    {
        return Database::instance()->select(
            "SELECT
                CONCAT(
                  LPAD(FLOOR(TIMESTAMPDIFF(MINUTE, s.scheduled_start, ar.time_in) / 5) * 5, 3, ' '),
                  ' min'
                ) AS label,
                FLOOR(TIMESTAMPDIFF(MINUTE, s.scheduled_start, ar.time_in) / 5) * 5 AS offset_minutes,
                COUNT(*) AS total
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to
                AND ar.time_in IS NOT NULL
                AND TIMESTAMPDIFF(MINUTE, s.scheduled_start, ar.time_in) BETWEEN -30 AND 60
              GROUP BY offset_minutes
              ORDER BY offset_minutes",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Time-out compliance: how often students actually tap out. @return array<string,mixed> */
    public static function timeOutCompliance(string $from, string $to): array
    {
        $row = Database::instance()->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ar.time_out IS NOT NULL AND ar.auto_generated_time_out = 0 THEN 1 ELSE 0 END) AS manual_out,
                SUM(ar.auto_generated_time_out) AS auto_out,
                SUM(CASE WHEN ar.departure_status = 'no_time_out' THEN 1 ELSE 0 END) AS missing
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date BETWEEN :from AND :to AND ar.time_in IS NOT NULL",
            ['from' => $from, 'to' => $to]
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);

        return [
            'total'        => $total,
            'manual_out'   => (int) ($row['manual_out'] ?? 0),
            'auto_out'     => (int) ($row['auto_out'] ?? 0),
            'missing'      => (int) ($row['missing'] ?? 0),
            'compliance_percentage' => $total === 0
                ? 0.0
                : round((int) ($row['manual_out'] ?? 0) / $total * 100, 1),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function monthlyTrend(int $months = 12): array
    {
        return Database::instance()->select(
            "SELECT DATE_FORMAT(s.session_date, '%Y-%m') AS label,
                    COUNT(*) AS total,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage,
                    SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent
               FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE s.session_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
              GROUP BY label
              ORDER BY label",
            ['months' => max(1, min($months, 60))]
        );
    }

    /** Device uptime derived from heartbeat density. @return list<array<string,mixed>> */
    public static function deviceUptime(int $days = 7): array
    {
        return Database::instance()->select(
            'SELECT d.device_id AS label, c.room_number,
                    COUNT(h.heartbeat_id) AS heartbeats,
                    ROUND(COUNT(h.heartbeat_id)
                          / NULLIF((:days * 24 * 3600) / NULLIF(d.heartbeat_interval_sec, 0), 0) * 100, 1) AS uptime_percentage,
                    ROUND(AVG(h.wifi_signal), 0) AS average_signal,
                    MAX(h.queue_depth) AS peak_queue
               FROM devices d
               LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
               LEFT JOIN device_heartbeats h ON h.device_row_id = d.id
                    AND h.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
              WHERE d.deleted_at IS NULL AND d.status <> \'decommissioned\'
              GROUP BY d.id, d.device_id, c.room_number, d.heartbeat_interval_sec
              ORDER BY uptime_percentage ASC',
            ['days' => max(1, min($days, 90))]
        );
    }

    /** Synchronisation and authentication success rates. @return array<string,mixed> */
    public static function reliabilityMetrics(int $days = 7): array
    {
        $db = Database::instance();

        $sync = $db->selectOne(
            "SELECT
                SUM(CASE WHEN event = 'sync_completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN event = 'sync_failed' THEN 1 ELSE 0 END) AS failed
               FROM device_logs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $days]
        ) ?? [];

        $fingerprint = $db->selectOne(
            "SELECT
                SUM(CASE WHEN result = 'verified' THEN 1 ELSE 0 END) AS verified,
                COUNT(*) AS attempts
               FROM fingerprint_logs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                AND result NOT IN ('enrolled','deleted')",
            ['days' => $days]
        ) ?? [];

        $rfid = $db->selectOne(
            "SELECT
                SUM(CASE WHEN result = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                COUNT(*) AS total
               FROM rfid_logs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $days]
        ) ?? [];

        $syncTotal = (int) ($sync['completed'] ?? 0) + (int) ($sync['failed'] ?? 0);
        $fpTotal   = (int) ($fingerprint['attempts'] ?? 0);
        $rfidTotal = (int) ($rfid['total'] ?? 0);

        return [
            'sync_success_rate' => $syncTotal === 0 ? null : round((int) $sync['completed'] / $syncTotal * 100, 1),
            'fingerprint_success_rate' => $fpTotal === 0 ? null : round((int) $fingerprint['verified'] / $fpTotal * 100, 1),
            'rfid_acceptance_rate' => $rfidTotal === 0 ? null : round((int) $rfid['accepted'] / $rfidTotal * 100, 1),
            'sync_failures'  => (int) ($sync['failed'] ?? 0),
            'fingerprint_failures' => $fpTotal - (int) ($fingerprint['verified'] ?? 0),
            'rfid_rejections' => $rfidTotal - (int) ($rfid['accepted'] ?? 0),
        ];
    }

    /** RFID rejection breakdown, so the top cause is obvious. @return list<array<string,mixed>> */
    public static function rejectionBreakdown(int $days = 7): array
    {
        return Database::instance()->select(
            "SELECT result AS label, COUNT(*) AS total
               FROM rfid_logs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                AND result <> 'accepted'
              GROUP BY result
              ORDER BY total DESC",
            ['days' => max(1, min($days, 90))]
        );
    }

    /**
     * Students below the configured attendance threshold, grouped by section
     * (Part 15.5, Chronic Absence by Section).
     *
     * @return list<array<string,mixed>>
     */
    public static function chronicAbsence(
        string $from,
        string $to,
        ?float $threshold = null,
        ?int $gradeLevelId = null,
        ?int $sectionId = null
    ): array {
        $threshold = $threshold ?? (float) Config::get('attendance.chronic_absence_threshold_percent', 80.0);

        $where    = ['s.session_date BETWEEN :from AND :to'];
        $bindings = ['from' => $from, 'to' => $to, 'threshold' => $threshold];

        if ($gradeLevelId !== null) {
            $where[]           = 'ar.grade_level_id = :grade';
            $bindings['grade'] = $gradeLevelId;
        }

        if ($sectionId !== null) {
            $where[]             = 'ar.section_id = :section';
            $bindings['section'] = $sectionId;
        }

        return Database::instance()->select(
            "SELECT st.student_id, st.student_number,
                    CONCAT(st.last_name, ', ', st.first_name) AS student_name,
                    sec.section_code, sec.section_name, gl.grade_level_code,
                    CONCAT(adv.last_name, ', ', adv.first_name) AS adviser_name,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absences,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM attendance_records ar
               JOIN students st     ON st.student_id = ar.student_id
               JOIN sections sec    ON sec.section_id = ar.section_id
               JOIN grade_levels gl ON gl.grade_level_id = ar.grade_level_id
               LEFT JOIN teachers adv ON adv.teacher_id = sec.adviser_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY st.student_id, st.student_number, st.last_name, st.first_name,
                       sec.section_code, sec.section_name, gl.grade_level_code, adv.last_name, adv.first_name
             HAVING percentage < :threshold
              ORDER BY percentage ASC, sec.section_code",
            $bindings
        );
    }

    /** Section ranking within a grade level (Part 15.5, Section Comparison). @return list<array<string,mixed>> */
    public static function sectionComparison(int $gradeLevelId, string $from, string $to): array
    {
        return self::attendanceBySection($from, $to, $gradeLevelId);
    }
}
