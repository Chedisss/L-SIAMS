<?php
declare(strict_types=1);

namespace App\Validators;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\SecurityLogService;
use App\Services\SettingsService;

/**
 * Part 14.2 — a teacher may only be assigned sections whose grade level is
 * within that teacher's assigned grade level(s).
 *
 * Same posture as the department validator: the grouped dropdown is UX, this is
 * the control, and it runs on every path that can pair a teacher with a section
 * including bulk import and the reverse (adviser) direction.
 */
final class TeacherSectionAssignmentValidator
{
    public const ERROR_CODE = 'GRADE_LEVEL_MISMATCH_BLOCKED';

    /**
     * @param  list<int> $sectionIds
     * @throws ValidationException
     */
    public static function assertAssignable(int $teacherId, array $sectionIds, string $field = 'section_ids'): void
    {
        if ($sectionIds === []) {
            return;
        }

        $db      = Database::instance();
        $teacher = $db->selectOne(
            'SELECT teacher_id, status FROM teachers WHERE teacher_id = :id AND deleted_at IS NULL',
            ['id' => $teacherId]
        );

        if ($teacher === null) {
            throw new ValidationException([$field => ['The selected teacher does not exist.']]);
        }

        $allowedGrades = self::allowedGradeLevelIds($teacherId);

        if ($allowedGrades === []) {
            throw new ValidationException(
                [$field => ['Assign a grade level to this teacher before assigning sections.']],
                'Teacher has no grade level assignment.',
                self::ERROR_CODE
            );
        }

        $gradeLabels = self::gradeLabels($allowedGrades);

        foreach (array_unique($sectionIds) as $sectionId) {
            $section = $db->selectOne(
                'SELECT sec.section_id, sec.section_code, sec.section_name, sec.status,
                        sec.grade_level_id, gl.grade_level_name, gl.grade_level_code
                   FROM sections sec
                   JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
                  WHERE sec.section_id = :id AND sec.deleted_at IS NULL',
                ['id' => $sectionId]
            );

            if ($section === null) {
                throw new ValidationException([$field => ['One of the selected sections does not exist.']]);
            }

            if ((string) $section['status'] !== 'active') {
                throw new ValidationException([
                    $field => [sprintf('Section "%s" is not active and cannot be assigned.', $section['section_code'])],
                ]);
            }

            if (in_array((int) $section['grade_level_id'], $allowedGrades, true)) {
                continue;
            }

            SecurityLogService::log(
                SecurityLogService::GRADE_LEVEL_MISMATCH_BLOCKED,
                'medium',
                sprintf(
                    'Blocked assignment of section "%s" (%s) to teacher #%d assigned to %s.',
                    $section['section_code'],
                    $section['grade_level_name'],
                    $teacherId,
                    $gradeLabels
                ),
                [
                    'teacher_id'             => $teacherId,
                    'teacher_grade_level_ids' => $allowedGrades,
                    'section_id'             => (int) $section['section_id'],
                    'section_grade_level_id' => (int) $section['grade_level_id'],
                ]
            );

            throw new ValidationException(
                [$field => [sprintf(
                    'Section "%s" is a %s section and cannot be assigned to a teacher assigned to %s.',
                    $section['section_code'],
                    $section['grade_level_name'],
                    $gradeLabels
                )]],
                'Grade level mismatch blocked.',
                self::ERROR_CODE
            );
        }
    }

    /** Reverse direction: setting a section's adviser. */
    public static function assertAdviserAssignable(int $sectionId, int $teacherId): void
    {
        self::assertAssignable($teacherId, [$sectionId], 'adviser_id');
    }

    /** @return list<int> */
    public static function allowedGradeLevelIds(int $teacherId): array
    {
        $db = Database::instance();

        $rows = $db->select(
            'SELECT grade_level_id FROM teacher_grade_levels WHERE teacher_id = :teacher',
            ['teacher' => $teacherId]
        );

        $allowed = array_map(static fn (array $r): int => (int) $r['grade_level_id'], $rows);

        if (SettingsService::bool('academic.allow_cross_department_exceptions', false)) {
            $exceptions = $db->select(
                "SELECT grade_level_id
                   FROM teacher_grade_level_exceptions
                  WHERE teacher_id = :teacher
                    AND status = 'active'
                    AND effective_from <= CURDATE()
                    AND effective_to >= CURDATE()",
                ['teacher' => $teacherId]
            );

            foreach ($exceptions as $row) {
                $allowed[] = (int) $row['grade_level_id'];
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Sections a teacher may be assigned, grouped by grade level — the payload
     * behind GET /api/sections/assignable.
     *
     * @return list<array<string,mixed>>
     */
    public static function assignableSections(int $teacherId): array
    {
        return self::sectionsInGradeLevels(self::allowedGradeLevelIds($teacherId));
    }

    /**
     * The same list, for a teacher who does not exist yet — see the note on
     * TeacherSubjectAssignmentValidator::subjectsInDepartments(). Grade levels
     * are the only input the query needs, and the registration form collects
     * them on the same screen.
     *
     * @param  list<int> $gradeLevelIds
     * @return list<array<string,mixed>>
     */
    public static function sectionsInGradeLevels(array $gradeLevelIds): array
    {
        $grades = array_values(array_unique(array_filter(
            array_map('intval', $gradeLevelIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($grades === []) {
            return [];
        }

        $placeholders = [];
        $bindings     = [];

        foreach ($grades as $index => $gradeId) {
            $placeholders[]             = ':grade' . $index;
            $bindings['grade' . $index] = $gradeId;
        }

        return Database::instance()->select(
            'SELECT sec.section_id, sec.section_code, sec.section_name, sec.strand,
                    sec.capacity, sec.enrolled_count,
                    sec.grade_level_id, gl.grade_level_name, gl.grade_level_code, gl.numeric_level
               FROM sections sec
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
              WHERE sec.grade_level_id IN (' . implode(',', $placeholders) . ')
                AND sec.status = \'active\'
                AND sec.deleted_at IS NULL
              ORDER BY gl.numeric_level, sec.section_code',
            $bindings
        );
    }

    /** Human-readable list of a teacher's grade levels, for error messages. */
    public static function gradeLabels(array $gradeLevelIds): string
    {
        if ($gradeLevelIds === []) {
            return 'no grade level';
        }

        $placeholders = [];
        $bindings     = [];

        foreach (array_values($gradeLevelIds) as $index => $id) {
            $placeholders[]         = ':g' . $index;
            $bindings['g' . $index] = $id;
        }

        $rows = Database::instance()->select(
            'SELECT grade_level_name FROM grade_levels
              WHERE grade_level_id IN (' . implode(',', $placeholders) . ')
              ORDER BY numeric_level',
            $bindings
        );

        $names = array_map(static fn (array $r): string => (string) $r['grade_level_name'], $rows);

        return $names === [] ? 'no grade level' : implode(' and ', array_filter([
            implode(', ', array_slice($names, 0, -1)),
            end($names),
        ]));
    }

    /**
     * Schedules that would break if a teacher's grade levels changed.
     *
     * @param  list<int> $newGradeLevelIds
     * @return list<array<string,mixed>>
     */
    public static function impactOfGradeLevelChange(int $teacherId, array $newGradeLevelIds): array
    {
        if ($newGradeLevelIds === []) {
            $newGradeLevelIds = [0];
        }

        $placeholders = [];
        $bindings     = ['teacher' => $teacherId];

        foreach (array_values($newGradeLevelIds) as $index => $id) {
            $placeholders[]         = ':g' . $index;
            $bindings['g' . $index] = (int) $id;
        }

        return Database::instance()->select(
            'SELECT sch.schedule_id, sch.day_of_week, sch.start_time, sch.end_time,
                    sec.section_code, gl.grade_level_name, s.subject_code, c.room_number
               FROM schedules sch
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               JOIN subjects s      ON s.subject_id = sch.subject_id
               JOIN classrooms c    ON c.classroom_id = sch.classroom_id
              WHERE sch.teacher_id = :teacher
                AND sch.status = \'active\'
                AND sch.deleted_at IS NULL
                AND sec.grade_level_id NOT IN (' . implode(',', $placeholders) . ')
              ORDER BY gl.numeric_level, sch.day_of_week, sch.start_time',
            $bindings
        );
    }
}
