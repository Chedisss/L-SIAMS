<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\AttendanceQueryService;
use App\Services\Export\CsvWriter;
use App\Services\ImportService;
use App\Services\ReportService;
use App\Services\RfidService;
use App\Services\StudentService;

final class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $pagination = $this->pagination($request);
        $filters    = $this->filters($request);

        $result = StudentService::paginate(
            $filters,
            $pagination['page'],
            $pagination['per_page'],
            $request->string('sort', 'last_name'),
            $request->string('direction', 'ASC')
        );

        $meta = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        // The table refreshes over AJAX (Part 7: "Everything without page
        // reload"), so the same action serves both the full page and the
        // partial.
        if ($request->wantsJson()) {
            return $this->json([
                'rows'       => $result['rows'],
                'pagination' => $meta,
            ]);
        }

        return $this->view('admin.students.index', [
            'pageTitle'   => 'Students',
            'students'    => $result['rows'],
            'pagination'  => $meta,
            'filters'     => $filters,
            'sections'    => AcademicStructureService::sections(['status' => 'active']),
            'gradeLevels' => AcademicStructureService::gradeLevels(),
            'sort'        => $request->string('sort', 'last_name'),
            'direction'   => $request->string('direction', 'ASC'),
        ]);
    }

    public function show(Request $request): Response
    {
        $studentId = $request->routeInt('id');
        $student   = StudentService::find($studentId);

        if ($student === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Student not found.');
        }

        $payload = [
            'student'    => $student,
            'summary'    => StudentService::attendanceSummary($studentId),
            'attendance' => AttendanceQueryService::forStudent($studentId, 50),
            'transfers'  => StudentService::transferHistory($studentId),
            'cardHistory' => $student['card_uid'] === null
                ? []
                : RfidService::tapHistory((string) $student['card_uid'], 25),
        ];

        if ($request->wantsJson()) {
            return $this->json($payload);
        }

        return $this->view('admin.students.show', $payload + [
            'pageTitle' => trim(sprintf('%s %s', $student['first_name'], $student['last_name'])),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin.students.form', [
            'pageTitle'   => 'Add Student',
            'student'     => null,
            'gradeLevels' => AcademicStructureService::gradeLevels(),
            'sections'    => AcademicStructureService::sections(['status' => 'active']),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'student_number'   => 'required|string|max:30|slug',
            'section_id'       => 'required|int|exists:sections,section_id',
            'grade_level_id'   => 'nullable|int',
            'first_name'       => 'required|string|max:60|alpha_space',
            'middle_name'      => 'nullable|string|max:60|alpha_space',
            'last_name'        => 'required|string|max:60|alpha_space',
            'suffix'           => 'nullable|string|max:10',
            'gender'           => 'nullable|in:Male,Female,Other,Prefer not to say',
            'birthdate'        => 'nullable|date|before_today',
            'email'            => 'nullable|email',
            'address'          => 'nullable|string|max:255|no_html',
            'guardian_name'    => 'required|string|max:150|alpha_space',
            'guardian_contact' => 'required|phone',
            'guardian_email'   => 'nullable|email',
            'status'           => 'nullable|in:active,inactive',
            'card_uid'         => 'nullable|uid',
        ], [
            'student_number'   => 'Student number',
            'guardian_contact' => 'Guardian contact',
            'card_uid'         => 'RFID card UID',
        ]);

        $userId    = $this->requireUserId();
        $studentId = StudentService::create($data, $userId);

        if (!empty($data['card_uid'])) {
            RfidService::assign($studentId, (string) $data['card_uid'], $userId);
        }

        if ($request->wantsJson()) {
            return $this->json(['student_id' => $studentId], 'Student registered successfully.', 201);
        }

        return $this->redirect('/admin/students/' . $studentId, 'Student registered successfully.');
    }

    public function edit(Request $request): Response
    {
        $student = StudentService::find($request->routeInt('id'));

        if ($student === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Student not found.');
        }

        return $this->view('admin.students.form', [
            'pageTitle'   => 'Edit Student',
            'student'     => $student,
            'gradeLevels' => AcademicStructureService::gradeLevels(),
            'sections'    => AcademicStructureService::sections(['status' => 'active']),
        ]);
    }

    public function update(Request $request): Response
    {
        $studentId = $request->routeInt('id');

        $data = $this->validate($request, [
            'student_number'   => 'required|string|max:30|slug',
            'first_name'       => 'required|string|max:60|alpha_space',
            'middle_name'      => 'nullable|string|max:60|alpha_space',
            'last_name'        => 'required|string|max:60|alpha_space',
            'suffix'           => 'nullable|string|max:10',
            'gender'           => 'nullable|in:Male,Female,Other,Prefer not to say',
            'birthdate'        => 'nullable|date|before_today',
            'email'            => 'nullable|email',
            'address'          => 'nullable|string|max:255|no_html',
            'guardian_name'    => 'required|string|max:150|alpha_space',
            'guardian_contact' => 'required|phone',
            'guardian_email'   => 'nullable|email',
            'status'           => 'nullable|in:active,inactive',
        ]);

        StudentService::update($studentId, $data, $this->requireUserId());

        if ($request->wantsJson()) {
            return $this->json(['student_id' => $studentId], 'Student updated successfully.');
        }

        return $this->redirect('/admin/students/' . $studentId, 'Student updated successfully.');
    }

    public function transfer(Request $request): Response
    {
        $data = $this->validate($request, [
            'section_id'     => 'required|int|exists:sections,section_id',
            'reason'         => 'required|string|min:5|max:500|no_html',
            'effective_date' => 'required|date',
        ], [
            'section_id'     => 'Destination section',
            'reason'         => 'Transfer reason',
            'effective_date' => 'Effective date',
        ]);

        $studentId = $request->routeInt('id');

        StudentService::transfer(
            $studentId,
            (int) $data['section_id'],
            (string) $data['reason'],
            (string) $data['effective_date'],
            $this->requireUserId()
        );

        if ($request->wantsJson()) {
            return $this->json(['student_id' => $studentId], 'Student transferred. Historical attendance is unchanged.');
        }

        return $this->redirect(
            '/admin/students/' . $studentId,
            'Student transferred. Their previous attendance records keep their original section.'
        );
    }

    public function archive(Request $request): Response
    {
        $studentId = $request->routeInt('id');

        StudentService::archive($studentId, $this->requireUserId(), $request->string('reason', '') ?: null);

        if ($request->wantsJson()) {
            return $this->json([], 'Student archived. Attendance history is retained.');
        }

        return $this->redirect('/admin/students', 'Student archived. Attendance history is retained.');
    }

    public function restore(Request $request): Response
    {
        StudentService::restore($request->routeInt('id'), $this->requireUserId());

        if ($request->wantsJson()) {
            return $this->json([], 'Student restored.');
        }

        return $this->redirect('/admin/students', 'Student restored.');
    }

    public function export(Request $request): Response
    {
        $format  = $request->string('format', 'xlsx');
        $filters = $this->filters($request);

        $result = StudentService::paginate($filters, 1, 20000);

        $report = [
            'title'    => 'Student Directory',
            'subtitle' => 'Generated ' . Clock::now()->format('M j, Y g:i A'),
            'headers'  => ['Student No.', 'Last Name', 'First Name', 'Middle Name', 'Grade', 'Section', 'Gender', 'Guardian', 'Contact', 'RFID UID', 'Status', 'Attendance %'],
            'rows'     => array_map(static fn (array $r): array => [
                $r['student_number'],
                $r['last_name'],
                $r['first_name'],
                $r['middle_name'] ?? '',
                $r['grade_level_code'],
                $r['section_code'],
                $r['gender'],
                $r['guardian_name'],
                $r['guardian_contact'],
                $r['card_uid'] ?? '',
                $r['status'],
                ($r['attendance_percentage'] ?? 0) . '%',
            ], $result['rows']),
            'statistics' => ['Students' => $result['total']],
            'meta'       => ['Filters' => $this->describeFilters($filters)],
        ];

        $rendered = ReportService::export($report, $format);

        return Response::attachment($rendered['content'], $rendered['filename'], $rendered['mime']);
    }

    public function importForm(Request $request): Response
    {
        return $this->view('admin.students.import', ['pageTitle' => 'Import Students']);
    }

    public function importTemplate(Request $request): Response
    {
        return Response::attachment(
            ImportService::template('students'),
            'lsiams-student-import-template.csv',
            'text/csv; charset=UTF-8'
        );
    }

    /**
     * Validate the upload and show what would happen. Nothing is written until
     * the administrator confirms on the next step.
     */
    public function importPreview(Request $request): Response
    {
        $file = $request->file('file');

        if ($file === null) {
            return $this->fail('FILE_REQUIRED', 'Choose a CSV or Excel file to import.', 422);
        }

        $parsed  = ImportService::parseUpload($file, 'students');
        $preview = ImportService::previewStudents($parsed['rows']);

        // The validated rows are echoed back to the client and re-validated on
        // commit, so a tampered payload cannot bypass the checks.
        return $this->json($preview, sprintf(
            '%d row(s) ready to import, %d with problems.',
            $preview['summary']['valid'],
            $preview['summary']['invalid']
        ));
    }

    public function importCommit(Request $request): Response
    {
        /** @var list<array<string,mixed>> $records */
        $records = $request->input('records', []);

        if (!is_array($records) || $records === []) {
            return $this->fail('NO_RECORDS', 'There are no valid rows to import.', 422);
        }

        // Re-validate from scratch: the preview response is client-held state
        // and must never be trusted as pre-approved.
        $revalidated = ImportService::previewStudents(array_map(
            static fn (array $r): array => array_map('strval', $r),
            $records
        ));

        if ($revalidated['invalid'] !== []) {
            return Response::json([
                'success' => false,
                'code'    => 'IMPORT_VALIDATION_FAILED',
                'message' => 'Some rows became invalid. Re-upload the file and review the preview.',
                'data'    => ['invalid' => $revalidated['invalid']],
            ], 422);
        }

        $result = ImportService::commitStudents($revalidated['valid'], $this->requireUserId());

        return $this->json($result, sprintf(
            '%d student(s) imported and %d RFID card(s) assigned.',
            $result['imported'],
            $result['cards_assigned']
        ));
    }

    /** Bulk archive, gated on password re-confirmation (Part 2 business rules). */
    public function bulkArchive(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $ids    = array_map('intval', $request->array('student_ids'));
        $userId = $this->requireUserId();
        $reason = $request->string('reason', 'Bulk archive');

        if ($ids === []) {
            return $this->fail('NO_SELECTION', 'Select at least one student.', 422);
        }

        $archived = 0;

        foreach ($ids as $studentId) {
            StudentService::archive($studentId, $userId, $reason);
            $archived++;
        }

        return $this->json(['archived' => $archived], sprintf('%d student(s) archived.', $archived));
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return [
            'search'           => $request->string('search', ''),
            'section_id'       => $request->int('section_id', 0) ?: null,
            'grade_level_id'   => $request->int('grade_level_id', 0) ?: null,
            'status'           => $request->string('status', ''),
            'rfid_status'      => $request->string('rfid_status', ''),
            'include_archived' => $request->bool('include_archived', false),
        ];
    }

    /** @param array<string,mixed> $filters */
    private function describeFilters(array $filters): string
    {
        $parts = [];

        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '' && $value !== false) {
                $parts[] = str_replace('_', ' ', $key) . ': ' . (is_bool($value) ? 'yes' : (string) $value);
            }
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }
}
