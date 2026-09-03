<?php
declare(strict_types=1);

namespace App\Validators;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\SecurityLogService;

/**
 * Part 14.1 — a teacher may only be assigned subjects belonging to that
 * teacher's department.
 *
 * This class is the enforcement mechanism. The filtered dropdown is a
 * convenience for the administrator and nothing more: every entry point that
 * can create or change a teacher–subject pairing calls assertAssignable(),
 * and every one of them assumes the request is forged.
 *
 * Call sites (all of them):
 *   - TeacherController::store / update      (Assigned Subjects multi-select)
 *   - ScheduleController::store / update     (Teacher + Subject pairing)
 *   - SubjectController::updateTeachers      (the reverse direction)
 *   - ImportService::importTeachers          (bulk CSV/Excel)
 *   - Api\TeacherController, Api\ScheduleController
 */
final class TeacherSubjectAssignmentValidator
{
    public const ERROR_CODE = 'CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED';

    /**
     * @param  list<int> $subjectIds
     * @throws ValidationException on the first offending subject; nothing is saved
     */
    public static function assertAssignable(int $teacherId, array $subjectIds, string $field = 'subject_ids'): void
    {
        if ($subjectIds === []) {
            return;
        }

        $teacher = self::loadTeacher($teacherId);

        if ($teacher === null) {
            throw new ValidationException([$field => ['The selected teacher does not exist.']]);
        }

        $allowedDepartments = self::allowedDepartmentIds($teacherId, (int) $teacher['department_id']);

        foreach (array_unique($subjectIds) as $subjectId) {
            $subject = Database::instance()->selectOne(
                'SELECT s.subject_id, s.subject_code, s.subject_name, s.department_id, s.status,
                        d.department_name
                   FROM subjects s
                   JOIN departments d ON d.department_id = s.department_id
                  WHERE s.subject_id = :id AND s.deleted_at IS NULL',
                ['id' => $subjectId]
            );

            if ($subject === null) {
                throw new ValidationException([$field => ['One of the selected subjects does not exist.']]);
            }

            if ((string) $subject['status'] !== 'active') {
                throw new ValidationException([
                    $field => [sprintf('Subject "%s" is inactive and cannot be assigned.', $subject['subject_name'])],
                ]);
            }

            if (in_array((int) $subject['department_id'], $allowedDepartments, true)) {
                continue;
            }

            // Rejected. Log it as a security event before throwing: a forged
            // request that got past the UI is exactly what this event is for.
            SecurityLogService::log(
                SecurityLogService::CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED,
                'medium',
                sprintf(
                    'Blocked assignment of subject "%s" (%s) to teacher #%d in the %s.',
                    $subject['subject_name'],
                    $subject['department_name'],
                    $teacherId,
                    $teacher['department_name']
                ),
                [
                    'teacher_id'            => $teacherId,
                    'teacher_department_id' => (int) $teacher['department_id'],
                    'subject_id'            => (int) $subject['subject_id'],
                    'subject_department_id' => (int) $subject['department_id'],
                ]
            );

            throw new ValidationException(
                [$field => [sprintf(
                    'Subject "%s" belongs to the %s and cannot be assigned to a teacher in the %s.',
                    $subject['subject_name'],
                    $subject['department_name'],
                    $teacher['department_name']
                )]],
                'Cross-department assignment blocked.',
                self::ERROR_CODE
            );
        }
    }

    /** Reverse direction: assigning teachers to a subject from the Subjects page. */
    public static function assertTeachersAssignable(int $subjectId, array $teacherIds, string $field = 'teacher_ids'): void
    {
        foreach (array_unique($teacherIds) as $teacherId) {
            self::assertAssignable((int) $teacherId, [$subjectId], $field);
        }
    }

    /**
     * The department IDs whose subjects this teacher may hold: their primary
     * department, any secondary departments, plus any live cross-department
     * exception — but only when the exception mechanism is enabled globally.
     *
     * @return list<int>
     */
    public static function allowedDepartmentIds(int $teacherId, ?int $primaryDepartmentId = null): array
    {
        $db = Database::instance();

        if ($primaryDepartmentId === null) {
            $teacher             = self::loadTeacher($teacherId);
            $primaryDepartmentId = $teacher === null ? null : (int) $teacher['department_id'];
        }

        $allowed = $primaryDepartmentId === null ? [] : [$primaryDepartmentId];

        $secondary = $db->select(
            'SELECT department_id FROM teacher_departments WHERE teacher_id = :teacher',
            ['teacher' => $teacherId]
        );

        foreach ($secondary as $row) {
            $allowed[] = (int) $row['department_id'];
        }

        if (self::exceptionsEnabled()) {
            $exceptions = $db->select(
                "SELECT department_id
                   FROM teacher_department_exceptions
                  WHERE teacher_id = :teacher
                    AND status = 'active'
                    AND effective_from <= CURDATE()
                    AND effective_to >= CURDATE()",
                ['teacher' => $teacherId]
            );

            foreach ($exceptions as $row) {
                $allowed[] = (int) $row['department_id'];
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * The subjects a teacher may be assigned, grouped by department — the exact
     * payload behind GET /api/subjects/assignable.
     *
     * @return list<array<string,mixed>>
     */
    public static function assignableSubjects(int $teacherId): array
    {
        $primary = self::loadTeacher($teacherId);

        return self::subjectsInDepartments(
            self::allowedDepartmentIds($teacherId),
            $primary === null ? 0 : (int) $primary['department_id']
        );
    }

    /**
     * The same list, for a teacher who does not exist yet.
     *
     * The registration form has to show subjects before it can save anything —
     * asking someone to save a teacher, reopen them and only then pick subjects
     * is the kind of two-pass workflow that gets skipped, leaving teachers with
     * no subjects and no schedule. Departments are the only input the query
     * needs, and the form already knows which one was chosen.
     *
     * @param  list<int> $departmentIds
     * @return list<array<string,mixed>>
     */
    public static function subjectsInDepartments(array $departmentIds, int $primaryDepartmentId = 0): array
    {
        $departments = array_values(array_unique(array_filter(
            array_map('intval', $departmentIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($departments === []) {
            return [];
        }

        $placeholders = [];
        $bindings     = [];

        foreach ($departments as $index => $departmentId) {
            $placeholders[]            = ':dept' . $index;
            $bindings['dept' . $index] = $departmentId;
        }

        $bindings['primary'] = $primaryDepartmentId;

        return Database::instance()->select(
            'SELECT s.subject_id, s.subject_code, s.subject_name,
                    s.department_id, d.department_name, d.department_code,
                    (s.department_id <> :primary) AS is_cross_department
               FROM subjects s
               JOIN departments d ON d.department_id = s.department_id
              WHERE s.department_id IN (' . implode(',', $placeholders) . ')
                AND s.status = \'active\'
                AND s.deleted_at IS NULL
              ORDER BY d.department_name, s.subject_code',
            $bindings
        );
    }

    /**
     * Assignments that would become invalid if a teacher moved departments —
     * the list the confirmation modal in Part 14.1 must show before the change
     * is allowed to proceed.
     *
     * @return array{subjects:list<array<string,mixed>>,schedules:list<array<string,mixed>>}
     */
    public static function impactOfDepartmentChange(int $teacherId, int $newDepartmentId): array
    {
        $db = Database::instance();

        $subjects = $db->select(
            'SELECT s.subject_id, s.subject_code, s.subject_name, d.department_name
               FROM teacher_subjects ts
               JOIN subjects s    ON s.subject_id = ts.subject_id
               JOIN departments d ON d.department_id = s.department_id
              WHERE ts.teacher_id = :teacher AND s.department_id <> :new_department',
            ['teacher' => $teacherId, 'new_department' => $newDepartmentId]
        );

        $schedules = $db->select(
            'SELECT sch.schedule_id, sch.day_of_week, sch.start_time, sch.end_time,
                    s.subject_code, s.subject_name, d.department_name,
                    sec.section_code, c.room_number
               FROM schedules sch
               JOIN subjects s     ON s.subject_id = sch.subject_id
               JOIN departments d  ON d.department_id = s.department_id
               JOIN sections sec   ON sec.section_id = sch.section_id
               JOIN classrooms c   ON c.classroom_id = sch.classroom_id
              WHERE sch.teacher_id = :teacher
                AND sch.status = \'active\'
                AND sch.deleted_at IS NULL
                AND s.department_id <> :new_department
              ORDER BY FIELD(sch.day_of_week, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'), sch.start_time',
            ['teacher' => $teacherId, 'new_department' => $newDepartmentId]
        );

        return ['subjects' => $subjects, 'schedules' => $schedules];
    }

    /** @return array<string,mixed>|null */
    private static function loadTeacher(int $teacherId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT t.teacher_id, t.department_id, t.status, d.department_name, d.department_code
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
              WHERE t.teacher_id = :id AND t.deleted_at IS NULL',
            ['id' => $teacherId]
        );
    }

    /**
     * `academic.allow_cross_department_exceptions` defaults to false so that an
     * institution wanting hard enforcement gets it without any configuration.
     */
    public static function exceptionsEnabled(): bool
    {
        return \App\Services\SettingsService::bool('academic.allow_cross_department_exceptions', false);
    }
}
