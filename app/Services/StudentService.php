<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use PDOException;

/**
 * Student records and section transfers.
 *
 * Two rules from Part 13 shape everything here: a student's grade level is
 * derived from their section and never stored independently, and a student is
 * archived rather than deleted so their attendance history stays intact and
 * queryable forever.
 */
final class StudentService
{
    /**
     * @param  array<string,mixed> $data
     * @return int the new student_id
     */
    public static function create(array $data, int $userId): int
    {
        $db = Database::instance();

        self::assertSectionMatchesGradeLevel(
            (int) $data['section_id'],
            isset($data['grade_level_id']) ? (int) $data['grade_level_id'] : null
        );

        return (int) $db->transaction(static function (Database $db) use ($data, $userId): int {
            try {
                $studentId = (int) $db->insert('students', [
                    'student_number'   => trim((string) $data['student_number']),
                    'section_id'       => (int) $data['section_id'],
                    'first_name'       => trim((string) $data['first_name']),
                    'middle_name'      => self::nullIfBlank($data['middle_name'] ?? null),
                    'last_name'        => trim((string) $data['last_name']),
                    'suffix'           => self::nullIfBlank($data['suffix'] ?? null),
                    'gender'           => (string) ($data['gender'] ?? 'Prefer not to say'),
                    'birthdate'        => self::nullIfBlank($data['birthdate'] ?? null),
                    'email'            => self::nullIfBlank($data['email'] ?? null),
                    'address'          => self::nullIfBlank($data['address'] ?? null),
                    'guardian_name'    => trim((string) $data['guardian_name']),
                    'guardian_contact' => trim((string) $data['guardian_contact']),
                    'guardian_email'   => self::nullIfBlank($data['guardian_email'] ?? null),
                    'photo_path'       => self::nullIfBlank($data['photo_path'] ?? null),
                    'status'           => (string) ($data['status'] ?? 'active'),
                    'enrolled_at'      => (string) ($data['enrolled_at'] ?? Clock::today()),
                    'created_at'       => Clock::nowString(),
                    'updated_at'       => Clock::nowString(),
                ]);
            } catch (PDOException $e) {
                throw self::translateDatabaseError($e, $data);
            }

            // The initial enrolment is the first row of the transfer history, so
            // "which section was this student in on date X" is answerable from
            // one table for the whole of their time at the school.
            $db->insert('student_section_history', [
                'student_id'      => $studentId,
                'from_section_id' => null,
                'to_section_id'   => (int) $data['section_id'],
                'reason'          => 'Initial enrolment',
                'effective_date'  => (string) ($data['enrolled_at'] ?? Clock::today()),
                'performed_by'    => $userId,
                'created_at'      => Clock::nowString(),
            ]);

            self::syncSubjectEnrolments($db, $studentId, (int) $data['section_id']);

            AuditService::log(
                AuditService::STUDENT_CREATED,
                'students',
                'student',
                $studentId,
                null,
                ['student_number' => $data['student_number'], 'section_id' => (int) $data['section_id']],
                sprintf('Student %s registered.', $data['student_number'])
            );

            return $studentId;
        });
    }

    /** @param array<string,mixed> $data */
    public static function update(int $studentId, array $data, int $userId): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM students WHERE student_id = :id', ['id' => $studentId]);

        if ($existing === null) {
            throw new ValidationException(['student_id' => ['Student not found.']]);
        }

        // A section change is a transfer, not a field edit — it needs a reason
        // and an effective date, so it is refused here and routed to transfer().
        if (isset($data['section_id']) && (int) $data['section_id'] !== (int) $existing['section_id']) {
            throw new ValidationException([
                'section_id' => ['Use the Transfer Student action to move a student between sections. A reason and effective date are required.'],
            ]);
        }

        $update = [
            'first_name'       => trim((string) ($data['first_name'] ?? $existing['first_name'])),
            'middle_name'      => self::nullIfBlank($data['middle_name'] ?? $existing['middle_name']),
            'last_name'        => trim((string) ($data['last_name'] ?? $existing['last_name'])),
            'suffix'           => self::nullIfBlank($data['suffix'] ?? $existing['suffix']),
            'gender'           => (string) ($data['gender'] ?? $existing['gender']),
            'birthdate'        => self::nullIfBlank($data['birthdate'] ?? $existing['birthdate']),
            'email'            => self::nullIfBlank($data['email'] ?? $existing['email']),
            'address'          => self::nullIfBlank($data['address'] ?? $existing['address']),
            'guardian_name'    => trim((string) ($data['guardian_name'] ?? $existing['guardian_name'])),
            'guardian_contact' => trim((string) ($data['guardian_contact'] ?? $existing['guardian_contact'])),
            'guardian_email'   => self::nullIfBlank($data['guardian_email'] ?? $existing['guardian_email']),
            'status'           => (string) ($data['status'] ?? $existing['status']),
            'updated_at'       => Clock::nowString(),
        ];

        if (!empty($data['photo_path'])) {
            $update['photo_path'] = (string) $data['photo_path'];
        }

        if (!empty($data['student_number']) && (string) $data['student_number'] !== (string) $existing['student_number']) {
            $update['student_number'] = trim((string) $data['student_number']);
        }

        try {
            $db->update('students', $update, ['student_id' => $studentId]);
        } catch (PDOException $e) {
            throw self::translateDatabaseError($e, $data);
        }

        AuditService::logChange(
            AuditService::STUDENT_UPDATED,
            'students',
            'student',
            $studentId,
            $existing,
            $update,
            ['updated_at', 'created_at'],
            sprintf('Student %s updated.', $existing['student_number'])
        );
    }

    /**
     * Section transfer (Part 13.4).
     *
     * Historical attendance keeps the section it was recorded under — nothing is
     * retro-rewritten — because a report of last term's attendance must reflect
     * where the student actually sat.
     */
    public static function transfer(
        int $studentId,
        int $toSectionId,
        string $reason,
        string $effectiveDate,
        int $userId
    ): void {
        $db = Database::instance();

        if (trim($reason) === '') {
            throw new ValidationException(['reason' => ['A transfer reason is required.']]);
        }

        $db->transaction(static function (Database $db) use ($studentId, $toSectionId, $reason, $effectiveDate, $userId): void {
            $student = $db->selectOne(
                'SELECT * FROM students WHERE student_id = :id FOR UPDATE',
                ['id' => $studentId]
            );

            if ($student === null) {
                throw new ValidationException(['student_id' => ['Student not found.']]);
            }

            $fromSectionId = (int) $student['section_id'];

            if ($fromSectionId === $toSectionId) {
                throw new ValidationException(['section_id' => ['The student is already in this section.']]);
            }

            $target = $db->selectOne(
                'SELECT sec.*, gl.grade_level_name
                   FROM sections sec
                   JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
                  WHERE sec.section_id = :id AND sec.deleted_at IS NULL FOR UPDATE',
                ['id' => $toSectionId]
            );

            if ($target === null) {
                throw new ValidationException(['section_id' => ['The destination section does not exist.']]);
            }

            if ((string) $target['status'] !== 'active') {
                throw new ValidationException(['section_id' => ['The destination section is not active.']]);
            }

            if ((int) $target['enrolled_count'] >= (int) $target['capacity']) {
                throw new ValidationException(
                    ['section_id' => [sprintf(
                        'Section "%s" is full (%d/%d). Choose another section or increase capacity.',
                        $target['section_code'],
                        (int) $target['enrolled_count'],
                        (int) $target['capacity']
                    )]],
                    'Section capacity exceeded.',
                    'SECTION_CAPACITY_EXCEEDED'
                );
            }

            $db->update('students', [
                'section_id' => $toSectionId,
                'updated_at' => Clock::nowString(),
            ], ['student_id' => $studentId]);

            $db->insert('student_section_history', [
                'student_id'      => $studentId,
                'from_section_id' => $fromSectionId,
                'to_section_id'   => $toSectionId,
                'reason'          => mb_substr($reason, 0, 500),
                'effective_date'  => $effectiveDate,
                'performed_by'    => $userId,
                'created_at'      => Clock::nowString(),
            ]);

            self::syncSubjectEnrolments($db, $studentId, $toSectionId);

            $fromSection = $db->selectOne('SELECT section_code FROM sections WHERE section_id = :id', ['id' => $fromSectionId]);

            AuditService::log(
                AuditService::STUDENT_SECTION_TRANSFER,
                'students',
                'student',
                $studentId,
                ['section_id' => $fromSectionId, 'section_code' => $fromSection['section_code'] ?? null],
                ['section_id' => $toSectionId, 'section_code' => $target['section_code'], 'reason' => $reason, 'effective_date' => $effectiveDate],
                sprintf(
                    'Student %s transferred from %s to %s effective %s. Reason: %s',
                    $student['student_number'],
                    $fromSection['section_code'] ?? '?',
                    $target['section_code'],
                    $effectiveDate,
                    $reason
                )
            );

            RealtimeService::broadcast(
                [
                    RealtimeService::CHANNEL_ADMIN,
                    RealtimeService::sectionChannel($fromSectionId),
                    RealtimeService::sectionChannel($toSectionId),
                ],
                'section.roster_changed',
                [
                    'student_id'   => $studentId,
                    'from_section' => $fromSection['section_code'] ?? null,
                    'to_section'   => (string) $target['section_code'],
                ]
            );
        });
    }

    /**
     * Bring a student's subject enrolments in line with their section's
     * schedule. Existing rows are left alone so a deliberate cross-section
     * enrolment survives a transfer.
     */
    private static function syncSubjectEnrolments(Database $db, int $studentId, int $sectionId): void
    {
        $db->execute(
            'INSERT IGNORE INTO student_subject_enrolments
                 (student_id, subject_id, section_id, school_year_id, status, created_at, updated_at)
             SELECT :student, sch.subject_id, :section, sch.school_year_id, \'enrolled\', :now, :now
               FROM schedules sch
              WHERE sch.section_id = :section
                AND sch.status = \'active\'
                AND sch.deleted_at IS NULL
              GROUP BY sch.subject_id, sch.school_year_id',
            ['student' => $studentId, 'section' => $sectionId, 'now' => Clock::nowString()]
        );
    }

    /** Archive, never delete. Attendance history remains intact (Part 2). */
    public static function archive(int $studentId, int $userId, ?string $reason = null): void
    {
        $db      = Database::instance();
        $student = $db->selectOne('SELECT * FROM students WHERE student_id = :id', ['id' => $studentId]);

        if ($student === null) {
            throw new ValidationException(['student_id' => ['Student not found.']]);
        }

        $db->transaction(static function (Database $db) use ($studentId, $student, $userId, $reason): void {
            $db->update('students', [
                'status'     => 'archived',
                'deleted_at' => Clock::nowString(),
                'updated_at' => Clock::nowString(),
            ], ['student_id' => $studentId]);

            // An archived student's card must stop working immediately, or it
            // remains a live credential for a person no longer enrolled.
            $db->execute(
                "UPDATE rfid_cards SET status = 'inactive', updated_at = :now
                  WHERE student_id = :student AND status = 'active'",
                ['student' => $studentId, 'now' => Clock::nowString()]
            );

            AuditService::log(
                AuditService::STUDENT_ARCHIVED,
                'students',
                'student',
                $studentId,
                ['status' => $student['status']],
                ['status' => 'archived', 'reason' => $reason],
                sprintf('Student %s archived. Attendance history retained. %s', $student['student_number'], $reason ?? ''),
                'success',
                $userId
            );
        });
    }

    public static function restore(int $studentId, int $userId): void
    {
        $db      = Database::instance();
        $student = $db->selectOne('SELECT * FROM students WHERE student_id = :id', ['id' => $studentId]);

        if ($student === null) {
            throw new ValidationException(['student_id' => ['Student not found.']]);
        }

        $section = $db->selectOne(
            'SELECT capacity, enrolled_count, section_code FROM sections WHERE section_id = :id',
            ['id' => (int) $student['section_id']]
        );

        if ($section !== null && (int) $section['enrolled_count'] >= (int) $section['capacity']) {
            throw new ValidationException(['section_id' => [sprintf(
                'Section "%s" is full. Transfer the student to another section before restoring.',
                $section['section_code']
            )]]);
        }

        $db->update('students', [
            'status'     => 'active',
            'deleted_at' => null,
            'updated_at' => Clock::nowString(),
        ], ['student_id' => $studentId]);

        AuditService::log(
            'STUDENT_RESTORED',
            'students',
            'student',
            $studentId,
            ['status' => 'archived'],
            ['status' => 'active'],
            sprintf('Student %s restored from archive.', $student['student_number']),
            'success',
            $userId
        );
    }

    private static function assertSectionMatchesGradeLevel(int $sectionId, ?int $claimedGradeLevelId): void
    {
        if ($claimedGradeLevelId === null) {
            return;
        }

        // The form filters sections client-side for convenience; this is the
        // control. Part 13.5: "Client-side filtering is a convenience, never a
        // control."
        $actual = Database::instance()->scalar(
            'SELECT grade_level_id FROM sections WHERE section_id = :id',
            ['id' => $sectionId]
        );

        if ($actual === null) {
            throw new ValidationException(['section_id' => ['The selected section does not exist.']]);
        }

        if ((int) $actual !== $claimedGradeLevelId) {
            throw new ValidationException([
                'section_id' => ['Selected section does not belong to the selected grade level.'],
            ]);
        }
    }

    private static function translateDatabaseError(PDOException $e, array $data): ValidationException
    {
        if (str_contains($e->getMessage(), 'SECTION_CAPACITY_EXCEEDED')) {
            $section = Database::instance()->selectOne(
                'SELECT section_code, capacity, enrolled_count FROM sections WHERE section_id = :id',
                ['id' => (int) ($data['section_id'] ?? 0)]
            );

            return new ValidationException(
                ['section_id' => [sprintf(
                    'Selected section is full (%d/%d). Choose another section or increase capacity.',
                    (int) ($section['enrolled_count'] ?? 0),
                    (int) ($section['capacity'] ?? 0)
                )]],
                'Section capacity exceeded.',
                'SECTION_CAPACITY_EXCEEDED'
            );
        }

        if (Database::isDuplicateKey($e)) {
            return new ValidationException(['student_number' => ['This student number is already registered.']]);
        }

        throw $e;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage, string $sort = 'last_name', string $direction = 'ASC'): array
    {
        $where    = ['st.deleted_at IS NULL'];
        $bindings = [];

        if (!empty($filters['include_archived'])) {
            $where = ['1=1'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(st.student_number LIKE :search
                         OR CONCAT(st.first_name, \' \', st.last_name) LIKE :search
                         OR st.last_name LIKE :search
                         OR sec.section_code LIKE :search
                         OR rc.card_uid LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        foreach (['section_id' => 'st.section_id', 'status' => 'st.status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]        = "{$column} = :{$key}";
                $bindings[$key] = $filters[$key];
            }
        }

        if (!empty($filters['grade_level_id'])) {
            $where[]                    = 'sec.grade_level_id = :grade_level_id';
            $bindings['grade_level_id'] = (int) $filters['grade_level_id'];
        }

        if (($filters['rfid_status'] ?? '') === 'assigned') {
            $where[] = 'rc.rfid_id IS NOT NULL';
        } elseif (($filters['rfid_status'] ?? '') === 'missing') {
            $where[] = 'rc.rfid_id IS NULL';
        }

        $whereSql = implode(' AND ', $where);

        $sortable = [
            'student_number' => 'st.student_number',
            'last_name'      => 'st.last_name',
            'section'        => 'sec.section_code',
            'grade'          => 'gl.numeric_level',
            'status'         => 'st.status',
            'created_at'     => 'st.created_at',
        ];

        $orderBy   = $sortable[$sort] ?? 'st.last_name';
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $db = Database::instance();

        $base = "FROM students st
                 JOIN sections sec    ON sec.section_id = st.section_id
                 JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
                 LEFT JOIN rfid_cards rc ON rc.student_id = st.student_id AND rc.status = 'active'
                WHERE {$whereSql}";

        $total = (int) $db->scalar("SELECT COUNT(*) {$base}", $bindings);

        $rows = $db->select(
            "SELECT st.*, sec.section_code, sec.section_name, gl.grade_level_id,
                    gl.grade_level_code, gl.grade_level_name, gl.numeric_level,
                    rc.card_uid, rc.rfid_id, rc.status AS rfid_status,
                    (SELECT ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early') THEN 1 ELSE 0 END)
                                  / NULLIF(COUNT(*), 0) * 100, 1)
                       FROM attendance_records ar
                      WHERE ar.student_id = st.student_id
                        AND ar.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS attendance_percentage
             {$base}
             ORDER BY {$orderBy} {$direction}, st.student_id
             LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $studentId): ?array
    {
        return Database::instance()->selectOne(
            "SELECT st.*, sec.section_code, sec.section_name, sec.strand, sec.adviser_id,
                    gl.grade_level_id, gl.grade_level_code, gl.grade_level_name, gl.numeric_level,
                    rc.card_uid, rc.rfid_id, rc.status AS rfid_status, rc.issue_date AS rfid_issue_date,
                    CONCAT(t.last_name, ', ', t.first_name) AS adviser_name
               FROM students st
               JOIN sections sec    ON sec.section_id = st.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               LEFT JOIN rfid_cards rc ON rc.student_id = st.student_id AND rc.status = 'active'
               LEFT JOIN teachers t    ON t.teacher_id = sec.adviser_id
              WHERE st.student_id = :id",
            ['id' => $studentId]
        );
    }

    /** @return array<string,mixed> */
    public static function attendanceSummary(int $studentId, int $days = 30): array
    {
        $row = Database::instance()->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN final_status = 'Present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN final_status = 'Late' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN final_status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN final_status = 'Left Early' THEN 1 ELSE 0 END) AS left_early,
                SUM(CASE WHEN final_status = 'Incomplete' THEN 1 ELSE 0 END) AS incomplete,
                SUM(CASE WHEN final_status = 'Excused' THEN 1 ELSE 0 END) AS excused,
                AVG(duration_minutes) AS avg_dwell
               FROM attendance_records
              WHERE student_id = :id AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['id' => $studentId, 'days' => $days]
        ) ?? [];

        $total    = (int) ($row['total'] ?? 0);
        $attended = (int) ($row['present'] ?? 0) + (int) ($row['late'] ?? 0)
            + (int) ($row['left_early'] ?? 0) + (int) ($row['incomplete'] ?? 0);

        return [
            'total'      => $total,
            'present'    => (int) ($row['present'] ?? 0),
            'late'       => (int) ($row['late'] ?? 0),
            'absent'     => (int) ($row['absent'] ?? 0),
            'left_early' => (int) ($row['left_early'] ?? 0),
            'incomplete' => (int) ($row['incomplete'] ?? 0),
            'excused'    => (int) ($row['excused'] ?? 0),
            'average_dwell_minutes' => $row['avg_dwell'] === null ? null : (int) round((float) $row['avg_dwell']),
            'percentage' => $total === 0 ? 0.0 : round($attended / $total * 100, 1),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function transferHistory(int $studentId): array
    {
        return Database::instance()->select(
            "SELECT h.*, sf.section_code AS from_code, stt.section_code AS to_code, u.username
               FROM student_section_history h
               LEFT JOIN sections sf  ON sf.section_id = h.from_section_id
               JOIN sections stt      ON stt.section_id = h.to_section_id
               LEFT JOIN users u      ON u.user_id = h.performed_by
              WHERE h.student_id = :id
              ORDER BY h.history_id DESC",
            ['id' => $studentId]
        );
    }
}
