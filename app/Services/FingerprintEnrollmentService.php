<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;

/**
 * The hand-off between the enrolment screen and the sensor.
 *
 * Enrolment used to be a form: an administrator stood at the terminal, drove the
 * sensor by some other means, read the slot number off it and typed that number
 * into the browser. Nothing connected the two halves, so nothing stopped a typo
 * from binding a teacher to somebody else's finger.
 *
 * Here the browser opens a request against a terminal, the terminal picks it up
 * on its next poll and runs the sensor's own capture cycle, and the slot comes
 * back from the sensor rather than from a keyboard. The administrator never
 * types a number.
 *
 * What is still true: no biometric template reaches this server. The request
 * carries the slot the template must occupy; the template stays in the sensor's
 * flash, and this database records only which teacher owns which slot.
 */
final class FingerprintEnrollmentService
{
    /** Stages the firmware reports, in the order they occur. */
    public const STAGE_WAITING   = 'waiting_for_device';
    public const STAGE_READY     = 'ready';
    public const STAGE_PLACE     = 'place_finger';
    public const STAGE_REMOVE    = 'remove_finger';
    public const STAGE_PLACE_AGAIN = 'place_again';
    public const STAGE_STORING   = 'storing';
    public const STAGE_DONE      = 'done';

    /** @var list<string> */
    private const STAGES = [
        self::STAGE_WAITING, self::STAGE_READY, self::STAGE_PLACE,
        self::STAGE_REMOVE, self::STAGE_PLACE_AGAIN, self::STAGE_STORING, self::STAGE_DONE,
    ];

    private static function ttlSeconds(): int
    {
        return (int) Config::get('attendance.fingerprint.enrollment_ttl_seconds', 180);
    }

    // ------------------------------------------------------------- open ----

    /**
     * Open a request for a teacher on a terminal.
     *
     * @return array<string,mixed>
     */
    public static function open(int $teacherId, int $deviceRowId, int $requestedBy): array
    {
        $db = Database::instance();

        self::expireStale();

        $teacher = $db->selectOne(
            "SELECT teacher_id, first_name, last_name, employee_number, status
               FROM teachers WHERE teacher_id = :id AND deleted_at IS NULL",
            ['id' => $teacherId]
        );

        if ($teacher === null) {
            throw new ValidationException(['teacher_id' => ['Teacher not found.']]);
        }

        if ((string) $teacher['status'] !== 'active') {
            throw new ValidationException([
                'teacher_id' => ['This teacher account is not active, so it cannot be enrolled.'],
            ]);
        }

        $device = $db->selectOne(
            'SELECT d.*, v.health FROM devices d
          LEFT JOIN v_device_status v ON v.device_row_id = d.id
             WHERE d.id = :id AND d.deleted_at IS NULL',
            ['id' => $deviceRowId]
        );

        if ($device === null) {
            throw new ValidationException(['device_row_id' => ['Terminal not found.']]);
        }

        if ((string) $device['claim_status'] !== 'claimed') {
            throw new ValidationException([
                'device_row_id' => [
                    'That terminal has never completed first-boot activation, so it cannot be asked '
                    . 'to scan anything yet. Flash its provisioning file and power it on first.',
                ],
            ]);
        }

        if (in_array((string) $device['status'], ['disabled', 'decommissioned', 'suspended'], true)) {
            throw new ValidationException([
                'device_row_id' => ['That terminal is ' . $device['status'] . ' and will not answer.'],
            ]);
        }

        return $db->transaction(static function (Database $db) use (
            $teacherId, $deviceRowId, $requestedBy, $teacher, $device
        ): array {
            // One at a time per terminal. Two open requests would race for the
            // person standing at the sensor, and whichever finished first would
            // claim a finger that may have been presented for the other.
            $open = $db->selectOne(
                "SELECT r.*, t.first_name, t.last_name
                   FROM fingerprint_enrollment_requests r
                   JOIN teachers t ON t.teacher_id = r.teacher_id
                  WHERE r.device_row_id = :device
                    AND r.status IN ('pending','scanning')
                  ORDER BY r.request_id DESC LIMIT 1
                    FOR UPDATE",
                ['device' => $deviceRowId]
            );

            if ($open !== null) {
                throw new BusinessRuleException(
                    'ENROLLMENT_IN_PROGRESS',
                    sprintf(
                        'That terminal is already enrolling %s %s. Wait for it to finish, or cancel it.',
                        $open['first_name'],
                        $open['last_name']
                    ),
                    ['request_id' => (int) $open['request_id']],
                    409
                );
            }

            // Allocated here rather than on the device: the slot has to be free
            // across the whole school, and only the server can see that. The
            // sensor is told which slot to write, and refusing a slot that is
            // already taken is then a server decision, not a firmware one.
            $slot = FingerprintService::nextAvailableSlot($deviceRowId);

            $requestId = (int) $db->insert('fingerprint_enrollment_requests', [
                'teacher_id'         => $teacherId,
                'device_row_id'      => $deviceRowId,
                'sensor_template_id' => $slot,
                'status'             => 'pending',
                'stage'              => self::STAGE_WAITING,
                'message'            => 'Waiting for the terminal to pick this up…',
                'requested_by'       => $requestedBy,
                'expires_at'         => Clock::now()
                    ->modify('+' . self::ttlSeconds() . ' seconds')->format('Y-m-d H:i:s'),
                'created_at'         => Clock::nowString(),
                'updated_at'         => Clock::nowString(),
            ]);

            AuditService::log(
                'FINGERPRINT_ENROLLMENT_REQUESTED',
                'fingerprints',
                'teacher',
                $teacherId,
                null,
                [
                    'device_id'          => (string) $device['device_id'],
                    'sensor_template_id' => $slot,
                    'request_id'         => $requestId,
                ],
                sprintf(
                    'Enrolment requested for %s %s on terminal %s, sensor slot %d.',
                    $teacher['first_name'],
                    $teacher['last_name'],
                    $device['device_id'],
                    $slot
                ),
                'success',
                $requestedBy
            );

            return self::find($requestId) ?? [];
        });
    }

    // ------------------------------------------------------ device side ----

    /**
     * The request a terminal should be acting on, if any.
     *
     * Picking one up moves it to 'scanning' so the wizard can stop saying
     * "waiting for the terminal" the moment the terminal has actually heard.
     *
     * @param  array<string,mixed> $device
     * @return array<string,mixed>|null
     */
    public static function claimForDevice(array $device): ?array
    {
        $db          = Database::instance();
        $deviceRowId = (int) $device['id'];

        self::expireStale();

        return $db->transaction(static function (Database $db) use ($deviceRowId): ?array {
            $request = $db->selectOne(
                "SELECT r.*, t.first_name, t.last_name, t.employee_number
                   FROM fingerprint_enrollment_requests r
                   JOIN teachers t ON t.teacher_id = r.teacher_id
                  WHERE r.device_row_id = :device
                    AND r.status IN ('pending','scanning')
                  ORDER BY r.request_id ASC LIMIT 1
                    FOR UPDATE",
                ['device' => $deviceRowId]
            );

            if ($request === null) {
                return null;
            }

            if ((string) $request['status'] === 'pending') {
                $db->update('fingerprint_enrollment_requests', [
                    'status'     => 'scanning',
                    'stage'      => self::STAGE_READY,
                    'message'    => 'Terminal ready — ask the teacher to place their finger on the sensor.',
                    'claimed_at' => Clock::nowString(),
                    'updated_at' => Clock::nowString(),
                ], ['request_id' => (int) $request['request_id']]);

                $request['status']  = 'scanning';
                $request['stage']   = self::STAGE_READY;
                $request['message'] = 'Terminal ready — ask the teacher to place their finger on the sensor.';
            }

            return $request;
        });
    }

    /**
     * A progress ping from the firmware as it walks the capture cycle.
     *
     * @return array<string,mixed>
     */
    public static function progress(int $requestId, int $deviceRowId, string $stage, ?string $message = null): array
    {
        $request = self::findForDevice($requestId, $deviceRowId);

        if (!in_array($stage, self::STAGES, true)) {
            throw new ValidationException(['stage' => ['Unknown enrolment stage.']]);
        }

        if (!in_array((string) $request['status'], ['pending', 'scanning'], true)) {
            throw new BusinessRuleException(
                'ENROLLMENT_NOT_OPEN',
                'That enrolment is no longer open.',
                [],
                409
            );
        }

        Database::instance()->update('fingerprint_enrollment_requests', [
            'status'     => 'scanning',
            'stage'      => $stage,
            'message'    => $message === null ? self::defaultMessage($stage) : mb_substr($message, 0, 255),
            // Each step of a capture cycle is evidence somebody is standing
            // there, so the window moves with them rather than expiring under
            // a teacher who is on their third attempt.
            'expires_at' => Clock::now()->modify('+' . self::ttlSeconds() . ' seconds')->format('Y-m-d H:i:s'),
            'updated_at' => Clock::nowString(),
        ], ['request_id' => $requestId]);

        return self::find($requestId) ?? [];
    }

    /**
     * The sensor stored the template. Record the enrolment.
     *
     * @return array<string,mixed>
     */
    public static function complete(
        int $requestId,
        int $deviceRowId,
        int $sensorTemplateId,
        ?int $quality = null,
        int $sampleCount = 0
    ): array {
        $request = self::findForDevice($requestId, $deviceRowId);

        if (!in_array((string) $request['status'], ['pending', 'scanning'], true)) {
            throw new BusinessRuleException(
                'ENROLLMENT_NOT_OPEN',
                'That enrolment is no longer open.',
                [],
                409
            );
        }

        // The firmware is told which slot to write and echoes back what it
        // actually wrote. A mismatch means the sensor put the template
        // somewhere else, and binding the teacher to the slot we asked for
        // would then point at whatever finger already lived there.
        if ($sensorTemplateId !== (int) $request['sensor_template_id']) {
            self::fail(
                $requestId,
                $deviceRowId,
                sprintf(
                    'The sensor stored the template in slot %d, not the requested slot %d. Nothing was recorded.',
                    $sensorTemplateId,
                    (int) $request['sensor_template_id']
                )
            );

            throw new BusinessRuleException(
                'SLOT_MISMATCH',
                'The sensor used a different slot than the one requested; the enrolment was discarded.',
                [],
                409
            );
        }

        FingerprintService::enroll(
            (int) $request['teacher_id'],
            $sensorTemplateId,
            $deviceRowId,
            $quality,
            $sampleCount,
            $request['requested_by'] === null ? null : (int) $request['requested_by']
        );

        Database::instance()->update('fingerprint_enrollment_requests', [
            'status'        => 'completed',
            'stage'         => self::STAGE_DONE,
            'message'       => 'Fingerprint enrolled.',
            'quality_score' => $quality,
            'sample_count'  => $sampleCount,
            'completed_at'  => Clock::nowString(),
            'updated_at'    => Clock::nowString(),
        ], ['request_id' => $requestId]);

        return self::find($requestId) ?? [];
    }

    /** The capture cycle did not produce a usable template. */
    public static function fail(int $requestId, int $deviceRowId, string $reason): array
    {
        $request = self::findForDevice($requestId, $deviceRowId);

        Database::instance()->update('fingerprint_enrollment_requests', [
            'status'       => 'failed',
            'message'      => mb_substr($reason, 0, 255),
            'completed_at' => Clock::nowString(),
            'updated_at'   => Clock::nowString(),
        ], ['request_id' => $requestId]);

        Database::instance()->insert('fingerprint_logs', [
            'teacher_id'         => (int) $request['teacher_id'],
            'device_row_id'      => $deviceRowId,
            'sensor_template_id' => (int) $request['sensor_template_id'],
            'result'             => 'failed',
            'message'            => mb_substr('Enrolment failed: ' . $reason, 0, 255),
            'ip_address'         => RequestContext::ip(),
            'created_at'         => Clock::nowString(),
        ]);

        return self::find($requestId) ?? [];
    }

    // ------------------------------------------------------ browser side ---

    public static function cancel(int $requestId, ?int $userId = null): array
    {
        $request = self::find($requestId);

        if ($request === null) {
            throw new ValidationException(['request_id' => ['Enrolment request not found.']]);
        }

        if (!in_array((string) $request['status'], ['pending', 'scanning'], true)) {
            return $request;
        }

        Database::instance()->update('fingerprint_enrollment_requests', [
            'status'       => 'cancelled',
            'message'      => 'Cancelled from the enrolment screen.',
            'completed_at' => Clock::nowString(),
            'updated_at'   => Clock::nowString(),
        ], ['request_id' => $requestId]);

        AuditService::log(
            'FINGERPRINT_ENROLLMENT_CANCELLED',
            'fingerprints',
            'teacher',
            (int) $request['teacher_id'],
            null,
            ['request_id' => $requestId],
            'Enrolment request cancelled before the sensor completed.',
            'success',
            $userId
        );

        return self::find($requestId) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $requestId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT r.*, t.first_name, t.last_name, t.employee_number,
                    d.device_id, c.room_number
               FROM fingerprint_enrollment_requests r
               JOIN teachers t ON t.teacher_id = r.teacher_id
               JOIN devices  d ON d.id = r.device_row_id
          LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE r.request_id = :id',
            ['id' => $requestId]
        );
    }

    /**
     * Close requests nobody acted on.
     *
     * Called on every open and every device poll rather than left to the
     * maintenance worker: a terminal that was switched off mid-enrolment would
     * otherwise block that terminal until the worker's next pass, and the
     * person standing in front of it has no way to know why.
     */
    public static function expireStale(): int
    {
        return Database::instance()->execute(
            "UPDATE fingerprint_enrollment_requests
                SET status = 'expired',
                    message = 'Timed out — the terminal did not complete the scan.',
                    completed_at = :now,
                    updated_at = :now
              WHERE status IN ('pending','scanning')
                AND expires_at < :now",
            ['now' => Clock::nowString()]
        );
    }

    // ------------------------------------------------------------ helpers --

    /** @return array<string,mixed> */
    private static function findForDevice(int $requestId, int $deviceRowId): array
    {
        $request = Database::instance()->selectOne(
            'SELECT * FROM fingerprint_enrollment_requests WHERE request_id = :id',
            ['id' => $requestId]
        );

        if ($request === null) {
            throw new ValidationException(['request_id' => ['Enrolment request not found.']]);
        }

        // A terminal may only speak about its own request. Without this a
        // compromised terminal in one room could complete an enrolment opened
        // in another, and the audit trail would name the wrong room.
        if ((int) $request['device_row_id'] !== $deviceRowId) {
            throw new BusinessRuleException(
                'WRONG_DEVICE',
                'That enrolment request belongs to a different terminal.',
                [],
                403
            );
        }

        return $request;
    }

    private static function defaultMessage(string $stage): string
    {
        return match ($stage) {
            self::STAGE_READY       => 'Terminal ready — ask the teacher to place their finger on the sensor.',
            self::STAGE_PLACE       => 'Place the finger firmly on the sensor.',
            self::STAGE_REMOVE      => 'Lift the finger off the sensor.',
            self::STAGE_PLACE_AGAIN => 'Place the same finger again.',
            self::STAGE_STORING     => 'Building the template…',
            self::STAGE_DONE        => 'Fingerprint enrolled.',
            default                 => 'Waiting for the terminal to pick this up…',
        };
    }
}
