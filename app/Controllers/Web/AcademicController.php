<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\ScheduleService;
use App\Services\SchoolYearService;
use App\Services\TeacherService;

/**
 * Academic Setup: departments, grade levels, sections, subjects and classrooms
 * (Part 13).
 */
final class AcademicController extends Controller
{
    // ------------------------------------------------------ departments --

    public function departments(Request $request): Response
    {
        $archived    = $request->string('view', '') === 'archived';
        $departments = AcademicStructureService::departments(false, $archived);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $departments]);
        }

        return $this->view('admin.academic.departments', [
            'pageTitle'   => $archived ? 'Archived Departments' : 'Departments',
            'departments' => $departments,
            'archived'    => $archived,
            // Counted rather than fetched: the tab is worth showing only when
            // there is something behind it, and an empty archive should not
            // advertise itself on a page nobody has ever archived from.
            'archivedCount' => count(AcademicStructureService::departments(false, true)),
            'teachers'    => TeacherService::paginate([], 1, 500)['rows'],
        ]);
    }

    public function storeDepartment(Request $request): Response
    {
        $data = $this->validate($request, [
            'department_code' => 'required|string|max:20|code',
            'department_name' => 'required|string|max:120|no_html',
            'description'     => 'nullable|string|max:500|no_html',
            'head_teacher_id' => 'nullable|int',
            'status'          => 'nullable|in:active,inactive',
        ], [
            'department_code' => 'Department code',
            'department_name' => 'Department name',
        ]);

        $id = AcademicStructureService::createDepartment($data);

        return $this->json(['department_id' => $id], 'Department created successfully.', 201);
    }

    public function updateDepartment(Request $request): Response
    {
        $id = $request->routeInt('id');

        $data = $this->validate($request, [
            'department_name' => 'required|string|max:120|no_html',
            'description'     => 'nullable|string|max:500|no_html',
            'head_teacher_id' => 'nullable|int',
            'status'          => 'nullable|in:active,inactive',
        ]);

        AcademicStructureService::updateDepartment($id, $data);

        return $this->json(['department_id' => $id], 'Department updated successfully.');
    }

    public function departmentDetail(Request $request): Response
    {
        $id = $request->routeInt('id');

        return $this->json([
            'impact'   => AcademicStructureService::departmentArchiveImpact($id),
            'summary'  => AcademicStructureService::departmentSummary($id),
            'subjects' => AcademicStructureService::subjects(['department_id' => $id]),
        ]);
    }

    public function archiveDepartment(Request $request): Response
    {
        AcademicStructureService::archiveDepartment(
            $request->routeInt('id'),
            $request->bool('confirmed', false)
        );

        return $this->json([], 'Department archived.');
    }

    public function restoreDepartment(Request $request): Response
    {
        AcademicStructureService::restoreDepartment($request->routeInt('id'));

        return $this->json(
            [],
            'Department restored. It comes back inactive — set it active again when it is in use.'
        );
    }

    // ----------------------------------------------------- grade levels --

    public function gradeLevels(Request $request): Response
    {
        $gradeLevels = AcademicStructureService::gradeLevels(false);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $gradeLevels]);
        }

        return $this->view('admin.academic.grade-levels', [
            'pageTitle'   => 'Grade Levels',
            'gradeLevels' => $gradeLevels,
        ]);
    }

    public function storeGradeLevel(Request $request): Response
    {
        $data = $this->validate($request, [
            'grade_level_code' => 'required|string|max:10|code',
            'grade_level_name' => 'required|string|max:50|no_html',
            'numeric_level'    => 'required|int|between:1,20',
            'track'            => 'nullable|in:Academic,TVL,Sports,Arts and Design',
            'status'           => 'nullable|in:active,inactive',
        ], [
            'numeric_level' => 'Numeric level',
        ]);

        $id = AcademicStructureService::createGradeLevel($data);

        return $this->json(['grade_level_id' => $id], 'Grade level created successfully.', 201);
    }

    // --------------------------------------------------------- sections --

    public function sections(Request $request): Response
    {
        $filters = [
            'grade_level_id' => $request->int('grade_level_id', 0) ?: null,
            'strand'         => $request->string('strand', ''),
            'adviser_id'     => $request->int('adviser_id', 0) ?: null,
            'status'         => $request->string('status', ''),
            'search'         => $request->string('search', ''),
        ];

        $sections = AcademicStructureService::sections($filters);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $sections]);
        }

        return $this->view('admin.academic.sections', [
            'pageTitle'    => 'Sections',
            'sections'     => $sections,
            'filters'      => $filters,
            'gradeLevels'  => AcademicStructureService::gradeLevels(),
            'teachers'     => TeacherService::paginate(['status' => 'active'], 1, 500)['rows'],
            'classrooms'   => AcademicStructureService::classrooms(true),
            'schoolYears'  => SchoolYearService::all(),
        ]);
    }

    public function showSection(Request $request): Response
    {
        $sectionId = $request->routeInt('id');
        $section   = AcademicStructureService::findSection($sectionId);

        if ($section === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Section not found.');
        }

        $payload = [
            'section'   => $section,
            'roster'    => AcademicStructureService::sectionRoster($sectionId),
            'schedules' => ScheduleService::forSection($sectionId),
        ];

        if ($request->wantsJson()) {
            return $this->json($payload);
        }

        return $this->view('admin.academic.section-detail', $payload + [
            'pageTitle' => (string) $section['section_code'],
        ]);
    }

    public function storeSection(Request $request): Response
    {
        $data = $this->validate($request, [
            'section_code'   => 'required|string|max:30|code',
            'section_name'   => 'required|string|max:80|no_html',
            'grade_level_id' => 'required|int|exists:grade_levels,grade_level_id',
            'adviser_id'     => 'nullable|int',
            'strand'         => 'nullable|string|max:30|code',
            'capacity'       => 'required|int|between:1,200',
            'default_classroom_id' => 'nullable|int',
            'status'         => 'nullable|in:active,inactive',
        ], [
            'section_code'   => 'Section code',
            'grade_level_id' => 'Grade level',
        ]);

        $id = AcademicStructureService::createSection($data, $this->requireUserId());

        return $this->json(['section_id' => $id], 'Section created successfully.', 201);
    }

    public function updateSection(Request $request): Response
    {
        $id = $request->routeInt('id');

        $data = $this->validate($request, [
            'section_name'   => 'required|string|max:80|no_html',
            'grade_level_id' => 'nullable|int',
            'adviser_id'     => 'nullable|int',
            'strand'         => 'nullable|string|max:30|code',
            'capacity'       => 'required|int|between:1,200',
            'default_classroom_id' => 'nullable|int',
            'status'         => 'nullable|in:active,inactive',
        ]);

        AcademicStructureService::updateSection($id, $data, $this->requireUserId());

        return $this->json(['section_id' => $id], 'Section updated successfully.');
    }

    public function archiveSection(Request $request): Response
    {
        AcademicStructureService::archiveSection($request->routeInt('id'));

        return $this->json([], 'Section archived. Attendance history is retained.');
    }

    public function restoreSection(Request $request): Response
    {
        $stillArchived = AcademicStructureService::restoreSection($request->routeInt('id'));

        // The schedules are the part somebody will otherwise assume came back,
        // so it is said plainly rather than left to be discovered when the
        // timetable is empty.
        $message = $stillArchived > 0
            ? sprintf(
                'Section restored, inactive and with no timetable. Its %d archived schedule(s) '
                . 'were left alone — those periods may since have been given to another section, '
                . 'so rebuild the timetable rather than assuming it came back.',
                $stillArchived
            )
            : 'Section restored. It comes back inactive — set it active again when it is in use.';

        return $this->json(['schedules_left_archived' => $stillArchived], $message);
    }

    // --------------------------------------------------------- subjects --

    public function subjects(Request $request): Response
    {
        $archived = $request->string('view', '') === 'archived';

        $filters = [
            'department_id' => $request->int('department_id', 0) ?: null,
            'status'        => $request->string('status', ''),
            'search'        => $request->string('search', ''),
            'archived'      => $archived,
        ];

        $subjects = AcademicStructureService::subjects($filters);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $subjects]);
        }

        return $this->view('admin.academic.subjects', [
            'pageTitle'   => $archived ? 'Archived Subjects' : 'Subjects',
            'subjects'    => $subjects,
            'filters'     => $filters,
            'archived'    => $archived,
            'archivedCount' => count(AcademicStructureService::subjects(['archived' => true])),
            'departments' => AcademicStructureService::departments(true),
            'gradeLevels' => AcademicStructureService::gradeLevels(),
        ]);
    }

    public function subjectTeachers(Request $request): Response
    {
        return $this->json([
            'teachers' => AcademicStructureService::subjectTeachers($request->routeInt('id')),
        ]);
    }

    public function subjectArchiveImpact(Request $request): Response
    {
        return $this->json([
            'impact' => AcademicStructureService::subjectArchiveImpact($request->routeInt('id')),
        ]);
    }

    public function archiveSubject(Request $request): Response
    {
        AcademicStructureService::archiveSubject(
            $request->routeInt('id'),
            $request->bool('confirmed', false)
        );

        return $this->json([], 'Subject archived. Attendance recorded against it is untouched.');
    }

    public function restoreSubject(Request $request): Response
    {
        AcademicStructureService::restoreSubject($request->routeInt('id'));

        return $this->json(
            [],
            'Subject restored. It comes back inactive and with no teachers assigned to it.'
        );
    }

    public function storeSubject(Request $request): Response
    {
        $data = $this->validate($request, [
            'subject_code'    => 'required|string|max:30|code',
            'subject_name'    => 'required|string|max:150|no_html',
            'description'     => 'nullable|string|max:500|no_html',
            'department_id'   => 'required|int|exists:departments,department_id',
            'grade_level_ids' => 'required|array|min:1',
            'syllabus'        => 'nullable|array',
            'status'          => 'nullable|in:active,inactive',
        ], [
            'subject_code'    => 'Subject code',
            'department_id'   => 'Department',
            'grade_level_ids' => 'Offered to grade level(s)',
        ]);

        $id = AcademicStructureService::createSubject($data);

        return $this->json(['subject_id' => $id], 'Subject created successfully.', 201);
    }

    public function updateSubject(Request $request): Response
    {
        $id = $request->routeInt('id');

        $data = $this->validate($request, [
            // Editable, and validated exactly as it is on creation. Nothing in
            // the system keys on a subject code — schedules, sessions and
            // attendance all carry subject_id — so a typo in one was not worth
            // being permanent.
            'subject_code'    => 'required|string|max:30|code',
            'subject_name'    => 'required|string|max:150|no_html',
            'description'     => 'nullable|string|max:500|no_html',
            'department_id'   => 'required|int|exists:departments,department_id',
            'grade_level_ids' => 'nullable|array',
            'syllabus'        => 'nullable|array',
            'status'          => 'nullable|in:active,inactive',
        ], [
            'subject_code' => 'Subject code',
        ]);

        AcademicStructureService::updateSubject($id, $data);

        return $this->json(['subject_id' => $id], 'Subject updated successfully.');
    }

    public function subjectDetail(Request $request): Response
    {
        $id = $request->routeInt('id');

        return $this->json([
            'grade_level_ids' => AcademicStructureService::subjectGradeLevelIds($id),
            'syllabus'        => AcademicStructureService::subjectSyllabuses($id),
        ]);
    }

    // ------------------------------------------------------- classrooms --

    public function classrooms(Request $request): Response
    {
        $classrooms = AcademicStructureService::classrooms();

        if ($request->wantsJson()) {
            return $this->json(['rows' => $classrooms]);
        }

        return $this->view('admin.academic.classrooms', [
            'pageTitle'  => 'Classrooms',
            'classrooms' => $classrooms,
        ]);
    }

    public function storeClassroom(Request $request): Response
    {
        $data = $this->validate($request, [
            'room_number'   => 'required|string|max:30|code',
            'building'      => 'nullable|string|max:60|no_html',
            'floor'         => 'nullable|string|max:20|no_html',
            'capacity'      => 'required|int|between:1,300',
            'location_note' => 'nullable|string|max:255|no_html',
            'status'        => 'nullable|in:active,inactive,maintenance',
        ], [
            'room_number' => 'Room number',
        ]);

        $id = AcademicStructureService::createClassroom($data);

        return $this->json(['classroom_id' => $id], 'Classroom created successfully.', 201);
    }
}
