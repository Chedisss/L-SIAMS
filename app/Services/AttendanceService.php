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
use Throwable;

/**
 * The tap engine.
 *
 * Implements the authoritative validation sequence from Part 15.2 (steps 7–18;
 * steps 1–6 are device authentication and run in DeviceAuthMiddleware before
 * the request reaches here), the time-in/time-out state machine from Part 16,
 * and the concurrency guarantees from Part 17.2.
 *
 * Locking discipline, applied identically on every path so deadlocks cannot
 * arise from inconsistent ordering:
 *
 *      1. attendance_sessions row   FOR UPDATE
 *      2. attendance_records row    FOR UPDATE
 *      3. log writes
 *
 * The unique key uq_attendance_session_student is the real guarantee. The row
 * locks make the common case orderly; the constraint makes the pathological
 * case — two devices, same card, same millisecond — impossible rather than
 * merely unlikely.
 */
final class AttendanceService
{
    public const INTENT_TIME_IN  = 'time_in';
    public const INTENT_TIME_OUT = 'time_out';
    public const INTENT_COMPLETE = 'complete';

    /**
     * Writes that must outlive a rolled-back rejection.
     *
     * A rejected tap records why it was rejected and then throws, which unwinds
     * the transaction. Left inside it, the rejection log and the session's
     * rejected-tap counter would be rolled back along with the rejection —
     * losing exactly the evidence they exist to preserve. These buffers hold
     * them until the rollback is done. See flushDeferredWrites().
     *
     * @var list<array<string,mixed>>
     */
    private static array $deferredLogs = [];

    /** @var list<int> session ids whose rejected-tap counter should increment */
    private static array $deferredRejectedCounts = [];

    /**
     * Process one card tap.
     *
     * @param  array<string,mixed> $device authenticated device row
     * @return array<string,mixed> device-renderable response
     */
    public static function tap(
        array $device,
        string $cardUid,
        ?string $requestId = null,
        ?string $forcedIntent = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        $cardUid = self::normaliseUid($cardUid);
        $db      = Database::instance();

        // Offline queue replays carry the original tap time; live taps use
        // server time so a drifting device clock cannot shift a student between
        // Present and Late (Part 4, "attendance timestamps should use server
        // time whenever possible").
        $now = $occurredAt ?? Clock::now();

        // Anything left over from an earlier tap would otherwise be attributed
        // to this one.
        self::discardDeferredWrites();

        try {
            $result = $db->transaction(static function (Database $db) use ($device, $cardUid, $requestId, $forcedIntent, $now): array {
                return self::processTap($db, $device, $cardUid, $requestId, $forcedIntent, $now);
            });

            self::discardDeferredWrites();

            return $result;
        } catch (BusinessRuleException $e) {
            // The transaction has rolled back; the rejection log and the
            // session's rejected-tap counter are written now so that they
            // survive it.
            self::flushDeferredWrites($db);

            throw $e;
        } catch (PDOException $e) {
            self::flushDeferredWrites($db);

            // Expected race: another connection inserted the row between our
            // lock and our insert. That is a duplicate tap, not a server fault.
            if (Database::isDuplicateKey($e)) {
                $keyName = Database::duplicateKeyName($e);

                Logger::info('Tap resolved to duplicate by unique constraint', [
                    'key'    => $keyName,
                    'device' => $device['device_id'] ?? null,
                ]);

                if ($keyName === 'uq_attendance_request_id') {
                    // Idempotent retry that beat the processed_requests write.
                    throw new BusinessRuleException(
                        'DUPLICATE_REQUEST',
                        'This tap was already processed.',
                        self::display('ALREADY COMPLETE', '', 'amber', 'long'),
                        409
                    );
                }

                throw new BusinessRuleException(
                    'DUPLICATE_TIME_IN',
                    'Attendance already recorded.',
                    self::display('ALREADY RECORDED', '', 'amber', 'long'),
                    409
                );
            }

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed> $device
     * @return array<string,mixed>
     */
    private static function processTap(
        Database $db,
        array $device,
        string $cardUid,
        ?string $requestId,
        ?string $forcedIntent,
        DateTimeImmutable $now
    ): array {
        $deviceRowId = (int) $device['id'];

        // --- 6b. This exact tap has already been applied ---------------------
        // A request_id identifies one physical tap, not one HTTP call, so a
        // record that was already written must be recognised however it comes
        // back — including a queue replay that arrives long after the fact.
        //
        // Without this check the replay falls through to intent resolution,
        // which sees the student is timed in and reads the *same* tap as a
        // time-out. A terminal that synced successfully but lost the response
        // would then close a student's attendance by retrying, which is the
        // precise failure idempotency exists to prevent.
        //
        // The unique key on request_id does not cover this: it only fires on an
        // INSERT, and the misread replay performs an UPDATE.
        if ($requestId !== null && $requestId !== '') {
            $existing = $db->selectOne(
                'SELECT attendance_id FROM attendance_records WHERE request_id = :rid LIMIT 1',
                ['rid' => $requestId]
            );

            if ($existing !== null) {
                throw new BusinessRuleException(
                    'DUPLICATE_REQUEST',
                    'This tap was already processed.',
                    self::display('ALREADY RECORDED', '', 'amber', 'long'),
                    409
                );
            }
        }

        // --- 7. An attendance session is open on this device -----------------
        // LOCK 1. Taking the session lock first serialises every tap for this
        // classroom, which is what lets the counters and the close routine
        // agree with each other.
        $session = $db->selectOne(
            "SELECT * FROM attendance_sessions
              WHERE device_row_id = :device AND status = 'open'
              LIMIT 1 FOR UPDATE",
            ['device' => $deviceRowId]
        );

        if ($session === null) {
            self::logScan($db, $cardUid, null, $deviceRowId, null, 'session_not_open', 'unknown', null, null,
                'No attendance session is open on this terminal.');

            throw new BusinessRuleException(
                'SESSION_NOT_OPEN',
                'No attendance session is open. The teacher must scan their fingerprint first.',
                self::display('NO SESSION', 'Awaiting teacher', 'red', 'long'),
                409
            );
        }

        $sessionId = (int) $session['session_id'];

        // --- 8. Session not expired ------------------------------------------
        if (Clock::parse((string) $session['expires_at']) < $now) {
            self::logScan($db, $cardUid, null, $deviceRowId, $sessionId, 'session_closed', 'unknown', null, null,
                'Session window has elapsed.');

            throw new BusinessRuleException(
                'SESSION_CLOSED',
                'Attendance session closed.',
                self::display('SESSION CLOSED', '', 'red', 'long'),
                409
            );
        }

        // --- 9. RFID UID exists ----------------------------------------------
        $card = $db->selectOne(
            'SELECT rc.*, s.student_id, s.student_number, s.first_name, s.last_name, s.middle_name,
                    s.status AS student_status, s.section_id AS student_section_id, s.photo_path,
                    sec.section_code AS student_section_code, sec.grade_level_id
               FROM rfid_cards rc
               LEFT JOIN students s  ON s.student_id = rc.student_id AND s.deleted_at IS NULL
               LEFT JOIN sections sec ON sec.section_id = s.section_id
              WHERE rc.card_uid = :uid
              LIMIT 1',
            ['uid' => $cardUid]
        );

        if ($card === null) {
            self::recordUnknownCard($db, $cardUid, $deviceRowId, $sessionId);
            self::incrementRejected($db, $sessionId);
            self::publishRejection($session, $cardUid, 'RFID_UNKNOWN', 'Unknown RFID card.', null);

            throw new BusinessRuleException(
                'RFID_UNKNOWN',
                'Unknown RFID.',
                self::display('UNKNOWN CARD', $cardUid, 'red-blink', 'long'),
                404
            );
        }

        // --- 10. Card status = active ----------------------------------------
        if ((string) $card['status'] !== 'active') {
            self::logScan($db, $cardUid, $card['student_id'] === null ? null : (int) $card['student_id'],
                $deviceRowId, $sessionId, 'card_disabled', 'unknown', null, null,
                'Card status: ' . $card['status']);
            self::incrementRejected($db, $sessionId);

            throw new BusinessRuleException(
                'RFID_DISABLED',
                'This card has been disabled.',
                self::display('CARD DISABLED', self::shortName($card), 'red', 'long'),
                403
            );
        }

        if ($card['student_id'] === null) {
            self::logScan($db, $cardUid, null, $deviceRowId, $sessionId, 'unknown_card', 'unknown', null, null,
                'Card is registered but not assigned to a student.');
            self::incrementRejected($db, $sessionId);

            throw new BusinessRuleException(
                'RFID_UNASSIGNED',
                'This card is not assigned to any student.',
                self::display('CARD NOT ASSIGNED', '', 'red', 'long'),
                409
            );
        }

        $studentId = (int) $card['student_id'];

        // --- 11. Student status = active -------------------------------------
        if ((string) $card['student_status'] !== 'active') {
            self::logScan($db, $cardUid, $studentId, $deviceRowId, $sessionId, 'student_inactive', 'unknown', null, null,
                'Student status: ' . $card['student_status']);
            self::incrementRejected($db, $sessionId);

            throw new BusinessRuleException(
                'STUDENT_INACTIVE',
                'This student record is not active.',
                self::display('STUDENT INACTIVE', self::shortName($card), 'red', 'long'),
                403
            );
        }

        // --- 12. student.section_id == session.section_id --------------------
        // The rule this whole system exists to enforce: a student may only be
        // recorded in a session belonging to their own section. The tap is
        // rejected outright — never recorded elsewhere, never as a guest, never
        // silently discarded.
        if ((int) $card['student_section_id'] !== (int) $session['section_id']) {
            self::handleSectionMismatch($db, $session, $card, $deviceRowId, $cardUid);
        }

        // --- 13. Student is enrolled in the session's subject ----------------
        self::assertEnrolledInSubject($db, $session, $card, $deviceRowId, $cardUid, $studentId);

        // --- 14. Determine tap intent ----------------------------------------
        // LOCK 2. The FOR UPDATE here is what makes two near-simultaneous taps
        // of the same card resolve deterministically instead of both reading
        // "no row" and both inserting.
        $record = $db->selectOne(
            'SELECT * FROM attendance_records
              WHERE session_id = :session AND student_id = :student
              LIMIT 1 FOR UPDATE',
            ['session' => $sessionId, 'student' => $studentId]
        );

        $intent = self::resolveIntent($record, $device, $forcedIntent);

        $schedule = $db->selectOne(
            'SELECT * FROM schedules WHERE schedule_id = :id',
            ['id' => (int) $session['schedule_id']]
        ) ?? [];

        return match ($intent) {
            self::INTENT_TIME_IN  => self::recordTimeIn($db, $session, $schedule, $device, $card, $cardUid, $requestId, $now),
            self::INTENT_TIME_OUT => self::recordTimeOut($db, $session, $schedule, $device, $card, $record ?? [], $cardUid, $now),
            default               => self::rejectComplete($db, $session, $card, $deviceRowId, $cardUid, $record ?? []),
        };
    }

    /**
     * Tap-intent resolution (Part 16.2).
     *
     * The ESP32 does not decide this. It reports "card X tapped on device Y at
     * time Z"; the server decides, from the record state, what that means. A
     * dedicated entry/exit terminal (device_role) overrides the state machine
     * because in that deployment the physical position of the reader carries
     * the intent.
     *
     * @param array<string,mixed>|null $record
     * @param array<string,mixed>      $device
     */
    private static function resolveIntent(?array $record, array $device, ?string $forcedIntent): string
    {
        $role = (string) ($device['device_role'] ?? 'both');

        if ($role === 'entry') {
            return $record === null ? self::INTENT_TIME_IN : self::INTENT_COMPLETE;
        }

        if ($role === 'exit') {
            if ($record === null) {
                // A tap-out with no matching tap-in is not attendance.
                return self::INTENT_COMPLETE;
            }

            return $record['time_out'] === null ? self::INTENT_TIME_OUT : self::INTENT_COMPLETE;
        }

        if ($forcedIntent === self::INTENT_TIME_IN || $forcedIntent === self::INTENT_TIME_OUT) {
            if ($forcedIntent === self::INTENT_TIME_IN && $record !== null) {
                return self::INTENT_COMPLETE;
            }
            if ($forcedIntent === self::INTENT_TIME_OUT && ($record === null || $record['time_out'] !== null)) {
                return self::INTENT_COMPLETE;
            }

            return $forcedIntent;
        }

        if ($record === null) {
            return self::INTENT_TIME_IN;
        }

        if ($record['time_in'] !== null && $record['time_out'] === null) {
            return self::INTENT_TIME_OUT;
        }

        return self::INTENT_COMPLETE;
    }

    // ------------------------------------------------------------ time in --

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $schedule
     * @param array<string,mixed> $device
     * @param array<string,mixed> $card
     * @return array<string,mixed>
     */
    private static function recordTimeIn(
        Database $db,
        array $session,
        array $schedule,
        array $device,
        array $card,
        string $cardUid,
        ?string $requestId,
        DateTimeImmutable $now
    ): array {
        $sessionId = (int) $session['session_id'];
        $studentId = (int) $card['student_id'];
        $start     = Clock::parse((string) $session['scheduled_start']);

        $windowOpen  = (int) ($schedule['time_in_window_open'] ?? 10);
        $windowClose = (int) ($schedule['time_in_window_close'] ?? 30);
        $lateAfter   = (int) ($schedule['late_threshold_minutes'] ?? 15);

        $opensAt  = $start->modify('-' . $windowOpen . ' minutes');
        $closesAt = $start->modify('+' . $windowClose . ' minutes');
        $lateAt   = $start->modify('+' . $lateAfter . ' minutes');

        if ($now < $opensAt) {
            self::logScan($db, $cardUid, $studentId, (int) $device['id'], $sessionId,
                'time_in_not_yet_open', 'time_in', null, null,
                'Tap-in opens at ' . $opensAt->format('H:i'));
            self::incrementRejected($db, $sessionId);

            throw new BusinessRuleException(
                'TIME_IN_NOT_YET_OPEN',
                sprintf('Tap-in opens at %s.', $opensAt->format('g:i A')),
                self::display('TOO EARLY', 'Opens ' . $opensAt->format('H:i'), 'amber', 'long'),
                409
            );
        }

        if ($now > $closesAt) {
            self::logScan($db, $cardUid, $studentId, (int) $device['id'], $sessionId,
                'time_in_closed', 'time_in', null, null,
                'Tap-in closed at ' . $closesAt->format('H:i'));
            self::incrementRejected($db, $sessionId);

            throw new BusinessRuleException(
                'TIME_IN_CLOSED',
                sprintf('Tap-in closed at %s.', $closesAt->format('g:i A')),
                self::display('TIME-IN CLOSED', '', 'amber', 'long'),
                409
            );
        }

        $arrivalStatus = $now <= $lateAt
            ? AttendanceStatusResolver::ARRIVAL_PRESENT
            : AttendanceStatusResolver::ARRIVAL_LATE;

        $finalStatus = AttendanceStatusResolver::resolve(
            $arrivalStatus,
            AttendanceStatusResolver::DEPARTURE_PENDING
        );

        $attendanceId = (int) $db->insert('attendance_records', [
            'session_id'     => $sessionId,
            'student_id'     => $studentId,
            // Denormalised at write time, permanently (Part 15.4).
            'section_id'     => (int) $card['student_section_id'],
            'grade_level_id' => (int) $card['grade_level_id'],
            'subject_id'     => (int) $session['subject_id'],
            'teacher_id'     => (int) $session['teacher_id'],
            'classroom_id'   => (int) $session['classroom_id'],
            'rfid_uid'       => $cardUid,
            'time_in'           => $now->format('Y-m-d H:i:s'),
            'time_in_device_id' => (string) $device['device_id'],
            'time_in_ip'        => RequestContext::ip(),
            'time_in_mac'       => (string) $device['mac_address'],
            'arrival_status'    => $arrivalStatus,
            'departure_status'  => AttendanceStatusResolver::DEPARTURE_PENDING,
            'final_status'      => $finalStatus,
            'request_id'        => $requestId,
            'created_at'        => Clock::nowString(),
            'updated_at'        => Clock::nowString(),
        ]);

        $counters = self::updateSessionCounters($db, $sessionId);

        self::logScan($db, $cardUid, $studentId, (int) $device['id'], $sessionId, 'accepted', 'time_in',
            (int) $card['student_section_id'], (int) $session['section_id'],
            'Time in recorded as ' . $arrivalStatus);

        $payload = self::eventPayload($db, $session, $card, $attendanceId, $counters, [
            'time_in'        => $now->format('H:i:s'),
            'arrival_status' => $arrivalStatus,
            'final_status'   => $finalStatus,
        ]);

        RealtimeService::broadcast(
            self::channelsFor($session),
            'attendance.time_in',
            $payload
        );

        AuditService::log(
            AuditService::ATTENDANCE_RECORDED,
            'attendance',
            'attendance_record',
            $attendanceId,
            null,
            ['intent' => 'time_in', 'status' => $finalStatus, 'student_id' => $studentId],
            sprintf('Time in recorded for %s (%s).', self::shortName($card), $finalStatus)
        );

        return [
            'success'        => true,
            'code'           => 'TIME_IN_RECORDED',
            'intent'         => self::INTENT_TIME_IN,
            'attendance_id'  => $attendanceId,
            'student'        => self::fullName($card),
            'student_number' => (string) $card['student_number'],
            'status'         => $finalStatus,
            'arrival_status' => $arrivalStatus,
            'time'           => $now->format('g:i A'),
            'display_line_1' => $arrivalStatus === AttendanceStatusResolver::ARRIVAL_LATE ? 'TIME IN — LATE' : 'TIME IN — PRESENT',
            'display_line_2' => self::shortName($card),
            'display_line_3' => $now->format('H:i') . '  ' . strtoupper($arrivalStatus),
            'led'            => $arrivalStatus === AttendanceStatusResolver::ARRIVAL_LATE ? 'amber' : 'green',
            'buzzer'         => $arrivalStatus === AttendanceStatusResolver::ARRIVAL_LATE ? 'double_short' : 'short',
            'counters'       => $counters,
        ];
    }

    // ----------------------------------------------------------- time out --

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $schedule
     * @param array<string,mixed> $device
     * @param array<string,mixed> $card
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function recordTimeOut(
        Database $db,
        array $session,
        array $schedule,
        array $device,
        array $card,
        array $record,
        string $cardUid,
        DateTimeImmutable $now
    ): array {
        $sessionId    = (int) $session['session_id'];
        $studentId    = (int) $card['student_id'];
        $attendanceId = (int) $record['attendance_id'];

        $end       = Clock::parse((string) $session['scheduled_end']);
        $timeIn    = Clock::parse((string) $record['time_in']);
        $outOpen   = (int) ($schedule['time_out_window_open'] ?? 10);
        $outClose  = (int) ($schedule['time_out_window_close'] ?? 15);
        $minDwell  = (int) ($schedule['minimum_dwell_minutes'] ?? 20);

        $opensAt  = $end->modify('-' . $outOpen . ' minutes');
        $closesAt = $end->modify('+' . $outClose . ' minutes');

        $dwellMinutes = (int) floor(($now->getTimestamp() - $timeIn->getTimestamp()) / 60);

        $departureStatus = AttendanceStatusResolver::DEPARTURE_TIMED_OUT;

        // Tapping out before the normal window is allowed only once the minimum
        // dwell has elapsed, and it is recorded as leaving early — which is
        // precisely the behaviour a tap-in-and-walk-out is meant to expose.
        if ($now < $opensAt) {
            if ($dwellMinutes < $minDwell) {
                self::logScan($db, $cardUid, $studentId, (int) $device['id'], $sessionId,
                    'minimum_dwell_not_met', 'time_out', null, null,
                    sprintf('Dwell %d min, minimum %d min.', $dwellMinutes, $minDwell));
                self::incrementRejected($db, $sessionId);

                throw new BusinessRuleException(
                    'MINIMUM_DWELL_NOT_MET',
                    sprintf('You must stay at least %d minutes before tapping out.', $minDwell),
                    self::display('TOO EARLY TO EXIT', sprintf('%d/%d min', $dwellMinutes, $minDwell), 'amber', 'long'),
                    409
                );
            }

            $departureStatus = AttendanceStatusResolver::DEPARTURE_LEFT_EARLY;
        }

        if ($now > $closesAt) {
            self::logScan($db, $cardUid, $studentId, (int) $device['id'], $sessionId,
                'time_out_closed', 'time_out', null, null,
                'Tap-out closed at ' . $closesAt->format('H:i'));
            self::incrementRejected($db, $sessionId);

            throw new BusinessRuleException(
                'TIME_OUT_CLOSED',
                sprintf('Tap-out closed at %s.', $closesAt->format('g:i A')),
                self::display('TIME-OUT CLOSED', '', 'amber', 'long'),
                409
            );
        }

        $finalStatus = AttendanceStatusResolver::resolve(
            (string) $record['arrival_status'],
            $departureStatus
        );

        $db->update('attendance_records', [
            'time_out'            => $now->format('Y-m-d H:i:s'),
            'time_out_device_id'  => (string) $device['device_id'],
            'time_out_ip'         => RequestContext::ip(),
            'time_out_mac'        => (string) $device['mac_address'],
            'duration_minutes'    => $dwellMinutes,
            'departure_status'    => $departureStatus,
            'final_status'        => $finalStatus,
            'updated_at'          => Clock::nowString(),
        ], ['attendance_id' => $attendanceId]);

        $counters = self::updateSessionCounters($db, $sessionId);

        self::logScan($db, $cardUid, $studentId, (int) $device['id'], $sessionId, 'accepted', 'time_out',
            (int) $card['student_section_id'], (int) $session['section_id'],
            sprintf('Time out recorded after %d minutes (%s).', $dwellMinutes, $departureStatus));

        $payload = self::eventPayload($db, $session, $card, $attendanceId, $counters, [
            'time_in'          => substr((string) $record['time_in'], 11, 8),
            'time_out'         => $now->format('H:i:s'),
            'duration_minutes' => $dwellMinutes,
            'departure_status' => $departureStatus,
            'final_status'     => $finalStatus,
        ]);

        RealtimeService::broadcast(self::channelsFor($session), 'attendance.time_out', $payload);

        AuditService::log(
            AuditService::ATTENDANCE_RECORDED,
            'attendance',
            'attendance_record',
            $attendanceId,
            null,
            ['intent' => 'time_out', 'status' => $finalStatus, 'duration_minutes' => $dwellMinutes],
            sprintf('Time out recorded for %s after %d minutes.', self::shortName($card), $dwellMinutes)
        );

        $leftEarly = $departureStatus === AttendanceStatusResolver::DEPARTURE_LEFT_EARLY;

        return [
            'success'          => true,
            'code'             => 'TIME_OUT_RECORDED',
            'intent'           => self::INTENT_TIME_OUT,
            'attendance_id'    => $attendanceId,
            'student'          => self::fullName($card),
            'student_number'   => (string) $card['student_number'],
            'status'           => $finalStatus,
            'departure_status' => $departureStatus,
            'duration_minutes' => $dwellMinutes,
            'time'             => $now->format('g:i A'),
            'display_line_1'   => $leftEarly ? 'TIME OUT — EARLY' : 'TIME OUT',
            'display_line_2'   => self::shortName($card),
            'display_line_3'   => $now->format('H:i') . '   ' . $dwellMinutes . ' min',
            'led'              => $leftEarly ? 'amber-blink' : 'blue',
            'buzzer'           => $leftEarly ? 'triple_short' : 'double_short',
            'counters'         => $counters,
        ];
    }

    /**
     * Third tap: the visit is already complete.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $card
     * @param array<string,mixed> $record
     */
    private static function rejectComplete(
        Database $db,
        array $session,
        array $card,
        int $deviceRowId,
        string $cardUid,
        array $record
    ): array {
        $sessionId = (int) $session['session_id'];

        self::logScan($db, $cardUid, (int) $card['student_id'], $deviceRowId, $sessionId,
            'already_complete', 'unknown', null, null, 'Time in and time out are both already recorded.');
        self::incrementRejected($db, $sessionId);

        throw new BusinessRuleException(
            'ALREADY_COMPLETE',
            'Attendance already recorded for this session.',
            self::display(
                'ALREADY COMPLETE',
                self::shortName($card),
                'amber',
                'long'
            ) + [
                'time_in'  => isset($record['time_in']) ? substr((string) $record['time_in'], 11, 5) : null,
                'time_out' => isset($record['time_out']) ? substr((string) $record['time_out'], 11, 5) : null,
            ],
            409
        );
    }

    // -------------------------------------------------- rejection paths ----

    /**
     * SECTION_MISMATCH (Part 15.3).
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $card
     */
    private static function handleSectionMismatch(
        Database $db,
        array $session,
        array $card,
        int $deviceRowId,
        string $cardUid
    ): never {
        $sessionId = (int) $session['session_id'];
        $studentId = (int) $card['student_id'];

        self::logScan(
            $db,
            $cardUid,
            $studentId,
            $deviceRowId,
            $sessionId,
            'section_mismatch',
            'unknown',
            (int) $card['student_section_id'],
            (int) $session['section_id'],
            'Student belongs to a different section.'
        );

        self::incrementRejected($db, $sessionId);

        // Repeated mismatches for one student usually mean a misassigned
        // student record, not misconduct — so the notification says so.
        $threshold = (int) Config::get('attendance.section_mismatch_alert_threshold', 3);

        $todayCount = (int) $db->scalar(
            "SELECT COUNT(*) FROM rfid_logs
              WHERE student_id = :student AND result = 'section_mismatch' AND DATE(created_at) = :today",
            ['student' => $studentId, 'today' => Clock::today()]
        );

        if ($todayCount >= $threshold) {
            NotificationService::toAdministrators(
                'attendance',
                'Repeated section mismatch',
                sprintf(
                    '%s (%s) has been rejected %d times today for tapping in the wrong section. This usually means the student record is assigned to the wrong section.',
                    self::fullName($card),
                    (string) $card['student_number'],
                    $todayCount
                ),
                'high',
                '/admin/students?search=' . urlencode((string) $card['student_number'])
            );

            SecurityLogService::log(
                SecurityLogService::SECTION_MISMATCH_REPEATED,
                'medium',
                sprintf('Student %s triggered %d section mismatches today.', $card['student_number'], $todayCount),
                ['student_id' => $studentId, 'count' => $todayCount],
                $deviceRowId
            );
        }

        self::publishRejection($session, $cardUid, 'SECTION_MISMATCH', 'Student is not enrolled in this section.', [
            'student_id'      => $studentId,
            'student_name'    => self::fullName($card),
            'student_number'  => (string) $card['student_number'],
            'student_section' => (string) $card['student_section_code'],
            'today_count'     => $todayCount,
        ]);

        throw new BusinessRuleException(
            'SECTION_MISMATCH',
            'Student is not enrolled in this section.',
            [
                'display_line_1' => 'WRONG SECTION',
                'display_line_2' => self::shortName($card) . ' — ' . $card['student_section_code'],
                'led'            => 'red',
                'buzzer'         => 'long',
                'hold_ms'        => 3000,
            ],
            403
        );
    }

    /**
     * Step 13. A student may legitimately take one subject with another
     * section, which is why the enrolment table exists alongside the section
     * check rather than being implied by it.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $card
     */
    private static function assertEnrolledInSubject(
        Database $db,
        array $session,
        array $card,
        int $deviceRowId,
        string $cardUid,
        int $studentId
    ): void {
        $subjectId = (int) $session['subject_id'];

        $enrolled = $db->scalar(
            "SELECT 1 FROM student_subject_enrolments
              WHERE student_id = :student AND subject_id = :subject AND status = 'enrolled'
              LIMIT 1",
            ['student' => $studentId, 'subject' => $subjectId]
        );

        if ($enrolled !== null) {
            return;
        }

        // No explicit enrolment row: fall back to the section-level offering,
        // which is how most schools actually operate. Only if neither holds is
        // the tap rejected.
        $sectionOffers = $db->scalar(
            'SELECT 1 FROM schedules
              WHERE section_id = :section AND subject_id = :subject
                AND status = \'active\' AND deleted_at IS NULL
              LIMIT 1',
            ['section' => (int) $card['student_section_id'], 'subject' => $subjectId]
        );

        if ($sectionOffers !== null) {
            return;
        }

        self::logScan($db, $cardUid, $studentId, $deviceRowId, (int) $session['session_id'],
            'not_enrolled_in_subject', 'unknown', (int) $card['student_section_id'], (int) $session['section_id'],
            'Student is not enrolled in this subject.');
        self::incrementRejected($db, (int) $session['session_id']);

        throw new BusinessRuleException(
            'NOT_ENROLLED_IN_SUBJECT',
            'Student is not enrolled in this subject.',
            self::display('NOT ENROLLED', self::shortName($card), 'red', 'long'),
            403
        );
    }

    // ------------------------------------------------------------ helpers --

    /** @param array<string,mixed> $session @return list<string> */
    public static function channelsFor(array $session): array
    {
        return [
            RealtimeService::CHANNEL_ADMIN,
            RealtimeService::sessionChannel((int) $session['session_id']),
            RealtimeService::teacherChannel((int) $session['teacher_id']),
            RealtimeService::sectionChannel((int) $session['section_id']),
        ];
    }

    /**
     * @param array<string,mixed>      $session
     * @param array<string,mixed>      $card
     * @param array<string,int>        $counters
     * @param array<string,mixed>      $extra
     * @return array<string,mixed>
     */
    private static function eventPayload(
        Database $db,
        array $session,
        array $card,
        int $attendanceId,
        array $counters,
        array $extra
    ): array {
        static $contextCache = [];

        $sessionId = (int) $session['session_id'];

        if (!isset($contextCache[$sessionId])) {
            $contextCache[$sessionId] = $db->selectOne(
                'SELECT sec.section_code, sub.subject_code, sub.subject_name, c.room_number
                   FROM attendance_sessions s
                   JOIN sections sec  ON sec.section_id = s.section_id
                   JOIN subjects sub  ON sub.subject_id = s.subject_id
                   JOIN classrooms c  ON c.classroom_id = s.classroom_id
                  WHERE s.session_id = :id',
                ['id' => $sessionId]
            ) ?? [];
        }

        $context = $contextCache[$sessionId];

        return [
            'attendance_id' => $attendanceId,
            'session_id'    => (string) $session['session_code'],
            'student'       => [
                'id'        => (int) $card['student_id'],
                'number'    => (string) $card['student_number'],
                'name'      => self::fullName($card),
                'photo_url' => $card['photo_path'] === null ? null : '/uploads/students/' . basename((string) $card['photo_path']),
            ],
            'section'   => ['id' => (int) $session['section_id'], 'code' => (string) ($context['section_code'] ?? '')],
            'subject'   => [
                'id'   => (int) $session['subject_id'],
                'code' => (string) ($context['subject_code'] ?? ''),
                'name' => (string) ($context['subject_name'] ?? ''),
            ],
            'classroom' => ['id' => (int) $session['classroom_id'], 'room' => (string) ($context['room_number'] ?? '')],
            'counters'  => $counters,
        ] + $extra;
    }

    /** @param array<string,mixed> $session @param array<string,mixed>|null $extra */
    private static function publishRejection(array $session, string $cardUid, string $code, string $message, ?array $extra): void
    {
        RealtimeService::broadcast(self::channelsFor($session), 'attendance.rejected', [
            'session_id' => (string) $session['session_code'],
            'card_uid'   => $cardUid,
            'code'       => $code,
            'message'    => $message,
            'at'         => Clock::atom(),
        ] + ($extra ?? []));
    }

    /**
     * Recompute the live counters for an open session.
     *
     * Runs inside the caller's transaction while the session row is held, so
     * the numbers pushed to a dashboard always match what is committed.
     *
     * @return array<string,int>
     */
    public static function updateSessionCounters(Database $db, int $sessionId): array
    {
        $row = $db->selectOne(
            "SELECT
                SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) AS timed_in,
                SUM(CASE WHEN time_out IS NOT NULL THEN 1 ELSE 0 END) AS timed_out,
                SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN 1 ELSE 0 END) AS in_room,
                SUM(CASE WHEN arrival_status = 'late' THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN departure_status = 'left_early' THEN 1 ELSE 0 END) AS left_early,
                AVG(duration_minutes) AS avg_dwell
               FROM attendance_records WHERE session_id = :id",
            ['id' => $sessionId]
        ) ?? [];

        $roster = (int) $db->scalar(
            "SELECT COUNT(*) FROM students st
               JOIN attendance_sessions s ON s.section_id = st.section_id
              WHERE s.session_id = :id AND st.deleted_at IS NULL AND st.status = 'active'",
            ['id' => $sessionId]
        );

        $counters = [
            'timed_in'       => (int) ($row['timed_in'] ?? 0),
            'timed_out'      => (int) ($row['timed_out'] ?? 0),
            'in_room'        => (int) ($row['in_room'] ?? 0),
            'late'           => (int) ($row['late_count'] ?? 0),
            'left_early'     => (int) ($row['left_early'] ?? 0),
            'absent_pending' => max(0, $roster - (int) ($row['timed_in'] ?? 0)),
            'total'          => $roster,
            'average_dwell'  => (int) round((float) ($row['avg_dwell'] ?? 0)),
        ];

        $db->update('attendance_sessions', [
            'roster_count'          => $counters['total'],
            'present_count'         => $counters['timed_in'] - $counters['late'],
            'late_count'            => $counters['late'],
            'timed_out_count'       => $counters['timed_out'],
            'left_early_count'      => $counters['left_early'],
            'average_dwell_minutes' => $counters['average_dwell'] > 0 ? $counters['average_dwell'] : null,
        ], ['session_id' => $sessionId]);

        return $counters;
    }

    /**
     * Buffered rather than applied, for the same reason as the rejection log:
     * the caller increments and then throws, and the rollback would undo it.
     */
    private static function incrementRejected(Database $db, int $sessionId): void
    {
        self::$deferredRejectedCounts[] = $sessionId;
    }

    private static function logScan(
        Database $db,
        string $cardUid,
        ?int $studentId,
        ?int $deviceRowId,
        ?int $sessionId,
        string $result,
        string $intent,
        ?int $studentSectionId,
        ?int $sessionSectionId,
        ?string $message
    ): void {
        $row = [
            'card_uid'           => $cardUid,
            'student_id'         => $studentId,
            'device_row_id'      => $deviceRowId,
            'session_id'         => $sessionId,
            'intent'             => $intent,
            'result'             => $result,
            'student_section_id' => $studentSectionId,
            'session_section_id' => $sessionSectionId,
            'message'            => $message === null ? null : mb_substr($message, 0, 255),
            'ip_address'         => RequestContext::ip(),
            'created_at'         => Clock::nowString(),
        ];

        // A rejection is recorded by writing the log and then throwing, which
        // rolls the transaction back — and would take the log with it. The
        // whole point of the rejection log is to survive the rejection, so the
        // row is buffered here and written by flushDeferredWrites() once the
        // rollback is complete. An accepted tap has no such problem: its log
        // belongs in the same transaction as the attendance row, so that either
        // both exist or neither does.
        if ($result !== 'accepted') {
            self::$deferredLogs[] = $row;

            return;
        }

        try {
            $db->insert('rfid_logs', $row);
        } catch (Throwable $e) {
            Logger::error('RFID log write failed', ['error' => $e->getMessage(), 'uid' => $cardUid]);
        }
    }

    /**
     * Write everything that must outlive a rolled-back rejection.
     *
     * Called after the transaction has unwound, so these run on a connection
     * with no open transaction and commit on their own. Failures are logged and
     * swallowed: the caller is already returning a rejection to the terminal,
     * and losing the audit row must not turn a clean business rejection into a
     * server error.
     */
    private static function flushDeferredWrites(Database $db): void
    {
        $logs      = self::$deferredLogs;
        $rejected  = self::$deferredRejectedCounts;

        // Cleared first: a failure below must not leave rows buffered for the
        // next tap to write a second time.
        self::$deferredLogs            = [];
        self::$deferredRejectedCounts  = [];

        foreach ($logs as $row) {
            try {
                $db->insert('rfid_logs', $row);
            } catch (Throwable $e) {
                Logger::error('RFID log write failed', ['error' => $e->getMessage(), 'uid' => $row['card_uid'] ?? '']);
            }
        }

        foreach (array_count_values($rejected) as $sessionId => $count) {
            try {
                $db->execute(
                    'UPDATE attendance_sessions SET rejected_tap_count = rejected_tap_count + :n WHERE session_id = :id',
                    ['n' => $count, 'id' => (int) $sessionId]
                );
            } catch (Throwable $e) {
                Logger::error('Rejected-tap counter update failed', ['error' => $e->getMessage(), 'session' => $sessionId]);
            }
        }
    }

    /** Drop anything buffered by a tap that ended up succeeding. */
    private static function discardDeferredWrites(): void
    {
        self::$deferredLogs           = [];
        self::$deferredRejectedCounts = [];
    }

    private static function recordUnknownCard(Database $db, string $cardUid, int $deviceRowId, ?int $sessionId): void
    {
        self::logScan($db, $cardUid, null, $deviceRowId, $sessionId, 'unknown_card', 'unknown', null, null,
            'Card UID is not registered.');

        $db->execute(
            'INSERT INTO unknown_rfid_logs (card_uid, device_row_id, session_id, seen_count, first_seen_at, last_seen_at)
                  VALUES (:uid, :device, :session, 1, :now, :now)
             ON DUPLICATE KEY UPDATE
                  seen_count = seen_count + 1,
                  last_seen_at = VALUES(last_seen_at),
                  device_row_id = VALUES(device_row_id),
                  session_id = VALUES(session_id)',
            ['uid' => $cardUid, 'device' => $deviceRowId, 'session' => $sessionId, 'now' => Clock::nowString()]
        );

        SecurityLogService::log(
            SecurityLogService::UNKNOWN_RFID,
            'low',
            sprintf('Unknown RFID card %s presented.', $cardUid),
            ['card_uid' => $cardUid],
            $deviceRowId
        );
    }

    /** @return array<string,mixed> */
    private static function display(string $line1, string $line2, string $led, string $buzzer): array
    {
        return [
            'display_line_1' => $line1,
            'display_line_2' => $line2,
            'led'            => $led,
            'buzzer'         => $buzzer,
            'hold_ms'        => 2000,
        ];
    }

    /** @param array<string,mixed> $card */
    private static function fullName(array $card): string
    {
        $middle = trim((string) ($card['middle_name'] ?? ''));

        return sprintf(
            '%s, %s%s',
            $card['last_name'],
            $card['first_name'],
            $middle === '' ? '' : ' ' . mb_substr($middle, 0, 1) . '.'
        );
    }

    /** OLED lines are 16–21 characters; anything longer is unreadable. */
    private static function shortName(array $card): string
    {
        if (!isset($card['last_name'])) {
            return '';
        }

        $name = sprintf('%s, %s', $card['last_name'], mb_substr((string) $card['first_name'], 0, 8));

        return mb_substr($name, 0, 20);
    }

    public static function normaliseUid(string $uid): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $uid));
    }
}
