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

    /**
     * Undo an archive.
     *
     * Archiving a department is a soft delete, so nothing was destroyed and
     * there is nothing to rebuild — the row is simply made visible again. It
     * comes back inactive rather than active, because the archive set it
     * inactive and reversing the deletion is not the same as asserting the
     * department is once more in use; somebody has to say that separately.
     */
    public static function restoreDepartment(int $departmentId): void
    {
        $db = Database::instance();

        $department = $db->selectOne(
            'SELECT department_id, department_name, deleted_at FROM departments WHERE department_id = :id',
            ['id' => $departmentId]
        );

        if ($department === null) {
            throw new ValidationException(['department_id' => ['Department not found.']]);
        }

        if ($department['deleted_at'] === null) {
            throw new ValidationException(['department_id' => ['That department is not archived.']]);
        }

        // A restored department whose code now collides with one created since
        // it was archived would give two live rows the same code. The archive
        // is not undone in that case; the collision is named instead.
        $clash = $db->scalar(
            'SELECT COUNT(*) FROM departments
              WHERE department_code = (SELECT department_code FROM departments WHERE department_id = :id)
                AND department_id <> :id2 AND deleted_at IS NULL',
            ['id' => $departmentId, 'id2' => $departmentId]
        );

        if ((int) $clash > 0) {
            throw new ValidationException(['department_code' => [
                'Another department is already using this code. Rename that one first, or edit '
                . 'this department\'s code before restoring it.',
            ]]);
        }

        $db->update('departments', [
            'deleted_at' => null,
            'updated_at' => Clock::nowString(),
        ], ['department_id' => $departmentId]);

        AuditService::log(
            AuditService::DEPARTMENT_UPDATED,
            'academic_setup',
            'department',
            $departmentId,
            ['deleted_at' => $department['deleted_at']],
            ['deleted_at' => null],
            sprintf('Department "%s" restored from the archive.', (string) $department['department_name'])
        );
    }

    /** @return array<string,mixed>|null */
    public static function department(int $departmentId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT * FROM departments WHERE department_id = :id AND deleted_at IS NULL',
            ['id' => $departmentId]
        );
    }

    /**
     * @param bool $archived list the archived departments instead of the live
     *                       ones. Archiving sets deleted_at, and with no way to
     *                       ask for those rows an archived department was gone
     *                       from the interface for good — a mis-click was
     *                       permanent and phpMyAdmin was the only way to see
     *                       what had happened.
     *
     * @return list<array<string,mixed>>
     */
    public static function departments(bool $activeOnly = false, bool $archived = false): array
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
                 WHERE d.deleted_at IS " . ($archived ? 'NOT NULL' : 'NULL');

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

    /**
     * @param  list<int> $gradeLevelIds
     * @return list<array<string,mixed>>
     */
    public static function gradeLevelsByIds(array $gradeLevelIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $gradeLevelIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $bindings     = [];

        foreach ($ids as $index => $id) {
            $placeholders[]             = ':grade' . $index;
            $bindings['grade' . $index] = $id;
        }

        return Database::instance()->select(
            'SELECT * FROM grade_levels
              WHERE grade_level_id IN (' . implode(',', $placeholders) . ')
              ORDER BY numeric_level',
            $bindings
        );
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
                'strand'         => self::strandFor((int) $data['grade_level_id'], $data['strand'] ?? null),
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
            'strand'       => self::strandFor(
                (int) $existing['grade_level_id'],
                $data['strand'] ?? $existing['strand']
            ),
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
     * Undo a section archive.
     *
     * The schedules are deliberately NOT brought back with it. Archiving took
     * them down together because a section with nobody in it should not hold
     * room and teacher slots, but time has passed: those periods may since
     * have been given to another section, and silently reinstating them would
     * create the double bookings the scheduler exists to prevent — discovered
     * by two classes arriving at one room. The section returns empty and its
     * timetable is rebuilt deliberately, which is the only version of this
     * that cannot surprise anybody.
     *
     * @return int how many schedules stayed archived, so the caller can say so
     */
    public static function restoreSection(int $sectionId): int
    {
        $db = Database::instance();

        $section = $db->selectOne(
            'SELECT section_id, section_code, deleted_at FROM sections WHERE section_id = :id',
            ['id' => $sectionId]
        );

        if ($section === null) {
            throw new ValidationException(['section_id' => ['Section not found.']]);
        }

        if ($section['deleted_at'] === null) {
            throw new ValidationException(['section_id' => ['That section is not archived.']]);
        }

        $clash = $db->scalar(
            'SELECT COUNT(*) FROM sections
              WHERE section_code = (SELECT section_code FROM sections WHERE section_id = :id)
                AND section_id <> :id2 AND deleted_at IS NULL',
            ['id' => $sectionId, 'id2' => $sectionId]
        );

        if ((int) $clash > 0) {
            throw new ValidationException(['section_code' => [
                'Another section is already using this code. Rename that one first, or edit this '
                . 'section\'s code before restoring it.',
            ]]);
        }

        $stillArchived = (int) $db->scalar(
            "SELECT COUNT(*) FROM schedules WHERE section_id = :id AND status = 'archived'",
            ['id' => $sectionId]
        );

        // 'inactive', not 'active'. Undoing a deletion is not the same as
        // declaring the section to be running again, and it has no students
        // and no timetable at this point — presenting it as active would put
        // an empty section in front of every dropdown in the system.
        $db->update('sections', [
            'status'     => 'inactive',
            'deleted_at' => null,
            'updated_at' => Clock::nowString(),
        ], ['section_id' => $sectionId]);

        AuditService::log(
            AuditService::SECTION_UPDATED,
            'academic_setup',
            'section',
            $sectionId,
            ['deleted_at' => $section['deleted_at'], 'status' => 'archived'],
            ['deleted_at' => null, 'status' => 'inactive', 'schedules_left_archived' => $stillArchived],
            sprintf(
                'Section %s restored from the archive. %d schedule(s) left archived.',
                (string) $section['section_code'],
                $stillArchived
            )
        );

        return $stillArchived;
    }

    /**
     * @param  array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function sections(array $filters = []): array
    {
        // Archived sections are excluded unless they are what was asked for.
        // The view used to do this itself, which meant the page's own
        // "Archived" status option searched a set the archived rows had
        // already been removed from and silently returned nothing. Deciding it
        // here is what lets both lists exist.
        $where    = [((string) ($filters['status'] ?? '')) === 'archived'
            ? 'v.deleted_at IS NOT NULL'
            : 'v.deleted_at IS NULL'];
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
    /** @return array<string,mixed>|null */
    public static function findSubject(int $subjectId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT s.*, d.department_code, d.department_name
               FROM subjects s
               JOIN departments d ON d.department_id = s.department_id
              WHERE s.subject_id = :id AND s.deleted_at IS NULL',
            ['id' => $subjectId]
        );
    }

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

            self::writeSubjectGradeLevels(
                $db,
                (int) $id,
                (array) ($data['grade_level_ids'] ?? []),
                (array) ($data['syllabus'] ?? [])
            );

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

        // The subject code is editable, unlike the department code.
        //
        // The department rule exists because "every export, report and
        // integration keyed on it" would be invalidated by a rename. That
        // reasoning was copied here, and it is not true of subjects: nothing
        // in this system keys on subject_code. Schedules, sessions and
        // attendance records all carry subject_id, no query looks a subject up
        // by its code, and no table snapshots the code alongside the history.
        // It is a display label, and the subject *name* beside it has always
        // been editable — so the code was the only field a school could not
        // correct after a typo, for no reason that held.
        $newCode = isset($data['subject_code']) && trim((string) $data['subject_code']) !== ''
            ? strtoupper(trim((string) $data['subject_code']))
            : (string) $existing['subject_code'];

        $db->transaction(static function (Database $db) use ($subjectId, $data, $existing, $newDepartmentId, $newCode): void {
            $update = [
                'subject_code'  => $newCode,
                'subject_name'  => trim((string) ($data['subject_name'] ?? $existing['subject_name'])),
                'description'   => self::nullIfBlank($data['description'] ?? $existing['description']),
                'department_id' => $newDepartmentId,
                'status'        => (string) ($data['status'] ?? $existing['status']),
                'updated_at'    => Clock::nowString(),
            ];

            try {
                $db->update('subjects', $update, ['subject_id' => $subjectId]);
            } catch (PDOException $e) {
                // Same handling as createSubject: the unique index is the
                // authority, so a code taken between the check and the write
                // is caught here rather than becoming a 500.
                if (Database::isDuplicateKey($e)) {
                    throw new ValidationException(['subject_code' => ['This subject code is already in use.']]);
                }

                throw $e;
            }

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
                self::writeSubjectGradeLevels(
                    $db,
                    $subjectId,
                    (array) $data['grade_level_ids'],
                    (array) ($data['syllabus'] ?? [])
                );
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

    /**
     * The teachers qualified to teach a subject.
     *
     * The Subjects page shows a count and nothing else, so "3" means going to
     * Teachers, filtering, and reading down a list to find out who. This is
     * the answer to the question the count raises.
     *
     * is_exception is carried through because it is the interesting half: a
     * teacher qualified through their own department is unremarkable, and one
     * qualified across departments was a deliberate decision somebody made,
     * which is worth being able to see without opening the teacher.
     *
     * @return list<array<string,mixed>>
     */
    public static function subjectTeachers(int $subjectId): array
    {
        return Database::instance()->select(
            "SELECT t.teacher_id, t.employee_number, t.first_name, t.last_name,
                    t.status, ts.is_exception, d.department_name,
                    (SELECT COUNT(*) FROM schedules sch
                      WHERE sch.teacher_id = t.teacher_id
                        AND sch.subject_id = :subject2
                        AND sch.status = 'active' AND sch.deleted_at IS NULL) AS schedule_count
               FROM teacher_subjects ts
               JOIN teachers t    ON t.teacher_id = ts.teacher_id
               JOIN departments d ON d.department_id = t.department_id
              WHERE ts.subject_id = :subject AND t.deleted_at IS NULL
              ORDER BY t.last_name, t.first_name",
            ['subject' => $subjectId, 'subject2' => $subjectId]
        );
    }

    /**
     * What archiving a subject would affect.
     *
     * Subjects were the only thing in Academic Setup with no way to remove
     * them at all — departments and sections both archive, subjects had
     * nothing, so a duplicate created by a typo or left behind by a change of
     * naming stayed in every dropdown for good. This is the list an
     * administrator sees before deciding, the same shape departmentArchiveImpact
     * returns.
     *
     * @return array<string,mixed>
     */
    public static function subjectArchiveImpact(int $subjectId): array
    {
        $db = Database::instance();

        return [
            'teachers' => $db->select(
                "SELECT t.teacher_id, t.employee_number, t.first_name, t.last_name
                   FROM teacher_subjects ts
                   JOIN teachers t ON t.teacher_id = ts.teacher_id
                  WHERE ts.subject_id = :id AND t.deleted_at IS NULL",
                ['id' => $subjectId]
            ),
            'schedules' => $db->select(
                "SELECT sch.schedule_id, sch.day_of_week, sch.start_time,
                        sec.section_code, c.room_number
                   FROM schedules sch
                   JOIN sections sec  ON sec.section_id = sch.section_id
                   JOIN classrooms c  ON c.classroom_id = sch.classroom_id
                  WHERE sch.subject_id = :id
                    AND sch.status = 'active' AND sch.deleted_at IS NULL",
                ['id' => $subjectId]
            ),
            // Named but never a blocker. Attendance is permanent, and it keeps
            // resolving through subject_id whatever happens to the subject row
            // — archiving does not touch a single record. Saying the number out
            // loud is what stops somebody assuming it does.
            'attendance_records' => (int) $db->scalar(
                'SELECT COUNT(*) FROM attendance_records WHERE subject_id = :id',
                ['id' => $subjectId]
            ),
        ];
    }

    /**
     * Archive a subject.
     *
     * A soft delete, like every other archive here: the row stays, attendance
     * keeps resolving through it, and restoreSubject() undoes it.
     *
     * Active schedules are a hard stop rather than a confirmable warning.
     * ScheduleService does not check a subject's status when it decides what a
     * terminal may open, so a schedule whose subject has been archived goes on
     * running — a class that opens every day for a subject the school believes
     * it has removed. Archiving with the schedules still in place would create
     * exactly that, silently. Teacher assignments are different: they grant
     * nothing on their own and are cleared here.
     */
    public static function archiveSubject(int $subjectId, bool $confirmed): void
    {
        $db      = Database::instance();
        $subject = $db->selectOne(
            'SELECT subject_id, subject_code, subject_name FROM subjects
              WHERE subject_id = :id AND deleted_at IS NULL',
            ['id' => $subjectId]
        );

        if ($subject === null) {
            throw new ValidationException(['subject_id' => ['Subject not found, or already archived.']]);
        }

        $impact = self::subjectArchiveImpact($subjectId);

        if ($impact['schedules'] !== []) {
            // Named, not counted. "This subject is on 3 active schedules" tells
            // somebody they are blocked without telling them by what, and the
            // Schedules list has no subject filter to go and find them with —
            // so a count alone leaves them scrolling a week's timetable looking
            // for three rows. Each one is identified well enough to be found on
            // sight.
            $listed = array_slice($impact['schedules'], 0, 6);
            $lines  = [];

            foreach ($listed as $schedule) {
                $lines[] = sprintf(
                    '%s %s · %s · Room %s',
                    (string) $schedule['day_of_week'],
                    substr((string) $schedule['start_time'], 0, 5),
                    (string) $schedule['section_code'],
                    (string) $schedule['room_number']
                );
            }

            $remaining = count($impact['schedules']) - count($listed);

            throw new ValidationException(['subject_id' => [sprintf(
                'This subject is on %d active schedule(s): %s%s. A schedule keeps running even '
                . 'after its subject is archived, so those classes would still open every day for '
                . 'a subject you believe you have removed. Archive or repoint them first.',
                count($impact['schedules']),
                implode('; ', $lines),
                $remaining > 0 ? sprintf(' and %d more', $remaining) : ''
            )]]);
        }

        if (!$confirmed && $impact['teachers'] !== []) {
            throw new ValidationException(
                ['subject_id' => [sprintf(
                    'Archiving this subject removes it from %d teacher(s) qualified to teach it. '
                    . 'Confirm to continue.',
                    count($impact['teachers'])
                )]],
                'Subject archive requires confirmation.',
                'SUBJECT_ARCHIVE_CASCADE'
            );
        }

        $db->transaction(static function (Database $db) use ($subjectId): void {
            $db->update('subjects', [
                'status'     => 'inactive',
                'deleted_at' => Clock::nowString(),
                'updated_at' => Clock::nowString(),
            ], ['subject_id' => $subjectId]);

            // The qualification goes with it. Leaving it would let the subject
            // reappear in a teacher's list the moment it was restored, with
            // nobody having decided that.
            $db->execute('DELETE FROM teacher_subjects WHERE subject_id = :s', ['s' => $subjectId]);
        });

        AuditService::log(
            AuditService::SUBJECT_ARCHIVED,
            'academic_setup',
            'subject',
            $subjectId,
            null,
            [
                'teachers_unassigned' => count($impact['teachers']),
                'attendance_records'  => $impact['attendance_records'],
            ],
            sprintf(
                'Subject %s (%s) archived. %d teacher assignment(s) removed; %d attendance record(s) untouched.',
                (string) $subject['subject_code'],
                (string) $subject['subject_name'],
                count($impact['teachers']),
                $impact['attendance_records']
            )
        );
    }

    /** Undo a subject archive. Teacher assignments are not restored with it. */
    public static function restoreSubject(int $subjectId): void
    {
        $db      = Database::instance();
        $subject = $db->selectOne(
            'SELECT subject_id, subject_code, deleted_at FROM subjects WHERE subject_id = :id',
            ['id' => $subjectId]
        );

        if ($subject === null) {
            throw new ValidationException(['subject_id' => ['Subject not found.']]);
        }

        if ($subject['deleted_at'] === null) {
            throw new ValidationException(['subject_id' => ['That subject is not archived.']]);
        }

        $clash = $db->scalar(
            'SELECT COUNT(*) FROM subjects
              WHERE subject_code = (SELECT subject_code FROM subjects WHERE subject_id = :id)
                AND subject_id <> :id2 AND deleted_at IS NULL',
            ['id' => $subjectId, 'id2' => $subjectId]
        );

        if ((int) $clash > 0) {
            throw new ValidationException(['subject_code' => [
                'Another subject is already using this code. Rename that one first, or change this '
                . 'subject\'s code before restoring it.',
            ]]);
        }

        $db->update('subjects', [
            'deleted_at' => null,
            'updated_at' => Clock::nowString(),
        ], ['subject_id' => $subjectId]);

        AuditService::log(
            AuditService::SUBJECT_UPDATED,
            'academic_setup',
            'subject',
            $subjectId,
            ['deleted_at' => $subject['deleted_at']],
            ['deleted_at' => null],
            sprintf(
                'Subject %s restored from the archive. It comes back inactive and with no teachers assigned.',
                (string) $subject['subject_code']
            )
        );
    }

    /** @return list<array<string,mixed>> */
    public static function subjects(array $filters = []): array
    {
        // Archived subjects are excluded unless they are what was asked for —
        // the same shape sections() uses, so both archives are reachable the
        // same way.
        $where    = [!empty($filters['archived']) ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL'];
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

    /**
     * The syllabus written for this subject at each grade it is offered to.
     *
     * One subject taught across six grades is not six syllabuses' worth of the
     * same text — Mathematics in Grade 1 and Mathematics in Grade 6 share a
     * name and nothing else. The subject's own description says what the
     * subject is; this says what it covers where.
     *
     * @return array<int,string> grade_level_id => syllabus, blank ones omitted
     */
    public static function subjectSyllabuses(int $subjectId): array
    {
        $rows = Database::instance()->select(
            "SELECT grade_level_id, syllabus
               FROM subject_grade_levels
              WHERE subject_id = :id AND syllabus IS NOT NULL AND syllabus <> ''",
            ['id' => $subjectId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['grade_level_id']] = (string) $row['syllabus'];
        }

        return $out;
    }

    /**
     * Write the grades a subject is offered to, and the syllabus for each.
     *
     * Not a DELETE followed by re-INSERT, which is what this used to be. That
     * was harmless while the table held nothing but the pairing; now it holds
     * the syllabus, and rewriting the set on every edit would silently destroy
     * a term's worth of somebody's writing every time the subject's name was
     * corrected.
     *
     * So: remove only what was actually unticked, add only what is actually
     * new, and update the syllabus in place. A grade that stays ticked keeps
     * its text untouched unless the caller sent a new one for it.
     *
     * @param list<int>          $gradeLevelIds
     * @param array<int,string>  $syllabuses grade_level_id => text
     */
    private static function writeSubjectGradeLevels(
        Database $db,
        int $subjectId,
        array $gradeLevelIds,
        array $syllabuses
    ): void {
        $wanted = array_values(array_unique(array_map('intval', $gradeLevelIds)));

        if ($wanted === []) {
            $db->execute('DELETE FROM subject_grade_levels WHERE subject_id = :s', ['s' => $subjectId]);

            return;
        }

        $placeholders = [];
        $bindings     = ['s' => $subjectId];

        foreach ($wanted as $index => $gradeLevelId) {
            $placeholders[]            = ':g' . $index;
            $bindings['g' . $index]    = $gradeLevelId;
        }

        $db->execute(
            'DELETE FROM subject_grade_levels
              WHERE subject_id = :s AND grade_level_id NOT IN (' . implode(',', $placeholders) . ')',
            $bindings
        );

        foreach ($wanted as $gradeLevelId) {
            $db->execute(
                'INSERT IGNORE INTO subject_grade_levels (subject_id, grade_level_id) VALUES (:s, :g)',
                ['s' => $subjectId, 'g' => $gradeLevelId]
            );

            // Only when the caller actually sent something for this grade.
            // An edit form that does not carry the syllabus fields must not be
            // read as an instruction to clear them.
            if (!array_key_exists($gradeLevelId, $syllabuses)) {
                continue;
            }

            $text = trim((string) $syllabuses[$gradeLevelId]);

            $db->execute(
                'UPDATE subject_grade_levels SET syllabus = :t
                  WHERE subject_id = :s AND grade_level_id = :g',
                ['t' => $text === '' ? null : $text, 's' => $subjectId, 'g' => $gradeLevelId]
            );
        }
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
    /**
     * Archive a classroom.
     *
     * Classrooms could be created and listed and nothing else — no edit, no
     * removal — so a room added by mistake, or one the school simply does not
     * use, stayed in the list and in every schedule dropdown for good.
     *
     * Two hard stops, both about things that would go on working invisibly:
     *
     * A registered terminal is bound to its classroom, and DeviceApi resolves
     * the room from the device rather than the other way round. Archiving the
     * room out from under a live terminal would leave it opening sessions in a
     * classroom the school believes it has removed.
     *
     * Active schedules are the same argument as subjects — ScheduleService
     * does not check a classroom's status when deciding what may open.
     */
    public static function archiveClassroom(int $classroomId): void
    {
        $db        = Database::instance();
        $classroom = $db->selectOne(
            'SELECT classroom_id, room_number, building FROM classrooms
              WHERE classroom_id = :id AND deleted_at IS NULL',
            ['id' => $classroomId]
        );

        if ($classroom === null) {
            throw new ValidationException(['classroom_id' => ['Classroom not found, or already archived.']]);
        }

        $device = $db->selectOne(
            "SELECT device_id FROM devices
              WHERE classroom_id = :id AND deleted_at IS NULL
                AND status NOT IN ('decommissioned')",
            ['id' => $classroomId]
        );

        if ($device !== null) {
            throw new ValidationException(['classroom_id' => [sprintf(
                'Terminal %s is registered to this room. A terminal resolves its classroom from '
                . 'this record, so it would go on opening sessions in a room you believe you have '
                . 'removed. Move the terminal to another classroom or decommission it first.',
                (string) $device['device_id']
            )]]);
        }

        $schedules = $db->select(
            "SELECT sch.day_of_week, sch.start_time, sub.subject_code, sec.section_code
               FROM schedules sch
               JOIN subjects sub ON sub.subject_id = sch.subject_id
               JOIN sections sec ON sec.section_id = sch.section_id
              WHERE sch.classroom_id = :id AND sch.status = 'active' AND sch.deleted_at IS NULL",
            ['id' => $classroomId]
        );

        if ($schedules !== []) {
            $listed = array_slice($schedules, 0, 6);
            $lines  = [];

            foreach ($listed as $schedule) {
                $lines[] = sprintf(
                    '%s %s · %s · %s',
                    (string) $schedule['day_of_week'],
                    substr((string) $schedule['start_time'], 0, 5),
                    (string) $schedule['subject_code'],
                    (string) $schedule['section_code']
                );
            }

            $remaining = count($schedules) - count($listed);

            throw new ValidationException(['classroom_id' => [sprintf(
                'This room is on %d active schedule(s): %s%s. Move or archive them first.',
                count($schedules),
                implode('; ', $lines),
                $remaining > 0 ? sprintf(' and %d more', $remaining) : ''
            )]]);
        }

        $db->update('classrooms', [
            'status'     => 'inactive',
            'deleted_at' => Clock::nowString(),
            'updated_at' => Clock::nowString(),
        ], ['classroom_id' => $classroomId]);

        AuditService::log(
            AuditService::CLASSROOM_ARCHIVED,
            'academic_setup',
            'classroom',
            $classroomId,
            null,
            null,
            sprintf(
                'Classroom %s (%s) archived.',
                (string) $classroom['room_number'],
                (string) $classroom['building']
            )
        );
    }

    /** Undo a classroom archive. It comes back inactive. */
    public static function restoreClassroom(int $classroomId): void
    {
        $db        = Database::instance();
        $classroom = $db->selectOne(
            'SELECT classroom_id, room_number, deleted_at FROM classrooms WHERE classroom_id = :id',
            ['id' => $classroomId]
        );

        if ($classroom === null) {
            throw new ValidationException(['classroom_id' => ['Classroom not found.']]);
        }

        if ($classroom['deleted_at'] === null) {
            throw new ValidationException(['classroom_id' => ['That classroom is not archived.']]);
        }

        $db->update('classrooms', [
            'deleted_at' => null,
            'updated_at' => Clock::nowString(),
        ], ['classroom_id' => $classroomId]);

        AuditService::log(
            AuditService::CLASSROOM_UPDATED,
            'academic_setup',
            'classroom',
            $classroomId,
            ['deleted_at' => $classroom['deleted_at']],
            ['deleted_at' => null],
            sprintf('Classroom %s restored from the archive.', (string) $classroom['room_number'])
        );
    }

    public static function classrooms(bool $activeOnly = false, bool $archived = false): array
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
                 WHERE c.deleted_at IS " . ($archived ? 'NOT NULL' : 'NULL') . "";

        if ($activeOnly) {
            $sql .= " AND c.status = 'active'";
        }

        return Database::instance()->select($sql . ' ORDER BY c.building, c.room_number');
    }

    /**
     * Why assignableClassrooms() came back empty.
     *
     * "Register a device before scheduling" is the wrong instruction to give
     * somebody who has registered one — and registering a terminal without
     * choosing a classroom is easy, because the field defaults to Unassigned
     * and nothing on that form says the terminal is unusable without it. The
     * three states need three different sentences.
     *
     * @return array{reason:string,message:string,action_url:string,devices:list<array<string,mixed>>}
     */
    public static function classroomTerminalGap(): array
    {
        $db = Database::instance();

        $classrooms = (int) $db->scalar(
            "SELECT COUNT(*) FROM classrooms WHERE status = 'active' AND deleted_at IS NULL"
        );

        if ($classrooms === 0) {
            return [
                'reason'     => 'no_classrooms',
                'message'    => 'No classroom exists yet. Add a classroom, then give it a terminal.',
                'action_url' => '/admin/classrooms',
                'devices'    => [],
            ];
        }

        // Same conditions assignableClassrooms() joins on, minus the classroom.
        $unassigned = $db->select(
            "SELECT id, device_id, device_name
               FROM devices
              WHERE classroom_id IS NULL
                AND deleted_at IS NULL
                AND status IN ('active','offline','pending')
                AND device_role IN ('both','entry')
              ORDER BY device_id"
        );

        if ($unassigned !== []) {
            $names = implode(', ', array_map(static fn (array $d): string => (string) $d['device_id'], $unassigned));

            return [
                'reason'     => 'devices_unassigned',
                'message'    => count($unassigned) === 1
                    ? sprintf(
                        'Terminal %s is registered but not assigned to a classroom, so no room can host a '
                        . 'schedule yet. Open it and set its Classroom.',
                        $names
                    )
                    : sprintf(
                        '%d terminals are registered but none is assigned to a classroom, so no room can host '
                        . 'a schedule yet. Open one and set its Classroom: %s.',
                        count($unassigned),
                        $names
                    ),
                'action_url' => '/admin/devices',
                'devices'    => $unassigned,
            ];
        }

        return [
            'reason'     => 'no_devices',
            'message'    => 'No classroom has a registered attendance terminal. Register a device before scheduling.',
            'action_url' => '/admin/devices',
            'devices'    => [],
        ];
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

    /**
     * A strand only exists in Senior High.
     *
     * Grades 7–10 follow a single curriculum with no track, so a strand there
     * is meaningless — and meaningless once stored is meaningless in every
     * report and export that reads the column afterwards. The section form
     * hides the field below Grade 11, but hiding a field is a convenience;
     * this is the rule. A value submitted for a junior grade is dropped rather
     * than rejected, because the request is not malicious — it is a stale
     * field on a form whose grade level changed.
     */
    private static function strandFor(int $gradeLevelId, mixed $strand): ?string
    {
        $numericLevel = (int) Database::instance()->scalar(
            'SELECT numeric_level FROM grade_levels WHERE grade_level_id = :id',
            ['id' => $gradeLevelId]
        );

        return $numericLevel >= 11 ? self::nullIfBlank($strand) : null;
    }
}
