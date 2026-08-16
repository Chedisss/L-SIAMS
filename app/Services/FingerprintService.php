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
 * Note what is *not* stored here: no biometric template ever reaches this
 * database. The R307 keeps the template in its own flash and returns a slot
 * number; the server records only that number and the teacher it maps to. The
 * consequence is that a full database compromise leaks no biometric data at
 * all, which is the right trade for a system deployed in a school.
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

        $fingerprint = $db->selectOne(
            'SELECT fp.*, t.teacher_id, t.first_name, t.last_name, t.status AS teacher_status,
                    t.employee_number, t.department_id
               FROM fingerprint_templates fp
               JOIN teachers t ON t.teacher_id = fp.teacher_id
              WHERE fp.sensor_template_id = :slot
                AND fp.enrolled_device_row_id = :device
                AND t.deleted_at IS NULL
              LIMIT 1',
            ['slot' => $sensorTemplateId, 'device' => $deviceRowId]
        );

        // Templates are synchronised across terminals, so fall back to a global
        // slot lookup before declaring the print unknown.
        if ($fingerprint === null) {
            $fingerprint = $db->selectOne(
                'SELECT fp.*, t.teacher_id, t.first_name, t.last_name, t.status AS teacher_status,
                        t.employee_number, t.department_id
                   FROM fingerprint_templates fp
                   JOIN teachers t ON t.teacher_id = fp.teacher_id
                  WHERE fp.sensor_template_id = :slot AND t.deleted_at IS NULL
                  LIMIT 1',
                ['slot' => $sensorTemplateId]
            );
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
        $candidates = ScheduleService::activeForDevice($deviceRowId);
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
            $reason = $candidates === []
                ? 'No class is scheduled in this room at this time.'
                : 'You are not the assigned teacher for the current class in this room.';

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
            'display_line_3' => $session['subject_code'] . ' ' . $session['section_code'],
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
              WHERE t.deleted_at IS NULL
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
        return Database::instance()->select(
            "SELECT d.id AS device_row_id, d.device_id, d.device_name,
                    c.room_number,
                    d.sensor_template_count, d.sensor_reported_at,
                    (SELECT COUNT(*) FROM fingerprint_templates fp
                      WHERE fp.status = 'active'
                        AND (fp.enrolled_device_row_id = d.id OR fp.enrolled_device_row_id IS NULL)
                    ) AS expected
               FROM devices d
          LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE d.deleted_at IS NULL
                AND d.sensor_template_count IS NOT NULL
             HAVING d.sensor_template_count <> expected
              ORDER BY d.device_id"
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
