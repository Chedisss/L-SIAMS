<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\AuthService;
use App\Services\FingerprintEnrollmentService;
use App\Services\FingerprintService;
use App\Services\ScheduleService;
use App\Services\TeacherService;
use App\Services\UserRegistrationService;
use App\Validators\TeacherSectionAssignmentValidator;
use App\Validators\TeacherSubjectAssignmentValidator;

/**
 * Administrator-facing teacher management (Part 2).
 *
 * Every write path here runs the Part 14 validators. The cascading dropdowns
 * that make the form pleasant are served by Api\ConstraintController; this
 * controller assumes none of that filtering happened.
 */
final class TeacherManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $pagination = $this->pagination($request);

        $filters = [
            'search'             => $request->string('search', ''),
            'department_id'      => $request->int('department_id', 0) ?: null,
            'status'             => $request->string('status', ''),
            'fingerprint_status' => $request->string('fingerprint_status', ''),
        ];

        $result = TeacherService::paginate($filters, $pagination['page'], $pagination['per_page']);
        $meta   = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('admin.teachers.index', [
            'pageTitle'         => 'Teachers',
            'teachers'          => $result['rows'],
            'pagination'        => $meta,
            'filters'           => $filters,
            'departments'       => AcademicStructureService::departments(),
            'withoutGradeLevel' => TeacherService::withoutGradeLevel(),
        ]);
    }

    public function show(Request $request): Response
    {
        $teacherId = $request->routeInt('id');
        $teacher   = TeacherService::find($teacherId);

        if ($teacher === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Teacher not found.');
        }

        $payload = [
            'teacher'     => $teacher,
            'constraints' => TeacherService::constraints($teacherId),
            'schedules'   => ScheduleService::forTeacher($teacherId),
            'week'        => ScheduleService::weekGridForTeacher($teacherId),
            'subjectIds'  => TeacherService::subjectIds($teacherId),
            'sectionIds'  => TeacherService::sectionIds($teacherId),
            'fingerprintLogs' => FingerprintService::logsForTeacher($teacherId, 20),
            'loginHistory'    => $teacher['user_id'] === null
                ? []
                : AuthService::loginHistory((int) $teacher['user_id'], 15),
        ];

        if ($request->wantsJson()) {
            return $this->json($payload);
        }

        return $this->view('admin.teachers.show', $payload + [
            'pageTitle' => trim(sprintf('%s %s', $teacher['first_name'], $teacher['last_name'])),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin.teachers.form', [
            'pageTitle'         => 'Register Teacher',
            'teacher'           => null,
            'departments'       => AcademicStructureService::departments(true),
            'gradeLevels'       => AcademicStructureService::gradeLevels(),
            'suggestedEmployee' => TeacherService::nextEmployeeNumber(),
            'subjectIds'        => [],
            'sectionIds'        => [],
            'gradeLevelIds'     => [],
            // The fingerprint is taken before the record exists, so the form
            // needs somewhere to send the person standing at it — a desk-side
            // scanner if there is one, a classroom terminal otherwise.
            'devices'           => FingerprintEnrollmentService::captureDevices(),
        ]);
    }

    /**
     * Registers the teacher and their web account together, returning the
     * one-time credentials for the slip.
     */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'employee_number' => 'required|string|max:30|slug',
            'first_name'      => 'required|string|max:60|alpha_space',
            'middle_name'     => 'nullable|string|max:60|alpha_space',
            'last_name'       => 'required|string|max:60|alpha_space',
            'suffix'          => 'nullable|string|max:10',
            'email'           => 'required|email',
            'phone'           => 'nullable|phone',
            'position'        => 'nullable|string|max:80',
            'department_id'   => 'required|int|exists:departments,department_id',
            'grade_level_ids' => 'required|array|min:1',
            'subject_ids'     => 'nullable|array',
            // Required: a teacher is registered by presenting their finger at a
            // terminal first, and this is the capture that produced. Without it
            // the record would exist unable to open a single session, which is
            // the state this ordering exists to make impossible.
            'fingerprint_request_id' => 'required|int',
            'section_ids'     => 'nullable|array',
            'username'        => 'nullable|string|min:4|max:32|slug',
            'password'        => 'nullable|string|max:200',
            'hired_at'        => 'nullable|date',
            'force_password_change' => 'nullable|bool',
        ], [
            'employee_number' => 'Employee number',
            'department_id'   => 'Department',
            'grade_level_ids' => 'Assigned grade level(s)',
        ]);

        $username = (string) ($data['username'] ?? '');

        if ($username === '') {
            $username = UserRegistrationService::suggestUsername(
                (string) $data['first_name'],
                (string) $data['last_name']
            );
        }

        $result = UserRegistrationService::register([
            'role'            => 'teacher',
            'username'        => $username,
            'email'           => $data['email'],
            'password'        => $data['password'] ?? null,
            'password_confirmation' => $request->string('password_confirmation', (string) ($data['password'] ?? '')),
            'first_name'      => $data['first_name'],
            'middle_name'     => $data['middle_name'] ?? null,
            'last_name'       => $data['last_name'],
            'suffix'          => $data['suffix'] ?? null,
            'employee_number' => $data['employee_number'],
            'department_id'   => $data['department_id'],
            'phone'           => $data['phone'] ?? null,
            'position'        => $data['position'] ?? null,
            'hired_at'        => $data['hired_at'] ?? null,
            'grade_level_ids' => $data['grade_level_ids'],
            'subject_ids'     => $data['subject_ids'] ?? [],
            'section_ids'     => $data['section_ids'] ?? [],
            'force_password_change' => $data['force_password_change'] ?? true,
            'fingerprint_request_id' => (int) $data['fingerprint_request_id'],
        ], $this->requireUserId());

        $slip = UserRegistrationService::credentialSlip(
            $result['username'],
            $result['password'],
            'teacher',
            trim(sprintf('%s %s', $data['first_name'], $data['last_name']))
        );

        // The password is returned here and nowhere else, ever again.
        return $this->json([
            'teacher_id'      => $result['teacher_id'],
            'user_id'         => $result['user_id'],
            'username'        => $result['username'],
            'password'        => $result['password'],
            'generated'       => $result['generated'],
            'status'          => $result['status'],
            'credential_slip' => $slip,
            'redirect'        => '/admin/teachers/' . $result['teacher_id'],
        ], 'Teacher registered. Copy the credentials now — they cannot be shown again.', 201);
    }

    public function edit(Request $request): Response
    {
        $teacherId = $request->routeInt('id');
        $teacher   = TeacherService::find($teacherId);

        if ($teacher === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Teacher not found.');
        }

        return $this->view('admin.teachers.form', [
            'pageTitle'     => 'Edit Teacher',
            'teacher'       => $teacher,
            'departments'   => AcademicStructureService::departments(true),
            'gradeLevels'   => AcademicStructureService::gradeLevels(),
            'subjectIds'    => TeacherService::subjectIds($teacherId),
            'sectionIds'    => TeacherService::sectionIds($teacherId),
            'gradeLevelIds' => TeacherService::gradeLevelIds($teacherId),
        ]);
    }

    public function update(Request $request): Response
    {
        $teacherId = $request->routeInt('id');

        $data = $this->validate($request, [
            'first_name'      => 'required|string|max:60|alpha_space',
            'middle_name'     => 'nullable|string|max:60|alpha_space',
            'last_name'       => 'required|string|max:60|alpha_space',
            'suffix'          => 'nullable|string|max:10',
            'email'           => 'required|email',
            'phone'           => 'nullable|phone',
            'position'        => 'nullable|string|max:80',
            'department_id'   => 'required|int|exists:departments,department_id',
            'grade_level_ids' => 'required|array|min:1',
            'subject_ids'     => 'nullable|array',
            'section_ids'     => 'nullable|array',
            'status'          => 'nullable|in:active,inactive',
        ]);

        TeacherService::update(
            $teacherId,
            $data,
            $this->requireUserId(),
            $request->bool('confirm_cascade', false)
        );

        if ($request->wantsJson()) {
            return $this->json(['teacher_id' => $teacherId], 'Teacher updated successfully.');
        }

        return $this->redirect('/admin/teachers/' . $teacherId, 'Teacher updated successfully.');
    }

    /**
     * Preview what a department or grade-level change would break, so the
     * confirmation modal can list it item by item (Part 14.1).
     */
    public function changeImpact(Request $request): Response
    {
        $teacherId = $request->routeInt('id');

        $payload = [];

        if ($request->has('department_id')) {
            $payload['department'] = TeacherSubjectAssignmentValidator::impactOfDepartmentChange(
                $teacherId,
                $request->int('department_id')
            );
        }

        if ($request->has('grade_level_ids')) {
            $payload['grade_levels'] = TeacherSectionAssignmentValidator::impactOfGradeLevelChange(
                $teacherId,
                array_map('intval', $request->array('grade_level_ids'))
            );
        }

        return $this->json($payload);
    }

    public function archive(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        TeacherService::archive($request->routeInt('id'), $this->requireUserId());

        if ($request->wantsJson()) {
            return $this->json([], 'Teacher archived. Their schedules were archived and their account disabled.');
        }

        return $this->redirect('/admin/teachers', 'Teacher archived.');
    }

    public function resetPassword(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $teacher = TeacherService::find($request->routeInt('id'));

        if ($teacher === null || $teacher['user_id'] === null) {
            return $this->fail('NO_ACCOUNT', 'This teacher has no web account.', 422);
        }

        $password = AuthService::adminResetPassword((int) $teacher['user_id']);

        return $this->json([
            'username' => $teacher['username'],
            'password' => $password,
            'credential_slip' => UserRegistrationService::credentialSlip(
                (string) $teacher['username'],
                $password,
                'teacher',
                trim(sprintf('%s %s', $teacher['first_name'], $teacher['last_name']))
            ),
        ], 'Password reset. Copy it now — it cannot be shown again.');
    }
}
