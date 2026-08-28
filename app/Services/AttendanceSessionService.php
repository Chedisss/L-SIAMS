<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Logger;
use DateTimeImmutable;
use PDOException;

/**
 * Attendance session lifecycle.
 *
 * Opening a session requires the teacher to prove they are present. There are
 * exactly two proofs, and both arrive here already verified: a fingerprint
 * matched at the room's terminal (FingerprintService) or the teacher's own
 * account password typed at their own dashboard (SessionOverrideService). This
 * class verifies neither — it records which one was used, in opened_method, so
 * a session opened without a scan is never mistaken for one that was.
 *
 * Closing is a single transaction that stamps missing time-outs, generates
 * absences for the whole roster and computes the rollups; any failure rolls
 * the entire close back, because a half-closed session would misreport an
 * entire class.
 */
final class AttendanceSessionService
{
    /**
     * Open a session once the teacher's identity has been proved.
     *
     * @param  array<string,mixed> $device
     * @param  array<string,mixed> $teacher
     * @param  array<string,mixed> $schedule
     * @param  'fingerprint'|'password' $openedMethod which proof the caller verified
     * @param  int|null $openedByUserId               the account behind a password override
     * @return array<string,mixed>
     */
    public static function open(
        array $device,
        array $teacher,
        array $schedule,
        ?int $fingerprintLogId = null,
        ?int $apiKeyId = null,
        string $openedMethod = 'fingerprint',
        ?int $openedByUserId = null
    ): array {
        $db  = Database::instance();
        $now = Clock::now();

        // fingerprint_log_id is a nullable foreign key recording which
        // verification authorised this session. Zero is not a log id — it is
        // what a caller with nothing to record naturally passes — and writing
        // it would fail the constraint at insert time with an error that says
        // nothing about the real cause. Normalise it to NULL, which is what
        // "no log row" actually means.
        $fingerprintLogId = ($fingerprintLogId !== null && $fingerprintLogId > 0)
            ? $fingerprintLogId
            : null;

        // An unrecognised value would be silently coerced by the ENUM, and the
        // one thing this column exists to do is tell the truth about how a
        // session was opened.
        $openedMethod = $openedMethod === 'password' ? 'password' : 'fingerprint';

        $openedByUserId = $openedMethod === 'password' && $openedByUserId !== null && $openedByUserId > 0
            ? $openedByUserId
            : null;

        $proof = $openedMethod === 'password'
            ? 'after password verification (fingerprint override)'
            : 'after fingerprint verification';

        $date  = $now->format('Y-m-d');
        $start = Clock::combine($date, (string) $schedule['start_time']);
        $end   = Clock::combine($date, (string) $schedule['end_time']);

        $expiresAt = $end->modify('+' . (int) $schedule['time_out_window_close'] . ' minutes');

        try {
            return $db->transaction(static function (Database $db) use (
                $device, $teacher, $schedule, $fingerprintLogId, $apiKeyId, $now, $date, $start, $end, $expiresAt,
                $openedMethod, $openedByUserId, $proof
            ): array {
                // The unique keys uq_one_open_session_per_classroom and
                // uq_one_open_session_per_device make a second concurrent open
                // impossible, but checking first lets us return a useful
                // message instead of a constraint violation in the common case.
                $existing = $db->selectOne(
                    "SELECT s.*, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
                       FROM attendance_sessions s
                       JOIN teachers t ON t.teacher_id = s.teacher_id
                      WHERE s.classroom_id = :classroom AND s.status = 'open'
                      LIMIT 1 FOR UPDATE",
                    ['classroom' => (int) $schedule['classroom_id']]
                );

                $handedOverFrom = null;

                if ($existing !== null) {
                    // Whose room is it? If the open session's period is still
                    // running, it is theirs and nobody else may take it. The
                    // caller has already established that this teacher's own
                    // lesson is openable (ScheduleService::openableForDevice(),
                    // or the same rule from the teacher's end in
                    // SessionOverrideService), so the two cannot both be true
                    // — but this is the room's own lock, and it answers for
                    // itself rather than trusting what the caller checked.
                    $previousEnd = Clock::parse((string) $existing['scheduled_end']);

                    if ($previousEnd > $now) {
                        throw new BusinessRuleException(
                            'SESSION_ALREADY_OPEN',
                            sprintf(
                                'An attendance session is already open in this room (%s, opened by %s).',
                                $existing['session_code'],
                                $existing['teacher_name']
                            ),
                            [
                                'display_line_1' => 'SESSION ACTIVE',
                                'display_line_2' => (string) $existing['session_code'],
                                'led'            => 'amber',
                                'buzzer'         => 'long',
                            ],
                            409
                        );
                    }

                    // The bell has gone on that period. Its session is only
                    // still open because the sweeper closes at expires_at and
                    // that is minutes away — meanwhile the next teacher is
                    // standing at the reader with a full class waiting.
                    //
                    // Hand the room over: close it now, exactly as the sweeper
                    // would have, which stamps every outstanding tap-out at the
                    // scheduled end rather than at this moment. Nobody is
                    // credited with minutes in a period that had finished.
                    self::close((int) $existing['session_id'], 'system', null);

                    $handedOverFrom = (string) $existing['session_code'];
                }

                // A session already recorded for this schedule today, and not
                // currently open.
                //
                // uq_session_schedule_day is unique on (schedule_id,
                // session_date), so the insert below cannot add a second one —
                // it failed with "A session for this schedule has already been
                // recorded today" and the teacher's scan did nothing. One
                // session per class per day is the right rule, but treating it
                // as one *attempt* per day is not: a session closed early by
                // mistake, or closed by the auto-close while the class was
                // still running, locked that class out for the rest of the day
                // with no way back. Students who had not tapped out could not,
                // and the teacher had no remedy at all.
                //
                // Reopening keeps the rule and removes the trap. It is the
                // same session continuing — same code, same rows, original
                // opened_at — so nothing already recorded moves. Only the
                // window is extended, and this is reached only after the
                // schedule check has confirmed the class is live right now, so
                // it cannot revive yesterday's class or one that has finished.
                $earlier = $db->selectOne(
                    "SELECT * FROM attendance_sessions
                      WHERE schedule_id = :schedule AND session_date = :date
                      LIMIT 1 FOR UPDATE",
                    ['schedule' => (int) $schedule['schedule_id'], 'date' => $date]
                );

                if ($earlier !== null) {
                    $db->update('attendance_sessions', [
                        'status'         => 'open',
                        'closed_at'      => null,
                        'closed_by'      => null,
                        'closed_by_type' => null,
                        'expires_at'     => $expiresAt->format('Y-m-d H:i:s'),
                        'device_row_id'  => (int) $device['id'],
                        // The reopening is its own act of identification, so
                        // the columns describe how *this* one was authorised.
                        // A session first opened at the reader and reopened by
                        // password reads as a password opening, which is the
                        // honest answer to "was a finger scanned to get into
                        // this room today".
                        'opened_method'     => $openedMethod,
                        'opened_by_user_id' => $openedByUserId,
                        'updated_at'     => Clock::nowString(),
                    ], ['session_id' => (int) $earlier['session_id']]);

                    $reopened = self::hydrateReopened(
                        $db, (int) $earlier['session_id'], $teacher, $schedule, $start, $end, $expiresAt
                    );

                    AuditService::log(
                        AuditService::ATTENDANCE_SESSION_OPENED,
                        'attendance',
                        'attendance_session',
                        (int) $earlier['session_id'],
                        ['status' => (string) $earlier['status'], 'closed_at' => $earlier['closed_at']],
                        [
                            'status'        => 'open',
                            'expires_at'    => $expiresAt->format('Y-m-d H:i:s'),
                            'opened_method' => $openedMethod,
                            'handed_over_from' => $handedOverFrom,
                        ],
                        sprintf(
                            'Attendance session %s reopened by %s %s in room %s %s. '
                            . 'It had been closed by %s.%s',
                            (string) $earlier['session_code'],
                            $teacher['first_name'],
                            $teacher['last_name'],
                            $schedule['room_number'] ?? '?',
                            $proof,
                            (string) ($earlier['closed_by_type'] ?? 'unknown'),
                            $handedOverFrom === null
                                ? ''
                                : sprintf(' The room was handed over from %s, whose period had ended.', $handedOverFrom)
                        )
                    );

                    return $reopened;
                }

                $sessionCode = self::nextSessionCode($db, $now);

                $sessionId = (int) $db->insert('attendance_sessions', [
                    'session_code'    => $sessionCode,
                    'schedule_id'     => (int) $schedule['schedule_id'],
                    'teacher_id'      => (int) $teacher['teacher_id'],
                    'device_row_id'   => (int) $device['id'],
                    'classroom_id'    => (int) $schedule['classroom_id'],
                    'section_id'      => (int) $schedule['section_id'],
                    'subject_id'      => (int) $schedule['subject_id'],
                    'session_date'    => $date,
                    'scheduled_start' => $start->format('Y-m-d H:i:s'),
                    'scheduled_end'   => $end->format('Y-m-d H:i:s'),
                    'opened_at'       => $now->format('Y-m-d H:i:s'),
                    'expires_at'      => $expiresAt->format('Y-m-d H:i:s'),
                    'status'          => 'open',
                    'fingerprint_log_id' => $fingerprintLogId,
                    'opened_method'      => $openedMethod,
                    'opened_by_user_id'  => $openedByUserId,
                    'api_key_id'      => $apiKeyId,
                    'open_ip'         => RequestContext::ip(),
                    'open_mac'        => (string) $device['mac_address'],
                    'roster_count'    => 0,
                    'created_at'      => Clock::nowString(),
                    'updated_at'      => Clock::nowString(),
                ]);

                /** @var array<string,mixed> $session */
                $session = $db->selectOne(
                    'SELECT * FROM attendance_sessions WHERE session_id = :id',
                    ['id' => $sessionId]
                ) ?? [];

                // The register the students are already in, before anybody taps.
                $carried  = self::carryForward($db, $session, $start);
                $counters = AttendanceService::updateSessionCounters($db, $sessionId);

                RealtimeService::broadcast(
                    AttendanceService::channelsFor($session),
                    'session.opened',
                    [
                        'session_id'   => $sessionCode,
                        'teacher'      => sprintf('%s %s', $teacher['first_name'], $teacher['last_name']),
                        'subject'      => (string) ($schedule['subject_name'] ?? ''),
                        'section'      => (string) ($schedule['section_code'] ?? ''),
                        'classroom'    => (string) ($schedule['room_number'] ?? ''),
                        'device_id'    => (string) $device['device_id'],
                        'opened_at'    => $now->format(DATE_ATOM),
                        'scheduled_end' => $end->format(DATE_ATOM),
                        'expires_at'   => $expiresAt->format(DATE_ATOM),
                        'carried_in'   => $carried['count'],
                        'carried_from' => $carried['from_code'],
                        'counters'     => $counters,
                    ]
                );

                AuditService::log(
                    AuditService::ATTENDANCE_SESSION_OPENED,
                    'attendance',
                    'attendance_session',
                    $sessionId,
                    null,
                    [
                        'session_code'  => $sessionCode,
                        'teacher_id'    => (int) $teacher['teacher_id'],
                        'schedule_id'   => (int) $schedule['schedule_id'],
                        'device_id'     => (string) $device['device_id'],
                        'opened_method' => $openedMethod,
                        'handed_over_from' => $handedOverFrom,
                        'carried_in'    => $carried['count'],
                        'carried_from'  => $carried['from_code'],
                    ],
                    sprintf(
                        'Attendance session %s opened by %s %s in room %s %s.%s%s',
                        $sessionCode,
                        $teacher['first_name'],
                        $teacher['last_name'],
                        $schedule['room_number'] ?? '?',
                        $proof,
                        $handedOverFrom === null
                            ? ''
                            : sprintf(' The room was handed over from %s, whose period had ended.', $handedOverFrom),
                        $carried['count'] === 0
                            ? ''
                            : sprintf(
                                ' %d student(s) carried forward from %s without tapping again.',
                                $carried['count'],
                                (string) $carried['from_code']
                            )
                    )
                );

                return [
                    'session_id'      => $sessionId,
                    'session_code'    => $sessionCode,
                    'opened_method'   => $openedMethod,
                    'teacher_name'    => sprintf('%s %s', $teacher['first_name'], $teacher['last_name']),
                    'subject_code'    => (string) ($schedule['subject_code'] ?? ''),
                    'subject_name'    => (string) ($schedule['subject_name'] ?? ''),
                    'section_code'    => (string) ($schedule['section_code'] ?? ''),
                    'room_number'     => (string) ($schedule['room_number'] ?? ''),
                    'scheduled_start' => $start->format('H:i'),
                    'scheduled_end'   => $end->format('H:i'),
                    'expires_at'      => $expiresAt->format(DATE_ATOM),
                    'roster_count'    => $counters['total'],
                    'carried_in'      => $carried['count'],
                    'carried_from'    => $carried['from_code'],
                    'late_after'      => $start->modify('+' . (int) $schedule['late_threshold_minutes'] . ' minutes')->format('H:i'),
                    'time_in_closes'  => $start->modify('+' . (int) $schedule['time_in_window_close'] . ' minutes')->format('H:i'),
                    'time_out_opens'  => $end->modify('-' . (int) $schedule['time_out_window_open'] . ' minutes')->format('H:i'),
                ];
            });
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                /** @var string|null $key */
                $key = Database::duplicateKeyName($e);

                $message = match ($key) {
                    'uq_one_open_session_per_classroom' => 'An attendance session is already open in this classroom.',
                    'uq_one_open_session_per_device'    => 'This terminal already has an open attendance session.',
                    'uq_session_schedule_day'           => 'A session for this schedule has already been recorded today.',
                    default                              => 'A session for this classroom already exists.',
                };

                throw new BusinessRuleException(
                    'SESSION_ALREADY_OPEN',
                    $message,
                    ['display_line_1' => 'SESSION ACTIVE', 'led' => 'amber', 'buzzer' => 'long'],
                    409
                );
            }

            throw $e;
        }
    }

    /**
     * Close a session (Part 16.7).
     *
     * Everything below happens in one transaction: auto time-outs, absence
     * generation, rollups, status change. A session must never be left
     * half-closed, so a failure anywhere rolls all of it back and the caller
     * may retry.
     *
     * @return array<string,mixed> the rollup summary
     */
    public static function close(int $sessionId, string $closedByType = 'teacher', ?int $closedByUserId = null): array
    {
        $db = Database::instance();

        return $db->transaction(static function (Database $db) use ($sessionId, $closedByType, $closedByUserId): array {
            $session = $db->selectOne(
                'SELECT * FROM attendance_sessions WHERE session_id = :id LIMIT 1 FOR UPDATE',
                ['id' => $sessionId]
            );

            if ($session === null) {
                throw new BusinessRuleException('SESSION_NOT_FOUND', 'Attendance session not found.', [], 404);
            }

            if ((string) $session['status'] !== 'open') {
                throw new BusinessRuleException(
                    'SESSION_ALREADY_CLOSED',
                    'This attendance session is already closed.',
                    [],
                    409
                );
            }

            $now         = Clock::now();
            $scheduledEnd = Clock::parse((string) $session['scheduled_end']);
            $autoTimeout  = (bool) Config::get('attendance.auto_timeout_on_close', true);

            // 1. Settle every record that has a time-in but no time-out.
            $pending = $db->select(
                'SELECT * FROM attendance_records
                  WHERE session_id = :id AND time_in IS NOT NULL AND time_out IS NULL
                  FOR UPDATE',
                ['id' => $sessionId]
            );

            foreach ($pending as $record) {
                $timeIn = Clock::parse((string) $record['time_in']);

                if ($autoTimeout) {
                    // Whichever came first: the bell, or the moment the session
                    // actually ended. Both bounds exist for the same reason —
                    // nobody may be credited with classroom minutes they were
                    // not there for — and each catches a case the other misses.
                    //
                    //   the sweeper closes late, at 11:10 for a period that
                    //   ended at 11:00      -> the bell wins, 11:00
                    //
                    //   the teacher closes early, at 10:20 for a period that
                    //   runs to 11:00       -> the close wins, 10:20
                    //
                    // Only the first bound was applied, so a teacher who ended
                    // a class forty minutes early still had every student
                    // recorded as leaving at the bell, with the full period
                    // credited — while the session itself said it closed at
                    // 10:20. The register disagreed with its own session.
                    $timeOut = $now < $scheduledEnd ? $now : $scheduledEnd;

                    // A close that lands before somebody's tap-in cannot walk
                    // their arrival backwards. It is a zero-length visit, not a
                    // negative one.
                    $timeOut  = $timeOut > $timeIn ? $timeOut : $timeIn;
                    $duration = max(0, (int) floor(($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 60));

                    $departureStatus = AttendanceStatusResolver::DEPARTURE_AUTO_CLOSED;

                    $db->update('attendance_records', [
                        'time_out'                => $timeOut->format('Y-m-d H:i:s'),
                        'duration_minutes'        => $duration,
                        'departure_status'        => $departureStatus,
                        'auto_generated_time_out' => 1,
                        'final_status'            => AttendanceStatusResolver::resolve(
                            (string) $record['arrival_status'],
                            $departureStatus
                        ),
                        'updated_at' => Clock::nowString(),
                    ], ['attendance_id' => (int) $record['attendance_id']]);
                } else {
                    $departureStatus = AttendanceStatusResolver::DEPARTURE_NO_TIME_OUT;

                    $db->update('attendance_records', [
                        'departure_status' => $departureStatus,
                        'final_status'     => AttendanceStatusResolver::resolve(
                            (string) $record['arrival_status'],
                            $departureStatus
                        ),
                        'updated_at' => Clock::nowString(),
                    ], ['attendance_id' => (int) $record['attendance_id']]);
                }
            }

            // 2. Absence rows for every rostered student with no record at all.
            $absentCount = 0;

            if (Config::get('attendance.generate_absent_on_close', true)) {
                $absentCount = self::generateAbsences($db, $session);
            }

            // 3. Rollups.
            $rollup = self::computeRollup($db, $sessionId);

            $db->update('attendance_sessions', [
                'status'           => 'closed',
                'closed_at'        => $now->format('Y-m-d H:i:s'),
                'closed_by_type'   => $closedByType,
                'closed_by'        => $closedByUserId,
                'roster_count'     => $rollup['roster_count'],
                'present_count'    => $rollup['present_count'],
                'late_count'       => $rollup['late_count'],
                'absent_count'     => $rollup['absent_count'],
                'incomplete_count' => $rollup['incomplete_count'],
                'left_early_count' => $rollup['left_early_count'],
                'timed_out_count'  => $rollup['timed_out_count'],
                'average_dwell_minutes' => $rollup['average_dwell_minutes'],
                'attendance_percentage' => $rollup['attendance_percentage'],
                'updated_at'       => Clock::nowString(),
            ], ['session_id' => $sessionId]);

            $summary = $rollup + [
                'session_id'    => (string) $session['session_code'],
                'closed_at'     => $now->format(DATE_ATOM),
                'closed_by'     => $closedByType === 'system' ? 'SYSTEM' : ($closedByUserId ?? 'teacher'),
                'absent_generated' => $absentCount,
            ];

            RealtimeService::broadcast(
                AttendanceService::channelsFor($session),
                'session.closed',
                $summary
            );

            AuditService::log(
                AuditService::ATTENDANCE_SESSION_CLOSED,
                'attendance',
                'attendance_session',
                $sessionId,
                ['status' => 'open'],
                $summary,
                sprintf(
                    'Session %s closed by %s. Present %d, late %d, absent %d, incomplete %d.',
                    $session['session_code'],
                    $closedByType,
                    $rollup['present_count'],
                    $rollup['late_count'],
                    $rollup['absent_count'],
                    $rollup['incomplete_count']
                ),
                'success',
                $closedByUserId
            );

            return $summary;
        });
    }

    /**
     * Carry the previous period's register into this one.
     *
     * A section sits in one room for most of the day. The bell goes, one
     * teacher leaves, the next walks in, and the same forty students are in the
     * same chairs. Making all of them tap again for every subject is forty taps
     * of queueing, six or seven times a day, to establish a fact the system
     * recorded five minutes ago.
     *
     * So presence carries. Anyone who was Present or Late when the last period
     * ended starts this one Present, with no tap.
     *
     * What does NOT carry is anyone who was not in the room at the bell:
     * Absent, Left Early, Excused and Incomplete all carry nothing. That is the
     * more important half of the rule — missing first period must not condemn a
     * student to being marked absent for the rest of the day, and leaving early
     * must not either. They get exactly the same chance to tap in for the next
     * subject as anybody else, because this leaves them with no record at all
     * and the tap-in path treats them as it treats everyone.
     *
     * Provenance is not optional. A carried row sets carried_from_session_id
     * and leaves time_in_device_id, time_in_ip and time_in_mac NULL, because no
     * card was presented to any reader — writing a device id would be a lie
     * that every report and audit downstream would faithfully repeat.
     *
     * @param  array<string,mixed> $session the session being opened
     * @return array{count:int,from_code:?string}
     */
    private static function carryForward(Database $db, array $session, DateTimeImmutable $start): array
    {
        $none    = ['count' => 0, 'from_code' => null];
        $carryAt = Clock::now();

        if (!Config::get('attendance.carry_over.enabled', true)) {
            return $none;
        }

        // The afternoon starts from zero. A session opening at or after the
        // reset time never inherits a register, whatever preceded it and
        // however small the gap.
        //
        // This is deliberately not expressed as a gap. A morning class ending
        // at 11:50 and an afternoon class opening at 12:10 is twenty minutes
        // apart — well inside the limit — and carrying that register forward
        // would mark present every student who went home at lunch. The break
        // that matters is a fixed point in the school day, not a duration.
        $resetAt = trim((string) Config::get('attendance.carry_over.reset_at', '12:00'));

        if ($resetAt !== '' && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $resetAt, $clock) === 1) {
            $boundary = $start->setTime((int) $clock[1], (int) $clock[2], 0);

            if ($start >= $boundary) {
                return $none;
            }
        }

        $maxGap = max(0, (int) Config::get('attendance.carry_over.max_gap_minutes', 30));

        // The period this section was in immediately before. Same section, same
        // day, already closed, and it must have ENDED — an overlapping session
        // is a scheduling mistake, not a handover, and carrying from one would
        // duplicate a register that is still being written.
        //
        // The gap limit is what keeps "the period before" meaning the period
        // before. Two classes an hour apart are not a handover; the students
        // went somewhere in between, and whatever they did there is not
        // evidence that they are sitting here now.
        $previous = $db->selectOne(
            "SELECT * FROM attendance_sessions
              WHERE section_id   = :section
                AND session_date = :date
                AND session_id  <> :self
                AND status       = 'closed'
                AND scheduled_end <= :start
                AND scheduled_end >= :earliest
              ORDER BY scheduled_end DESC
              LIMIT 1",
            [
                'section'  => (int) $session['section_id'],
                'date'     => (string) $session['session_date'],
                'self'     => (int) $session['session_id'],
                'start'    => $start->format('Y-m-d H:i:s'),
                'earliest' => $start->modify('-' . $maxGap . ' minutes')->format('Y-m-d H:i:s'),
            ]
        );

        if ($previous === null) {
            return $none;
        }

        $carried = $db->execute(
            "INSERT INTO attendance_records
                 (session_id, student_id, section_id, grade_level_id, subject_id, teacher_id, classroom_id,
                  rfid_uid, time_in, arrival_status, departure_status, final_status,
                  carried_from_session_id, created_at, updated_at)
             SELECT :session, prev.student_id, prev.section_id, prev.grade_level_id,
                    :subject, :teacher, :classroom,
                    prev.rfid_uid, :time_in, 'present', 'pending', 'Present',
                    :previous, :now, :now
               FROM attendance_records prev
               JOIN students st ON st.student_id = prev.student_id
              WHERE prev.session_id = :previous2
                AND prev.final_status IN ('Present','Late')
                AND st.deleted_at IS NULL
                AND st.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM attendance_records ar
                     WHERE ar.session_id = :session2 AND ar.student_id = prev.student_id
                )",
            [
                'session'   => (int) $session['session_id'],
                'session2'  => (int) $session['session_id'],
                'previous'  => (int) $previous['session_id'],
                'previous2' => (int) $previous['session_id'],
                'subject'   => (int) $session['subject_id'],
                'teacher'   => (int) $session['teacher_id'],
                'classroom' => (int) $session['classroom_id'],
                // The period began at the bell and the student was already
                // sitting in it, so the bell is the arrival — stamping "now"
                // would date it to whenever the teacher got their finger read,
                // which on a late opening is a different fact entirely.
                //
                // Capped at the present moment for the opposite case: a teacher
                // who opens during the tap-in grace window, a few minutes
                // before the bell, must not produce a register of arrivals
                // timed in the future.
                'time_in'   => ($start > $carryAt ? $carryAt : $start)->format('Y-m-d H:i:s'),
                'now'       => Clock::nowString(),
            ]
        );

        if ($carried > 0) {
            $db->update('attendance_sessions', [
                'carried_in_count' => $carried,
                'updated_at'       => Clock::nowString(),
            ], ['session_id' => (int) $session['session_id']]);
        }

        return ['count' => $carried, 'from_code' => (string) $previous['session_code']];
    }

    /**
     * Insert Absent rows for rostered students who never tapped.
     *
     * INSERT … SELECT with a NOT EXISTS guard does this in one statement, which
     * matters when a large section closes: 45 individual round trips inside a
     * held transaction would extend the lock window unnecessarily.
     *
     * The departure is no_time_out, not pending. Pending means "in the room,
     * has not tapped out yet" — it is the state a present student sits in
     * while the class runs, and every screen renders it as still being there.
     * On somebody who never arrived it produced a row reading absent and still
     * in the room at the same time. no_time_out says the only thing that is
     * actually true: there is no tap-out, and there is never going to be one.
     *
     * The final status is unaffected either way — resolve() short-circuits on
     * an absent arrival before it looks at the departure at all.
     *
     * @param array<string,mixed> $session
     */
    private static function generateAbsences(Database $db, array $session): int
    {
        $sectionId = (int) $session['section_id'];
        $sessionId = (int) $session['session_id'];

        $gradeLevelId = (int) $db->scalar(
            'SELECT grade_level_id FROM sections WHERE section_id = :id',
            ['id' => $sectionId]
        );

        return $db->execute(
            'INSERT INTO attendance_records
                 (session_id, student_id, section_id, grade_level_id, subject_id, teacher_id, classroom_id,
                  arrival_status, departure_status, final_status, created_at, updated_at)
             SELECT :session, st.student_id, :section, :grade, :subject, :teacher, :classroom,
                    \'absent\', \'no_time_out\', \'Absent\', :now, :now
               FROM students st
              WHERE st.section_id = :section
                AND st.deleted_at IS NULL
                AND st.status = \'active\'
                AND NOT EXISTS (
                    SELECT 1 FROM attendance_records ar
                     WHERE ar.session_id = :session AND ar.student_id = st.student_id
                )',
            [
                'session'   => $sessionId,
                'section'   => $sectionId,
                'grade'     => $gradeLevelId,
                'subject'   => (int) $session['subject_id'],
                'teacher'   => (int) $session['teacher_id'],
                'classroom' => (int) $session['classroom_id'],
                'now'       => Clock::nowString(),
            ]
        );
    }

    /** @return array<string,mixed> */
    private static function computeRollup(Database $db, int $sessionId): array
    {
        $row = $db->selectOne(
            "SELECT
                COUNT(*) AS roster_count,
                SUM(CASE WHEN final_status = 'Present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN final_status = 'Late' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN final_status = 'Absent' THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE WHEN final_status = 'Incomplete' THEN 1 ELSE 0 END) AS incomplete_count,
                SUM(CASE WHEN final_status = 'Left Early' THEN 1 ELSE 0 END) AS left_early_count,
                SUM(CASE WHEN final_status = 'Excused' THEN 1 ELSE 0 END) AS excused_count,
                SUM(CASE WHEN departure_status IN ('timed_out','auto_closed') THEN 1 ELSE 0 END) AS timed_out_count,
                AVG(duration_minutes) AS average_dwell
               FROM attendance_records WHERE session_id = :id",
            ['id' => $sessionId]
        ) ?? [];

        $roster   = (int) ($row['roster_count'] ?? 0);
        $attended = (int) ($row['present_count'] ?? 0)
            + (int) ($row['late_count'] ?? 0)
            + (int) ($row['left_early_count'] ?? 0)
            + (int) ($row['incomplete_count'] ?? 0);

        return [
            'roster_count'     => $roster,
            'present_count'    => (int) ($row['present_count'] ?? 0),
            'late_count'       => (int) ($row['late_count'] ?? 0),
            'absent_count'     => (int) ($row['absent_count'] ?? 0),
            'incomplete_count' => (int) ($row['incomplete_count'] ?? 0),
            'left_early_count' => (int) ($row['left_early_count'] ?? 0),
            'excused_count'    => (int) ($row['excused_count'] ?? 0),
            'timed_out_count'  => (int) ($row['timed_out_count'] ?? 0),
            'average_dwell_minutes' => $row['average_dwell'] === null ? null : (int) round((float) $row['average_dwell']),
            'attendance_percentage' => $roster === 0 ? 0.0 : round($attended / $roster * 100, 2),
        ];
    }

    /**
     * Close every session past its window. Driven by the maintenance worker so
     * a classroom that loses power mid-session still gets a correct, closed
     * record rather than an eternally open one.
     */
    public static function autoCloseExpired(): int
    {
        if (!Config::get('attendance.auto_close_sessions', true)) {
            return 0;
        }

        $expired = Database::instance()->select(
            "SELECT session_id, session_code FROM attendance_sessions
              WHERE status = 'open' AND expires_at < :now
              LIMIT 100",
            ['now' => Clock::nowString()]
        );

        $closed = 0;

        foreach ($expired as $session) {
            try {
                self::close((int) $session['session_id'], 'system', null);
                $closed++;
            } catch (\Throwable $e) {
                Logger::error('Auto-close failed', [
                    'session_id' => $session['session_id'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $closed;
    }

    /** Warn a teacher shortly before their session auto-closes. */
    public static function notifyExpiringSessions(int $warnMinutes = 5): int
    {
        $sessions = Database::instance()->select(
            "SELECT s.*, sec.section_code, sub.subject_code, c.room_number
               FROM attendance_sessions s
               JOIN sections sec ON sec.section_id = s.section_id
               JOIN subjects sub ON sub.subject_id = s.subject_id
               JOIN classrooms c ON c.classroom_id = s.classroom_id
              WHERE s.status = 'open'
                AND s.expires_at BETWEEN :now AND DATE_ADD(:now, INTERVAL :minutes MINUTE)",
            ['now' => Clock::nowString(), 'minutes' => $warnMinutes]
        );

        foreach ($sessions as $session) {
            RealtimeService::broadcast(
                AttendanceService::channelsFor($session),
                'session.expiring',
                [
                    'session_id' => (string) $session['session_code'],
                    'expires_at' => Clock::parse((string) $session['expires_at'])->format(DATE_ATOM),
                    'subject'    => (string) $session['subject_code'],
                    'section'    => (string) $session['section_code'],
                    'room'       => (string) $session['room_number'],
                ]
            );
        }

        return count($sessions);
    }

    /** ATT-{year}-{6 digits}, unique per year. */
    /**
     * A reopened session in the same shape the terminal gets for a new one.
     *
     * The board reads these fields to drive its display and its windows, and
     * it has no idea whether the session it is being told about was opened a
     * moment ago or resumed. Returning a different shape for the two would
     * make reopening a second code path on the device, which is where the
     * differences would quietly accumulate.
     *
     * @param  array<string,mixed> $teacher
     * @param  array<string,mixed> $schedule
     * @return array<string,mixed>
     */
    private static function hydrateReopened(
        Database $db,
        int $sessionId,
        array $teacher,
        array $schedule,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeImmutable $expiresAt
    ): array {
        /** @var array<string,mixed> $session */
        $session  = $db->selectOne('SELECT * FROM attendance_sessions WHERE session_id = :id', ['id' => $sessionId]) ?? [];
        $counters = AttendanceService::updateSessionCounters($db, $sessionId);

        RealtimeService::broadcast(
            AttendanceService::channelsFor($session),
            'session.opened',
            [
                'session_id'    => (string) $session['session_code'],
                'teacher'       => sprintf('%s %s', $teacher['first_name'], $teacher['last_name']),
                'subject'       => (string) ($schedule['subject_name'] ?? ''),
                'section'       => (string) ($schedule['section_code'] ?? ''),
                'classroom'     => (string) ($schedule['room_number'] ?? ''),
                'reopened'      => true,
                'scheduled_end' => $end->format(DATE_ATOM),
                'expires_at'    => $expiresAt->format(DATE_ATOM),
                'counters'      => $counters,
            ]
        );

        return [
            'session_id'      => $sessionId,
            'session_code'    => (string) $session['session_code'],
            'opened_method'   => (string) ($session['opened_method'] ?? 'fingerprint'),
            'teacher_name'    => sprintf('%s %s', $teacher['first_name'], $teacher['last_name']),
            'subject_code'    => (string) ($schedule['subject_code'] ?? ''),
            'subject_name'    => (string) ($schedule['subject_name'] ?? ''),
            'section_code'    => (string) ($schedule['section_code'] ?? ''),
            'room_number'     => (string) ($schedule['room_number'] ?? ''),
            'scheduled_start' => $start->format('H:i'),
            'scheduled_end'   => $end->format('H:i'),
            'expires_at'      => $expiresAt->format(DATE_ATOM),
            'roster_count'    => $counters['total'],
            'late_after'      => $start->modify('+' . (int) $schedule['late_threshold_minutes'] . ' minutes')->format('H:i'),
            'time_in_closes'  => $start->modify('+' . (int) $schedule['time_in_window_close'] . ' minutes')->format('H:i'),
            'time_out_opens'  => $end->modify('-' . (int) $schedule['time_out_window_open'] . ' minutes')->format('H:i'),
            'reopened'        => true,
        ];
    }

    private static function nextSessionCode(Database $db, DateTimeImmutable $now): string
    {
        $year   = $now->format('Y');
        $prefix = 'ATT-' . $year . '-';

        $last = $db->scalar(
            'SELECT session_code FROM attendance_sessions
              WHERE session_code LIKE :prefix
              ORDER BY session_id DESC LIMIT 1',
            ['prefix' => $prefix . '%']
        );

        $sequence = $last === null ? 1 : ((int) substr((string) $last, -6)) + 1;

        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $sessionId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT s.*, sec.section_code, sec.section_name, sub.subject_code, sub.subject_name,
                    c.room_number, c.building, d.device_id,
                    CONCAT(t.first_name, \' \', t.last_name) AS teacher_name,
                    sch.start_time, sch.end_time, sch.late_threshold_minutes,
                    sch.time_in_window_close, sch.time_out_window_open, sch.minimum_dwell_minutes
               FROM attendance_sessions s
               JOIN sections sec  ON sec.section_id = s.section_id
               JOIN subjects sub  ON sub.subject_id = s.subject_id
               JOIN classrooms c  ON c.classroom_id = s.classroom_id
               JOIN teachers t    ON t.teacher_id = s.teacher_id
               JOIN devices d     ON d.id = s.device_row_id
               JOIN schedules sch ON sch.schedule_id = s.schedule_id
              WHERE s.session_id = :id',
            ['id' => $sessionId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $sessionCode): ?array
    {
        $id = Database::instance()->scalar(
            'SELECT session_id FROM attendance_sessions WHERE session_code = :code',
            ['code' => $sessionCode]
        );

        return $id === null ? null : self::find((int) $id);
    }

    /** Full roster with each student's live state — the session detail view. @return list<array<string,mixed>> */
    public static function roster(int $sessionId): array
    {
        return Database::instance()->select(
            "SELECT st.student_id, st.student_number, st.photo_path,
                    CONCAT(st.last_name, ', ', st.first_name) AS student_name,
                    rc.card_uid,
                    ar.attendance_id, ar.time_in, ar.time_out, ar.duration_minutes,
                    ar.arrival_status, ar.departure_status, ar.final_status,
                    ar.auto_generated_time_out,
                    ar.carried_from_session_id, prev.session_code AS carried_from_code,
                    prevsub.subject_code AS carried_from_subject,
                    ar.early_release_reason, ar.early_release_note,
                    ru.full_name AS early_released_by_name
               FROM attendance_sessions s
               JOIN students st ON st.section_id = s.section_id AND st.deleted_at IS NULL AND st.status = 'active'
               LEFT JOIN rfid_cards rc ON rc.student_id = st.student_id AND rc.status = 'active'
               LEFT JOIN attendance_records ar ON ar.session_id = s.session_id AND ar.student_id = st.student_id
               LEFT JOIN attendance_sessions prev ON prev.session_id = ar.carried_from_session_id
               LEFT JOIN subjects prevsub ON prevsub.subject_id = prev.subject_id
               LEFT JOIN users ru ON ru.user_id = ar.early_released_by
              WHERE s.session_id = :id
              ORDER BY st.last_name, st.first_name",
            ['id' => $sessionId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function openSessions(): array
    {
        return Database::instance()->select(
            "SELECT s.session_id, s.session_code, s.opened_at, s.expires_at,
                    s.present_count, s.late_count, s.roster_count, s.rejected_tap_count,
                    sec.section_code, sub.subject_code, c.room_number, d.device_id,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM attendance_sessions s
               JOIN sections sec ON sec.section_id = s.section_id
               JOIN subjects sub ON sub.subject_id = s.subject_id
               JOIN classrooms c ON c.classroom_id = s.classroom_id
               JOIN teachers t   ON t.teacher_id = s.teacher_id
               JOIN devices d    ON d.id = s.device_row_id
              WHERE s.status = 'open'
              ORDER BY s.opened_at DESC"
        );
    }
}
