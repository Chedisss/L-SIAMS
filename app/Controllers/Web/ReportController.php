<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\ReportService;
use App\Services\StudentService;
use App\Services\TeacherService;

final class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('admin.reports.index', [
            'pageTitle'   => 'Reports',
            'types'       => ReportService::TYPES,
            'sections'    => AcademicStructureService::sections(['status' => 'active']),
            'gradeLevels' => AcademicStructureService::gradeLevels(),
            'subjects'    => AcademicStructureService::subjects(['status' => 'active']),
            'teachers'    => TeacherService::paginate(['status' => 'active'], 1, 500)['rows'],
            'classrooms'  => AcademicStructureService::classrooms(true),
            'history'     => ReportService::history(20),
            'defaultFrom' => Clock::now()->modify('-30 days')->format('Y-m-d'),
            'defaultTo'   => Clock::today(),
        ]);
    }

    /** Preview before export (Part 7: "Preview before export"). */
    public function preview(Request $request): Response
    {
        $type    = $request->string('type', 'daily');
        $filters = $this->filters($request);

        $report = ReportService::build($type, $filters);

        return $this->json([
            'title'      => $report['title'],
            'subtitle'   => $report['subtitle'],
            'headers'    => $report['headers'],
            // Cap the preview so a 20,000-row report does not stall the browser;
            // the export itself carries everything.
            'rows'       => array_slice($report['rows'], 0, 100),
            'total_rows' => count($report['rows']),
            'statistics' => $report['statistics'],
            'meta'       => $report['meta'],
            'truncated'  => count($report['rows']) > 100,
        ]);
    }

    public function generate(Request $request): Response
    {
        $type   = $request->string('type', 'daily');
        $format = $request->string('format', 'pdf');

        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return $this->fail('INVALID_FORMAT', 'Choose PDF or Excel.', 422);
        }

        $filters = $this->filters($request);
        $report  = ReportService::build($type, $filters);

        // Persisting is optional: a one-off export need not clutter the history.
        if ($request->bool('save', false)) {
            ReportService::persist($report, $format, ['type' => $type] + $filters, $this->requireUserId());
        }

        $rendered = ReportService::export($report, $format);

        return Response::attachment($rendered['content'], $rendered['filename'], $rendered['mime']);
    }

    /**
     * Serve a previously generated report.
     *
     * Files live under storage/ outside the web root, so this action is the only
     * way to reach them and it enforces ownership: a teacher may only download
     * reports they generated themselves.
     */
    public function download(Request $request): Response
    {
        $report = ReportService::findGenerated($request->routeInt('id'));

        if ($report === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Report not found.');
        }

        if (!Auth::isAdmin() && (int) $report['generated_by'] !== Auth::id()) {
            throw new AuthorizationException('You may only download reports you generated.');
        }

        $path = (string) Config::get('app.paths.reports') . '/' . basename((string) $report['file_path']);

        if (!is_readable($path)) {
            throw new HttpException(410, 'FILE_MISSING', 'This report file is no longer on disk. Generate it again.');
        }

        // CSV is no longer offered, but reports generated as CSV before it was
        // withdrawn are still on disk and still listed in the history. They
        // download with the type they were written as; serving an old CSV as
        // something else would break a file that was correct when it was made.
        // The fallback is deliberately not CSV any more — an unrecognised
        // format is an unknown file, not a comma-separated one.
        $mime = match ((string) $report['format']) {
            'pdf'  => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv; charset=UTF-8',
            default => 'application/octet-stream',
        };

        return Response::download($path, (string) $report['file_path'], $mime);
    }

    /** Student picker for the student report; searched server-side. */
    public function searchStudents(Request $request): Response
    {
        $result = StudentService::paginate(
            ['search' => $request->string('q', ''), 'status' => 'active'],
            1,
            25
        );

        return $this->json([
            'rows' => array_map(static fn (array $r): array => [
                'student_id'     => (int) $r['student_id'],
                'student_number' => $r['student_number'],
                'name'           => sprintf('%s, %s', $r['last_name'], $r['first_name']),
                'section_code'   => $r['section_code'],
                // The card they hold now, so a form issuing one can tell
                // whether it is issuing or replacing before the server does.
                'card_uid'       => $r['card_uid'] ?? null,
            ], $result['rows']),
        ]);
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $filters = [
            'date'           => $request->string('date', ''),
            'date_from'      => $request->string('date_from', ''),
            'date_to'        => $request->string('date_to', ''),
            'section_id'     => $request->int('section_id', 0) ?: null,
            'grade_level_id' => $request->int('grade_level_id', 0) ?: null,
            'subject_id'     => $request->int('subject_id', 0) ?: null,
            'teacher_id'     => $request->int('teacher_id', 0) ?: null,
            'classroom_id'   => $request->int('classroom_id', 0) ?: null,
            'student_id'     => $request->int('student_id', 0) ?: null,
            'final_status'   => $request->string('final_status', ''),
            'department_id'  => $request->int('department_id', 0) ?: null,
            'threshold'      => $request->string('threshold', ''),
            'days'           => $request->int('days', 7),
        ];

        // A teacher generating a report is silently constrained to their own
        // data, whatever they put in the form.
        if (Auth::isTeacher()) {
            $filters['teacher_id'] = Auth::teacherId();
        }

        return array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== null
        );
    }
}
