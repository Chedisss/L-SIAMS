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
     * GET /api/subjects/{id}/grade-levels
     *
     * The grade levels a subject is offered for. A subject with no explicit
     * mapping falls back to every active grade level rather than to none: an
     * unmapped subject is an incomplete setup, not a subject nobody may teach,
     * and returning nothing would silently make it unschedulable.
     */
    public function subjectGradeLevels(Request $request): Response
    {
        $subjectId = $request->routeInt('id');
        $subject   = AcademicStructureService::findSubject($subjectId);

        if ($subject === null) {
            return $this->fail('SUBJECT_NOT_FOUND', 'That subject does not exist.', 404);
        }

        $ids         = AcademicStructureService::subjectGradeLevelIds($subjectId);
        $explicit    = $ids !== [];
        $gradeLevels = $explicit
            ? AcademicStructureService::gradeLevelsByIds($ids)
            : AcademicStructureService::gradeLevels();

        return $this->json([
            'grade_levels' => $gradeLevels,
            'subject'      => $subject,
            'explicit'     => $explicit,
            'helper_text'  => $gradeLevels === []
                ? 'No grade levels exist yet. Add one before scheduling.'
                : ($explicit
                    ? sprintf('Grade levels %s is offered for.', $subject['subject_code'])
                    : sprintf(
                        '%s is not mapped to any grade level, so all are shown. Set its grade levels '
                        . 'on the Subjects page to narrow this.',
                        $subject['subject_code']
                    )),
            'empty'       => $gradeLevels === [],
            'empty_action_url' => '/admin/grade-levels',
        ]);
    }

    /**
     * GET /api/teachers/assignable?subject_id=&section_id=
     *
     * The teachers who may take this subject in this section — the inverse of
     * the subject and section endpoints above, for a form that starts from the
     * class rather than the person.
     */
    public function assignableTeachers(Request $request): Response
    {
        $subjectId = $request->int('subject_id');
        $sectionId = $request->int('section_id');

        if ($subjectId <= 0 || $sectionId <= 0) {
            return $this->fail('PAIRING_REQUIRED', 'Choose a subject and a section first.', 422);
        }

        $subject = AcademicStructureService::findSubject($subjectId);
        $section = AcademicStructureService::findSection($sectionId);

        if ($subject === null) {
            return $this->fail('SUBJECT_NOT_FOUND', 'That subject does not exist.', 404);
        }

        if ($section === null) {
            return $this->fail('SECTION_NOT_FOUND', 'That section does not exist.', 404);
        }

        $teachers = TeacherService::assignableForPairing($subjectId, $sectionId);

        // Naming both halves matters when the list is empty: the fix is either
        // a department assignment or a grade level, and which one is not
        // guessable from "no teachers".
        return $this->json([
            'teachers'    => $teachers,
            'subject'     => $subject,
            'section'     => $section,
            'helper_text' => $teachers === []
                ? sprintf(
                    'No teacher is assigned to both the %s and %s. Give a teacher that department, '
                    . 'or that grade level, on their profile.',
                    $subject['department_name'] ?? 'that department',
                    $section['grade_level_code'] ?? 'that grade level'
                )
                : sprintf(
                    'Showing teachers who may take %s in %s.',
                    $subject['subject_code'] ?? 'this subject',
                    $section['section_code'] ?? 'this section'
                ),
            'empty'       => $teachers === [],
            'empty_action_url' => '/admin/teachers',
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
        $gap        = $classrooms === [] ? AcademicStructureService::classroomTerminalGap() : null;

        return $this->json([
            'classrooms'  => $classrooms,
            'section'     => $section,
            'helper_text' => $gap !== null
                ? $gap['message']
                : sprintf(
                    'Showing rooms with a registered terminal. %s has %d student(s) enrolled.',
                    $section['section_code'] ?? 'This section',
                    (int) ($section['enrolled_count'] ?? 0)
                ),
            'empty'       => $classrooms === [],
            'empty_reason' => $gap === null ? null : $gap['reason'],
            'empty_action_url' => $gap === null ? '/admin/devices' : $gap['action_url'],
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
