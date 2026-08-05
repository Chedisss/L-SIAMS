<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;

/**
 * Read paths for attendance, plus the administrator correction path.
 *
 * Teachers cannot modify attendance by any route — there is no method here that
 * accepts a teacher as the actor, and the routes that reach correct() are
 * gated on `role:administrator`.
 */
final class AttendanceQueryService
{
    /**
     * @param  array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage, string $sort = 'time_in', string $direction = 'DESC'): array
    {
        [$whereSql, $bindings] = self::buildFilters($filters);

        $sortable = [
            'time_in'      => 'v.time_in',
            'time_out'     => 'v.time_out',
            'student_name' => 'v.student_name',
            'section'      => 'v.section_code',
            'subject'      => 'v.subject_code',
            'status'       => 'v.final_status',
            'duration'     => 'v.duration_minutes',
        ];

        $orderBy   = $sortable[$sort] ?? 'v.time_in';
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $db = Database::instance();

        $total = (int) $db->scalar("SELECT COUNT(*) FROM v_attendance_detail v WHERE {$whereSql}", $bindings);

        $rows = $db->select(
            "SELECT v.* FROM v_attendance_detail v
              WHERE {$whereSql}
              ORDER BY {$orderBy} {$direction}, v.attendance_id DESC
              LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function buildFilters(array $filters): array
    {
        $where    = ['1=1'];
        $bindings = [];

        $map = [
            'section_id'     => 'v.section_id',
            'grade_level_id' => 'v.grade_level_id',
            'subject_id'     => 'v.subject_id',
            'teacher_id'     => 'v.teacher_id',
            'classroom_id'   => 'v.classroom_id',
            'student_id'     => 'v.student_id',
            'session_id'     => 'v.session_id',
            'final_status'   => 'v.final_status',
            'arrival_status' => 'v.arrival_status',
            'departure_status' => 'v.departure_status',
            'department_id'  => 'v.department_id',
        ];

        foreach ($map as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== null) {
                $where[]        = "{$column} = :{$key}";
                $bindings[$key] = $filters[$key];
            }
        }

        if (!empty($filters['date'])) {
            $where[]          = 'v.attendance_date = :date';
            $bindings['date'] = (string) $filters['date'];
        }

        if (!empty($filters['date_from'])) {
            $where[]               = 'v.attendance_date >= :date_from';
            $bindings['date_from'] = (string) $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[]             = 'v.attendance_date <= :date_to';
            $bindings['date_to'] = (string) $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(v.student_name LIKE :search
                         OR v.student_number LIKE :search
                         OR v.rfid_uid LIKE :search
                         OR v.teacher_name LIKE :search
                         OR v.session_code LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['auto_closed_only'])) {
            $where[] = 'v.auto_generated_time_out = 1';
        }

        if (!empty($filters['offline_only'])) {
            $where[] = 'v.synced_offline = 1';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $attendanceId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT v.*, ases.session_code, ases.opened_at, ases.closed_at, ases.scheduled_start, ases.scheduled_end,
                    ar.time_in_ip, ar.time_out_ip, ar.time_in_mac, ar.time_out_mac, ar.request_id,
                    dev_in.device_name AS time_in_device_name, dev_out.device_name AS time_out_device_name
               FROM v_attendance_detail v
               JOIN attendance_records ar   ON ar.attendance_id = v.attendance_id
               JOIN attendance_sessions ases ON ases.session_id = v.session_id
               LEFT JOIN devices dev_in  ON dev_in.device_id = ar.time_in_device_id
               LEFT JOIN devices dev_out ON dev_out.device_id = ar.time_out_device_id
              WHERE v.attendance_id = :id',
            ['id' => $attendanceId]
        );
    }

    /**
     * Administrator correction (Part 16 / Part 3).
     *
     * Every change requires a reason, preserves the original value in
     * attendance_modifications, and writes an audit entry. final_status is never
     * set directly — it is recomputed by the resolver so a correction cannot
     * introduce a combination the rest of the system does not understand.
     *
     * @param array<string,mixed> $changes
     */
    public static function correct(int $attendanceId, array $changes, string $reason, int $administratorId): void
    {
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => ['A reason is required for every attendance correction.']]);
        }

        $db = Database::instance();

        $db->transaction(static function (Database $db) use ($attendanceId, $changes, $reason, $administratorId): void {
            $record = $db->selectOne(
                'SELECT * FROM attendance_records WHERE attendance_id = :id FOR UPDATE',
                ['id' => $attendanceId]
            );

            if ($record === null) {
                throw new ValidationException(['attendance_id' => ['Attendance record not found.']]);
            }

            $update      = [];
            $modifications = [];

            $arrivalStatus   = (string) $record['arrival_status'];
            $departureStatus = (string) $record['departure_status'];

            if (isset($changes['arrival_status']) && $changes['arrival_status'] !== $arrivalStatus) {
                if (!in_array((string) $changes['arrival_status'], AttendanceStatusResolver::allArrivalStatuses(), true)) {
                    throw new ValidationException(['arrival_status' => ['Invalid arrival status.']]);
                }

                $modifications[] = ['arrival_status', $arrivalStatus, (string) $changes['arrival_status']];
                $arrivalStatus            = (string) $changes['arrival_status'];
                $update['arrival_status'] = $arrivalStatus;
            }

            if (isset($changes['departure_status']) && $changes['departure_status'] !== $departureStatus) {
                if (!in_array((string) $changes['departure_status'], AttendanceStatusResolver::allDepartureStatuses(), true)) {
                    throw new ValidationException(['departure_status' => ['Invalid departure status.']]);
                }

                $modifications[] = ['departure_status', $departureStatus, (string) $changes['departure_status']];
                $departureStatus            = (string) $changes['departure_status'];
                $update['departure_status'] = $departureStatus;
            }

            foreach (['time_in', 'time_out'] as $field) {
                if (!array_key_exists($field, $changes)) {
                    continue;
                }

                $newValue = $changes[$field] === '' || $changes[$field] === null
                    ? null
                    : Clock::parse((string) $changes[$field])->format('Y-m-d H:i:s');

                if ($newValue !== $record[$field]) {
                    $modifications[] = [$field, (string) ($record[$field] ?? ''), (string) ($newValue ?? '')];
                    $update[$field]  = $newValue;
                }
            }

            if ($modifications === []) {
                return;
            }

            // Keep the derived fields honest after a timestamp edit.
            $timeIn  = $update['time_in']  ?? $record['time_in'];
            $timeOut = $update['time_out'] ?? $record['time_out'];

            if ($timeIn !== null && $timeOut !== null) {
                $update['duration_minutes'] = max(0, (int) floor(
                    (Clock::parse((string) $timeOut)->getTimestamp() - Clock::parse((string) $timeIn)->getTimestamp()) / 60
                ));
            } elseif ($timeOut === null) {
                $update['duration_minutes'] = null;
            }

            $newFinalStatus = AttendanceStatusResolver::resolve($arrivalStatus, $departureStatus);

            if ($newFinalStatus !== (string) $record['final_status']) {
                $modifications[]        = ['final_status', (string) $record['final_status'], $newFinalStatus];
                $update['final_status'] = $newFinalStatus;
            }

            $update['updated_at'] = Clock::nowString();

            $db->update('attendance_records', $update, ['attendance_id' => $attendanceId]);

            foreach ($modifications as [$field, $oldValue, $newValue]) {
                $db->insert('attendance_modifications', [
                    'attendance_id'    => $attendanceId,
                    'administrator_id' => $administratorId,
                    'field_changed'    => $field,
                    'old_value'        => mb_substr((string) $oldValue, 0, 120),
                    'new_value'        => mb_substr((string) $newValue, 0, 120),
                    'reason'           => mb_substr($reason, 0, 500),
                    'ip_address'       => RequestContext::ip(),
                    'created_at'       => Clock::nowString(),
                ]);
            }

            AuditService::log(
                AuditService::ATTENDANCE_MODIFIED,
                'attendance',
                'attendance_record',
                $attendanceId,
                ['arrival_status' => $record['arrival_status'], 'departure_status' => $record['departure_status'], 'final_status' => $record['final_status']],
                ['arrival_status' => $arrivalStatus, 'departure_status' => $departureStatus, 'final_status' => $newFinalStatus, 'reason' => $reason],
                sprintf('Attendance record #%d corrected. Reason: %s', $attendanceId, $reason),
                'success',
                $administratorId
            );

            $session = $db->selectOne(
                'SELECT * FROM attendance_sessions WHERE session_id = :id',
                ['id' => (int) $record['session_id']]
            );

            if ($session !== null) {
                RealtimeService::broadcast(
                    AttendanceService::channelsFor($session),
                    'attendance.corrected',
                    [
                        'attendance_id' => $attendanceId,
                        'student_id'    => (int) $record['student_id'],
                        'final_status'  => $newFinalStatus,
                        'reason'        => $reason,
                    ]
                );
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public static function modificationHistory(int $attendanceId): array
    {
        return Database::instance()->select(
            'SELECT m.*, u.username, u.full_name
               FROM attendance_modifications m
               JOIN users u ON u.user_id = m.administrator_id
              WHERE m.attendance_id = :id
              ORDER BY m.modification_id DESC',
            ['id' => $attendanceId]
        );
    }

    /**
     * All taps for one student in one session, including the rejected ones —
     * the "full tap history" the details modal shows (Part 16.10).
     *
     * @return list<array<string,mixed>>
     */
    public static function tapHistoryForRecord(int $attendanceId): array
    {
        $record = Database::instance()->selectOne(
            'SELECT session_id, student_id FROM attendance_records WHERE attendance_id = :id',
            ['id' => $attendanceId]
        );

        if ($record === null) {
            return [];
        }

        return Database::instance()->select(
            'SELECT rl.*, d.device_id
               FROM rfid_logs rl
               LEFT JOIN devices d ON d.id = rl.device_row_id
              WHERE rl.session_id = :session AND rl.student_id = :student
              ORDER BY rl.log_id ASC',
            ['session' => (int) $record['session_id'], 'student' => (int) $record['student_id']]
        );
    }

    /**
     * The live feed. Ordered by attendance_id so the cursor is monotonic even
     * when two records share a timestamp to the second.
     *
     * @return list<array<string,mixed>>
     */
    public static function liveFeed(int $sinceId = 0, int $limit = 50, ?int $teacherId = null): array
    {
        $bindings = ['since' => $sinceId];
        $where    = ['v.attendance_id > :since', 'v.time_in IS NOT NULL'];

        if ($teacherId !== null) {
            $where[]               = 'v.teacher_id = :teacher';
            $bindings['teacher'] = $teacherId;
        }

        return Database::instance()->select(
            'SELECT v.attendance_id, v.student_number, v.student_name, v.student_photo,
                    v.section_code, v.subject_code, v.teacher_name, v.room_number,
                    v.rfid_uid, v.time_in, v.time_out, v.duration_minutes,
                    v.arrival_status, v.departure_status, v.final_status, v.session_code
               FROM v_attendance_detail v
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY v.attendance_id DESC
              LIMIT ' . max(1, min($limit, 200)),
            $bindings
        );
    }

    /** Rejected taps for the live feed's rejection strip. @return list<array<string,mixed>> */
    public static function recentRejections(int $limit = 20, ?int $teacherId = null): array
    {
        $where    = ["rl.result <> 'accepted'"];
        $bindings = [];

        if ($teacherId !== null) {
            $where[]             = 'ases.teacher_id = :teacher';
            $bindings['teacher'] = $teacherId;
        }

        return Database::instance()->select(
            'SELECT rl.log_id, rl.card_uid, rl.result, rl.message, rl.created_at,
                    s.student_number, CONCAT(s.last_name, \', \', s.first_name) AS student_name,
                    ssec.section_code AS student_section, sec.section_code AS session_section,
                    d.device_id, c.room_number, ases.session_code
               FROM rfid_logs rl
               LEFT JOIN students s              ON s.student_id = rl.student_id
               LEFT JOIN sections ssec           ON ssec.section_id = rl.student_section_id
               LEFT JOIN sections sec            ON sec.section_id = rl.session_section_id
               LEFT JOIN devices d               ON d.id = rl.device_row_id
               LEFT JOIN classrooms c            ON c.classroom_id = d.classroom_id
               LEFT JOIN attendance_sessions ases ON ases.session_id = rl.session_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY rl.log_id DESC
              LIMIT ' . max(1, min($limit, 100)),
            $bindings
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forStudent(int $studentId, int $limit = 50): array
    {
        return Database::instance()->select(
            'SELECT * FROM v_attendance_detail
              WHERE student_id = :id
              ORDER BY attendance_id DESC LIMIT ' . max(1, min($limit, 500)),
            ['id' => $studentId]
        );
    }
}
