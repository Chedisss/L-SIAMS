<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\ScheduleService;
use App\Services\TeacherService;

final class ScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'teacher_id'     => $request->int('teacher_id', 0) ?: null,
            'section_id'     => $request->int('section_id', 0) ?: null,
            'classroom_id'   => $request->int('classroom_id', 0) ?: null,
            'subject_id'     => $request->int('subject_id', 0) ?: null,
            'day_of_week'    => $request->string('day_of_week', ''),
            'grade_level_id' => $request->int('grade_level_id', 0) ?: null,
        ];

        $schedules = $this->query($filters);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $schedules]);
        }

        return $this->view('admin.schedules.index', [
            'pageTitle'   => 'Schedules',
            'schedules'   => $schedules,
            'filters'     => $filters,
            'days'        => ScheduleService::DAYS,
            'teachers'    => TeacherService::paginate(['status' => 'active'], 1, 500)['rows'],
            'sections'    => AcademicStructureService::sections(['status' => 'active']),
            'classrooms'  => AcademicStructureService::classrooms(true),
            'subjects'    => AcademicStructureService::subjects(['status' => 'active']),
            'gradeLevels' => AcademicStructureService::gradeLevels(),
            'view'        => $request->string('view', 'table'),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->scheduleRules($request);

        $scheduleId = ScheduleService::create(
            $data,
            $this->requireUserId(),
            $request->bool('allow_override', false)
        );

        return $this->json(['schedule_id' => $scheduleId], 'Schedule created successfully.', 201);
    }

    public function update(Request $request): Response
    {
        $scheduleId = $request->routeInt('id');
        $data       = $this->scheduleRules($request);

        ScheduleService::update(
            $scheduleId,
            $data,
            $this->requireUserId(),
            $request->bool('allow_override', false)
        );

        return $this->json(['schedule_id' => $scheduleId], 'Schedule updated successfully.');
    }

    public function show(Request $request): Response
    {
        $schedule = Database::instance()->selectOne(
            'SELECT sch.*, s.subject_code, s.subject_name, s.department_id,
                    sec.section_code, sec.grade_level_id, c.room_number,
                    CONCAT(t.first_name, \' \', t.last_name) AS teacher_name
               FROM schedules sch
               JOIN subjects s   ON s.subject_id = sch.subject_id
               JOIN sections sec ON sec.section_id = sch.section_id
               JOIN classrooms c ON c.classroom_id = sch.classroom_id
               JOIN teachers t   ON t.teacher_id = sch.teacher_id
              WHERE sch.schedule_id = :id',
            ['id' => $request->routeInt('id')]
        );

        if ($schedule === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Schedule not found.');
        }

        return $this->json(['schedule' => $schedule]);
    }

    public function archive(Request $request): Response
    {
        ScheduleService::archive($request->routeInt('id'));

        return $this->json([], 'Schedule archived. Attendance history is retained.');
    }

    /**
     * Live conflict check for the form (Part 14.4). Runs rules 11–14 without
     * persisting, so the banner can turn red before the user hits Save.
     */
    public function checkConflict(Request $request): Response
    {
        $data = $this->validate($request, [
            'teacher_id'   => 'required|int',
            'classroom_id' => 'required|int',
            'section_id'   => 'required|int',
            'subject_id'   => 'required|int',
            'day_of_week'  => 'required|in:' . implode(',', ScheduleService::DAYS),
            'start_time'   => 'required|time',
            'end_time'     => 'required|time',
        ]);

        $conflicts = ScheduleService::findConflicts(
            (int) $data['teacher_id'],
            (int) $data['classroom_id'],
            (int) $data['section_id'],
            (int) $data['subject_id'],
            (string) $data['day_of_week'],
            (string) $data['start_time'],
            (string) $data['end_time'],
            $request->int('schedule_id', 0) ?: null
        );

        return $this->json([
            'has_conflicts' => $conflicts !== [],
            'conflicts'     => $conflicts,
        ], $conflicts === [] ? 'No conflicts.' : 'Conflicts detected.');
    }

    /** @return array<string,mixed> */
    private function scheduleRules(Request $request): array
    {
        return $this->validate($request, [
            'teacher_id'   => 'required|int|exists:teachers,teacher_id',
            'subject_id'   => 'required|int|exists:subjects,subject_id',
            'section_id'   => 'required|int|exists:sections,section_id',
            'classroom_id' => 'required|int|exists:classrooms,classroom_id',
            'day_of_week'  => 'required|in:' . implode(',', ScheduleService::DAYS),
            'start_time'   => 'required|time',
            'end_time'     => 'required|time',
            'time_in_window_open'    => 'nullable|int|between:0,180',
            'late_threshold_minutes' => 'nullable|int|between:0,180',
            'time_in_window_close'   => 'nullable|int|between:1,240',
            'time_out_window_open'   => 'nullable|int|between:0,180',
            'time_out_window_close'  => 'nullable|int|between:0,180',
            'minimum_dwell_minutes'  => 'nullable|int|between:0,480',
            'status'                 => 'nullable|in:active,inactive',
        ], [
            'teacher_id'   => 'Teacher',
            'subject_id'   => 'Subject',
            'section_id'   => 'Section',
            'classroom_id' => 'Classroom',
            'day_of_week'  => 'Day',
        ]);
    }

    /**
     * @param  array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function query(array $filters): array
    {
        $where    = ['sch.status = \'active\'', 'sch.deleted_at IS NULL'];
        $bindings = [];

        $map = [
            'teacher_id'   => 'sch.teacher_id',
            'section_id'   => 'sch.section_id',
            'classroom_id' => 'sch.classroom_id',
            'subject_id'   => 'sch.subject_id',
            'day_of_week'  => 'sch.day_of_week',
            'grade_level_id' => 'sec.grade_level_id',
        ];

        foreach ($map as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]        = "{$column} = :{$key}";
                $bindings[$key] = $filters[$key];
            }
        }

        return Database::instance()->select(
            'SELECT sch.*, s.subject_code, s.subject_name, d.department_name,
                    sec.section_code, sec.section_name, sec.enrolled_count,
                    gl.grade_level_code, gl.grade_level_name,
                    c.room_number, c.building, c.capacity AS room_capacity,
                    CONCAT(t.first_name, \' \', t.last_name) AS teacher_name,
                    t.employee_number,
                    dev.device_id, dev.status AS device_status,
                    (SELECT COUNT(*) FROM attendance_sessions ases
                      WHERE ases.schedule_id = sch.schedule_id) AS session_count
               FROM schedules sch
               JOIN subjects s      ON s.subject_id = sch.subject_id
               JOIN departments d   ON d.department_id = s.department_id
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               JOIN classrooms c    ON c.classroom_id = sch.classroom_id
               JOIN teachers t      ON t.teacher_id = sch.teacher_id
               LEFT JOIN devices dev ON dev.classroom_id = c.classroom_id
                    AND dev.deleted_at IS NULL AND dev.device_role IN (\'both\',\'entry\')
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY FIELD(sch.day_of_week, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                       sch.start_time, c.room_number',
            $bindings
        );
    }
}
