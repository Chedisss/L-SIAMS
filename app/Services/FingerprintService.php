<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;

/**
 * Teacher fingerprint enrolment and verification.
 *
 * This database DOES hold biometric templates, and it deliberately did not
 * until migration 018. The reason for the change is in that migration: a
 * template that lives only in the sensor it was enrolled on makes its teacher
 * unknown in every other classroom, and a school timetable puts teachers in
 * more than one room.
 *
 * Templates are encrypted with the application key, so a stolen .sql dump on
 * its own is not enough — but a host compromise that takes both the dump and
 * APP_KEY now leaks biometric data, where previously there was none to leak.
 * A template is the sensor's own feature vector rather than an image and
 * cannot be turned back into a fingerprint picture, which limits the harm
 * without removing it.
 *
 * Fingerprints authorise teachers opening sessions and nothing else — student
 * attendance never involves the sensor (Part 3).
 */
final class FingerprintService
{
    /**
     * Verify a fingerprint and, if everything checks out, open the session.
     *
     * @param  array<string,mixed> $device
     * @return array<string,mixed>
     */
    public static function verifyAndOpenSession(
        array $device,
        int $sensorTemplateId,
        ?int $confidence = null,
        ?int $scheduleId = null
    ): array {
        $db          = Database::instance();
        $deviceRowId = (int) $device['id'];

        self::assertDeviceNotLocked($device);

        // fingerprint_slots, not fingerprint_templates.
        //
        // A slot number is allocated by one sensor, in its own flash, counting
        // from 1. It identifies a person only in combination with the device
        // that allocated it, which is exactly what this table records — one
        // row per (terminal, slot), with a unique key that makes two teachers
        // sharing a slot on one sensor unrepresentable.
        //
        // Reading the origin enrolment instead, as this used to, could only
        // ever recognise a teacher on the terminal they first enrolled at.
        // Every synced copy lives here.
        $fingerprint = $db->selectOne(
            "SELECT fp.*, t.teacher_id, t.first_name, t.last_name, t.status AS teacher_status,
                    t.employee_number, t.department_id
               FROM fingerprint_slots s
               JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
               JOIN teachers t               ON t.teacher_id = fp.teacher_id
              WHERE s.sensor_template_id = :slot
                AND s.device_row_id      = :device
                AND s.status             = 'present'
                AND t.deleted_at IS NULL
              LIMIT 1",
            ['slot' => $sensorTemplateId, 'device' => $deviceRowId]
        );

        // There used to be a fallback here that looked the slot up across every
        // terminal when the device-scoped lookup found nothing, justified by a
        // comment claiming "templates are synchronised across terminals". No
        // such synchronisation exists anywhere in this system, and the fallback
        // it justified could name the wrong teacher.
        //
        // Slot numbers are not global. Every sensor allocates from slot 1
        // upward in its own flash, independently, so "slot 3" on the terminal
        // in Room 101 and "slot 3" on the one in Room 102 are two different
        // people as a matter of course — a collision by design, not by
        // accident.
        //
        // So the fallback fired exactly when it was least safe to trust: a
        // print that this terminal's sensor matched to a slot with no record
        // for THIS device, resolved against whichever teacher happened to hold
        // that slot number somewhere else. That opens a class register in
        // another teacher's name, off one stale template left behind by a
        // reflash or a deleted row. Attendance attributed to the wrong teacher
        // is worse than a refused scan by a wide margin.
        //
        // Now the mismatch is detected and named rather than silently
        // resolved, because "your fingerprint is enrolled on a different
        // terminal" is an answer somebody can act on, and "not recognised"
        // sends them to re-enrol a finger that is already enrolled.
        if ($fingerprint === null) {
            $elsewhere = $db->selectOne(
                "SELECT s2.device_row_id AS enrolled_device_row_id, d.device_id, c.room_number,
                        CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
                   FROM fingerprint_slots s2
                   JOIN fingerprint_templates fp ON fp.fingerprint_id = s2.fingerprint_id
                   JOIN teachers t               ON t.teacher_id = fp.teacher_id
              LEFT JOIN devices d                ON d.id = s2.device_row_id
              LEFT JOIN classrooms c             ON c.classroom_id = d.classroom_id
                  WHERE s2.sensor_template_id = :slot
                    AND s2.device_row_id <> :device
                    AND t.deleted_at IS NULL
                  LIMIT 1",
                ['slot' => $sensorTemplateId, 'device' => $deviceRowId]
            );

            if ($elsewhere !== null) {
                // Two shapes of the same problem, and they need different
                // sentences. A row tied to another terminal is somebody
                // enrolled in the wrong room. A row tied to no terminal at all
                // is an enrolment whose sensor was removed — the template may
                // still sit in some sensor's flash, or nowhere, and there is
                // no way to tell which. Neither is safe to accept, and neither
                // is "not recognised".
                $orphaned = $elsewhere['enrolled_device_row_id'] === null;

                self::logAttempt(
                    null,
                    $deviceRowId,
                    $sensorTemplateId,
                    'unknown',
                    $confidence,
                    $orphaned
                        ? sprintf(
                            'Slot %d has no enrolment on this terminal. A record for that slot exists for %s '
                            . 'but is tied to no terminal, so which sensor holds the template is unknown.',
                            $sensorTemplateId,
                            (string) $elsewhere['teacher_name']
                        )
                        : sprintf(
                            'Slot %d has no enrolment on this terminal. The same slot number belongs to '
                            . '%s on terminal %s, which is a different sensor and therefore a different print.',
                            $sensorTemplateId,
                            (string) $elsewhere['teacher_name'],
                            (string) ($elsewhere['device_id'] ?? 'unknown')
                        )
                );

                SecurityLogService::log(
                    SecurityLogService::FINGERPRINT_SLOT_CROSS_DEVICE,
                    'medium',
                    sprintf(
                        'A scan on terminal %d matched slot %d, which is enrolled on a different terminal (%s). '
                        . 'Refused rather than resolved — slot numbers are per-sensor and mean nothing across devices.',
                        $deviceRowId,
                        $sensorTemplateId,
                        (string) ($elsewhere['device_id'] ?? 'unknown')
                    ),
                    ['slot' => $sensorTemplateId, 'enrolled_on' => $elsewhere['device_id'] ?? null],
                    $deviceRowId
                );

                self::registerFailure($device);

                throw new BusinessRuleException(
                    'FINGERPRINT_WRONG_TERMINAL',
                    $orphaned
                        ? 'This fingerprint enrolment is not tied to any terminal, so it cannot be verified '
                          . 'here. Ask an administrator to enrol you again on this terminal.'
                        : sprintf(
                            'This fingerprint is not enrolled on this terminal. A fingerprint is stored in the '
                            . 'sensor it was enrolled on and cannot be read by another one — ask an administrator '
                            . 'to enrol you on the terminal in %s.',
                            (string) ($elsewhere['room_number'] ?? 'this room')
                        ),
                    self::display('WRONG TERMINAL', 'ENROL HERE FIRST', 'red', 'rapid'),
                    403
                );
            }
        }

        if ($fingerprint === null) {
            self::logAttempt(null, $deviceRowId, $sensorTemplateId, 'unknown', $confidence,
                'No enrolled fingerprint matches slot ' . $sensorTemplateId);
            self::registerFailure($device);

            throw new BusinessRuleException(
                'FINGERPRINT_UNKNOWN',
                'Fingerprint not recognised.',
                self::display('NOT RECOGNIZED', '', 'red', 'rapid'),
                403
            );
        }

        $teacherId = (int) $fingerprint['teacher_id'];

        if ((string) $fingerprint['status'] !== 'active') {
            self::logAttempt($teacherId, $deviceRowId, $sensorTemplateId, 'failed', $confidence,
                'Fingerprint record is ' . $fingerprint['status']);

            throw new BusinessRuleException(
                'FINGERPRINT_DISABLED',
                'This fingerprint enrolment has been disabled.',
                self::display('FINGERPRINT DISABLED', '', 'red', 'rapid'),
                403
            );
        }

        if ((string) $fingerprint['teacher_status'] !== 'active') {
            self::logAttempt($teacherId, $deviceRowId, $sensorTemplateId, 'teacher_inactive', $confidence,
                'Teacher account is ' . $fingerprint['teacher_status']);

            throw new BusinessRuleException(
                'TEACHER_INACTIVE',
                'This teacher account is not active.',
                self::display('ACCOUNT INACTIVE', '', 'red', 'rapid'),
                403
            );
        }

        // The schedule check is the real authorisation step: a verified
        // fingerprint only opens a session the teacher is actually assigned to,
        // in the classroom this terminal is bound to, right now.
        //
        // openableForDevice(), not activeForDevice(): a lesson that has ended
        // is still "active" while its tap-out window runs, and treating that as
        // permission to OPEN let the previous teacher take the room from the
        // one whose lesson was actually in progress.
        $candidates = ScheduleService::openableForDevice($deviceRowId);
        $schedule   = null;

        foreach ($candidates as $candidate) {
            if ((int) $candidate['teacher_id'] !== $teacherId) {
                continue;
            }
            if ($scheduleId !== null && (int) $candidate['schedule_id'] !== $scheduleId) {
                continue;
            }
            $schedule = $candidate;
            break;
        }

        if ($schedule === null) {
            // Name both sides. "You are not the assigned teacher for the
            // current class in this room" is true and useless: it does not say
            // who the finger was taken to be, and that is the thing most likely
            // to be wrong.
            //
            // A slot number identifies a person only together with the sensor
            // that allocated it, so a sensor holding a template this server has
            // no record of — the "holding 5, we have 4" case on the Fingerprints
            // page — can match a finger to a slot that resolves to somebody
            // else entirely. The refusal then lands on a teacher who IS
            // assigned, standing at the right terminal in the right room,
            // being told they are not who they are.
            //
            // The per-teacher verification log cannot show this either: it
            // filters on the resolved teacher_id, so the attempt is filed under
            // the wrong person and JB's log looks empty. This message is the
            // only place the mismatch can surface, so it says both names.
            $scanned  = trim((string) $fingerprint['first_name'] . ' ' . (string) $fingerprint['last_name']);
            $assigned = [];

            foreach ($candidates as $candidate) {
                $name = trim((string) ($candidate['teacher_name'] ?? ''));

                if ($name !== '' && !in_array($name, $assigned, true)) {
                    $assigned[] = $name;
                }
            }

            $reason = $candidates === []
                ? 'No class is scheduled in this room at this time.'
                : sprintf(
                    'This fingerprint is registered to %s, and the class in this room now is %s. '
                    . 'If you are not %s, the sensor matched your finger to the wrong stored '
                    . 'template — an administrator should clear this terminal\'s sensor from the '
                    . 'Fingerprints page and let it be rewritten.',
                    $scanned,
                    $assigned === []
                        ? 'assigned to somebody else'
                        : implode(' / ', $assigned) . '\'s',
                    $scanned
                );

            self::logAttempt($teacherId, $deviceRowId, $sensorTemplateId, 'no_schedule', $confidence, $reason);

            throw new BusinessRuleException(
                'NO_ACTIVE_SCHEDULE',
                $reason,
                self::display('NOT ASSIGNED', mb_substr((string) $fingerprint['last_name'], 0, 16), 'red', 'rapid'),
                403
            );
        }

        // Success: record the verification, then open the session referencing
        // this exact log row so the session's provenance is traceable.
        $logId = self::logAttempt($teacherId, $deviceRowId, $sensorTemplateId, 'verified', $confidence,
            'Fingerprint verified for schedule #' . $schedule['schedule_id']);

        $db->execute(
            'UPDATE fingerprint_templates
                SET verification_count = verification_count + 1,
                    last_verified_at = :now,
                    failure_count = 0
              WHERE fingerprint_id = :id',
            ['now' => Clock::nowString(), 'id' => (int) $fingerprint['fingerprint_id']]
        );

        self::clearFailures($deviceRowId);

        $teacher = [
            'teacher_id'      => $teacherId,
            'first_name'      => (string) $fingerprint['first_name'],
            'last_name'       => (string) $fingerprint['last_name'],
            'employee_number' => (string) $fingerprint['employee_number'],
        ];

        $session = AttendanceSessionService::open(
            $device,
            $teacher,
            $schedule,
            $logId,
            isset($device['api_key_id']) ? (int) $device['api_key_id'] : null
        );

        AuditService::log(
            AuditService::FINGERPRINT_VERIFIED,
            'attendance',
            'teacher',
            $teacherId,
            null,
            ['device_id' => (string) $device['device_id'], 'session_code' => $session['session_code']],
            sprintf('Fingerprint verified for %s %s; attendance session opened.',
                $fingerprint['first_name'], $fingerprint['last_name'])
        );

        return [
            'success'        => true,
            'code'           => 'FINGERPRINT_VERIFIED',
            'teacher'        => sprintf('%s %s', $fingerprint['first_name'], $fingerprint['last_name']),
            'display_line_1' => 'SESSION OPEN',
            'display_line_2' => mb_substr((string) $fingerprint['last_name'], 0, 20),
            // The teacher needs to know how many of their class the register
            // already has before they start wondering why nobody is queueing.
            'display_line_3' => (int) ($session['carried_in'] ?? 0) > 0
                ? sprintf('%s  %d CARRIED', $session['subject_code'], (int) $session['carried_in'])
                : $session['subject_code'] . ' ' . $session['section_code'],
            'led'            => 'green',
            'buzzer'         => 'short',
            'session'        => $session,
        ];
    }

    /**
     * Administrator-driven enrolment. The device performs the multi-sample
     * capture; the server records the resulting slot.
     *
     * @return array<string,mixed>
     */
    public static function enroll(
        int $teacherId,
        int $sensorTemplateId,
        ?int $deviceRowId = null,
        ?int $quality = null,
        int $sampleCount = 0,
        ?int $enrolledBy = null
    ): array {
        $db = Database::instance();

        $teacher = $db->selectOne(
            'SELECT * FROM teachers WHERE teacher_id = :id AND deleted_at IS NULL',
            ['id' => $teacherId]
        );

        if ($teacher === null) {
            throw new ValidationException(['teacher_id' => ['Teacher not found.']]);
        }

        if ($sensorTemplateId < 1 || $sensorTemplateId > 999) {
            throw new ValidationException([
                'sensor_template_id' => ['Sensor slot must be between 1 and 999.'],
            ]);
        }

        // A slot already claimed by a different teacher on the same device would
        // let one person's finger open another's sessions.
        $conflict = $db->selectOne(
            'SELECT fp.fingerprint_id, t.first_name, t.last_name
               FROM fingerprint_templates fp
               JOIN teachers t ON t.teacher_id = fp.teacher_id
              WHERE fp.sensor_template_id = :slot
                AND fp.teacher_id <> :teacher
                AND (fp.enrolled_device_row_id = :device OR :device IS NULL)
              LIMIT 1',
            ['slot' => $sensorTemplateId, 'teacher' => $teacherId, 'device' => $deviceRowId]
        );

        if ($conflict !== null) {
            throw new ValidationException([
                'sensor_template_id' => [sprintf(
                    'Sensor slot %d is already used by %s %s. Choose another slot.',
                    $sensorTemplateId,
                    $conflict['first_name'],
                    $conflict['last_name']
                )],
            ]);
        }

        return $db->transaction(static function (Database $db) use (
            $teacherId, $sensorTemplateId, $deviceRowId, $quality, $sampleCount, $enrolledBy, $teacher
        ): array {
            $existing = $db->selectOne(
                'SELECT * FROM fingerprint_templates WHERE teacher_id = :id',
                ['id' => $teacherId]
            );

            $payload = [
                'sensor_template_id'     => $sensorTemplateId,
                'enrolled_device_row_id' => $deviceRowId,
                'quality_score'          => $quality,
                'sample_count'           => $sampleCount,
                'enrollment_date'        => Clock::nowString(),
                'enrolled_by'            => $enrolledBy,
                'status'                 => 'active',
                'failure_count'          => 0,
                'updated_at'             => Clock::nowString(),
            ];

            if ($existing === null) {
                $payload['teacher_id']        = $teacherId;
                $payload['verification_count'] = 0;
                $payload['created_at']         = Clock::nowString();
                $fingerprintId = (int) $db->insert('fingerprint_templates', $payload);
                $isReenrol     = false;
            } else {
                $fingerprintId = (int) $existing['fingerprint_id'];
                $db->update('fingerprint_templates', $payload, ['fingerprint_id' => $fingerprintId]);
                $isReenrol = true;
            }

            $db->update('teachers', ['fingerprint_status' => 'enrolled'], ['teacher_id' => $teacherId]);

            // A teacher account held inactive pending enrolment becomes usable
            // the moment enrolment completes (Part 19.2).
            if ($teacher['user_id'] !== null) {
                $db->execute(
                    "UPDATE users SET status = 'active'
                      WHERE user_id = :id AND status = 'inactive'",
                    ['id' => (int) $teacher['user_id']]
                );
            }

            $db->insert('fingerprint_logs', [
                'teacher_id'         => $teacherId,
                'device_row_id'      => $deviceRowId,
                'sensor_template_id' => $sensorTemplateId,
                'result'             => 'enrolled',
                'confidence'         => $quality,
                'message'            => $isReenrol ? 'Fingerprint re-enrolled.' : 'Fingerprint enrolled.',
                'ip_address'         => RequestContext::ip(),
                'created_at'         => Clock::nowString(),
            ]);

            AuditService::log(
                AuditService::FINGERPRINT_ENROLLED,
                'fingerprints',
                'teacher',
                $teacherId,
                null,
                ['sensor_template_id' => $sensorTemplateId, 'device_row_id' => $deviceRowId],
                sprintf('%s fingerprint for %s %s in sensor slot %d.',
                    $isReenrol ? 'Re-enrolled' : 'Enrolled',
                    $teacher['first_name'], $teacher['last_name'], $sensorTemplateId)
            );

            return [
                'fingerprint_id'     => $fingerprintId,
                'teacher_id'         => $teacherId,
                'sensor_template_id' => $sensorTemplateId,
                're_enrolled'        => $isReenrol,
            ];
        });
    }

    public static function delete(int $teacherId, ?int $userId = null): void
    {
        $db = Database::instance();

        $fingerprint = $db->selectOne(
            'SELECT * FROM fingerprint_templates WHERE teacher_id = :id',
            ['id' => $teacherId]
        );

        if ($fingerprint === null) {
            throw new ValidationException(['teacher_id' => ['This teacher has no fingerprint enrolment.']]);
        }

        $db->transaction(static function (Database $db) use ($teacherId, $fingerprint, $userId): void {
            $db->execute('DELETE FROM fingerprint_templates WHERE teacher_id = :id', ['id' => $teacherId]);
            $db->update('teachers', ['fingerprint_status' => 'not_enrolled'], ['teacher_id' => $teacherId]);

            $db->insert('fingerprint_logs', [
                'teacher_id'         => $teacherId,
                'sensor_template_id' => (int) $fingerprint['sensor_template_id'],
                'result'             => 'deleted',
                'message'            => 'Fingerprint enrolment deleted by administrator.',
                'ip_address'         => RequestContext::ip(),
                'created_at'         => Clock::nowString(),
            ]);
        });

        AuditService::log(
            AuditService::FINGERPRINT_DELETED,
            'fingerprints',
            'teacher',
            $teacherId,
            ['sensor_template_id' => (int) $fingerprint['sensor_template_id']],
            null,
            'Fingerprint enrolment deleted. The teacher cannot open sessions until re-enrolled.',
            'success',
            $userId
        );

        NotificationService::toAdministrators(
            'fingerprint',
            'Fingerprint deleted',
            sprintf('Teacher #%d can no longer open attendance sessions until re-enrolled.', $teacherId),
            'normal',
            '/admin/fingerprints'
        );
    }

    public static function setStatus(int $teacherId, string $status): void
    {
        Database::instance()->update(
            'fingerprint_templates',
            ['status' => $status, 'updated_at' => Clock::nowString()],
            ['teacher_id' => $teacherId]
        );

        Database::instance()->update(
            'teachers',
            ['fingerprint_status' => $status === 'active' ? 'enrolled' : 'disabled'],
            ['teacher_id' => $teacherId]
        );

        AuditService::log(
            'FINGERPRINT_STATUS_CHANGED',
            'fingerprints',
            'teacher',
            $teacherId,
            null,
            ['status' => $status],
            'Fingerprint enrolment set to ' . $status
        );
    }

    // ------------------------------------------------- failure handling ----

    /** @param array<string,mixed> $device */
    private static function assertDeviceNotLocked(array $device): void
    {
        if ($device['locked_until'] === null) {
            return;
        }

        $lockedUntil = Clock::parse((string) $device['locked_until']);

        if ($lockedUntil <= Clock::now()) {
            Database::instance()->update('devices', ['locked_until' => null], ['id' => (int) $device['id']]);

            return;
        }

        $remaining = (int) ceil(($lockedUntil->getTimestamp() - Clock::timestamp()) / 60);

        throw new BusinessRuleException(
            'DEVICE_LOCKED',
            sprintf('This terminal is locked for %d more minute(s) after repeated failed scans.', $remaining),
            self::display('DEVICE LOCKED', $remaining . ' min', 'red-blink', 'rapid5') + ['hold_ms' => 10000],
            423
        );
    }

    /**
     * Escalate on repeated failures: log, then alert, then lock the terminal.
     *
     * @param array<string,mixed> $device
     */
    private static function registerFailure(array $device): void
    {
        $db          = Database::instance();
        $deviceRowId = (int) $device['id'];

        $windowMinutes = (int) Config::get('attendance.fingerprint.device_lock_minutes', 5);
        $maxFailures   = (int) Config::get('attendance.fingerprint.max_failures_before_lock', 5);
        $alertAfter    = (int) Config::get('attendance.fingerprint.alert_after_failures', 3);

        $recentFailures = (int) $db->scalar(
            "SELECT COUNT(*) FROM fingerprint_logs
              WHERE device_row_id = :device
                AND result IN ('unknown','failed')
                AND created_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
            ['device' => $deviceRowId, 'minutes' => $windowMinutes]
        );

        if ($recentFailures >= $alertAfter) {
            SecurityLogService::log(
                SecurityLogService::FINGERPRINT_FAILURE_THRESHOLD,
                $recentFailures >= $maxFailures ? 'high' : 'medium',
                sprintf(
                    'Terminal %s recorded %d failed fingerprint scans in %d minutes.',
                    $device['device_id'],
                    $recentFailures,
                    $windowMinutes
                ),
                ['failures' => $recentFailures],
                $deviceRowId,
                (string) $device['device_id']
            );
        }

        if ($recentFailures >= $maxFailures) {
            $until = Clock::now()->modify('+' . $windowMinutes . ' minutes');

            $db->update('devices', ['locked_until' => $until->format('Y-m-d H:i:s')], ['id' => $deviceRowId]);

            $db->insert('device_logs', [
                'device_row_id'  => $deviceRowId,
                'device_id_text' => (string) $device['device_id'],
                'event'          => 'locked',
                'severity'       => 'warning',
                'message'        => sprintf('Locked for %d minutes after %d failed fingerprint scans.', $windowMinutes, $recentFailures),
                'ip_address'     => RequestContext::ip(),
                'created_at'     => Clock::nowString(),
            ]);

            NotificationService::toAdministrators(
                'security',
                'Terminal locked',
                sprintf(
                    'Terminal %s was locked for %d minutes after %d failed fingerprint scans.',
                    $device['device_id'],
                    $windowMinutes,
                    $recentFailures
                ),
                'high',
                '/admin/devices'
            );
        }
    }

    private static function clearFailures(int $deviceRowId): void
    {
        Database::instance()->update('devices', ['locked_until' => null], ['id' => $deviceRowId]);
    }

    private static function logAttempt(
        ?int $teacherId,
        ?int $deviceRowId,
        ?int $sensorTemplateId,
        string $result,
        ?int $confidence,
        ?string $message
    ): int {
        $logId = (int) Database::instance()->insert('fingerprint_logs', [
            'teacher_id'         => $teacherId,
            'device_row_id'      => $deviceRowId,
            'sensor_template_id' => $sensorTemplateId,
            'result'             => $result,
            'confidence'         => $confidence,
            'message'            => $message === null ? null : mb_substr($message, 0, 255),
            'ip_address'         => RequestContext::ip(),
            'created_at'         => Clock::nowString(),
        ]);

        if ($result !== 'verified' && $result !== 'enrolled') {
            AuditService::log(
                AuditService::FINGERPRINT_FAILED,
                'attendance',
                'fingerprint_log',
                $logId,
                null,
                ['result' => $result, 'device_row_id' => $deviceRowId],
                $message ?? 'Fingerprint attempt failed.',
                'failure'
            );

            if ($teacherId !== null) {
                Database::instance()->execute(
                    'UPDATE fingerprint_templates SET failure_count = failure_count + 1 WHERE teacher_id = :id',
                    ['id' => $teacherId]
                );
            }
        }

        return $logId;
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

    /** @return list<array<string,mixed>> */
    public static function listEnrolments(): array
    {
        return Database::instance()->select(
            "SELECT fp.*, t.employee_number, t.first_name, t.last_name, t.photo_path,
                    d.department_name, dev.device_id AS enrolled_device,
                    c.room_number AS enrolled_room
               FROM fingerprint_templates fp
               JOIN teachers t     ON t.teacher_id = fp.teacher_id
               JOIN departments d  ON d.department_id = t.department_id
               LEFT JOIN devices dev ON dev.id = fp.enrolled_device_row_id
               LEFT JOIN classrooms c ON c.classroom_id = dev.classroom_id
              -- Archived and inactive staff are excluded, not merely soft-deleted
              -- ones. deleted_at alone was not enough: the Edit Teacher form
              -- writes teachers.status straight from the dropdown without ever
              -- touching deleted_at, so a teacher set to Inactive there stayed
              -- on this page indefinitely. Somebody who cannot open a session
              -- has no business in a list of who can.
              WHERE t.deleted_at IS NULL AND t.status = 'active'
              ORDER BY t.last_name, t.first_name"
        );
    }

    /** @return list<array<string,mixed>> */
    public static function logsForTeacher(int $teacherId, int $limit = 50): array
    {
        return Database::instance()->select(
            'SELECT fl.*, d.device_id, c.room_number
               FROM fingerprint_logs fl
               LEFT JOIN devices d    ON d.id = fl.device_row_id
               LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE fl.teacher_id = :id
              ORDER BY fl.log_id DESC LIMIT ' . max(1, min($limit, 200)),
            ['id' => $teacherId]
        );
    }

    /**
     * Lowest free sensor slot on a device.
     *
     * @param list<int> $alsoTaken Slots held by an in-flight capture that has
     *                             not been bound to a teacher yet, and so has
     *                             no template row here to be seen through.
     */
    /**
     * Terminals whose sensor is not holding what the records say it should.
     *
     * The server stores which teacher owns which slot; the R307 stores the
     * templates. Nothing kept the two honest, and they come apart quietly: a
     * template stored while the completion callback was lost leaves the sensor
     * holding a print with no row behind it, and erasing the sensor leaves
     * rows describing prints that are gone. Both look identical on this page —
     * every teacher "Active" — while the reader answers NOT RECOGNISED.
     *
     * The count now arrives on the heartbeat, so the two can simply be
     * compared. Only a genuine disagreement is reported: a terminal that has
     * never said (older firmware, or no heartbeat since the upgrade) is left
     * alone rather than accused.
     *
     * @return list<array<string,mixed>>
     */
    public static function sensorMismatches(): array
    {
        // What the server believes this sensor holds is its slot records, not
        // the enrolments that happened to be captured on it.
        //
        // The difference did not exist before templates could be copied. A
        // sensor held exactly what had been enrolled at it, so counting
        // enrolments by enrolled_device_row_id was the same number. Since
        // migration 018 a terminal holding templates it never enrolled is the
        // ordinary case — it is the entire point — and the old count read every
        // synced template as an intruder. A terminal that had just finished
        // collecting the staff reported "holding 4 fingerprints, but 0 are
        // recorded here", and recommended wiping a sensor that was working
        // perfectly, while the coverage table two inches below it said
        // "4 of 4 · Complete".
        //
        // Counting present slot rows is also what makes this agree with
        // terminalStatus(), which reads the same table. Two panels on one page
        // disagreeing about the same sensor is worse than either being wrong
        // alone: it leaves nobody knowing which to believe.
        return Database::instance()->select(
            // `stale` decides how this is worded, and it matters more than it
            // looks. sensor_template_count is written only when a heartbeat
            // actually carried fp_templates, and the firmware sends that field
            // only while the sensor answers getTemplateCount(). A sensor that
            // stops answering therefore freezes its last count instead of
            // clearing it — deliberately, so a terminal on older firmware does
            // not read as "unknown" — and the mismatch below then repeated a
            // days-old number in the present tense for as long as the fault
            // lasted. "Room 101 is holding 5 fingerprints" was not true; the
            // sensor had said nothing since.
            //
            // Heartbeats run every 30 seconds and a terminal is offline at 90,
            // so anything older than ten minutes is not a lagging reading, it
            // is a sensor that has gone quiet — which is the fault worth
            // reporting, and the one this warning was hiding.
            "SELECT d.id AS device_row_id, d.device_id, d.device_name,
                    c.room_number,
                    d.sensor_template_count, d.sensor_reported_at,
                    d.fingerprint_ok, d.last_heartbeat_at,
                    (d.sensor_reported_at IS NULL
                     OR d.sensor_reported_at < :stale_before) AS stale,
                    (SELECT COUNT(*) FROM fingerprint_slots s
                      WHERE s.device_row_id = d.id
                        AND s.status = 'present'
                    ) AS expected
               FROM devices d
          LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE d.deleted_at IS NULL
                AND d.sensor_template_count IS NOT NULL
             HAVING d.sensor_template_count <> expected
              ORDER BY d.device_id",
            ['stale_before' => Clock::now()->modify('-10 minutes')->format('Y-m-d H:i:s')]
        );
    }

    public static function nextAvailableSlot(?int $deviceRowId = null, array $alsoTaken = []): int
    {
        $rows = Database::instance()->select(
            $deviceRowId === null
                ? 'SELECT sensor_template_id FROM fingerprint_templates ORDER BY sensor_template_id'
                : 'SELECT sensor_template_id FROM fingerprint_templates
                    WHERE enrolled_device_row_id = :device OR enrolled_device_row_id IS NULL
                    ORDER BY sensor_template_id',
            $deviceRowId === null ? [] : ['device' => $deviceRowId]
        );

        $used = array_map(static fn (array $r): int => (int) $r['sensor_template_id'], $rows);
        $used = array_merge($used, array_map('intval', $alsoTaken));

        for ($slot = 1; $slot <= 999; $slot++) {
            if (!in_array($slot, $used, true)) {
                return $slot;
            }
        }

        throw new ValidationException(['sensor_template_id' => ['No free sensor slots remain on this device.']]);
    }
}
