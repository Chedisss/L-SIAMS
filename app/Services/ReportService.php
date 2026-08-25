<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\Export\CsvWriter;
use App\Services\Export\PdfWriter;
use App\Services\Export\XlsxWriter;

/**
 * Report generation (Part 8 Phase 8, extended by Part 15.5).
 *
 * Every report resolves to a {title, headers, rows, statistics} structure, and
 * the three exporters consume that same structure. Adding a report therefore
 * means writing one query, not three renderers.
 */
final class ReportService
{
    public const TYPES = [
        'daily'                 => 'Daily Attendance',
        'weekly'                => 'Weekly Attendance',
        'monthly'               => 'Monthly Attendance',
        'student'               => 'Student Attendance',
        'teacher'               => 'Teacher Attendance',
        'subject'               => 'Subject Attendance',
        'section_daily'         => 'Section Daily Attendance Sheet',
        'section_summary'       => 'Section Attendance Summary',
        'section_comparison'    => 'Section Comparison',
        'adviser'               => 'Adviser Report',
        'section_roster_rfid'   => 'Section Roster with RFID Status',
        'chronic_absence'       => 'Chronic Absence by Section',
        'device_uptime'         => 'Device Uptime',
        'audit'                 => 'Audit Log',
        'security'              => 'Security Log',
    ];

    /**
     * Build a report dataset.
     *
     * @param  array<string,mixed> $filters
     * @return array{title:string,subtitle:string,headers:list<string>,rows:list<array<int,mixed>>,statistics:array<string,mixed>,meta:array<string,string>}
     */
    public static function build(string $type, array $filters): array
    {
        if (!array_key_exists($type, self::TYPES)) {
            throw new ValidationException(['type' => ['Unknown report type.']]);
        }

        $from = (string) ($filters['date_from'] ?? Clock::now()->modify('-30 days')->format('Y-m-d'));
        $to   = (string) ($filters['date_to'] ?? Clock::today());

        return match ($type) {
            'daily', 'weekly', 'monthly' => self::attendanceReport($type, $filters, $from, $to),
            'student'                    => self::studentReport($filters, $from, $to),
            'teacher'                    => self::teacherReport($filters, $from, $to),
            'subject'                    => self::subjectReport($filters, $from, $to),
            'section_daily'              => self::sectionDailySheet($filters),
            'section_summary'            => self::sectionSummary($filters, $from, $to),
            'section_comparison'         => self::sectionComparison($filters, $from, $to),
            'adviser'                    => self::adviserReport($filters, $from, $to),
            'section_roster_rfid'        => self::sectionRosterRfid($filters),
            'chronic_absence'            => self::chronicAbsence($filters, $from, $to),
            'device_uptime'              => self::deviceUptime($filters),
            'audit'                      => self::auditReport($filters, $from, $to),
            'security'                   => self::securityReport($filters, $from, $to),
        };
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function attendanceReport(string $type, array $filters, string $from, string $to): array
    {
        if ($type === 'daily') {
            $from = $to = (string) ($filters['date'] ?? Clock::today());
        } elseif ($type === 'weekly') {
            $from = Clock::parse($to)->modify('-6 days')->format('Y-m-d');
        } elseif ($type === 'monthly') {
            $from = Clock::parse($to)->modify('first day of this month')->format('Y-m-d');
            $to   = Clock::parse($to)->modify('last day of this month')->format('Y-m-d');
        }

        $queryFilters             = $filters;
        $queryFilters['date_from'] = $from;
        $queryFilters['date_to']   = $to;
        unset($queryFilters['date']);

        [$whereSql, $bindings] = AttendanceQueryService::buildFilters($queryFilters);

        $rows = Database::instance()->select(
            "SELECT v.attendance_date, v.student_number, v.student_name, v.section_code,
                    v.grade_level_code, v.subject_code, v.teacher_name, v.room_number,
                    v.time_in, v.time_out, v.duration_minutes, v.final_status, v.rfid_uid
               FROM v_attendance_detail v
              WHERE {$whereSql}
              ORDER BY v.attendance_date DESC, v.section_code, v.student_name
              LIMIT 20000",
            $bindings
        );

        return [
            'title'    => self::TYPES[$type],
            'subtitle' => $from === $to ? 'For ' . self::humanDate($from) : self::humanDate($from) . ' – ' . self::humanDate($to),
            'headers'  => ['Date', 'Student No.', 'Student', 'Section', 'Grade', 'Subject', 'Teacher', 'Room', 'Time In', 'Time Out', 'Duration', 'Status'],
            'rows'     => array_map(static fn (array $r): array => [
                self::humanDate((string) $r['attendance_date']),
                $r['student_number'],
                $r['student_name'],
                $r['section_code'],
                $r['grade_level_code'],
                $r['subject_code'],
                $r['teacher_name'],
                $r['room_number'],
                self::humanTime($r['time_in']),
                self::humanTime($r['time_out']),
                $r['duration_minutes'] === null ? '—' : $r['duration_minutes'] . ' min',
                $r['final_status'],
            ], $rows),
            'statistics' => self::statisticsFor($rows, 'final_status'),
            'meta'       => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function studentReport(array $filters, string $from, string $to): array
    {
        $studentId = (int) ($filters['student_id'] ?? 0);

        if ($studentId <= 0) {
            throw new ValidationException(['student_id' => ['Select a student for this report.']]);
        }

        $student = StudentService::find($studentId);

        if ($student === null) {
            throw new ValidationException(['student_id' => ['Student not found.']]);
        }

        $rows = Database::instance()->select(
            'SELECT v.attendance_date, v.subject_code, v.subject_name, v.teacher_name, v.room_number,
                    v.time_in, v.time_out, v.duration_minutes, v.final_status, v.section_code
               FROM v_attendance_detail v
              WHERE v.student_id = :student AND v.attendance_date BETWEEN :from AND :to
              ORDER BY v.attendance_date DESC, v.time_in DESC',
            ['student' => $studentId, 'from' => $from, 'to' => $to]
        );

        $summary = StudentService::attendanceSummary($studentId, 3650);

        return [
            'title'    => 'Student Attendance Report',
            'subtitle' => sprintf(
                '%s, %s  ·  %s  ·  %s',
                $student['last_name'],
                $student['first_name'],
                $student['student_number'],
                $student['section_code']
            ),
            'headers' => ['Date', 'Subject', 'Teacher', 'Room', 'Section', 'Time In', 'Time Out', 'Duration', 'Status'],
            'rows'    => array_map(static fn (array $r): array => [
                self::humanDate((string) $r['attendance_date']),
                $r['subject_code'],
                $r['teacher_name'],
                $r['room_number'],
                $r['section_code'],
                self::humanTime($r['time_in']),
                self::humanTime($r['time_out']),
                $r['duration_minutes'] === null ? '—' : $r['duration_minutes'] . ' min',
                $r['final_status'],
            ], $rows),
            'statistics' => [
                'Records'    => count($rows),
                'Present'    => $summary['present'],
                'Late'       => $summary['late'],
                'Absent'     => $summary['absent'],
                'Attendance' => $summary['percentage'] . '%',
            ],
            'meta' => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function teacherReport(array $filters, string $from, string $to): array
    {
        $teacherId = (int) ($filters['teacher_id'] ?? 0);

        if ($teacherId <= 0) {
            throw new ValidationException(['teacher_id' => ['Select a teacher for this report.']]);
        }

        $teacher = TeacherService::find($teacherId);

        $rows = Database::instance()->select(
            "SELECT s.session_date, s.session_code, sub.subject_code, sec.section_code, c.room_number,
                    s.opened_at, s.closed_at, s.roster_count, s.present_count, s.late_count,
                    s.absent_count, s.incomplete_count, s.attendance_percentage, s.rejected_tap_count
               FROM attendance_sessions s
               JOIN subjects sub ON sub.subject_id = s.subject_id
               JOIN sections sec ON sec.section_id = s.section_id
               JOIN classrooms c ON c.classroom_id = s.classroom_id
              WHERE s.teacher_id = :teacher AND s.session_date BETWEEN :from AND :to
              ORDER BY s.session_date DESC, s.opened_at DESC",
            ['teacher' => $teacherId, 'from' => $from, 'to' => $to]
        );

        $totalRoster  = array_sum(array_map(static fn (array $r): int => (int) $r['roster_count'], $rows));
        $totalPresent = array_sum(array_map(
            static fn (array $r): int => (int) $r['present_count'] + (int) $r['late_count'],
            $rows
        ));

        return [
            'title'    => 'Teacher Attendance Report',
            'subtitle' => sprintf(
                '%s %s  ·  %s  ·  %s',
                $teacher['first_name'] ?? '',
                $teacher['last_name'] ?? '',
                $teacher['employee_number'] ?? '',
                $teacher['department_name'] ?? ''
            ),
            'headers' => ['Date', 'Session', 'Subject', 'Section', 'Room', 'Opened', 'Closed', 'Roster', 'Present', 'Late', 'Absent', 'Rejected', '%'],
            'rows'    => array_map(static fn (array $r): array => [
                self::humanDate((string) $r['session_date']),
                $r['session_code'],
                $r['subject_code'],
                $r['section_code'],
                $r['room_number'],
                self::humanTime($r['opened_at']),
                self::humanTime($r['closed_at']),
                $r['roster_count'],
                $r['present_count'],
                $r['late_count'],
                $r['absent_count'],
                $r['rejected_tap_count'],
                $r['attendance_percentage'] === null ? '—' : $r['attendance_percentage'] . '%',
            ], $rows),
            'statistics' => [
                'Sessions'   => count($rows),
                'Students'   => $totalRoster,
                'Attended'   => $totalPresent,
                'Attendance' => $totalRoster === 0 ? '0%' : round($totalPresent / $totalRoster * 100, 1) . '%',
            ],
            'meta' => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function subjectReport(array $filters, string $from, string $to): array
    {
        $subjectId    = (int) ($filters['subject_id'] ?? 0) ?: null;
        $gradeLevelId = (int) ($filters['grade_level_id'] ?? 0) ?: null;
        $sectionId    = (int) ($filters['section_id'] ?? 0) ?: null;

        // Naming one subject changes the question being asked. Across all
        // subjects the useful answer is a league table — one row each, worst
        // first. For a single subject that table is one row and says almost
        // nothing, so the report becomes what somebody choosing a subject
        // actually wanted: that subject, day by day.
        if ($subjectId !== null) {
            return self::subjectByDateReport($filters, $from, $to, $subjectId, $gradeLevelId, $sectionId);
        }

        $rows = AnalyticsService::attendanceBySubject($from, $to, null, $gradeLevelId);

        return [
            'title'    => 'Subject Attendance Report',
            'subtitle' => self::humanDate($from) . ' – ' . self::humanDate($to),
            'headers'  => ['Subject', 'Name', 'Department', 'Records', 'Incomplete', 'Attendance %'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['label'],
                $r['subject_name'],
                $r['department_name'],
                $r['total'],
                $r['incomplete'],
                ($r['percentage'] ?? 0) . '%',
            ], $rows),
            'statistics' => ['Subjects' => count($rows)],
            'meta'       => self::describeFilters($filters, $from, $to),
        ];
    }

    /**
     * One subject, day by day — the report that is per date AND per subject.
     *
     * Every other attendance report answers one or the other: the daily report
     * is one date across all subjects, the subject report is one range summed
     * per subject. Neither answers "how did Mathematics 10 do, day by day, this
     * month", which is the question a teacher or head of department asks first.
     *
     * @param  array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private static function subjectByDateReport(
        array $filters,
        string $from,
        string $to,
        int $subjectId,
        ?int $gradeLevelId,
        ?int $sectionId
    ): array {
        $subject = Database::instance()->selectOne(
            'SELECT subject_code, subject_name FROM subjects WHERE subject_id = :id',
            ['id' => $subjectId]
        );

        if ($subject === null) {
            throw new ValidationException(['subject_id' => ['Subject not found.']]);
        }

        $rows = AnalyticsService::subjectByDate($from, $to, $subjectId, $gradeLevelId, $sectionId);

        $records = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $rows));
        $present = array_sum(array_map(
            static fn (array $r): int => (int) $r['present'] + (int) $r['late'] + (int) $r['left_early'],
            $rows
        ));

        return [
            'title'    => sprintf('%s — Attendance by Date', $subject['subject_code']),
            'subtitle' => sprintf(
                '%s  ·  %s – %s',
                $subject['subject_name'],
                self::humanDate($from),
                self::humanDate($to)
            ),
            'headers' => ['Date', 'Section', 'Teacher', 'Roster', 'Present', 'Late', 'Left Early', 'Absent', 'Attendance %'],
            'rows'    => array_map(static fn (array $r): array => [
                self::humanDate((string) $r['attendance_date']),
                $r['section_code'],
                $r['teacher_name'],
                $r['total'],
                $r['present'],
                $r['late'],
                $r['left_early'],
                $r['absent'],
                ($r['percentage'] ?? 0) . '%',
            ], $rows),
            'statistics' => [
                'Class meetings' => count($rows),
                'Records'        => $records,
                'Attendance'     => ($records === 0 ? 0 : round($present / $records * 100, 1)) . '%',
            ],
            'meta' => self::describeFilters($filters, $from, $to),
        ];
    }

    /**
     * Section Daily Attendance Sheet (Part 15.5) — the full roster for one
     * section on one date, including students with no record at all.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private static function sectionDailySheet(array $filters): array
    {
        $sectionId = (int) ($filters['section_id'] ?? 0);
        $date      = (string) ($filters['date'] ?? Clock::today());

        if ($sectionId <= 0) {
            throw new ValidationException(['section_id' => ['Select a section for this report.']]);
        }

        $section = AcademicStructureService::findSection($sectionId);

        if ($section === null) {
            throw new ValidationException(['section_id' => ['Section not found.']]);
        }

        // LEFT JOIN from the roster so an absent student who never tapped still
        // appears — a sheet that silently omits them would be worse than useless.
        $rows = Database::instance()->select(
            "SELECT st.student_number,
                    CONCAT(st.last_name, ', ', st.first_name) AS student_name,
                    rc.card_uid,
                    ar.time_in, ar.time_out, ar.duration_minutes, ar.final_status,
                    ar.auto_generated_time_out, sub.subject_code
               FROM students st
               LEFT JOIN rfid_cards rc ON rc.student_id = st.student_id AND rc.status = 'active'
               LEFT JOIN attendance_records ar ON ar.student_id = st.student_id
                    AND ar.session_id IN (
                        SELECT session_id FROM attendance_sessions
                         WHERE section_id = :section AND session_date = :date
                    )
               LEFT JOIN subjects sub ON sub.subject_id = ar.subject_id
              WHERE st.section_id = :section AND st.deleted_at IS NULL AND st.status = 'active'
              ORDER BY st.last_name, st.first_name, sub.subject_code",
            ['section' => $sectionId, 'date' => $date]
        );

        return [
            'title'    => 'Section Daily Attendance Sheet',
            'subtitle' => sprintf(
                '%s — %s  ·  %s  ·  Adviser: %s',
                $section['section_code'],
                $section['section_name'],
                self::humanDate($date),
                $section['adviser_name'] ?? 'unassigned'
            ),
            'headers' => ['Student No.', 'Student', 'Card UID', 'Subject', 'Time In', 'Time Out', 'Duration', 'Status'],
            'rows'    => array_map(static fn (array $r): array => [
                $r['student_number'],
                $r['student_name'],
                $r['card_uid'] ?? 'no card',
                $r['subject_code'] ?? '—',
                self::humanTime($r['time_in']),
                (int) ($r['auto_generated_time_out'] ?? 0) === 1
                    ? self::humanTime($r['time_out']) . ' (auto)'
                    : self::humanTime($r['time_out']),
                $r['duration_minutes'] === null ? '—' : $r['duration_minutes'] . ' min',
                $r['final_status'] ?? 'No record',
            ], $rows),
            'statistics' => self::statisticsFor($rows, 'final_status'),
            'meta'       => ['Section' => (string) $section['section_code'], 'Date' => self::humanDate($date)],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function sectionSummary(array $filters, string $from, string $to): array
    {
        $rows = AnalyticsService::attendanceBySection(
            $from,
            $to,
            empty($filters['grade_level_id']) ? null : (int) $filters['grade_level_id'],
            // The form offers a Section filter for this report and it was
            // going nowhere: picking one section still returned every section.
            empty($filters['section_id']) ? null : (int) $filters['section_id']
        );

        return [
            'title'    => 'Section Attendance Summary',
            'subtitle' => self::humanDate($from) . ' – ' . self::humanDate($to),
            'headers'  => ['Section', 'Name', 'Grade', 'Records', 'Present', 'Late', 'Absent', 'Attendance %'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['label'],
                $r['section_name'],
                $r['grade_level_code'],
                $r['total'],
                $r['present'],
                $r['late'],
                $r['absent'],
                ($r['percentage'] ?? 0) . '%',
            ], $rows),
            'statistics' => [
                'Sections' => count($rows),
                'Records'  => array_sum(array_map(static fn (array $r): int => (int) $r['total'], $rows)),
            ],
            'meta' => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function sectionComparison(array $filters, string $from, string $to): array
    {
        $gradeLevelId = (int) ($filters['grade_level_id'] ?? 0);

        if ($gradeLevelId <= 0) {
            throw new ValidationException(['grade_level_id' => ['Select a grade level to compare its sections.']]);
        }

        $rows  = AnalyticsService::sectionComparison($gradeLevelId, $from, $to);
        $grade = Database::instance()->selectOne(
            'SELECT grade_level_name FROM grade_levels WHERE grade_level_id = :id',
            ['id' => $gradeLevelId]
        );

        $ranked = [];
        foreach ($rows as $index => $row) {
            $ranked[] = [
                $index + 1,
                $row['label'],
                $row['section_name'],
                $row['total'],
                $row['present'],
                $row['late'],
                $row['absent'],
                ($row['percentage'] ?? 0) . '%',
            ];
        }

        return [
            'title'    => 'Section Comparison',
            'subtitle' => sprintf('%s  ·  %s – %s', $grade['grade_level_name'] ?? '', self::humanDate($from), self::humanDate($to)),
            'headers'  => ['Rank', 'Section', 'Name', 'Records', 'Present', 'Late', 'Absent', 'Attendance %'],
            'rows'     => $ranked,
            'statistics' => [
                'Sections' => count($rows),
                'Best'     => $rows === [] ? '—' : ($rows[0]['label'] . ' ' . $rows[0]['percentage'] . '%'),
                'Lowest'   => $rows === [] ? '—' : (end($rows)['label'] . ' ' . end($rows)['percentage'] . '%'),
            ],
            'meta' => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function adviserReport(array $filters, string $from, string $to): array
    {
        $adviserId = (int) ($filters['teacher_id'] ?? 0);

        if ($adviserId <= 0) {
            throw new ValidationException(['teacher_id' => ['Select an adviser for this report.']]);
        }

        $adviser = TeacherService::find($adviserId);

        $rows = Database::instance()->select(
            "SELECT sec.section_code, st.student_number,
                    CONCAT(st.last_name, ', ', st.first_name) AS student_name,
                    sub.subject_code,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN ar.final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN ar.final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                    ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early','Incomplete') THEN 1 ELSE 0 END)
                          / NULLIF(COUNT(*),0) * 100, 1) AS percentage
               FROM sections sec
               JOIN students st ON st.section_id = sec.section_id AND st.deleted_at IS NULL
               JOIN attendance_records ar ON ar.student_id = st.student_id
               JOIN subjects sub ON sub.subject_id = ar.subject_id
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE sec.adviser_id = :adviser AND s.session_date BETWEEN :from AND :to
              GROUP BY sec.section_code, st.student_id, st.student_number, st.last_name, st.first_name, sub.subject_code
              ORDER BY sec.section_code, st.last_name, sub.subject_code",
            ['adviser' => $adviserId, 'from' => $from, 'to' => $to]
        );

        return [
            'title'    => 'Adviser Report',
            'subtitle' => sprintf(
                '%s %s  ·  %s – %s',
                $adviser['first_name'] ?? '',
                $adviser['last_name'] ?? '',
                self::humanDate($from),
                self::humanDate($to)
            ),
            'headers' => ['Section', 'Student No.', 'Student', 'Subject', 'Records', 'Present', 'Late', 'Absent', 'Attendance %'],
            'rows'    => array_map(static fn (array $r): array => [
                $r['section_code'],
                $r['student_number'],
                $r['student_name'],
                $r['subject_code'],
                $r['total'],
                $r['present'],
                $r['late'],
                $r['absent'],
                ($r['percentage'] ?? 0) . '%',
            ], $rows),
            'statistics' => ['Rows' => count($rows)],
            'meta'       => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function sectionRosterRfid(array $filters): array
    {
        $sectionId = (int) ($filters['section_id'] ?? 0);

        if ($sectionId <= 0) {
            throw new ValidationException(['section_id' => ['Select a section for this report.']]);
        }

        $section = AcademicStructureService::findSection($sectionId);
        $roster  = AcademicStructureService::sectionRoster($sectionId);

        $withCard = count(array_filter($roster, static fn (array $r): bool => ($r['rfid_status'] ?? null) === 'active'));

        return [
            'title'    => 'Section Roster with RFID Status',
            'subtitle' => sprintf('%s — %s', $section['section_code'] ?? '', $section['section_name'] ?? ''),
            'headers'  => ['Student No.', 'Student', 'Card UID', 'Card Status', 'Issued', 'Guardian', 'Contact', 'Attendance %'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['student_number'],
                sprintf('%s, %s', $r['last_name'], $r['first_name']),
                $r['card_uid'] ?? '—',
                $r['rfid_status'] ?? 'No card',
                self::humanDate($r['issue_date'] ?? null),
                $r['guardian_name'],
                $r['guardian_contact'],
                ($r['attendance_percentage'] ?? 0) . '%',
            ], $roster),
            'statistics' => [
                'Students'    => count($roster),
                'With card'   => $withCard,
                'Missing card' => count($roster) - $withCard,
                'Capacity'    => sprintf('%d/%d', (int) ($section['enrolled_count'] ?? 0), (int) ($section['capacity'] ?? 0)),
            ],
            'meta' => ['Section' => (string) ($section['section_code'] ?? '')],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function chronicAbsence(array $filters, string $from, string $to): array
    {
        $threshold = isset($filters['threshold'])
            ? (float) $filters['threshold']
            : (float) Config::get('attendance.chronic_absence_threshold_percent', 80.0);

        // Grade level and section were both advertised on the form and both
        // discarded here, so "chronic absence in Grade 7" returned the whole
        // school and looked like the whole school was in trouble.
        $rows = AnalyticsService::chronicAbsence(
            $from,
            $to,
            $threshold,
            empty($filters['grade_level_id']) ? null : (int) $filters['grade_level_id'],
            empty($filters['section_id']) ? null : (int) $filters['section_id']
        );

        return [
            'title'    => 'Chronic Absence by Section',
            'subtitle' => sprintf(
                'Students below %.1f%% attendance  ·  %s – %s',
                $threshold,
                self::humanDate($from),
                self::humanDate($to)
            ),
            'headers' => ['Section', 'Grade', 'Student No.', 'Student', 'Adviser', 'Records', 'Absences', 'Attendance %'],
            'rows'    => array_map(static fn (array $r): array => [
                $r['section_code'],
                $r['grade_level_code'],
                $r['student_number'],
                $r['student_name'],
                $r['adviser_name'] ?? '—',
                $r['total'],
                $r['absences'],
                ($r['percentage'] ?? 0) . '%',
            ], $rows),
            'statistics' => [
                'Students flagged' => count($rows),
                'Threshold'        => $threshold . '%',
            ],
            'meta' => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function deviceUptime(array $filters): array
    {
        $days = (int) ($filters['days'] ?? 7);
        $rows = AnalyticsService::deviceUptime($days);

        return [
            'title'    => 'Device Uptime Report',
            'subtitle' => sprintf('Last %d day(s)', $days),
            'headers'  => ['Device', 'Room', 'Heartbeats', 'Uptime %', 'Avg Signal (dBm)', 'Peak Queue'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['label'],
                $r['room_number'] ?? '—',
                $r['heartbeats'],
                ($r['uptime_percentage'] ?? 0) . '%',
                $r['average_signal'] ?? '—',
                $r['peak_queue'] ?? 0,
            ], $rows),
            'statistics' => ['Devices' => count($rows)],
            'meta'       => ['Window' => $days . ' days'],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function auditReport(array $filters, string $from, string $to): array
    {
        $result = AuditService::paginate(
            ['date_from' => $from, 'date_to' => $to] + $filters,
            1,
            10000
        );

        return [
            'title'    => 'Audit Log',
            'subtitle' => self::humanDate($from) . ' – ' . self::humanDate($to),
            'headers'  => ['Timestamp', 'User', 'Role', 'Action', 'Module', 'Record', 'IP', 'Result'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['created_at'],
                $r['actor_label'] ?? $r['username'] ?? 'SYSTEM',
                $r['role_name'] ?? '—',
                $r['action'],
                $r['module'],
                trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? '')),
                $r['ip_address'] ?? '—',
                $r['result'],
            ], $result['rows']),
            'statistics' => ['Entries' => $result['total']],
            'meta'       => self::describeFilters($filters, $from, $to),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private static function securityReport(array $filters, string $from, string $to): array
    {
        $result = SecurityLogService::paginate(
            ['date_from' => $from, 'date_to' => $to] + $filters,
            1,
            10000
        );

        return [
            'title'    => 'Security Log',
            'subtitle' => self::humanDate($from) . ' – ' . self::humanDate($to),
            'headers'  => ['Timestamp', 'Severity', 'Event', 'Description', 'Source IP', 'Device', 'Status'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['created_at'],
                strtoupper((string) $r['severity']),
                $r['event'],
                $r['description'],
                $r['source_ip'] ?? '—',
                $r['device_public_id'] ?? '—',
                $r['resolution_status'],
            ], $result['rows']),
            'statistics' => ['Events' => $result['total']],
            'meta'       => self::describeFilters($filters, $from, $to),
        ];
    }

    // ---------------------------------------------------------- exports --

    /**
     * Render a built report to bytes.
     *
     * @param  array<string,mixed> $report
     * @return array{content:string,filename:string,mime:string}
     */
    public static function export(array $report, string $format): array
    {
        $slug      = self::slug((string) $report['title']);
        $timestamp = Clock::now()->format('Ymd-His');

        return match ($format) {
            'csv' => [
                'content'  => CsvWriter::build($report['headers'], $report['rows']),
                'filename' => sprintf('%s-%s.csv', $slug, $timestamp),
                'mime'     => 'text/csv; charset=UTF-8',
            ],
            'xlsx' => [
                'content'  => XlsxWriter::build($report['headers'], $report['rows'], mb_substr((string) $report['title'], 0, 31), (string) $report['title']),
                'filename' => sprintf('%s-%s.xlsx', $slug, $timestamp),
                'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'pdf' => [
                'content'  => PdfWriter::table(
                    (string) $report['title'],
                    $report['headers'],
                    $report['rows'],
                    (string) $report['subtitle'],
                    $report['meta'],
                    $report['statistics'],
                    SettingsService::schoolName()
                ),
                'filename' => sprintf('%s-%s.pdf', $slug, $timestamp),
                'mime'     => 'application/pdf',
            ],
            default => throw new ValidationException(['format' => ['Choose CSV, Excel or PDF.']]),
        };
    }

    /**
     * Persist a generated report and record it.
     *
     * Files land in storage/reports, which sits outside the web root — they are
     * served back through an authenticated controller action, never by direct
     * URL, so an attendance export cannot be guessed at over HTTP.
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $parameters
     */
    public static function persist(array $report, string $format, array $parameters, int $userId): int
    {
        $rendered  = self::export($report, $format);
        $directory = (string) Config::get('app.paths.reports');

        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $filename = sprintf('%d-%s', $userId, $rendered['filename']);
        $path     = $directory . '/' . $filename;

        file_put_contents($path, $rendered['content']);
        @chmod($path, 0640);

        $reportId = (int) Database::instance()->insert('generated_reports', [
            'report_name'  => (string) $report['title'],
            'report_type'  => (string) ($parameters['type'] ?? 'custom'),
            'format'       => $format,
            'parameters'   => (string) json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'file_path'    => $filename,
            'file_size'    => strlen($rendered['content']),
            'checksum'     => hash('sha256', $rendered['content']),
            'row_count'    => count($report['rows']),
            'generated_by' => $userId,
            'created_at'   => Clock::nowString(),
        ]);

        AuditService::log(
            AuditService::REPORT_GENERATED,
            'reports',
            'generated_report',
            $reportId,
            null,
            ['type' => $parameters['type'] ?? 'custom', 'format' => $format, 'rows' => count($report['rows'])],
            sprintf('Generated "%s" as %s (%d rows).', $report['title'], strtoupper($format), count($report['rows']))
        );

        return $reportId;
    }

    /** @return array<string,mixed>|null */
    public static function findGenerated(int $reportId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT r.*, u.username FROM generated_reports r
               JOIN users u ON u.user_id = r.generated_by
              WHERE r.report_id = :id',
            ['id' => $reportId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $limit = 50): array
    {
        return Database::instance()->select(
            'SELECT r.*, u.username, u.full_name
               FROM generated_reports r
               JOIN users u ON u.user_id = r.generated_by
              ORDER BY r.report_id DESC LIMIT ' . max(1, min($limit, 200))
        );
    }

    // ---------------------------------------------------------- helpers --

    /**
     * @param  list<array<string,mixed>> $rows
     * @return array<string,int|string>
     */
    private static function statisticsFor(array $rows, string $statusKey): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $status = (string) ($row[$statusKey] ?? 'No record');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $total    = count($rows);
        $attended = ($counts['Present'] ?? 0) + ($counts['Late'] ?? 0)
            + ($counts['Left Early'] ?? 0) + ($counts['Incomplete'] ?? 0);

        return [
            'Records'    => $total,
            'Present'    => $counts['Present'] ?? 0,
            'Late'       => $counts['Late'] ?? 0,
            'Absent'     => $counts['Absent'] ?? 0,
            'Attendance' => $total === 0 ? '0%' : round($attended / $total * 100, 1) . '%',
        ];
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<string,string>
     */
    private static function describeFilters(array $filters, string $from, string $to): array
    {
        $meta = ['Period' => self::humanDate($from) . ' – ' . self::humanDate($to)];
        $db   = Database::instance();

        $lookups = [
            'section_id'     => ['sections', 'section_id', 'section_code', 'Section'],
            'grade_level_id' => ['grade_levels', 'grade_level_id', 'grade_level_name', 'Grade level'],
            'subject_id'     => ['subjects', 'subject_id', 'subject_name', 'Subject'],
            'classroom_id'   => ['classrooms', 'classroom_id', 'room_number', 'Room'],
            'department_id'  => ['departments', 'department_id', 'department_name', 'Department'],
        ];

        foreach ($lookups as $key => [$table, $idColumn, $labelColumn, $label]) {
            if (!empty($filters[$key])) {
                $value = $db->scalar(
                    sprintf('SELECT `%s` FROM `%s` WHERE `%s` = :id', $labelColumn, $table, $idColumn),
                    ['id' => (int) $filters[$key]]
                );

                if ($value !== null) {
                    $meta[$label] = (string) $value;
                }
            }
        }

        if (!empty($filters['teacher_id'])) {
            $teacher = $db->selectOne(
                "SELECT CONCAT(first_name, ' ', last_name) AS name FROM teachers WHERE teacher_id = :id",
                ['id' => (int) $filters['teacher_id']]
            );

            if ($teacher !== null) {
                $meta['Teacher'] = (string) $teacher['name'];
            }
        }

        if (!empty($filters['final_status'])) {
            $meta['Status'] = (string) $filters['final_status'];
        }

        $meta['Generated by'] = Auth::fullName();

        return $meta;
    }

    private static function humanDate(?string $date): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }

        try {
            return Clock::parse($date)->format('M j, Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    private static function humanTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return Clock::parse($value)->format('g:i A');
        } catch (\Throwable) {
            return $value;
        }
    }

    private static function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));

        return trim($slug, '-') ?: 'report';
    }
}
