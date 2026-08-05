<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Validators\TeacherSectionAssignmentValidator;
use PDOException;

/**
 * Departments, grade levels and sections (Part 13).
 *
 * These are first-class entities, not labels, and the archive rules reflect
 * that: nothing that is still referenced by an active teacher, subject,
 * schedule or student can be archived without the administrator first being
 * shown exactly what would break.
 */
final class AcademicStructureService
{
    // ------------------------------------------------------- departments --

    /** @param array<string,mixed> $data */
    public static function createDepartment(array $data): int
    {
        $code = strtoupper(trim((string) $data['department_code']));

        if (preg_match('/^[A-Z0-9-]{2,20}$/', $code) !== 1) {
            throw new ValidationException([
                'department_code' => ['Department code must be 2–20 uppercase letters, digits or hyphens.'],
            ]);
        }

        try {
            $id = (int) Database::instance()->insert('departments', [
                'department_code' => $code,
                'department_name' => trim((string) $data['department_name']),
                'description'     => self::nullIfBlank($data['description'] ?? null),
                'head_teacher_id' => empty($data['head_teacher_id']) ? null : (int) $data['head_teacher_id'],
                'status'          => (string) ($data['status'] ?? 'active'),
                'created_at'      => Clock::nowString(),
                'updated_at'      => Clock::nowString(),
            ]);
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw new ValidationException([
                    'department_code' => ['A department with this code or name already exists.'],
                ]);
            }

            throw $e;
        }

        AuditService::log(
            AuditService::DEPARTMENT_CREATED,
            'academic_setup',
            'department',
            $id,
            null,
            ['department_code' => $code, 'department_name' => $data['department_name']],
            sprintf('Department %s created.', $code)
        );

        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateDepartment(int $departmentId, array $data): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM departments WHERE department_id = :id', ['id' => $departmentId]);

        if ($existing === null) {
            throw new ValidationException(['department_id' => ['Department not found.']]);
        }

        // Part 13.2: the code is immutable after creation. Renaming it would
        // silently invalidate every export, report and integration keyed on it.
        if (isset($data['department_code'])
            && strtoupper(trim((string) $data['department_code'])) !== (string) $existing['department_code']
        ) {
            throw new ValidationException([
                'department_code' => ['Department code is immutable once the department has been created.'],
            ]);
        }

        $update = [
            'department_name' => trim((string) ($data['department_name'] ?? $existing['department_name'])),
            'description'     => self::nullIfBlank($data['description'] ?? $existing['description']),
            'head_teacher_id' => empty($data['head_teacher_id']) ? null : (int) $data['head_teacher_id'],
            'status'          => (string) ($data['status'] ?? $existing['status']),
            'updated_at'      => Clock::nowString(),
        ];

        $db->update('departments', $update, ['department_id' => $departmentId]);

        AuditService::logChange(
            AuditService::DEPARTMENT_UPDATED,
            'academic_setup',
            'department',
            $departmentId,
            $existing,
            $update
        );
    }

    /**
     * What archiving a department would affect — the warning list Part 13.2
     * requires before the administrator can confirm.
     *
     * @return array<string,mixed>
     */
    public static function departmentArchiveImpact(int $departmentId): array
    {
        $db = Database::instance();

        return [
            'teachers' => $db->select(
                "SELECT teacher_id, employee_number, first_name, last_name
                   FROM teachers
                  WHERE department_id = :id AND deleted_at IS NULL AND status = 'active'",
                ['id' => $departmentId]
            ),
            'subjects' => $db->select(
                "SELECT subject_id, subject_code, subject_name
                   FROM subjects
                  WHERE department_id = :id AND deleted_at IS NULL AND status = 'active'",
                ['id' => $departmentId]
            ),
            'schedules' => $db->select(
                "SELECT sch.schedule_id, sch.day_of_week, sch.start_time, s.subject_code, sec.section_code
                   FROM schedules sch
                   JOIN subjects s   ON s.subject_id = sch.subject_id
                   JOIN sections sec ON sec.section_id = sch.section_id
                  WHERE s.department_id = :id AND sch.status = 'active' AND sch.deleted_at IS NULL",
                ['id' => $departmentId]
            ),
        ];
    }

    public static function archiveDepartment(int $departmentId, bool $confirmed): void
    {
        $impact = self::departmentArchiveImpact($departmentId);

        if (!$confirmed && ($impact['teachers'] !== [] || $impact['subjects'] !== [] || $impact['schedules'] !== [])) {
            throw new ValidationException(
                ['department_id' => [sprintf(
                    'Archiving this department affects %d teacher(s), %d subject(s) and %d active schedule(s). Confirm to continue.',
                    count($impact['teachers']),
                    count($impact['subjects']),
                    count($impact['schedules'])
                )]],
                'Department archive requires confirmation.',
                'DEPARTMENT_ARCHIVE_CASCADE'
            );
        }

        if ($impact['teachers'] !== []) {
            throw new ValidationException([
                'department_id' => [sprintf(
                    'Reassign %d active teacher(s) to another department before archiving this one.',
                    count($impact['teachers'])
                )],
            ]);
        }

        Database::instance()->update('departments', [
            'status'     => 'inactive',
            'deleted_at' => Clock::nowString(),
            'updated_at' => Clock::nowString(),
        ], ['department_id' => $departmentId]);

        AuditService::log(
            AuditService::DEPARTMENT_ARCHIVED,
            'academic_setup',
            'department',
            $departmentId,
            null,
            ['affected_subjects' => count($impact['subjects']), 'affected_schedules' => count($impact['schedules'])],
            'Department archived.'
        );
    }

    /** @return list<array<string,mixed>> */
    public static function departments(bool $activeOnly = false): array
    {
        $sql = "SELECT d.*,
                       (SELECT COUNT(*) FROM subjects s
                         WHERE s.department_id = d.department_id AND s.deleted_at IS NULL) AS subject_count,
                       (SELECT COUNT(*) FROM teachers t
                         WHERE t.department_id = d.department_id AND t.deleted_at IS NULL) AS teacher_count,
                       (SELECT COUNT(*) FROM schedules sch
                          JOIN subjects s2 ON s2.subject_id = sch.subject_id
                         WHERE s2.department_id = d.department_id
                           AND sch.status = 'active' AND sch.deleted_at IS NULL) AS schedule_count,
                       CONCAT(t.last_name, ', ', t.first_name) AS head_teacher_name
                  FROM departments d
                  LEFT JOIN teachers t ON t.teacher_id = d.head_teacher_id
                 WHERE d.deleted_at IS NULL";

        if ($activeOnly) {
            $sql .= " AND d.status = 'active'";
        }

        return Database::instance()->select($sql . ' ORDER BY d.department_name');
    }

    /** Attendance rollup for a department, used by the View Department modal. @return array<string,mixed> */
    public static function departmentSummary(int $departmentId): array
    {
        $row = Database::instance()->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early') THEN 1 ELSE 0 END) AS attended
               FROM attendance_records ar
               JOIN subjects s ON s.subject_id = ar.subject_id
              WHERE s.department_id = :id
                AND ar.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            ['id' => $departmentId]
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);

        return [
            'records_30d'           => $total,
            'attendance_percentage' => $total === 0 ? 0.0 : round((int) $row['attended'] / $total * 100, 1),
        ];
    }

    // ------------------------------------------------------ grade levels --

    /** @return list<array<string,mixed>> */
    public static function gradeLevels(bool $activeOnly = true): array
    {
        $sql = 'SELECT gl.*,
                       (SELECT COUNT(*) FROM sections sec
                         WHERE sec.grade_level_id = gl.grade_level_id AND sec.deleted_at IS NULL) AS section_count,
                       (SELECT COUNT(*) FROM students st
                          JOIN sections sec2 ON sec2.section_id = st.section_id
                         WHERE sec2.grade_level_id = gl.grade_level_id AND st.deleted_at IS NULL) AS student_count
                  FROM grade_levels gl';

        if ($activeOnly) {
            $sql .= " WHERE gl.status = 'active'";
        }

        // numeric_level, never the code — "G10" sorts before "G7" as a string.
        return Database::instance()->select($sql . ' ORDER BY gl.numeric_level');
    }

    /** @param array<string,mixed> $data */
    public static function createGradeLevel(array $data): int
    {
        try {
            $id = (int) Database::instance()->insert('grade_levels', [
                'grade_level_code' => strtoupper(trim((string) $data['grade_level_code'])),
                'grade_level_name' => trim((string) $data['grade_level_name']),
                'numeric_level'    => (int) $data['numeric_level'],
                'track'            => self::nullIfBlank($data['track'] ?? null),
                'status'           => (string) ($data['status'] ?? 'active'),
                'created_at'       => Clock::nowString(),
                'updated_at'       => Clock::nowString(),
            ]);
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw new ValidationException([
                    'numeric_level' => ['A grade level with this code or numeric level already exists.'],
                ]);
            }

            throw $e;
        }

        AuditService::log('GRADE_LEVEL_CREATED', 'academic_setup', 'grade_level', $id, null, $data, 'Grade level created.');

        return $id;
    }

    // ---------------------------------------------------------- sections --

    /** @param array<string,mixed> $data */
    public static function createSection(array $data, int $userId): int
    {
        $db        = Database::instance();
        $adviserId = empty($data['adviser_id']) ? null : (int) $data['adviser_id'];

        if ($adviserId !== null) {
            // The adviser must be qualified for this section's grade level; the
            // reverse direction of the Part 14.2 rule.
            self::assertAdviserEligible($adviserId, (int) $data['grade_level_id']);
        }

        try {
            $sectionId = (int) $db->insert('sections', [
                'section_code'   => strtoupper(trim((string) $data['section_code'])),
                'section_name'   => trim((string) $data['section_name']),
                'grade_level_id' => (int) $data['grade_level_id'],
                'adviser_id'     => $adviserId,
                'strand'         => self::nullIfBlank($data['strand'] ?? null),
                'capacity'       => max(1, (int) ($data['capacity'] ?? 45)),
                'school_year_id' => (int) ($data['school_year_id'] ?? SchoolYearService::currentId()),
                'default_classroom_id' => empty($data['default_classroom_id']) ? null : (int) $data['default_classroom_id'],
                'status'         => (string) ($data['status'] ?? 'active'),
                'created_at'     => Clock::nowString(),
                'updated_at'     => Clock::nowString(),
            ]);
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw new ValidationException([
                    'section_code' => ['A section with this code already exists for the current school year.'],
                ]);
            }

            throw $e;
        }

        if ($adviserId !== null) {
            $db->execute(
                'INSERT IGNORE INTO teacher_sections (teacher_id, section_id, assigned_by) VALUES (:t, :s, :u)',
                ['t' => $adviserId, 's' => $sectionId, 'u' => $userId]
            );
        }

        AuditService::log(
            AuditService::SECTION_CREATED,
            'academic_setup',
            'section',
            $sectionId,
            null,
            $data,
            sprintf('Section %s created.', $data['section_code'])
        );

        return $sectionId;
    }

    /** @param array<string,mixed> $data */
    public static function updateSection(int $sectionId, array $data, int $userId): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM sections WHERE section_id = :id', ['id' => $sectionId]);

        if ($existing === null) {
            throw new ValidationException(['section_id' => ['Section not found.']]);
        }

        // Part 13.4: a section belongs to exactly one grade level and cannot be
        // reassigned once students are enrolled — doing so would silently change
        // the derived grade level of every student in it.
        if (isset($data['grade_level_id'])
            && (int) $data['grade_level_id'] !== (int) $existing['grade_level_id']
            && (int) $existing['enrolled_count'] > 0
        ) {
            throw new ValidationException([
                'grade_level_id' => [sprintf(
                    'This section has %d enrolled student(s) and cannot be moved to a different grade level.',
                    (int) $existing['enrolled_count']
                )],
            ]);
        }

        $adviserId = array_key_exists('adviser_id', $data)
            ? (empty($data['adviser_id']) ? null : (int) $data['adviser_id'])
            : ($existing['adviser_id'] === null ? null : (int) $existing['adviser_id']);

        if ($adviserId !== null) {
            self::assertAdviserEligible($adviserId, (int) $existing['grade_level_id']);
        }

        $capacity = isset($data['capacity']) ? max(1, (int) $data['capacity']) : (int) $existing['capacity'];

        if ($capacity < (int) $existing['enrolled_count']) {
            throw new ValidationException([
                'capacity' => [sprintf(
                    'Capacity cannot be below the current enrolment of %d.',
                    (int) $existing['enrolled_count']
                )],
            ]);
        }

        $update = [
            'section_name' => trim((string) ($data['section_name'] ?? $existing['section_name'])),
            'adviser_id'   => $adviserId,
            'strand'       => self::nullIfBlank($data['strand'] ?? $existing['strand']),
            'capacity'     => $capacity,
            'default_classroom_id' => array_key_exists('default_classroom_id', $data)
                ? (empty($data['default_classroom_id']) ? null : (int) $data['default_classroom_id'])
                : ($existing['default_classroom_id'] === null ? null : (int) $existing['default_classroom_id']),
            'status'     => (string) ($data['status'] ?? $existing['status']),
            'updated_at' => Clock::nowString(),
        ];

        $db->update('sections', $update, ['section_id' => $sectionId]);

        if ($adviserId !== null) {
            $db->execute(
                'INSERT IGNORE INTO teacher_sections (teacher_id, section_id, assigned_by) VALUES (:t, :s, :u)',
                ['t' => $adviserId, 's' => $sectionId, 'u' => $userId]
            );
        }

        AuditService::logChange(
            AuditService::SECTION_UPDATED,
            'academic_setup',
            'section',
            $sectionId,
            $existing,
            $update
        );
    }

    private static function assertAdviserEligible(int $teacherId, int $gradeLevelId): void
    {
        $allowed = TeacherSectionAssignmentValidator::allowedGradeLevelIds($teacherId);

        if (in_array($gradeLevelId, $allowed, true)) {
            return;
        }

        $gradeName = Database::instance()->scalar(
            'SELECT grade_level_name FROM grade_levels WHERE grade_level_id = :id',
            ['id' => $gradeLevelId]
        );

        SecurityLogService::log(
            SecurityLogService::GRADE_LEVEL_MISMATCH_BLOCKED,
            'medium',
            sprintf('Blocked adviser assignment: teacher #%d is not assigned to grade level #%d.', $teacherId, $gradeLevelId),
            ['teacher_id' => $teacherId, 'grade_level_id' => $gradeLevelId]
        );

        throw new ValidationException(
            ['adviser_id' => [sprintf(
                'This teacher is not assigned to %s and cannot advise a section at that grade level.',
                $gradeName ?? 'that grade level'
            )]],
            'Grade level mismatch blocked.',
            TeacherSectionAssignmentValidator::ERROR_CODE
        );
    }

    public static function archiveSection(int $sectionId): void
    {
        $db      = Database::instance();
        $section = $db->selectOne('SELECT * FROM sections WHERE section_id = :id', ['id' => $sectionId]);

        if ($section === null) {
            throw new ValidationException(['section_id' => ['Section not found.']]);
        }

        // Part 13.4: all students must be transferred or archived first.
        // Attendance history is preserved and remains queryable by the archived
        // section, which is why we archive rather than delete.
        $remaining = (int) $db->scalar(
            "SELECT COUNT(*) FROM students WHERE section_id = :id AND deleted_at IS NULL AND status = 'active'",
            ['id' => $sectionId]
        );

        if ($remaining > 0) {
            throw new ValidationException([
                'section_id' => [sprintf(
                    'Transfer or archive the %d remaining student(s) before archiving this section.',
                    $remaining
                )],
            ]);
        }

        $db->transaction(static function (Database $db) use ($sectionId): void {
            $db->update('sections', [
                'status'     => 'archived',
                'deleted_at' => Clock::nowString(),
                'updated_at' => Clock::nowString(),
            ], ['section_id' => $sectionId]);

            $db->execute(
                "UPDATE schedules SET status = 'archived', deleted_at = :now
                  WHERE section_id = :id AND status = 'active'",
                ['id' => $sectionId, 'now' => Clock::nowString()]
            );
        });

        AuditService::log(
            AuditService::SECTION_ARCHIVED,
            'academic_setup',
            'section',
            $sectionId,
            null,
            null,
            sprintf('Section %s archived. Attendance history retained.', $section['section_code'])
        );
    }

    /**
     * @param  array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function sections(array $filters = []): array
    {
        $where    = ['1=1'];
        $bindings = [];

        foreach (['grade_level_id', 'adviser_id', 'status', 'school_year_id'] as $key) {
            if (!empty($filters[$key])) {
                $column         = $key === 'status' ? 'status' : $key;
                $where[]        = "v.{$column} = :{$key}";
                $bindings[$key] = $filters[$key];
            }
        }

        if (!empty($filters['strand'])) {
            $where[]            = 'v.strand = :strand';
            $bindings['strand'] = (string) $filters['strand'];
        }

        if (!empty($filters['search'])) {
            $where[]            = '(v.section_code LIKE :search OR v.section_name LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        return Database::instance()->select(
            'SELECT v.* FROM v_section_summary v WHERE ' . implode(' AND ', $where)
                . ' ORDER BY v.numeric_level, v.section_code',
            $bindings
        );
    }

    /** @return array<string,mixed>|null */
    public static function findSection(int $sectionId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT v.*, sec.school_year_id, sec.default_classroom_id, c.room_number AS default_room
               FROM v_section_summary v
               JOIN sections sec ON sec.section_id = v.section_id
               LEFT JOIN classrooms c ON c.classroom_id = sec.default_classroom_id
              WHERE v.section_id = :id',
            ['id' => $sectionId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function sectionRoster(int $sectionId): array
    {
        return Database::instance()->select(
            "SELECT st.student_id, st.student_number, st.first_name, st.last_name, st.middle_name,
                    st.photo_path, st.status, st.guardian_name, st.guardian_contact,
                    rc.card_uid, rc.status AS rfid_status, rc.issue_date,
                    (SELECT ROUND(SUM(CASE WHEN ar.final_status IN ('Present','Late','Left Early') THEN 1 ELSE 0 END)
                                  / NULLIF(COUNT(*),0) * 100, 1)
                       FROM attendance_records ar
                      WHERE ar.student_id = st.student_id
                        AND ar.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS attendance_percentage
               FROM students st
               LEFT JOIN rfid_cards rc ON rc.student_id = st.student_id AND rc.status = 'active'
              WHERE st.section_id = :id AND st.deleted_at IS NULL
              ORDER BY st.last_name, st.first_name",
            ['id' => $sectionId]
        );
    }

    // --------------------------------------------------------- subjects --

    /** @param array<string,mixed> $data */
    public static function createSubject(array $data): int
    {
        $db = Database::instance();

        $subjectId = (int) $db->transaction(static function (Database $db) use ($data): string {
            try {
                $id = $db->insert('subjects', [
                    'subject_code'  => strtoupper(trim((string) $data['subject_code'])),
                    'subject_name'  => trim((string) $data['subject_name']),
                    'description'   => self::nullIfBlank($data['description'] ?? null),
                    'department_id' => (int) $data['department_id'],
                    'units'         => (float) ($data['units'] ?? 1.0),
                    'status'        => (string) ($data['status'] ?? 'active'),
                    'created_at'    => Clock::nowString(),
                    'updated_at'    => Clock::nowString(),
                ]);
            } catch (PDOException $e) {
                if (Database::isDuplicateKey($e)) {
                    throw new ValidationException(['subject_code' => ['This subject code is already in use.']]);
                }

                throw $e;
            }

            foreach (array_map('intval', (array) ($data['grade_level_ids'] ?? [])) as $gradeLevelId) {
                $db->execute(
                    'INSERT IGNORE INTO subject_grade_levels (subject_id, grade_level_id) VALUES (:s, :g)',
                    ['s' => (int) $id, 'g' => $gradeLevelId]
                );
            }

            return $id;
        });

        AuditService::log(
            AuditService::SUBJECT_CREATED,
            'academic_setup',
            'subject',
            $subjectId,
            null,
            $data,
            sprintf('Subject %s created.', $data['subject_code'])
        );

        return $subjectId;
    }

    /** @param array<string,mixed> $data */
    public static function updateSubject(int $subjectId, array $data): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM subjects WHERE subject_id = :id', ['id' => $subjectId]);

        if ($existing === null) {
            throw new ValidationException(['subject_id' => ['Subject not found.']]);
        }

        $newDepartmentId = isset($data['department_id']) ? (int) $data['department_id'] : (int) $existing['department_id'];

        $db->transaction(static function (Database $db) use ($subjectId, $data, $existing, $newDepartmentId): void {
            $update = [
                'subject_name'  => trim((string) ($data['subject_name'] ?? $existing['subject_name'])),
                'description'   => self::nullIfBlank($data['description'] ?? $existing['description']),
                'department_id' => $newDepartmentId,
                'units'         => (float) ($data['units'] ?? $existing['units']),
                'status'        => (string) ($data['status'] ?? $existing['status']),
                'updated_at'    => Clock::nowString(),
            ];

            $db->update('subjects', $update, ['subject_id' => $subjectId]);

            // Moving a subject between departments strands any teacher who held
            // it under the old one; clearing those keeps the Part 14.1 invariant
            // true at all times rather than only at assignment time.
            if ($newDepartmentId !== (int) $existing['department_id']) {
                $db->execute(
                    'DELETE ts FROM teacher_subjects ts
                       JOIN teachers t ON t.teacher_id = ts.teacher_id
                      WHERE ts.subject_id = :s AND t.department_id <> :d AND ts.is_exception = 0',
                    ['s' => $subjectId, 'd' => $newDepartmentId]
                );
            }

            if (isset($data['grade_level_ids'])) {
                $db->execute('DELETE FROM subject_grade_levels WHERE subject_id = :s', ['s' => $subjectId]);

                foreach (array_map('intval', (array) $data['grade_level_ids']) as $gradeLevelId) {
                    $db->execute(
                        'INSERT IGNORE INTO subject_grade_levels (subject_id, grade_level_id) VALUES (:s, :g)',
                        ['s' => $subjectId, 'g' => $gradeLevelId]
                    );
                }
            }

            AuditService::logChange(
                AuditService::SUBJECT_UPDATED,
                'academic_setup',
                'subject',
                $subjectId,
                $existing,
                $update
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public static function subjects(array $filters = []): array
    {
        $where    = ['s.deleted_at IS NULL'];
        $bindings = [];

        if (!empty($filters['department_id'])) {
            $where[]                   = 's.department_id = :department_id';
            $bindings['department_id'] = (int) $filters['department_id'];
        }
        if (!empty($filters['status'])) {
            $where[]            = 's.status = :status';
            $bindings['status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[]            = '(s.subject_code LIKE :search OR s.subject_name LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        return Database::instance()->select(
            'SELECT s.*, d.department_name, d.department_code,
                    (SELECT GROUP_CONCAT(gl.grade_level_code ORDER BY gl.numeric_level SEPARATOR ", ")
                       FROM subject_grade_levels sgl
                       JOIN grade_levels gl ON gl.grade_level_id = sgl.grade_level_id
                      WHERE sgl.subject_id = s.subject_id) AS grade_levels,
                    (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.subject_id = s.subject_id) AS teacher_count
               FROM subjects s
               JOIN departments d ON d.department_id = s.department_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY d.department_name, s.subject_code',
            $bindings
        );
    }

    /** @return list<int> */
    public static function subjectGradeLevelIds(int $subjectId): array
    {
        $rows = Database::instance()->select(
            'SELECT grade_level_id FROM subject_grade_levels WHERE subject_id = :id',
            ['id' => $subjectId]
        );

        return array_map(static fn (array $r): int => (int) $r['grade_level_id'], $rows);
    }

    // ------------------------------------------------------- classrooms --

    /** @param array<string,mixed> $data */
    public static function createClassroom(array $data): int
    {
        try {
            $id = (int) Database::instance()->insert('classrooms', [
                'room_number'   => strtoupper(trim((string) $data['room_number'])),
                'building'      => self::nullIfBlank($data['building'] ?? null),
                'floor'         => self::nullIfBlank($data['floor'] ?? null),
                'capacity'      => max(1, (int) ($data['capacity'] ?? 50)),
                'location_note' => self::nullIfBlank($data['location_note'] ?? null),
                'status'        => (string) ($data['status'] ?? 'active'),
                'created_at'    => Clock::nowString(),
                'updated_at'    => Clock::nowString(),
            ]);
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw new ValidationException(['room_number' => ['A classroom with this room number already exists.']]);
            }

            throw $e;
        }

        AuditService::log(
            AuditService::CLASSROOM_CREATED,
            'academic_setup',
            'classroom',
            $id,
            null,
            $data,
            sprintf('Classroom %s created.', $data['room_number'])
        );

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function classrooms(bool $activeOnly = false): array
    {
        $sql = "SELECT c.*,
                       d.device_id, d.device_name, d.status AS device_status, d.device_role,
                       (SELECT COUNT(*) FROM schedules sch
                         WHERE sch.classroom_id = c.classroom_id
                           AND sch.status = 'active' AND sch.deleted_at IS NULL) AS schedule_count
                  FROM classrooms c
                  LEFT JOIN devices d ON d.classroom_id = c.classroom_id
                       AND d.deleted_at IS NULL
                       AND d.status IN ('pending','active','offline')
                 WHERE c.deleted_at IS NULL";

        if ($activeOnly) {
            $sql .= " AND c.status = 'active'";
        }

        return Database::instance()->select($sql . ' ORDER BY c.building, c.room_number');
    }

    /**
     * Classrooms a section can be scheduled into: active, device-equipped, with
     * a capacity flag so the form can warn before the validator rejects.
     *
     * @return list<array<string,mixed>>
     */
    public static function assignableClassrooms(int $sectionId): array
    {
        return Database::instance()->select(
            "SELECT c.classroom_id, c.room_number, c.building, c.floor, c.capacity,
                    d.device_id, d.device_role, d.status AS device_status,
                    (c.capacity >= sec.enrolled_count) AS capacity_ok,
                    sec.enrolled_count
               FROM classrooms c
               JOIN devices d ON d.classroom_id = c.classroom_id
                    AND d.deleted_at IS NULL
                    AND d.status IN ('active','offline','pending')
                    AND d.device_role IN ('both','entry')
               CROSS JOIN sections sec
              WHERE sec.section_id = :section
                AND c.status = 'active'
                AND c.deleted_at IS NULL
              ORDER BY capacity_ok DESC, c.building, c.room_number",
            ['section' => $sectionId]
        );
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
