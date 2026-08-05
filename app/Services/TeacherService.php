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
