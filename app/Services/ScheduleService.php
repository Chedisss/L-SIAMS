<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\AcademicStructureService;
use App\Validators\TeacherSectionAssignmentValidator;
use App\Validators\TeacherSubjectAssignmentValidator;

/**
 * Schedule creation, editing and conflict detection.
 *
 * validate() implements the ordered rule list from Part 14.3 exactly, failing
 * fast at the first violation. Every message names the conflicting record —
 * "Ms. Reyes is already assigned to G12-STEM-A in Room 204 from 08:00–09:00 on
 * Monday" — because a generic "Validation failed" gives an administrator
 * nothing to act on.
 */
final class ScheduleService
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * Run all 17 rules. Returns warnings that the administrator may override.
     *
     * @param  array<string,mixed> $data
     * @return array{warnings:list<string>}
     * @throws ValidationException
     */
    public static function validate(array $data, ?int $ignoreScheduleId = null, bool $allowOverride = false): array
    {
        $db       = Database::instance();
        $warnings = [];

        $teacherId   = (int) ($data['teacher_id'] ?? 0);
        $subjectId   = (int) ($data['subject_id'] ?? 0);
        $sectionId   = (int) ($data['section_id'] ?? 0);
        $classroomId = (int) ($data['classroom_id'] ?? 0);
        $day         = (string) ($data['day_of_week'] ?? '');
        $startTime   = self::normaliseTime((string) ($data['start_time'] ?? ''));
        $endTime     = self::normaliseTime((string) ($data['end_time'] ?? ''));

        // 1. Teacher selected
        if ($teacherId <= 0) {
            throw new ValidationException(['teacher_id' => ['Select a teacher.']]);
        }

        $teacher = $db->selectOne(
            'SELECT t.*, d.department_name,
                    CONCAT(t.first_name, \' \', t.last_name) AS display_name
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
              WHERE t.teacher_id = :id AND t.deleted_at IS NULL',
            ['id' => $teacherId]
        );

        if ($teacher === null) {
            throw new ValidationException(['teacher_id' => ['The selected teacher does not exist.']]);
        }

        // 2. Teacher is active
        if ((string) $teacher['status'] !== 'active') {
            throw new ValidationException(['teacher_id' => ['Teacher account is inactive.']]);
        }

        // 3. Teacher has a department (guaranteed by the NOT NULL FK, verified anyway)
        if ((int) $teacher['department_id'] <= 0) {
            throw new ValidationException(['teacher_id' => ['Assign a department to this teacher first.']]);
        }

        // 4. Teacher has grade level(s)
        $gradeLevels = TeacherSectionAssignmentValidator::allowedGradeLevelIds($teacherId);

        if ($gradeLevels === []) {
            throw new ValidationException(['teacher_id' => ['Assign a grade level to this teacher first.']]);
        }

        // 5. Subject ∈ teacher department  → CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED
        TeacherSubjectAssignmentValidator::assertAssignable($teacherId, [$subjectId], 'subject_id');

        // 6. Section ∈ teacher grade level → GRADE_LEVEL_MISMATCH_BLOCKED
        TeacherSectionAssignmentValidator::assertAssignable($teacherId, [$sectionId], 'section_id');

        $section = $db->selectOne(
            'SELECT sec.*, gl.grade_level_name, gl.grade_level_code
               FROM sections sec
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
              WHERE sec.section_id = :id',
            ['id' => $sectionId]
        );

        if ($section === null) {
            throw new ValidationException(['section_id' => ['The selected section does not exist.']]);
        }

        // 7. Subject is offered to that grade level
        $offered = $db->scalar(
            'SELECT 1 FROM subject_grade_levels
              WHERE subject_id = :subject AND grade_level_id = :grade LIMIT 1',
            ['subject' => $subjectId, 'grade' => (int) $section['grade_level_id']]
        );

        if ($offered === null) {
            $subject = $db->selectOne('SELECT subject_name FROM subjects WHERE subject_id = :id', ['id' => $subjectId]);

            throw new ValidationException(['subject_id' => [sprintf(
                'Subject "%s" is not offered to %s. Add the grade level to the subject first.',
                $subject['subject_name'] ?? 'selected',
                $section['grade_level_name']
            )]]);
        }

        // 8. Classroom exists and is active
        $classroom = $db->selectOne(
            'SELECT * FROM classrooms WHERE classroom_id = :id AND deleted_at IS NULL',
            ['id' => $classroomId]
        );

        if ($classroom === null) {
            throw new ValidationException(['classroom_id' => ['The selected classroom does not exist.']]);
        }

        if ((string) $classroom['status'] !== 'active') {
            throw new ValidationException(['classroom_id' => [sprintf(
                'Room %s is marked %s and cannot host a schedule.',
                $classroom['room_number'],
                $classroom['status']
            )]]);
        }

        // 9. Classroom has a registered, active ESP32 device
        $device = $db->selectOne(
            "SELECT device_id, status, claim_status FROM devices
              WHERE classroom_id = :classroom
                AND device_role IN ('both','entry')
                AND status IN ('active','offline','pending')
                AND deleted_at IS NULL
              LIMIT 1",
            ['classroom' => $classroomId]
        );

        if ($device === null) {
            // Telling somebody to register a device they have already
            // registered sends them round the same loop; the usual mistake is
            // a terminal saved with Classroom left on Unassigned.
            $gap = AcademicStructureService::classroomTerminalGap();

            throw new ValidationException(['classroom_id' => [
                $gap['reason'] === 'devices_unassigned'
                    ? sprintf('Room %s has no terminal. %s', $classroom['room_number'], $gap['message'])
                    : sprintf(
                        'Room %s has no registered attendance terminal. Register a device for this classroom first.',
                        $classroom['room_number']
                    ),
            ]]);
        }

        // 10. Classroom capacity ≥ section size (warning; overridable with a reason)
        if ((int) $classroom['capacity'] < (int) $section['enrolled_count']) {
            $message = sprintf(
                'Room %s seats %d but section %s has %d students enrolled.',
                $classroom['room_number'],
                (int) $classroom['capacity'],
                $section['section_code'],
                (int) $section['enrolled_count']
            );

            if (!$allowOverride) {
                throw new ValidationException(
                    ['classroom_id' => [$message . ' Confirm the override to continue.']],
                    $message,
                    'CLASSROOM_CAPACITY_WARNING'
                );
            }

            $warnings[] = $message;
        }

        if (!in_array($day, self::DAYS, true)) {
            throw new ValidationException(['day_of_week' => ['Select a valid day of the week.']]);
        }

        if ($startTime === null || $endTime === null || $endTime <= $startTime) {
            throw new ValidationException(['end_time' => ['End time must be after start time.']]);
        }

        // 11–14. Time conflicts.
        $conflicts = self::findConflicts($teacherId, $classroomId, $sectionId, $subjectId, $day, $startTime, $endTime, $ignoreScheduleId);

        if ($conflicts !== []) {
            throw new ValidationException(
                ['start_time' => array_map(static fn (array $c): string => (string) $c['message'], $conflicts)],
                $conflicts[0]['message'],
                'SCHEDULE_CONFLICT'
            );
        }

        // 15–17. Attendance window sanity.
        self::validateWindows($data, $startTime, $endTime);

        return ['warnings' => $warnings];
    }

    /**
     * Rules 11–14 without persisting anything — this is what
     * POST /api/schedules/check-conflict runs for the live banner.
     *
     * @return list<array{type:string,message:string,schedule_id:int}>
     */
    public static function findConflicts(
        int $teacherId,
        int $classroomId,
        int $sectionId,
        int $subjectId,
        string $day,
        string $startTime,
        string $endTime,
        ?int $ignoreScheduleId = null
    ): array {
        $db        = Database::instance();
        $conflicts = [];

        $bindings = [
            'day'   => $day,
            'start' => $startTime,
            'end'   => $endTime,
            'ignore' => $ignoreScheduleId ?? 0,
        ];

        // Two intervals overlap when each starts before the other ends. Using
        // strict inequality on both sides means back-to-back classes
        // (08:00–09:00 then 09:00–10:00) are legal, which is what schools do.
        $overlap = 'sch.day_of_week = :day
                    AND sch.status = \'active\'
                    AND sch.deleted_at IS NULL
                    AND sch.schedule_id <> :ignore
                    AND sch.start_time < :end
                    AND sch.end_time > :start';

        $selectClause = 'SELECT sch.schedule_id, sch.start_time, sch.end_time,
                                CONCAT(t.first_name, \' \', t.last_name) AS teacher_name,
                                sec.section_code, c.room_number, s.subject_code, s.subject_name
                           FROM schedules sch
                           JOIN teachers t   ON t.teacher_id = sch.teacher_id
                           JOIN sections sec ON sec.section_id = sch.section_id
                           JOIN classrooms c ON c.classroom_id = sch.classroom_id
                           JOIN subjects s   ON s.subject_id = sch.subject_id';

        // 11. Teacher cannot be in two places at once.
        $row = $db->selectOne(
            $selectClause . ' WHERE ' . $overlap . ' AND sch.teacher_id = :teacher LIMIT 1',
            $bindings + ['teacher' => $teacherId]
        );

        if ($row !== null) {
            $conflicts[] = [
                'type'        => 'teacher',
                'schedule_id' => (int) $row['schedule_id'],
                'message'     => sprintf(
                    'Schedule conflict: %s is already assigned to %s in Room %s from %s–%s on %s.',
                    $row['teacher_name'],
                    $row['section_code'],
                    $row['room_number'],
                    substr((string) $row['start_time'], 0, 5),
                    substr((string) $row['end_time'], 0, 5),
                    $day
                ),
            ];
        }

        // 12. A classroom hosts one class at a time.
        $row = $db->selectOne(
            $selectClause . ' WHERE ' . $overlap . ' AND sch.classroom_id = :classroom LIMIT 1',
            $bindings + ['classroom' => $classroomId]
        );

        if ($row !== null) {
            $conflicts[] = [
                'type'        => 'classroom',
                'schedule_id' => (int) $row['schedule_id'],
                'message'     => sprintf(
                    'Room conflict: Room %s is already booked for %s (%s) from %s–%s on %s.',
                    $row['room_number'],
                    $row['section_code'],
                    $row['subject_code'],
                    substr((string) $row['start_time'], 0, 5),
                    substr((string) $row['end_time'], 0, 5),
                    $day
                ),
            ];
        }

        // 13. A section cannot be in two places at once.
        $row = $db->selectOne(
            $selectClause . ' WHERE ' . $overlap . ' AND sch.section_id = :section LIMIT 1',
            $bindings + ['section' => $sectionId]
        );

        if ($row !== null) {
            $conflicts[] = [
                'type'        => 'section',
                'schedule_id' => (int) $row['schedule_id'],
                'message'     => sprintf(
                    'Section conflict: %s already has %s with %s in Room %s from %s–%s on %s.',
                    $row['section_code'],
                    $row['subject_code'],
                    $row['teacher_name'],
                    $row['room_number'],
                    substr((string) $row['start_time'], 0, 5),
                    substr((string) $row['end_time'], 0, 5),
                    $day
                ),
            ];
        }

        // 14. The same subject twice in one day for one section is a data-entry error.
        $row = $db->selectOne(
            $selectClause . ' WHERE sch.day_of_week = :day
                                AND sch.status = \'active\'
                                AND sch.deleted_at IS NULL
                                AND sch.schedule_id <> :ignore
                                AND sch.section_id = :section
                                AND sch.subject_id = :subject
                              LIMIT 1',
            [
                'day'     => $day,
                'ignore'  => $ignoreScheduleId ?? 0,
                'section' => $sectionId,
                'subject' => $subjectId,
            ]
        );

        if ($row !== null) {
            $conflicts[] = [
                'type'        => 'duplicate_subject',
                'schedule_id' => (int) $row['schedule_id'],
                'message'     => sprintf(
                    'Duplicate subject: %s already has %s scheduled on %s at %s.',
                    $row['section_code'],
                    $row['subject_code'],
                    $day,
                    substr((string) $row['start_time'], 0, 5)
                ),
            ];
        }

        return $conflicts;
    }

    /**
     * Rules 15–17: window sanity.
     *
     * The in and out windows must not overlap. Intent is actually resolved by
     * record state (Part 16.2), so overlapping windows would not corrupt data —
     * but they produce a confusing UI where a student cannot tell whether a tap
     * will register as arrival or departure, so they are rejected.
     *
     * @param array<string,mixed> $data
     */
    private static function validateWindows(array $data, string $startTime, string $endTime): void
    {
        $errors = [];

        $duration = (int) round(
            (strtotime('1970-01-01 ' . $endTime) - strtotime('1970-01-01 ' . $startTime)) / 60
        );

        $inOpen   = (int) ($data['time_in_window_open'] ?? 10);
        $late     = (int) ($data['late_threshold_minutes'] ?? 15);
        $inClose  = (int) ($data['time_in_window_close'] ?? 30);
        $outOpen  = (int) ($data['time_out_window_open'] ?? 10);
        $outClose = (int) ($data['time_out_window_close'] ?? 15);
        $dwell    = (int) ($data['minimum_dwell_minutes'] ?? 20);

        if ($inClose > $duration) {
            $errors['time_in_window_close'][] = sprintf(
                'Tap-in must close within the %d-minute class duration.',
                $duration
            );
        }

        if ($late >= $inClose) {
            $errors['late_threshold_minutes'][] = 'Late threshold must be earlier than the tap-in close time.';
        }

        if ($dwell >= $duration) {
            $errors['minimum_dwell_minutes'][] = sprintf(
                'Minimum dwell time must be shorter than the %d-minute class duration.',
                $duration
            );
        }

        // Tap-out opens at (end − outOpen), i.e. (duration − outOpen) minutes
        // after the start. Tap-in must have closed strictly before that.
        if ($inClose >= $duration - $outOpen) {
            $errors['time_out_window_open'][] = sprintf(
                'The tap-in window closes %d minutes after the start, but tap-out opens %d minutes after the start. The windows must not overlap.',
                $inClose,
                $duration - $outOpen
            );
        }

        foreach (['time_in_window_open' => $inOpen, 'time_out_window_close' => $outClose] as $field => $value) {
            if ($value < 0 || $value > 180) {
                $errors[$field][] = 'Window must be between 0 and 180 minutes.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Attendance window configuration is invalid.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId, bool $allowOverride = false): int
    {
        $result = self::validate($data, null, $allowOverride);

        $db = Database::instance();

        $scheduleId = (int) $db->transaction(static function (Database $db) use ($data, $userId): string {
            return $db->insert('schedules', [
                'teacher_id'     => (int) $data['teacher_id'],
                'subject_id'     => (int) $data['subject_id'],
                'section_id'     => (int) $data['section_id'],
                'classroom_id'   => (int) $data['classroom_id'],
                'school_year_id' => (int) ($data['school_year_id'] ?? SchoolYearService::currentId()),
                'day_of_week'    => (string) $data['day_of_week'],
                'start_time'     => self::normaliseTime((string) $data['start_time']),
                'end_time'       => self::normaliseTime((string) $data['end_time']),
                'time_in_window_open'    => (int) ($data['time_in_window_open'] ?? 10),
                'late_threshold_minutes' => (int) ($data['late_threshold_minutes'] ?? 15),
                'time_in_window_close'   => (int) ($data['time_in_window_close'] ?? 30),
                'time_out_window_open'   => (int) ($data['time_out_window_open'] ?? 10),
                'time_out_window_close'  => (int) ($data['time_out_window_close'] ?? 15),
                'minimum_dwell_minutes'  => (int) ($data['minimum_dwell_minutes'] ?? 20),
                'status'      => 'active',
                'created_by'  => $userId,
                'created_at'  => Clock::nowString(),
                'updated_at'  => Clock::nowString(),
            ]);
        });

        // Keep the teacher's assignment pivots consistent with the schedule
        // they were just given, so the Teacher profile is never out of date.
        self::syncTeacherAssignments((int) $data['teacher_id'], (int) $data['subject_id'], (int) $data['section_id'], $userId);

        AuditService::log(
            AuditService::SCHEDULE_CREATED,
            'schedules',
            'schedule',
            $scheduleId,
            null,
            $data,
            'Schedule created' . ($result['warnings'] === [] ? '' : ' with overrides: ' . implode('; ', $result['warnings']))
        );

        return $scheduleId;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $scheduleId, array $data, int $userId, bool $allowOverride = false): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM schedules WHERE schedule_id = :id AND deleted_at IS NULL', ['id' => $scheduleId]);

        if ($existing === null) {
            throw new ValidationException(['schedule_id' => ['Schedule not found.']]);
        }

        self::validate($data, $scheduleId, $allowOverride);

        $update = [
            'teacher_id'   => (int) $data['teacher_id'],
            'subject_id'   => (int) $data['subject_id'],
            'section_id'   => (int) $data['section_id'],
            'classroom_id' => (int) $data['classroom_id'],
            'day_of_week'  => (string) $data['day_of_week'],
            'start_time'   => self::normaliseTime((string) $data['start_time']),
            'end_time'     => self::normaliseTime((string) $data['end_time']),
            'time_in_window_open'    => (int) ($data['time_in_window_open'] ?? 10),
            'late_threshold_minutes' => (int) ($data['late_threshold_minutes'] ?? 15),
            'time_in_window_close'   => (int) ($data['time_in_window_close'] ?? 30),
            'time_out_window_open'   => (int) ($data['time_out_window_open'] ?? 10),
            'time_out_window_close'  => (int) ($data['time_out_window_close'] ?? 15),
            'minimum_dwell_minutes'  => (int) ($data['minimum_dwell_minutes'] ?? 20),
            'status'     => (string) ($data['status'] ?? 'active'),
            'updated_at' => Clock::nowString(),
        ];

        $db->update('schedules', $update, ['schedule_id' => $scheduleId]);

        self::syncTeacherAssignments((int) $data['teacher_id'], (int) $data['subject_id'], (int) $data['section_id'], $userId);

        AuditService::logChange(
            AuditService::SCHEDULE_UPDATED,
            'schedules',
            'schedule',
            $scheduleId,
            $existing,
            $update
        );
    }

    public static function archive(int $scheduleId): void
    {
        $db = Database::instance();

        $openSession = $db->scalar(
            "SELECT session_id FROM attendance_sessions
              WHERE schedule_id = :id AND status = 'open' LIMIT 1",
            ['id' => $scheduleId]
        );

        if ($openSession !== null) {
            throw new ValidationException([
                'schedule_id' => ['This schedule has an attendance session open right now. Close it before archiving.'],
            ]);
        }

        $db->update('schedules', [
            'status'     => 'archived',
            'deleted_at' => Clock::nowString(),
            'updated_at' => Clock::nowString(),
        ], ['schedule_id' => $scheduleId]);

        AuditService::log(
            AuditService::SCHEDULE_ARCHIVED,
            'schedules',
            'schedule',
            $scheduleId,
            null,
            null,
            'Schedule archived. Attendance history is retained.'
        );
    }

    private static function syncTeacherAssignments(int $teacherId, int $subjectId, int $sectionId, int $userId): void
    {
        $db = Database::instance();

        $db->execute(
            'INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, assigned_by) VALUES (:t, :s, :u)',
            ['t' => $teacherId, 's' => $subjectId, 'u' => $userId]
        );

        $db->execute(
            'INSERT IGNORE INTO teacher_sections (teacher_id, section_id, assigned_by) VALUES (:t, :s, :u)',
            ['t' => $teacherId, 's' => $sectionId, 'u' => $userId]
        );
    }

    private static function normaliseTime(string $time): ?string
    {
        $time = trim($time);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $time, $m) !== 1) {
            return null;
        }

        return sprintf('%s:%s:%s', $m[1], $m[2], $m[4] ?? '00');
    }

    /**
     * Schedules that could be opened on a given device right now — what the
     * terminal asks for after it authenticates, and what the fingerprint check
     * validates against.
     *
     * @return list<array<string,mixed>>
     */
    public static function activeForDevice(int $deviceRowId, ?\DateTimeImmutable $at = null): array
    {
        $at  = $at ?? Clock::now();
        $day = $at->format('l');

        return Database::instance()->select(
            'SELECT sch.*, s.subject_code, s.subject_name, sec.section_code, sec.section_name,
                    c.room_number, gl.grade_level_name,
                    CONCAT(t.first_name, \' \', t.last_name) AS teacher_name
               FROM schedules sch
               JOIN devices dev     ON dev.classroom_id = sch.classroom_id
               JOIN subjects s      ON s.subject_id = sch.subject_id
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               JOIN classrooms c    ON c.classroom_id = sch.classroom_id
               JOIN teachers t      ON t.teacher_id = sch.teacher_id
              WHERE dev.id = :device
                AND sch.day_of_week = :day
                AND sch.status = \'active\'
                AND sch.deleted_at IS NULL
                -- The window a session may be opened in: from tap-in open
                -- through to tap-out close.
                AND TIME(:now) >= SUBTIME(sch.start_time, SEC_TO_TIME(sch.time_in_window_open * 60))
                AND TIME(:now) <= ADDTIME(sch.end_time, SEC_TO_TIME(sch.time_out_window_close * 60))
              ORDER BY sch.start_time',
            ['device' => $deviceRowId, 'day' => $day, 'now' => $at->format('H:i:s')]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forTeacher(int $teacherId, ?string $day = null): array
    {
        $sql = 'SELECT sch.*, s.subject_code, s.subject_name, sec.section_code, sec.section_name,
                       c.room_number, c.building, gl.grade_level_name, d.department_name
                  FROM schedules sch
                  JOIN subjects s      ON s.subject_id = sch.subject_id
                  JOIN departments d   ON d.department_id = s.department_id
                  JOIN sections sec    ON sec.section_id = sch.section_id
                  JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
                  JOIN classrooms c    ON c.classroom_id = sch.classroom_id
                 WHERE sch.teacher_id = :teacher
                   AND sch.status = \'active\'
                   AND sch.deleted_at IS NULL';

        $bindings = ['teacher' => $teacherId];

        if ($day !== null) {
            $sql            .= ' AND sch.day_of_week = :day';
            $bindings['day'] = $day;
        }

        $sql .= ' ORDER BY FIELD(sch.day_of_week, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'), sch.start_time';

        return Database::instance()->select($sql, $bindings);
    }

    /** @return list<array<string,mixed>> */
    public static function forSection(int $sectionId): array
    {
        return Database::instance()->select(
            'SELECT sch.*, s.subject_code, s.subject_name, c.room_number,
                    CONCAT(t.last_name, \', \', t.first_name) AS teacher_name
               FROM schedules sch
               JOIN subjects s   ON s.subject_id = sch.subject_id
               JOIN classrooms c ON c.classroom_id = sch.classroom_id
               JOIN teachers t   ON t.teacher_id = sch.teacher_id
              WHERE sch.section_id = :section AND sch.status = \'active\' AND sch.deleted_at IS NULL
              ORDER BY FIELD(sch.day_of_week, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'), sch.start_time',
            ['section' => $sectionId]
        );
    }
}
