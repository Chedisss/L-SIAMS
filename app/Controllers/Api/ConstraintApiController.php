<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\TeacherService;
use App\Validators\TeacherSectionAssignmentValidator;
use App\Validators\TeacherSubjectAssignmentValidator;

/**
 * The cascading-dropdown endpoints from Part 14.5.
 *
 * These exist to make the forms pleasant. They are NOT the enforcement: the
 * validators run again on every submit, and assume the client sent whatever it
 * liked. Each endpoint is authenticated, administrator-only, rate limited and
 * logged like any other.
 */
final class ConstraintApiController extends Controller
{
    /**
     * GET /api/subjects/assignable?teacher_id=
     * GET /api/subjects/assignable?department_id=
     *
     * Subjects in the teacher's department(s), grouped so the UI can render
     * "── English Department ──" option-group headers.
     *
     * The department form works for a teacher who does not exist yet: the
     * registration screen has a department chosen but no teacher_id to quote,
     * and it still has to show what can be picked.
     */
    public function assignableSubjects(Request $request): Response
    {
        $teacherId    = $request->int('teacher_id');
        $departmentId = $request->int('department_id');

        if ($teacherId <= 0 && $departmentId <= 0) {
            return $this->fail('TEACHER_REQUIRED', 'Select a teacher or a department first.', 422);
        }

        if ($teacherId > 0) {
            $subjects   = TeacherSubjectAssignmentValidator::assignableSubjects($teacherId);
            $department = TeacherService::constraints($teacherId)['department'];
        } else {
            $subjects   = TeacherSubjectAssignmentValidator::subjectsInDepartments([$departmentId], $departmentId);
            $department = AcademicStructureService::department($departmentId);

            if ($department === null) {
                return $this->fail('DEPARTMENT_NOT_FOUND', 'That department does not exist.', 404);
            }
        }

        $grouped = [];

        foreach ($subjects as $subject) {
            $grouped[(string) $subject['department_name']][] = $subject;
        }

        $departmentName = $department['department_name'];

        return $this->json([
            'subjects'   => $subjects,
            'grouped'    => $grouped,
            'department' => $department,
            'helper_text' => $subjects === []
                ? sprintf('No subjects are configured for the %s. Add subjects before creating schedules.', $departmentName)
                : sprintf('Showing subjects for the %s only.', $departmentName),
            'empty'      => $subjects === [],
            'empty_action_url' => '/admin/subjects',
        ]);
    }

    /**
     * GET /api/sections/assignable?teacher_id=
     *
     * Sections within the teacher's grade level(s), grouped by grade level.
     */
    public function assignableSections(Request $request): Response
    {
        $teacherId = $request->int('teacher_id');

        // Same reasoning as assignableSubjects: the registration form knows the
        // grade levels before it knows the teacher, and must be able to ask.
        $gradeLevelIds = array_values(array_filter(
            array_map('intval', (array) $request->input('grade_level_ids', [])),
            static fn (int $id): bool => $id > 0
        ));

        if ($teacherId <= 0 && $gradeLevelIds === []) {
            return $this->fail('TEACHER_REQUIRED', 'Select a teacher or at least one grade level first.', 422);
        }

        if ($teacherId > 0) {
            $constraints    = TeacherService::constraints($teacherId);
            $sections       = TeacherSectionAssignmentValidator::assignableSections($teacherId);
            $gradeLevels    = $constraints['grade_levels'];
            $hasGradeLevel  = (bool) $constraints['has_grade_level'];
            $gradeLevelIds  = array_map(static fn (array $g): int => (int) $g['grade_level_id'], $gradeLevels);
        } else {
            $sections      = TeacherSectionAssignmentValidator::sectionsInGradeLevels($gradeLevelIds);
            $gradeLevels   = AcademicStructureService::gradeLevelsByIds($gradeLevelIds);
            $hasGradeLevel = $gradeLevels !== [];
        }

        $grouped = [];

        foreach ($sections as $section) {
            $grouped[(string) $section['grade_level_name']][] = $section;
        }

        $gradeLabels = TeacherSectionAssignmentValidator::gradeLabels($gradeLevelIds);

        return $this->json([
            'sections'     => $sections,
            'grouped'      => $grouped,
            'grade_levels' => $gradeLevels,
            'helper_text'  => !$hasGradeLevel
                ? 'Choose at least one grade level first — sections follow from it.'
                : ($sections === []
                    ? sprintf('No active %s sections exist. Create a section before scheduling.', $gradeLabels)
                    : sprintf('Showing %s sections only.', $gradeLabels)),
            'empty'        => $sections === [],
            'empty_action_url' => '/admin/sections',
        ]);
    }

    /**
     * GET /api/classrooms/assignable?section_id=
     *
     * Active, device-equipped rooms, with a capacity flag so the form can warn
     * before the validator refuses.
     */
    public function assignableClassrooms(Request $request): Response
    {
        $sectionId = $request->int('section_id');

        if ($sectionId <= 0) {
            return $this->fail('SECTION_REQUIRED', 'Select a section first.', 422);
        }

        $classrooms = AcademicStructureService::assignableClassrooms($sectionId);
        $section    = AcademicStructureService::findSection($sectionId);

        return $this->json([
            'classrooms'  => $classrooms,
            'section'     => $section,
            'helper_text' => $classrooms === []
                ? 'No classroom has a registered attendance terminal. Register a device before scheduling.'
                : sprintf(
                    'Showing rooms with a registered terminal. %s has %d student(s) enrolled.',
                    $section['section_code'] ?? 'This section',
                    (int) ($section['enrolled_count'] ?? 0)
                ),
            'empty'       => $classrooms === [],
            'empty_action_url' => '/admin/devices',
        ]);
    }

    /**
     * GET /api/teachers/{id}/constraints
     *
     * Everything the schedule form needs to know about a teacher in one call.
     */
    public function teacherConstraints(Request $request): Response
    {
        return $this->json(TeacherService::constraints($request->routeInt('id')));
    }
}
