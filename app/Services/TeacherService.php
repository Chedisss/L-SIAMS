<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Validators\TeacherSectionAssignmentValidator;
use App\Validators\TeacherSubjectAssignmentValidator;
use PDOException;

/**
 * Teacher records, department and grade-level assignments.
 *
 * The department change path is the interesting one: Part 14.1 requires that
 * moving a teacher between departments surfaces every assignment and schedule
 * that would become invalid, and that the whole operation runs in one
 * transaction that rolls back cleanly if the administrator cancels.
 */
final class TeacherService
{
    /** @param array<string,mixed> $data */
    public static function create(array $data, int $userId): int
    {
        $db = Database::instance();

        $subjectIds    = array_map('intval', (array) ($data['subject_ids'] ?? []));
        $sectionIds    = array_map('intval', (array) ($data['section_ids'] ?? []));
        $gradeLevelIds = array_map('intval', (array) ($data['grade_level_ids'] ?? []));

        if ($gradeLevelIds === []) {
            throw new ValidationException([
                'grade_level_ids' => ['Assign at least one grade level. A teacher without one cannot be placed on any schedule.'],
            ]);
        }

        return (int) $db->transaction(static function (Database $db) use (
            $data, $userId, $subjectIds, $sectionIds, $gradeLevelIds
        ): int {
            try {
                $teacherId = (int) $db->insert('teachers', [
                    'employee_number' => trim((string) $data['employee_number']),
                    'user_id'         => isset($data['user_id']) ? (int) $data['user_id'] : null,
                    'department_id'   => (int) $data['department_id'],
                    'first_name'      => trim((string) $data['first_name']),
                    'middle_name'     => self::nullIfBlank($data['middle_name'] ?? null),
                    'last_name'       => trim((string) $data['last_name']),
                    'suffix'          => self::nullIfBlank($data['suffix'] ?? null),
                    'email'           => mb_strtolower(trim((string) $data['email'])),
                    'phone'           => self::nullIfBlank($data['phone'] ?? null),
                    'position'        => self::nullIfBlank($data['position'] ?? null),
                    'photo_path'      => self::nullIfBlank($data['photo_path'] ?? null),
                    'status'          => (string) ($data['status'] ?? 'active'),
                    'hired_at'        => self::nullIfBlank($data['hired_at'] ?? null),
                    'created_at'      => Clock::nowString(),
                    'updated_at'      => Clock::nowString(),
                ]);
            } catch (PDOException $e) {
                throw self::translateDatabaseError($e);
            }

            $db->execute(
                'INSERT INTO teacher_departments (teacher_id, department_id, is_primary, assigned_by)
                      VALUES (:t, :d, 1, :u)',
                ['t' => $teacherId, 'd' => (int) $data['department_id'], 'u' => $userId]
            );

            self::replaceGradeLevels($db, $teacherId, $gradeLevelIds, $userId);

            // Validators run *after* the grade levels are in place, because the
            // section check reads from that pivot. The transaction means a
            // rejection here unwinds the teacher row too.
            if ($subjectIds !== []) {
                TeacherSubjectAssignmentValidator::assertAssignable($teacherId, $subjectIds);
                self::replaceSubjects($db, $teacherId, $subjectIds, $userId);
            }

            if ($sectionIds !== []) {
                TeacherSectionAssignmentValidator::assertAssignable($teacherId, $sectionIds);
                self::replaceSections($db, $teacherId, $sectionIds, $userId);
            }

            AuditService::log(
                AuditService::TEACHER_CREATED,
                'teachers',
                'teacher',
                $teacherId,
                null,
                [
                    'employee_number' => $data['employee_number'],
                    'department_id'   => (int) $data['department_id'],
                    'grade_level_ids' => $gradeLevelIds,
                    'subject_ids'     => $subjectIds,
                    'section_ids'     => $sectionIds,
                ],
                sprintf('Teacher %s registered.', $data['employee_number'])
            );

            return $teacherId;
        });
    }

    /** @param array<string,mixed> $data */
    public static function update(int $teacherId, array $data, int $userId, bool $confirmedCascade = false): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne(
            'SELECT * FROM teachers WHERE teacher_id = :id AND deleted_at IS NULL',
            ['id' => $teacherId]
        );

        if ($existing === null) {
            throw new ValidationException(['teacher_id' => ['Teacher not found.']]);
        }

        $newDepartmentId = isset($data['department_id'])
            ? (int) $data['department_id']
            : (int) $existing['department_id'];

        $departmentChanged = $newDepartmentId !== (int) $existing['department_id'];

        if ($departmentChanged && !$confirmedCascade) {
            $impact = TeacherSubjectAssignmentValidator::impactOfDepartmentChange($teacherId, $newDepartmentId);

            if ($impact['subjects'] !== [] || $impact['schedules'] !== []) {
                $newDepartment = $db->selectOne(
                    'SELECT department_name FROM departments WHERE department_id = :id',
                    ['id' => $newDepartmentId]
                );
                $oldDepartment = $db->selectOne(
                    'SELECT department_name FROM departments WHERE department_id = :id',
                    ['id' => (int) $existing['department_id']]
                );

                throw new ValidationException(
                    ['department_id' => [sprintf(
                        'Moving %s %s from %s to %s will invalidate %d subject assignment(s) and %d active schedule(s). Review before continuing.',
                        $existing['first_name'],
                        $existing['last_name'],
                        $oldDepartment['department_name'] ?? '?',
                        $newDepartment['department_name'] ?? '?',
                        count($impact['subjects']),
                        count($impact['schedules'])
                    )]],
                    'Department change requires confirmation.',
                    'DEPARTMENT_CHANGE_CASCADE'
                );
            }
        }

        $gradeLevelIds = isset($data['grade_level_ids'])
            ? array_map('intval', (array) $data['grade_level_ids'])
            : null;

        if ($gradeLevelIds !== null && $gradeLevelIds === []) {
            throw new ValidationException([
                'grade_level_ids' => ['A teacher must have at least one assigned grade level.'],
            ]);
        }

        if ($gradeLevelIds !== null && !$confirmedCascade) {
            $orphaned = TeacherSectionAssignmentValidator::impactOfGradeLevelChange($teacherId, $gradeLevelIds);

            if ($orphaned !== []) {
                throw new ValidationException(
                    ['grade_level_ids' => [sprintf(
                        'Changing the assigned grade levels will invalidate %d active schedule(s). Review before continuing.',
                        count($orphaned)
                    )]],
                    'Grade level change requires confirmation.',
                    'GRADE_LEVEL_CHANGE_CASCADE'
                );
            }
        }

        $db->transaction(static function (Database $db) use (
            $teacherId, $data, $userId, $existing, $newDepartmentId, $departmentChanged, $gradeLevelIds
        ): void {
            $update = [
                'department_id' => $newDepartmentId,
                'first_name'    => trim((string) ($data['first_name'] ?? $existing['first_name'])),
                'middle_name'   => self::nullIfBlank($data['middle_name'] ?? $existing['middle_name']),
                'last_name'     => trim((string) ($data['last_name'] ?? $existing['last_name'])),
                'suffix'        => self::nullIfBlank($data['suffix'] ?? $existing['suffix']),
                'email'         => mb_strtolower(trim((string) ($data['email'] ?? $existing['email']))),
                'phone'         => self::nullIfBlank($data['phone'] ?? $existing['phone']),
                'position'      => self::nullIfBlank($data['position'] ?? $existing['position']),
                'status'        => (string) ($data['status'] ?? $existing['status']),
                'updated_at'    => Clock::nowString(),
            ];

            if (!empty($data['photo_path'])) {
                $update['photo_path'] = (string) $data['photo_path'];
            }

            try {
                $db->update('teachers', $update, ['teacher_id' => $teacherId]);
            } catch (PDOException $e) {
                throw self::translateDatabaseError($e);
            }

            if ($departmentChanged) {
                $db->execute(
                    'UPDATE teacher_departments SET is_primary = 0 WHERE teacher_id = :t',
                    ['t' => $teacherId]
                );
                $db->execute(
                    'INSERT INTO teacher_departments (teacher_id, department_id, is_primary, assigned_by)
                          VALUES (:t, :d, 1, :u)
                     ON DUPLICATE KEY UPDATE is_primary = 1',
                    ['t' => $teacherId, 'd' => $newDepartmentId, 'u' => $userId]
                );

                // Drop the assignments that are no longer legal under the new
                // department. Archiving the schedules rather than deleting them
                // preserves the attendance already recorded against each one.
                $orphanedSubjects = $db->select(
                    'SELECT ts.subject_id FROM teacher_subjects ts
                       JOIN subjects s ON s.subject_id = ts.subject_id
                      WHERE ts.teacher_id = :t AND s.department_id <> :d AND ts.is_exception = 0',
                    ['t' => $teacherId, 'd' => $newDepartmentId]
                );

                foreach ($orphanedSubjects as $row) {
                    $db->execute(
                        'DELETE FROM teacher_subjects WHERE teacher_id = :t AND subject_id = :s',
                        ['t' => $teacherId, 's' => (int) $row['subject_id']]
                    );
                }

                $db->execute(
                    "UPDATE schedules sch
                       JOIN subjects s ON s.subject_id = sch.subject_id
                        SET sch.status = 'archived', sch.deleted_at = :now
                     WHERE sch.teacher_id = :t
                       AND s.department_id <> :d
                       AND sch.status = 'active'",
                    ['t' => $teacherId, 'd' => $newDepartmentId, 'now' => Clock::nowString()]
                );

                AuditService::log(
                    AuditService::TEACHER_DEPARTMENT_CHANGED,
                    'teachers',
                    'teacher',
                    $teacherId,
                    ['department_id' => (int) $existing['department_id']],
                    ['department_id' => $newDepartmentId, 'orphaned_subjects' => count($orphanedSubjects)],
                    sprintf(
                        'Department changed. %d subject assignment(s) removed and affected schedules archived.',
                        count($orphanedSubjects)
                    )
                );
            }

            if ($gradeLevelIds !== null) {
                self::replaceGradeLevels($db, $teacherId, $gradeLevelIds, $userId);

                $db->execute(
                    "UPDATE schedules sch
                       JOIN sections sec ON sec.section_id = sch.section_id
                        SET sch.status = 'archived', sch.deleted_at = :now
                     WHERE sch.teacher_id = :t
                       AND sch.status = 'active'
                       AND sec.grade_level_id NOT IN (" . implode(',', array_map('intval', $gradeLevelIds ?: [0])) . ')',
                    ['t' => $teacherId, 'now' => Clock::nowString()]
                );

                AuditService::log(
                    AuditService::TEACHER_GRADE_LEVELS_CHANGED,
                    'teachers',
                    'teacher',
                    $teacherId,
                    null,
                    ['grade_level_ids' => $gradeLevelIds],
                    'Assigned grade levels updated.'
                );
            }

            if (isset($data['subject_ids'])) {
                $subjectIds = array_map('intval', (array) $data['subject_ids']);
                TeacherSubjectAssignmentValidator::assertAssignable($teacherId, $subjectIds);
                self::replaceSubjects($db, $teacherId, $subjectIds, $userId);
            }

            if (isset($data['section_ids'])) {
                $sectionIds = array_map('intval', (array) $data['section_ids']);
                TeacherSectionAssignmentValidator::assertAssignable($teacherId, $sectionIds);
                self::replaceSections($db, $teacherId, $sectionIds, $userId);
            }

            AuditService::logChange(
                AuditService::TEACHER_UPDATED,
                'teachers',
                'teacher',
                $teacherId,
                $existing,
                $update
            );
        });
    }

    /** @param list<int> $gradeLevelIds */
    private static function replaceGradeLevels(Database $db, int $teacherId, array $gradeLevelIds, int $userId): void
    {
        $db->execute('DELETE FROM teacher_grade_levels WHERE teacher_id = :t', ['t' => $teacherId]);

        foreach (array_unique($gradeLevelIds) as $gradeLevelId) {
            $db->execute(
                'INSERT IGNORE INTO teacher_grade_levels (teacher_id, grade_level_id, assigned_by)
                      VALUES (:t, :g, :u)',
                ['t' => $teacherId, 'g' => $gradeLevelId, 'u' => $userId]
            );
        }
    }

    /** @param list<int> $subjectIds */
    private static function replaceSubjects(Database $db, int $teacherId, array $subjectIds, int $userId): void
    {
        $db->execute('DELETE FROM teacher_subjects WHERE teacher_id = :t', ['t' => $teacherId]);

        $primaryDepartment = (int) $db->scalar(
            'SELECT department_id FROM teachers WHERE teacher_id = :t',
            ['t' => $teacherId]
        );

        foreach (array_unique($subjectIds) as $subjectId) {
            $subjectDepartment = (int) $db->scalar(
                'SELECT department_id FROM subjects WHERE subject_id = :s',
                ['s' => $subjectId]
            );

            $db->execute(
                'INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, assigned_by, is_exception)
                      VALUES (:t, :s, :u, :ex)',
                [
                    't'  => $teacherId,
                    's'  => $subjectId,
                    'u'  => $userId,
                    // Flagged so the UI can show the amber Cross-Department badge
                    // everywhere the assignment appears (Part 14.6).
                    'ex' => $subjectDepartment === $primaryDepartment ? 0 : 1,
                ]
            );
        }
    }

    /** @param list<int> $sectionIds */
    private static function replaceSections(Database $db, int $teacherId, array $sectionIds, int $userId): void
    {
        $db->execute('DELETE FROM teacher_sections WHERE teacher_id = :t', ['t' => $teacherId]);

        foreach (array_unique($sectionIds) as $sectionId) {
            $db->execute(
                'INSERT IGNORE INTO teacher_sections (teacher_id, section_id, assigned_by) VALUES (:t, :s, :u)',
                ['t' => $teacherId, 's' => $sectionId, 'u' => $userId]
            );
        }
    }

    private static function translateDatabaseError(PDOException $e): ValidationException
    {
        if (!Database::isDuplicateKey($e)) {
            throw $e;
        }

        $key = Database::duplicateKeyName($e);

        return match ($key) {
            'uq_teacher_employee' => new ValidationException(['employee_number' => ['This employee number is already registered.']]),
            'uq_teacher_email'    => new ValidationException(['email' => ['This email address is already registered to another teacher.']]),
            'uq_teacher_user'     => new ValidationException(['user_id' => ['This user account is already linked to another teacher.']]),
            default               => new ValidationException(['employee_number' => ['A teacher with these details already exists.']]),
        };
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
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where    = ['t.deleted_at IS NULL'];
        $bindings = [];

        if (!empty($filters['search'])) {
            $where[] = '(t.employee_number LIKE :search
                         OR CONCAT(t.first_name, \' \', t.last_name) LIKE :search
                         OR t.email LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        foreach (['department_id' => 't.department_id', 'status' => 't.status', 'fingerprint_status' => 't.fingerprint_status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]        = "{$column} = :{$key}";
                $bindings[$key] = $filters[$key];
            }
        }

        $whereSql = implode(' AND ', $where);
        $db       = Database::instance();

        $total = (int) $db->scalar(
            "SELECT COUNT(*) FROM teachers t WHERE {$whereSql}",
            $bindings
        );

        $rows = $db->select(
            "SELECT t.*, d.department_name, d.department_code, u.username, u.status AS account_status,
                    (SELECT GROUP_CONCAT(gl.grade_level_code ORDER BY gl.numeric_level SEPARATOR ', ')
                       FROM teacher_grade_levels tgl
                       JOIN grade_levels gl ON gl.grade_level_id = tgl.grade_level_id
                      WHERE tgl.teacher_id = t.teacher_id) AS grade_levels,
                    (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.teacher_id = t.teacher_id) AS subject_count,
                    (SELECT COUNT(*) FROM teacher_sections tsec WHERE tsec.teacher_id = t.teacher_id) AS section_count,
                    (SELECT COUNT(*) FROM schedules sch
                      WHERE sch.teacher_id = t.teacher_id AND sch.status = 'active' AND sch.deleted_at IS NULL) AS schedule_count
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
               LEFT JOIN users u  ON u.user_id = t.user_id
              WHERE {$whereSql}
              ORDER BY t.last_name, t.first_name
              LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $teacherId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT t.*, d.department_name, d.department_code, u.username, u.status AS account_status,
                    u.last_login_at, fp.sensor_template_id, fp.enrollment_date, fp.verification_count,
                    fp.last_verified_at, fp.status AS fingerprint_record_status
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
               LEFT JOIN users u  ON u.user_id = t.user_id
               LEFT JOIN fingerprint_templates fp ON fp.teacher_id = t.teacher_id
              WHERE t.teacher_id = :id AND t.deleted_at IS NULL',
            ['id' => $teacherId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findByUserId(int $userId): ?array
    {
        $id = Database::instance()->scalar(
            'SELECT teacher_id FROM teachers WHERE user_id = :id AND deleted_at IS NULL',
            ['id' => $userId]
        );

        return $id === null ? null : self::find((int) $id);
    }

    /**
     * Teachers who may take a given subject in a given section.
     *
     * The inverse of the two assignment validators, which answer "what may
     * this teacher be given?". Scheduling asks the opposite question — the
     * class is the fixed thing and the teacher is what varies — and answering
     * it by listing every teacher and letting the administrator guess wastes
     * the constraint the system already knows.
     *
     * Eligibility is exactly what those validators enforce on save: the
     * subject's department must be one of the teacher's (primary, secondary,
     * or an active exception when exceptions are enabled), and the section's
     * grade level must be one the teacher carries (likewise). A teacher listed
     * here will pass validation; one omitted would have been refused.
     *
     * @return list<array<string,mixed>>
     */
    public static function assignableForPairing(int $subjectId, int $sectionId): array
    {
        $exceptions = TeacherSubjectAssignmentValidator::exceptionsEnabled();

        $departmentMatch = 'tdx.teacher_id IS NOT NULL';
        $gradeMatch      = 'tgx.teacher_id IS NOT NULL';

        return Database::instance()->select(
            sprintf(
                "SELECT t.teacher_id, t.first_name, t.last_name, t.employee_number,
                        d.department_code, d.department_name,
                        (t.department_id = sub.department_id) AS is_primary_department,
                        EXISTS (SELECT 1 FROM fingerprint_templates f
                                 WHERE f.teacher_id = t.teacher_id AND f.status = 'active') AS has_fingerprint
                   FROM teachers t
                   JOIN departments d ON d.department_id = t.department_id
                   JOIN subjects sub ON sub.subject_id = :subject
                   JOIN sections sec ON sec.section_id = :section
              LEFT JOIN teacher_departments td
                     ON td.teacher_id = t.teacher_id AND td.department_id = sub.department_id
              LEFT JOIN teacher_department_exceptions tdx
                     ON %s
              LEFT JOIN teacher_grade_levels tgl
                     ON tgl.teacher_id = t.teacher_id AND tgl.grade_level_id = sec.grade_level_id
              LEFT JOIN teacher_grade_level_exceptions tgx
                     ON %s
                  WHERE t.status = 'active'
                    AND t.deleted_at IS NULL
                    AND (t.department_id = sub.department_id OR td.teacher_id IS NOT NULL OR %s)
                    AND (tgl.teacher_id IS NOT NULL OR %s)
               ORDER BY is_primary_department DESC, t.last_name, t.first_name",
                $exceptions
                    ? "tdx.teacher_id = t.teacher_id AND tdx.department_id = sub.department_id
                       AND tdx.status = 'active'
                       AND tdx.effective_from <= CURDATE() AND tdx.effective_to >= CURDATE()"
                    : '1 = 0',
                $exceptions
                    ? "tgx.teacher_id = t.teacher_id AND tgx.grade_level_id = sec.grade_level_id
                       AND tgx.status = 'active'
                       AND tgx.effective_from <= CURDATE() AND tgx.effective_to >= CURDATE()"
                    : '1 = 0',
                $exceptions ? $departmentMatch : '1 = 0',
                $exceptions ? $gradeMatch : '1 = 0'
            ),
            ['subject' => $subjectId, 'section' => $sectionId]
        );
    }

    /** @return array<string,mixed> */
    public static function constraints(int $teacherId): array
    {
        $row = Database::instance()->selectOne(
            'SELECT * FROM v_teacher_constraints WHERE teacher_id = :id',
            ['id' => $teacherId]
        );

        if ($row === null) {
            throw new ValidationException(['teacher_id' => ['Teacher not found.']]);
        }

        $gradeLevels = Database::instance()->select(
            'SELECT gl.grade_level_id, gl.grade_level_code, gl.grade_level_name, gl.numeric_level, gl.track
               FROM teacher_grade_levels tgl
               JOIN grade_levels gl ON gl.grade_level_id = tgl.grade_level_id
              WHERE tgl.teacher_id = :id
              ORDER BY gl.numeric_level',
            ['id' => $teacherId]
        );

        return [
            'teacher_id'  => (int) $row['teacher_id'],
            'teacher_name' => (string) $row['teacher_name'],
            'department'  => [
                'department_id'   => (int) $row['department_id'],
                'department_code' => (string) $row['department_code'],
                'department_name' => (string) $row['department_name'],
            ],
            'grade_levels'              => $gradeLevels,
            'assignable_subject_count'  => (int) $row['assignable_subject_count'],
            'assignable_section_count'  => (int) $row['assignable_section_count'],
            'active_schedule_count'     => (int) $row['active_schedule_count'],
            'fingerprint_status'        => (string) $row['fingerprint_status'],
            'has_grade_level'           => (int) $row['grade_level_count'] > 0,
            'cross_department_exceptions_enabled' => TeacherSubjectAssignmentValidator::exceptionsEnabled(),
        ];
    }

    /** @return list<int> */
    public static function subjectIds(int $teacherId): array
    {
        $rows = Database::instance()->select(
            'SELECT subject_id FROM teacher_subjects WHERE teacher_id = :id',
            ['id' => $teacherId]
        );

        return array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
    }

    /** @return list<int> */
    public static function sectionIds(int $teacherId): array
    {
        $rows = Database::instance()->select(
            'SELECT section_id FROM teacher_sections WHERE teacher_id = :id',
            ['id' => $teacherId]
        );

        return array_map(static fn (array $r): int => (int) $r['section_id'], $rows);
    }

    /** @return list<int> */
    public static function gradeLevelIds(int $teacherId): array
    {
        return TeacherSectionAssignmentValidator::allowedGradeLevelIds($teacherId);
    }

    public static function archive(int $teacherId, int $userId): void
    {
        $db = Database::instance();

        $openSession = $db->scalar(
            "SELECT session_id FROM attendance_sessions WHERE teacher_id = :id AND status = 'open'",
            ['id' => $teacherId]
        );

        if ($openSession !== null) {
            throw new ValidationException([
                'teacher_id' => ['This teacher has an attendance session open right now. Close it before archiving.'],
            ]);
        }

        $teacher = $db->selectOne('SELECT * FROM teachers WHERE teacher_id = :id', ['id' => $teacherId]);

        if ($teacher === null) {
            throw new ValidationException(['teacher_id' => ['Teacher not found.']]);
        }

        $db->transaction(static function (Database $db) use ($teacherId, $teacher): void {
            $db->update('teachers', [
                'status'     => 'archived',
                'deleted_at' => Clock::nowString(),
                'updated_at' => Clock::nowString(),
            ], ['teacher_id' => $teacherId]);

            if ($teacher['user_id'] !== null) {
                $db->update('users', ['status' => 'archived'], ['user_id' => (int) $teacher['user_id']]);
                SessionService::terminateAllForUser((int) $teacher['user_id'], SessionService::REASON_ADMIN);
            }

            $db->execute(
                "UPDATE schedules SET status = 'archived', deleted_at = :now
                  WHERE teacher_id = :id AND status = 'active'",
                ['id' => $teacherId, 'now' => Clock::nowString()]
            );
        });

        AuditService::log(
            'TEACHER_ARCHIVED',
            'teachers',
            'teacher',
            $teacherId,
            ['status' => $teacher['status']],
            ['status' => 'archived'],
            sprintf('Teacher %s archived; account disabled and schedules archived.', $teacher['employee_number']),
            'success',
            $userId
        );
    }

    /** Teachers with no grade level — the amber badge and dashboard nudge in Part 14.2. @return list<array<string,mixed>> */
    public static function withoutGradeLevel(): array
    {
        return Database::instance()->select(
            "SELECT t.teacher_id, t.employee_number, t.first_name, t.last_name, d.department_name
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
              WHERE t.deleted_at IS NULL AND t.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM teacher_grade_levels tgl WHERE tgl.teacher_id = t.teacher_id)
              ORDER BY t.last_name"
        );
    }

    /**
     * The sections a teacher actually teaches, one row each.
     *
     * Derived from schedules rather than teacher_sections, matching how the
     * rest of the portal scopes a teacher: teacher_sections records who *may*
     * be given a section, a schedule records who *has* it. A teacher assigned
     * to a section they hold no class for has no roster to look at.
     *
     * A section taught for two subjects is still one section, so the subjects
     * are collapsed into the row rather than duplicating the class.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * What is happening to this teacher's attempt to open a session.
     *
     * The terminal is already scanning; it does not need telling to start. What
     * was missing was any way for the teacher to see the result of putting a
     * finger down — the reader gives a beep the person at a computer cannot
     * hear, and the outcome only ever appeared in the terminal's serial log,
     * which nobody in a classroom is watching.
     *
     * Every verification attempt is already written to fingerprint_logs with
     * its result and message, so this reads that rather than inventing a
     * parallel channel the device would have to be taught to feed. Nothing on
     * the board changes, and the reasons shown are the real ones the server
     * gave, not a UI approximation of them.
     *
     * $since bounds the search to attempts made after the teacher started
     * watching. Without it the panel would open already showing this morning's
     * failure and look like a fresh one.
     *
     * @return array<string,mixed>
     */
    /**
     * Why a scan that should be happening is not reaching the server.
     *
     * Everything here is already known: the terminal reports its sensor's
     * health on every heartbeat, and fingerprint_slots records which templates
     * that terminal actually holds. None of it was being shown to the one
     * person standing in front of the reader.
     *
     * Ordered by what stops a scan first. A dead terminal cannot report a dead
     * sensor, and a sensor that never started cannot be missing a template, so
     * a lower check firing while a higher one is true would be describing a
     * symptom rather than the cause.
     *
     * @return array{blocked?:string,blocked_message?:string,terminal?:string}
     */
    private static function whyTheReaderIsSilent(int $teacherId): array
    {
        $db = Database::instance();

        // The terminal for the class whose scan window is open. No open
        // window means nothing is expected to happen, so there is nothing to
        // explain — the panel is not being shown either.
        $terminal = $db->selectOne(
            "SELECT d.id AS device_row_id, d.device_id, d.status, d.fingerprint_ok,
                    d.last_heartbeat_at, d.heartbeat_interval_sec, c.room_number
               FROM schedules sch
               JOIN classrooms c   ON c.classroom_id = sch.classroom_id
          LEFT JOIN devices d      ON d.classroom_id = c.classroom_id
                                  AND d.deleted_at IS NULL
                                  AND d.status = 'active'
              WHERE sch.teacher_id = :teacher
                AND sch.status = 'active'
                AND sch.deleted_at IS NULL
                AND sch.day_of_week = :dow
                -- The same window ScheduleService::activeForDevice() uses, and
                -- for the same reason: this panel must explain a silent reader
                -- over exactly the period the reader is expected to answer in.
                -- A hardcoded ten minutes was wrong at both ends — it ignored
                -- time_in_window_open, which a school may set to anything, and
                -- it stopped at end_time, so a teacher scanning during the
                -- tap-out tail got no diagnosis at all while the terminal was
                -- still perfectly willing to be scanned at.
                AND TIME(:now) >= SUBTIME(sch.start_time, SEC_TO_TIME(sch.time_in_window_open * 60))
                AND TIME(:now2) <= ADDTIME(sch.end_time, SEC_TO_TIME(sch.time_out_window_close * 60))
              ORDER BY sch.start_time
              LIMIT 1",
            [
                'teacher' => $teacherId,
                'dow'     => Clock::now()->format('l'),
                'now'     => Clock::now()->format('H:i:s'),
                'now2'    => Clock::now()->format('H:i:s'),
            ]
        );

        if ($terminal === null || $terminal['device_row_id'] === null) {
            return $terminal === null
                ? []
                : [
                    'blocked'         => 'no_terminal',
                    'blocked_message' => sprintf(
                        'No active terminal is registered for Room %s, so there is no reader to '
                        . 'scan at. Open the class with your password instead.',
                        (string) $terminal['room_number']
                    ),
                ];
        }

        $deviceCode = (string) $terminal['device_id'];

        // Offline. Two missed heartbeats rather than one, so a single late
        // poll on a busy network is not reported as a fault.
        $interval = max(10, (int) $terminal['heartbeat_interval_sec']);
        $silentFor = $terminal['last_heartbeat_at'] === null
            ? null
            : Clock::now()->getTimestamp() - Clock::parse((string) $terminal['last_heartbeat_at'])->getTimestamp();

        if ($silentFor === null || $silentFor > $interval * 3) {
            return [
                'blocked'         => 'terminal_offline',
                'blocked_message' => sprintf(
                    'Terminal %s in Room %s is not reporting in%s, so nothing you do at the reader '
                    . 'reaches this system. Check its power and Wi-Fi, or open the class with your '
                    . 'password.',
                    $deviceCode,
                    (string) $terminal['room_number'],
                    $silentFor === null ? ' at all' : sprintf(' (last heard %d seconds ago)', $silentFor)
                ),
                'terminal' => $deviceCode,
            ];
        }

        // The terminal is alive and telling us its sensor is not.
        if ($terminal['fingerprint_ok'] !== null && (int) $terminal['fingerprint_ok'] === 0) {
            return [
                'blocked'         => 'sensor_down',
                'blocked_message' => sprintf(
                    'The fingerprint reader on terminal %s is not responding — the terminal is '
                    . 'online but its sensor did not answer. That is a hardware fault, not your '
                    . 'finger, and scanning again will not help. Open the class with your password '
                    . 'and have the sensor checked.',
                    $deviceCode
                ),
                'terminal' => $deviceCode,
            ];
        }

        // The sensor works, but cannot match a print it does not hold. This is
        // the case that reads as "not recognised" at the terminal and as
        // nothing at all here, because the firmware does not post a search
        // that found nothing.
        $slot = $db->selectOne(
            "SELECT s.status
               FROM fingerprint_slots s
               JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
              WHERE fp.teacher_id = :teacher AND s.device_row_id = :device
              ORDER BY FIELD(s.status, 'present', 'pending', 'failed'), s.slot_row_id
              LIMIT 1",
            ['teacher' => $teacherId, 'device' => (int) $terminal['device_row_id']]
        );

        $status = $slot === null ? null : (string) $slot['status'];

        if ($status === 'present') {
            return ['terminal' => $deviceCode];
        }

        return [
            'blocked'         => 'template_missing',
            'blocked_message' => match ($status) {
                'pending' => sprintf(
                    'Your fingerprint has not finished copying to terminal %s yet, so its sensor '
                    . 'cannot match you. It collects one per poll — give it a few minutes, or open '
                    . 'the class with your password.',
                    $deviceCode
                ),
                'failed' => sprintf(
                    'Terminal %s refused to store your fingerprint, so its sensor has no copy to '
                    . 'match against. An administrator can retry it from Fingerprints. Open the '
                    . 'class with your password in the meantime.',
                    $deviceCode
                ),
                default => sprintf(
                    'Your fingerprint is not on terminal %s, so its sensor cannot recognise you '
                    . 'however many times you scan. Ask an administrator to check the Fingerprints '
                    . 'page, and open the class with your password for now.',
                    $deviceCode
                ),
            },
            'terminal' => $deviceCode,
        ];
    }

    public static function sessionStartState(int $teacherId, ?string $since = null): array
    {
        $db = Database::instance();

        $open = $db->selectOne(
            "SELECT s.session_id, s.session_code, sub.subject_code, sec.section_code,
                    c.room_number, s.opened_at, s.expires_at
               FROM attendance_sessions s
               JOIN subjects sub  ON sub.subject_id = s.subject_id
               JOIN sections sec  ON sec.section_id = s.section_id
               JOIN classrooms c  ON c.classroom_id = s.classroom_id
              WHERE s.teacher_id = :teacher AND s.status = 'open'
              ORDER BY s.opened_at DESC LIMIT 1",
            ['teacher' => $teacherId]
        );

        if ($open !== null) {
            return [
                'state'        => 'open',
                'session_id'   => (int) $open['session_id'],
                'session_code' => (string) $open['session_code'],
                'subject_code' => (string) $open['subject_code'],
                'section_code' => (string) $open['section_code'],
                'room_number'  => (string) $open['room_number'],
                'message'      => sprintf(
                    'Session %s is open for %s with %s in Room %s.',
                    $open['session_code'],
                    $open['subject_code'],
                    $open['section_code'],
                    $open['room_number']
                ),
            ];
        }

        // The most recent attempt since watching began, whatever its outcome.
        // A failure is the useful case: it carries the server's own reason.
        $attempt = $db->selectOne(
            "SELECT result, message, confidence, created_at
               FROM fingerprint_logs
              WHERE teacher_id = :teacher
                AND (:since IS NULL OR created_at >= :since2)
              ORDER BY log_id DESC LIMIT 1",
            ['teacher' => $teacherId, 'since' => $since, 'since2' => $since]
        );

        if ($attempt === null) {
            // 'waiting' used to be the end of it, and it was indistinguishable
            // from every way this can actually fail — because the two most
            // likely failures never reach the server at all.
            //
            // The terminal posts to /api/attendance/start only after its sensor
            // matches a print. A sensor that did not initialise makes
            // handleFingerprint() return at its first line; a sensor that has
            // no copy of this teacher's template answers NOTFOUND and returns.
            // Neither writes a fingerprint_logs row, so the panel sat on
            // "Waiting for your fingerprint… place your finger on the terminal
            // now" for as long as the teacher was willing to keep placing it,
            // while the reason was already in the database.
            return ['state' => 'waiting', 'message' => null]
                + self::whyTheReaderIsSilent($teacherId);
        }

        // 'verified' with no open session means the scan was accepted and the
        // session opened and closed again inside the poll interval, or another
        // room already had one. Reported as an attempt rather than success, so
        // the panel never claims a session that is not there.
        return [
            'state'      => (string) $attempt['result'] === 'verified' ? 'verified' : 'refused',
            'result'     => (string) $attempt['result'],
            'message'    => (string) ($attempt['message'] ?? ''),
            'confidence' => $attempt['confidence'] === null ? null : (int) $attempt['confidence'],
            'at'         => (string) $attempt['created_at'],
        ];
    }

    public static function sectionsForTeacher(int $teacherId): array
    {
        return Database::instance()->select(
            "SELECT sec.section_id,
                    sec.section_code,
                    sec.section_name,
                    gl.grade_level_code,
                    gl.grade_level_name,
                    GROUP_CONCAT(DISTINCT sub.subject_code ORDER BY sub.subject_code SEPARATOR ', ') AS subject_codes,
                    COUNT(DISTINCT sch.subject_id) AS subject_count,
                    (SELECT COUNT(*) FROM students st
                      WHERE st.section_id = sec.section_id
                        AND st.deleted_at IS NULL AND st.status = 'active') AS student_count,
                    (SELECT COUNT(*) FROM students st
                      WHERE st.section_id = sec.section_id
                        AND st.deleted_at IS NULL AND st.status = 'active'
                        AND NOT EXISTS (SELECT 1 FROM rfid_cards rc
                                         WHERE rc.student_id = st.student_id
                                           AND rc.status = 'active')) AS without_card
               FROM schedules sch
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               JOIN subjects sub    ON sub.subject_id = sch.subject_id
              WHERE sch.teacher_id = :teacher
                AND sch.status = 'active'
                AND sch.deleted_at IS NULL
                AND sec.deleted_at IS NULL
              GROUP BY sec.section_id, sec.section_code, sec.section_name,
                       gl.grade_level_code, gl.grade_level_name, gl.numeric_level
              ORDER BY gl.numeric_level, sec.section_code",
            ['teacher' => $teacherId]
        );
    }

    /**
     * One section's roster, or null if this teacher does not teach it.
     *
     * The ownership test is the first thing that happens and it runs against
     * the teacher id from the session, so a section id typed into the URL
     * cannot reach a colleague's class. Returning null rather than throwing
     * lets the controller answer 404 — which section ids exist is not
     * something a teacher needs to learn by probing.
     *
     * Attendance is counted only for this teacher's own schedules in this
     * section. A student taught by two teachers has two different rates, and
     * blending them would describe neither class.
     *
     * @return array{section:array<string,mixed>,students:list<array<string,mixed>>}|null
     */
    public static function sectionRoster(int $teacherId, int $sectionId): ?array
    {
        $db = Database::instance();

        $section = $db->selectOne(
            "SELECT sec.section_id, sec.section_code, sec.section_name, sec.capacity,
                    gl.grade_level_code, gl.grade_level_name,
                    GROUP_CONCAT(DISTINCT sub.subject_code ORDER BY sub.subject_code SEPARATOR ', ') AS subject_codes
               FROM schedules sch
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               JOIN subjects sub    ON sub.subject_id = sch.subject_id
              WHERE sch.teacher_id = :teacher
                AND sch.section_id = :section
                AND sch.status = 'active'
                AND sch.deleted_at IS NULL
                AND sec.deleted_at IS NULL
              GROUP BY sec.section_id, sec.section_code, sec.section_name, sec.capacity,
                       gl.grade_level_code, gl.grade_level_name",
            ['teacher' => $teacherId, 'section' => $sectionId]
        );

        if ($section === null) {
            return null;
        }

        $students = $db->select(
            // A student with no active card cannot be marked present at all —
            // the terminal has nothing to read. Surfacing it here is the point
            // of the page: the teacher sees it before the first class rather
            // than when the tap does not happen.
            "SELECT st.student_id, st.student_number, st.first_name, st.middle_name,
                    st.last_name, st.suffix, st.gender, st.photo_path,
                    st.guardian_name, st.guardian_contact,
                    rc.card_uid,
                    (SELECT COUNT(*) FROM attendance_records ar
                      WHERE ar.student_id = st.student_id
                        AND ar.section_id = :section2
                        AND ar.teacher_id = :teacher2) AS meetings,
                    (SELECT COUNT(*) FROM attendance_records ar
                      WHERE ar.student_id = st.student_id
                        AND ar.section_id = :section3
                        AND ar.teacher_id = :teacher3
                        AND ar.final_status IN ('Present', 'Late')) AS attended
               FROM students st
               LEFT JOIN rfid_cards rc
                      ON rc.student_id = st.student_id AND rc.status = 'active'
              WHERE st.section_id = :section
                AND st.deleted_at IS NULL
                AND st.status = 'active'
              ORDER BY st.last_name, st.first_name",
            [
                'section'  => $sectionId,
                'section2' => $sectionId,
                'section3' => $sectionId,
                'teacher2' => $teacherId,
                'teacher3' => $teacherId,
            ]
        );

        foreach ($students as $index => $student) {
            $meetings = (int) $student['meetings'];

            // Null rather than 0% when the class has not met: a student who has
            // had no opportunity to attend has not attended badly.
            $students[$index]['attendance_rate'] = $meetings === 0
                ? null
                : (int) round(((int) $student['attended'] / $meetings) * 100);
        }

        return ['section' => $section, 'students' => $students];
    }

    public static function nextEmployeeNumber(): string
    {
        $year = Clock::now()->format('Y');
        $last = Database::instance()->scalar(
            'SELECT employee_number FROM teachers WHERE employee_number LIKE :prefix ORDER BY teacher_id DESC LIMIT 1',
            ['prefix' => 'T-' . $year . '-%']
        );

        $sequence = $last === null ? 1 : ((int) substr((string) $last, -3)) + 1;

        return sprintf('T-%s-%03d', $year, $sequence);
    }
}
