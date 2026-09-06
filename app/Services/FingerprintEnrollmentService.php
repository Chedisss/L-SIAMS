<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
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
 * The template itself now travels with the completion report, so it can be
 * copied to the other terminals (FingerprintSyncService). It is held here,
 * encrypted, only for a capture taken before its teacher record exists, and
 * cleared the moment bind() moves it onto the teacher. The request
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

    /** How long a captured-but-unattached print waits for its registration form. */
    private static function holdSeconds(): int
    {
        return (int) Config::get('attendance.fingerprint.enrollment_hold_seconds', 1800);
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

        return self::openRequest(
            $deviceRowId,
            $requestedBy,
            $teacherId,
            trim($teacher['first_name'] . ' ' . $teacher['last_name'])
        );
    }

    /**
     * Capture for somebody who is not registered yet.
     *
     * Registration takes the fingerprint first and creates the teacher second,
     * so there is nobody to point the request at while the sensor is working.
     * The slot it allocates is held against this request until a teacher row is
     * created and bound to it — or until the request is abandoned, at which
     * point the terminal is told to delete the template so the slot is genuinely
     * free rather than merely unreferenced.
     *
     * @return array<string,mixed>
     */
    public static function openForRegistration(string $label, int $deviceRowId, int $requestedBy): array
    {
        self::expireStale();

        $label = trim($label);

        return self::openRequest(
            $deviceRowId,
            $requestedBy,
            null,
            $label === '' ? 'New teacher' : mb_substr($label, 0, 120)
        );
    }

    /** @return array<string,mixed> */
    private static function openRequest(
        int $deviceRowId,
        int $requestedBy,
        ?int $teacherId,
        string $label
    ): array {
        $db = Database::instance();

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
            $teacherId, $deviceRowId, $requestedBy, $label, $device
        ): array {
            // One at a time per terminal. Two open requests would race for the
            // person standing at the sensor, and whichever finished first would
            // claim a finger that may have been presented for the other.
            $open = $db->selectOne(
                "SELECT r.request_id,
                        COALESCE(CONCAT(t.first_name, ' ', t.last_name), r.subject_label) AS who
                   FROM fingerprint_enrollment_requests r
              LEFT JOIN teachers t ON t.teacher_id = r.teacher_id
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
                        'That terminal is already enrolling %s. Wait for it to finish, or cancel it.',
                        $open['who']
                    ),
                    ['request_id' => (int) $open['request_id']],
                    409
                );
            }

            // Allocated here rather than on the device: the slot has to be free
            // across the whole school, and only the server can see that. The
            // sensor is told which slot to write, and refusing a slot that is
            // already taken is then a server decision, not a firmware one.
            $slot = self::slotFor($db, $deviceRowId, $teacherId);

            $requestId = (int) $db->insert('fingerprint_enrollment_requests', [
                'teacher_id'         => $teacherId,
                'subject_label'      => $label,
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
                    'Enrolment requested for %s on terminal %s, sensor slot %d.',
                    $label,
                    $device['device_id'],
                    $slot
                ),
                'success',
                $requestedBy
            );

            return self::find($requestId) ?? [];
        });
    }

    /**
     * The lowest slot that is neither enrolled nor spoken for.
     *
     * FingerprintService::nextAvailableSlot() only knows about templates that
     * have been recorded. A capture taken during registration has written a
     * real template to the sensor but has no teacher yet, so it is invisible
     * there — and handing the same slot to the next registration would have the
     * sensor overwrite one person's finger with another's.
     */
    /**
     * The slot this enrolment should write to.
     *
     * A teacher who already has a template re-enrols into the slot they
     * already own. The sensor's store overwrites that slot in place, so the
     * old template is genuinely replaced.
     *
     * Allocating a fresh slot instead — which is what happened before — left
     * the previous template sitting in the sensor with nothing pointing at it.
     * fingerprint_templates holds one row per teacher and it was updated to the
     * new slot, so the old one became invisible to the server while remaining
     * perfectly matchable by the reader. The same finger then matched slot 1 on
     * one scan and slot 2 on the next, depending on which stored copy scored
     * higher, and a scan that landed on the orphan was refused as
     * FINGERPRINT_UNKNOWN — a finger that was genuinely enrolled, rejected
     * because it matched the wrong copy of itself. Every re-enrolment made it
     * worse by adding another copy.
     *
     * Only the teacher's own slot is reused. A capture taken before the teacher
     * row exists has nobody to look up and still takes the next free slot.
     */
    private static function slotFor(Database $db, int $deviceRowId, ?int $teacherId): int
    {
        if ($teacherId !== null) {
            $owned = $db->scalar(
                // Prefer this device's own record if the teacher somehow has
                // more than one; the slot number is what the sensor here uses.
                'SELECT sensor_template_id
                   FROM fingerprint_templates
                  WHERE teacher_id = :teacher
                  ORDER BY (enrolled_device_row_id = :device) DESC, fingerprint_id DESC
                  LIMIT 1',
                ['teacher' => $teacherId, 'device' => $deviceRowId]
            );

            if ($owned !== null) {
                return (int) $owned;
            }
        }

        return self::nextFreeSlot($db, $deviceRowId);
    }

    private static function nextFreeSlot(Database $db, int $deviceRowId): int
    {
        // 'abandoned' belongs here as much as the in-flight states. Its template
        // is still in the sensor, waiting for the terminal to delete it — and
        // handing the slot out before that happens means the delete lands after
        // the next person has been enrolled into it, wiping the print that was
        // just taken. The slot only returns to circulation when the terminal
        // confirms, which flips the row to 'cancelled'.
        $held = $db->select(
            "SELECT sensor_template_id
               FROM fingerprint_enrollment_requests
              WHERE device_row_id = :device
                AND status IN ('pending','scanning','completed','abandoned')
                AND bound_at IS NULL",
            ['device' => $deviceRowId]
        );

        $reserved = array_map(static fn (array $r): int => (int) $r['sensor_template_id'], $held);

        return FingerprintService::nextAvailableSlot($deviceRowId, $reserved);
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
                "SELECT r.*, t.employee_number,
                        COALESCE(CONCAT(t.first_name, ' ', t.last_name), r.subject_label) AS display_name
                   FROM fingerprint_enrollment_requests r
              LEFT JOIN teachers t ON t.teacher_id = r.teacher_id
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
        int $sampleCount = 0,
        #[\SensitiveParameter] string $templateData = '',
        bool $duplicateChecked = false
    ): array {
        $request = self::findForDevice($requestId, $deviceRowId);

        // The duplicate-finger check runs on the terminal, because only the
        // sensor can match a print. Firmware is flashed by hand, so a terminal
        // still carrying an older sketch ran no check at all — and the server
        // accepted the enrolment anyway. That is how one finger came to be
        // enrolled to two teachers on a system that had already shipped the
        // fix: the fix was on a board nobody had reflashed.
        //
        // A control that an out-of-date device can skip is not a control. The
        // terminal must now state that it searched, and a completion without
        // that statement is refused. An un-updated terminal becomes unable to
        // enrol rather than able to enrol unsafely, which is the right way
        // round — and the message says exactly what to do about it, because
        // otherwise this reads as the reader being broken.
        if (!$duplicateChecked) {
            self::fail(
                $requestId,
                $deviceRowId,
                'This terminal did not check whether the finger was already enrolled. Update its firmware.',
                'DUPLICATE_CHECK_MISSING'
            );

            throw new BusinessRuleException(
                'DUPLICATE_CHECK_MISSING',
                'This terminal is running firmware older than 2.1.0, which cannot check whether a finger '
                . 'is already enrolled to somebody else. Re-flash it from firmware/L_SIAMS_Bench before '
                . 'enrolling anyone on it. Nothing was recorded.',
                [],
                409
            );
        }

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

        // A capture taken during registration has nobody to enrol yet. The
        // template is in the sensor and the slot is held against this request;
        // bind() attaches both to the teacher row the moment it is created.
        if ($request['teacher_id'] !== null) {
            $enrolled = FingerprintService::enroll(
                (int) $request['teacher_id'],
                $sensorTemplateId,
                $deviceRowId,
                $quality,
                $sampleCount,
                $request['requested_by'] === null ? null : (int) $request['requested_by']
            );

            // The bytes the sensor handed back, so every other terminal can be
            // given the same template. A terminal running older firmware sends
            // nothing, and that is not an error — the enrolment stands, it
            // simply stays local to this sensor until somebody re-enrols.
            if ($templateData !== '' && isset($enrolled['fingerprint_id'])) {
                FingerprintSyncService::captureTemplate(
                    (int) $enrolled['fingerprint_id'],
                    $deviceRowId,
                    $sensorTemplateId,
                    $templateData
                );
            }
        }

        Database::instance()->update('fingerprint_enrollment_requests', [
            'status'        => 'completed',
            'stage'         => self::STAGE_DONE,
            'message'       => $request['teacher_id'] === null
                ? 'Fingerprint captured. Finish the registration form to save it.'
                : 'Fingerprint enrolled.',
            'quality_score' => $quality,
            'sample_count'  => $sampleCount,
            // Held encrypted only while the registration form is still open.
            // bind() moves it onto the teacher's row and clears it here, so a
            // capture that is never bound expires with the request rather than
            // leaving biometric data in a staging table indefinitely.
            'template_data' => $request['teacher_id'] === null && $templateData !== ''
                ? Crypto::encrypt($templateData)
                : null,
            'completed_at'  => Clock::nowString(),
            'bound_at'      => $request['teacher_id'] === null ? null : Clock::nowString(),
            // An unbound capture is now waiting on somebody filling in the rest
            // of a registration form, which takes longer than the gap between
            // two finger placements. The window restarts here rather than
            // running out while they are still typing a department in.
            'expires_at'    => Clock::now()
                ->modify('+' . self::holdSeconds() . ' seconds')->format('Y-m-d H:i:s'),
            'updated_at'    => Clock::nowString(),
        ], ['request_id' => $requestId]);

        return self::find($requestId) ?? [];
    }

    /**
     * Attach a captured fingerprint to the teacher row it was taken for.
     *
     * Called inside the transaction that creates the teacher, so a registration
     * that fails for any other reason does not leave a slot marked as belonging
     * to a teacher who was never created.
     */
    public static function bind(int $requestId, int $teacherId, ?int $userId = null): void
    {
        $db = Database::instance();

        $request = $db->selectOne(
            'SELECT * FROM fingerprint_enrollment_requests WHERE request_id = :id FOR UPDATE',
            ['id' => $requestId]
        );

        if ($request === null) {
            throw new ValidationException([
                'fingerprint_request_id' => ['That fingerprint capture no longer exists. Scan again.'],
            ]);
        }

        if ((string) $request['status'] !== 'completed' || $request['bound_at'] !== null) {
            throw new ValidationException([
                'fingerprint_request_id' => [
                    'That fingerprint capture is not available to attach — it was already used, '
                    . 'cancelled or timed out. Scan again.',
                ],
            ]);
        }

        if ($request['teacher_id'] !== null && (int) $request['teacher_id'] !== $teacherId) {
            throw new ValidationException([
                'fingerprint_request_id' => ['That fingerprint capture belongs to a different teacher.'],
            ]);
        }

        $enrolled = FingerprintService::enroll(
            $teacherId,
            (int) $request['sensor_template_id'],
            (int) $request['device_row_id'],
            $request['quality_score'] === null ? null : (int) $request['quality_score'],
            (int) $request['sample_count'],
            $userId
        );

        // The template has been waiting here since the capture, because at
        // that moment there was no teacher row to hang it on. Now there is, so
        // it moves onto the teacher and the staging copy is cleared — a
        // capture that is never bound therefore leaves no biometric data
        // behind when its request expires.
        if ($request['template_data'] !== null && isset($enrolled['fingerprint_id'])) {
            try {
                FingerprintSyncService::captureTemplate(
                    (int) $enrolled['fingerprint_id'],
                    (int) $request['device_row_id'],
                    (int) $request['sensor_template_id'],
                    base64_encode(Crypto::decrypt((string) $request['template_data']))
                );
            } catch (\Throwable $e) {
                // The teacher is enrolled and their own terminal works. Losing
                // the onward copy is worth a log, not a failed registration
                // the administrator has to start over.
                \App\Core\Logger::warning('Held fingerprint template could not be moved onto the teacher', [
                    'request_id' => $requestId,
                    'teacher_id' => $teacherId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $db->update('fingerprint_enrollment_requests', [
            'teacher_id'    => $teacherId,
            'template_data' => null,
            'bound_at'      => Clock::nowString(),
            'message'       => 'Fingerprint enrolled with the teacher record.',
            'updated_at'    => Clock::nowString(),
        ], ['request_id' => $requestId]);
    }

    /**
     * A capture that will never be bound.
     *
     * The template is already in the sensor's flash, so marking the row is not
     * enough — the slot stays occupied by a print belonging to nobody, and the
     * next person allocated it would be enrolled over the top. 'abandoned' puts
     * it on the terminal's delete list; the slot is only genuinely free once the
     * terminal confirms it has gone.
     */
    public static function abandon(int $requestId, ?int $userId = null): void
    {
        $request = self::find($requestId);

        if ($request === null || $request['bound_at'] !== null) {
            return;
        }

        if (!in_array((string) $request['status'], ['pending', 'scanning', 'completed'], true)) {
            return;
        }

        // Nothing was written to the sensor, so there is nothing to delete.
        $status = (string) $request['status'] === 'completed' ? 'abandoned' : 'cancelled';

        Database::instance()->update('fingerprint_enrollment_requests', [
            'status'       => $status,
            'message'      => $status === 'abandoned'
                ? 'Registration was not completed; the template is being removed from the sensor.'
                : 'Cancelled before the sensor captured anything.',
            'completed_at' => $request['completed_at'] ?? Clock::nowString(),
            'updated_at'   => Clock::nowString(),
        ], ['request_id' => $requestId]);

        AuditService::log(
            'FINGERPRINT_ENROLLMENT_ABANDONED',
            'fingerprints',
            'teacher',
            null,
            null,
            ['request_id' => $requestId, 'sensor_template_id' => (int) $request['sensor_template_id']],
            sprintf(
                'Capture for "%s" was not attached to a teacher; sensor slot %d is being reclaimed.',
                (string) ($request['subject_label'] ?? 'unknown'),
                (int) $request['sensor_template_id']
            ),
            'success',
            $userId
        );
    }

    /**
     * Slots this terminal is holding templates for that nothing owns.
     *
     * @return list<int>
     */
    public static function discardSlotsFor(int $deviceRowId): array
    {
        $rows = Database::instance()->select(
            "SELECT sensor_template_id
               FROM fingerprint_enrollment_requests
              WHERE device_row_id = :device AND status = 'abandoned' AND bound_at IS NULL
              ORDER BY sensor_template_id",
            ['device' => $deviceRowId]
        );

        return array_values(array_unique(
            array_map(static fn (array $r): int => (int) $r['sensor_template_id'], $rows)
        ));
    }

    /** The terminal reports it has deleted the template, so the slot is free. */
    public static function confirmDiscarded(int $deviceRowId, int $slot): void
    {
        Database::instance()->execute(
            "UPDATE fingerprint_enrollment_requests
                SET status = 'cancelled',
                    message = 'Template removed from the sensor; the slot is free again.',
                    updated_at = :now
              WHERE device_row_id = :device
                AND sensor_template_id = :slot
                AND status = 'abandoned'",
            ['now' => Clock::nowString(), 'device' => $deviceRowId, 'slot' => $slot]
        );
    }

    /** The capture cycle did not produce a usable template. */
    /**
     * The terminal has recognised the finger it was about to enrol.
     *
     * A sensor will happily store the same finger in two slots, and nothing
     * downstream can tell afterwards that it did: two teachers end up with
     * enrolments that both match one person, and whichever slot the search
     * happens to return first decides whose class opens. That is worse than a
     * refused enrolment by a long way, and it is silent.
     *
     * So the firmware searches before it enrols and reports a hit here. The
     * slot number is all it can say — slots are per-sensor and mean nothing on
     * their own — so the mapping to a person is done here, where the records
     * are.
     *
     * A match against the teacher being enrolled is not a duplicate: that is
     * somebody re-enrolling, which is allowed and is how a poor first capture
     * gets replaced. Only a match against somebody else stops the enrolment.
     *
     * @return array{duplicate:bool,teacher_name:string,message:string}
     */
    public static function duplicateCheck(int $requestId, int $deviceRowId, int $matchedSlot): array
    {
        $request = self::findForDevice($requestId, $deviceRowId);

        $owner = Database::instance()->selectOne(
            "SELECT fp.teacher_id, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM fingerprint_slots s
               JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
               JOIN teachers t ON t.teacher_id = fp.teacher_id
              WHERE s.device_row_id = :device
                AND s.sensor_template_id = :slot
                AND t.deleted_at IS NULL
              LIMIT 1",
            ['device' => $deviceRowId, 'slot' => $matchedSlot]
        );

        // The sensor matched a slot this server has no record of. That is not
        // proof of a duplicate — it is proof the sensor and the register have
        // drifted apart — and refusing on it would block enrolment on a
        // terminal somebody had wiped by hand. Reported, not enforced.
        if ($owner === null) {
            Logger::warning('Fingerprint matched a slot with no record', [
                'device_row_id'      => $deviceRowId,
                'sensor_template_id' => $matchedSlot,
                'request_id'         => $requestId,
            ]);

            return ['duplicate' => false, 'teacher_name' => '', 'message' => ''];
        }

        if ($request['teacher_id'] !== null && (int) $owner['teacher_id'] === (int) $request['teacher_id']) {
            return ['duplicate' => false, 'teacher_name' => (string) $owner['teacher_name'], 'message' => ''];
        }

        $message = sprintf('This fingerprint is already enrolled to %s.', $owner['teacher_name']);

        self::fail($requestId, $deviceRowId, $message, 'DUPLICATE_FINGERPRINT');

        return [
            'duplicate'    => true,
            'teacher_name' => (string) $owner['teacher_name'],
            'message'      => $message,
        ];
    }

    /**
     * @param string|null $code Machine-readable reason, for the one consumer
     *                          that has to tell the cases apart: the enrolment
     *                          wizard, which shows a duplicate as a refusal
     *                          that stays on screen and everything else as an
     *                          ordinary failure. Prose is for the reader; this
     *                          is so the browser never has to parse it.
     */
    public static function fail(int $requestId, int $deviceRowId, string $reason, ?string $code = null): array
    {
        $request = self::findForDevice($requestId, $deviceRowId);

        $row = [
            'status'       => 'failed',
            'message'      => mb_substr($reason, 0, 255),
            'completed_at' => Clock::nowString(),
            'updated_at'   => Clock::nowString(),
        ];

        // Written only where the schema has it, so a server whose files are
        // newer than its database — pulled without running migrate — records
        // the failure with its message rather than throwing on the way to
        // recording it. Losing the code costs the wizard its emphasis; losing
        // the whole row would leave the dialog saying "Scanning" for ever,
        // which is the exact fault this change exists to fix.
        if ($code !== null && Database::instance()->hasColumn('fingerprint_enrollment_requests', 'failure_code')) {
            $row['failure_code'] = $code;
        }

        Database::instance()->update('fingerprint_enrollment_requests', $row, ['request_id' => $requestId]);

        Database::instance()->insert('fingerprint_logs', [
            'teacher_id'         => $request['teacher_id'] === null ? null : (int) $request['teacher_id'],
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

    /**
     * Terminals that can be asked to capture a fingerprint, best first.
     *
     * Enrolment scanners lead because they are the answer to "where do I do
     * this from" — a scanner on the desk means the whole job happens at the
     * computer the registration is being typed into, rather than walking
     * somebody to a classroom. Classroom terminals still work and are still
     * listed; they are simply the fallback rather than the assumption.
     *
     * @return list<array<string,mixed>>
     */
    public static function captureDevices(): array
    {
        return Database::instance()->select(
            "SELECT v.device_row_id AS id, v.device_id, v.device_name, v.room_number,
                    v.health, v.enrollment_station, v.claim_status
               FROM v_device_status v
              WHERE v.claim_status = 'claimed'
                AND v.configured_status IN ('active','offline')
              ORDER BY v.enrollment_station DESC,
                       FIELD(v.health, 'online', 'warning', 'offline'),
                       v.room_number, v.device_id"
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $requestId): ?array
    {
        // LEFT JOIN on teachers: a capture taken during registration has no
        // teacher yet, and an inner join would make the request disappear from
        // the very screen that is polling for it.
        return Database::instance()->selectOne(
            "SELECT r.*, t.first_name, t.last_name, t.employee_number,
                    COALESCE(CONCAT(t.first_name, ' ', t.last_name), r.subject_label) AS display_name,
                    d.device_id, c.room_number
               FROM fingerprint_enrollment_requests r
          LEFT JOIN teachers t ON t.teacher_id = r.teacher_id
               JOIN devices  d ON d.id = r.device_row_id
          LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE r.request_id = :id",
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
        $db  = Database::instance();
        $now = Clock::nowString();

        $expired = $db->execute(
            "UPDATE fingerprint_enrollment_requests
                SET status = 'expired',
                    message = 'Timed out — the terminal did not complete the scan.',
                    completed_at = :now,
                    updated_at = :now
              WHERE status IN ('pending','scanning')
                AND expires_at < :now",
            ['now' => $now]
        );

        // A capture that completed but was never attached to a teacher has a
        // template sitting in the sensor's flash. Marking it expired would leave
        // that print in place occupying a slot nothing owns; 'abandoned' puts it
        // on the terminal's delete list instead.
        $abandoned = $db->execute(
            "UPDATE fingerprint_enrollment_requests
                SET status = 'abandoned',
                    message = 'Registration was not completed; the template is being removed from the sensor.',
                    updated_at = :now
              WHERE status = 'completed'
                AND teacher_id IS NULL
                AND bound_at IS NULL
                AND expires_at < :now",
            ['now' => $now]
        );

        return $expired + $abandoned;
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
