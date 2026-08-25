<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;

/**
 * The hand-off between the card-issuing screen and a terminal's reader.
 *
 * Issuing a card meant typing its UID off a label, or tapping it at a terminal
 * and fishing it out of the unknown-card log afterwards. The second only works
 * while an attendance session is open — a tap is refused for having no session
 * long before anything looks at the card — so issuing a cohort's worth of cards
 * meant either keeping a class session artificially open or typing every UID.
 *
 * This is the fingerprint enrolment flow applied to the reader: the browser
 * opens a request against a terminal, the terminal picks it up on its next poll
 * and waits for a card with the reader to itself, and the UID comes back from
 * the reader rather than from a keyboard.
 *
 * The captured UID is held on the request rather than written straight into
 * rfid_cards. Reading a card is not an instruction to issue it — the
 * administrator still says which student it belongs to, and RfidService::assign
 * still applies every rule about sections, status and supersession.
 */
final class RfidEnrollmentService
{
    /** Stages the firmware reports, in the order they occur. */
    public const STAGE_WAITING  = 'waiting_for_device';
    public const STAGE_READY    = 'ready';
    public const STAGE_PRESENT  = 'present_card';
    public const STAGE_READING  = 'reading';
    public const STAGE_DONE     = 'done';

    /** @var list<string> */
    private const STAGES = [
        self::STAGE_WAITING, self::STAGE_READY, self::STAGE_PRESENT,
        self::STAGE_READING, self::STAGE_DONE,
    ];

    private static function ttlSeconds(): int
    {
        return (int) Config::get('attendance.rfid.enrollment_ttl_seconds', 180);
    }

    /** How long a captured UID waits for somebody to say whose card it is. */
    private static function holdSeconds(): int
    {
        return (int) Config::get('attendance.rfid.enrollment_hold_seconds', 1800);
    }

    // ------------------------------------------------------------- open ----

    /**
     * Ask a terminal to read the next card presented to it.
     *
     * The student is optional. Issuing usually runs card-first — tap, see who
     * it is, choose the student, tap the next — because a person standing at
     * the reader with a stack of cards should not have to go back to the
     * keyboard between each one.
     *
     * @return array<string,mixed>
     */
    public static function open(int $deviceRowId, int $requestedBy, ?int $studentId = null, string $label = ''): array
    {
        $db = Database::instance();

        self::expireStale();

        $student = null;

        if ($studentId !== null && $studentId > 0) {
            $student = $db->selectOne(
                'SELECT student_id, first_name, last_name, student_number, section_id, status
                   FROM students WHERE student_id = :id AND deleted_at IS NULL',
                ['id' => $studentId]
            );

            if ($student === null) {
                throw new ValidationException(['student_id' => ['Student not found.']]);
            }

            // Refused here as well as in assign(), so the reader is never asked
            // for a card that could not be issued once it arrived.
            if ((string) $student['status'] !== 'active') {
                throw new ValidationException([
                    'student_id' => ['Only active students may be issued a card.'],
                ]);
            }

            if ((int) $student['section_id'] <= 0) {
                throw new ValidationException([
                    'student_id' => [
                        'This student is not assigned to a section, so a card issued to them could '
                        . 'never be used — every tap resolves against the session\'s section.',
                    ],
                ]);
            }

            $label = trim($student['first_name'] . ' ' . $student['last_name']);
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
                    . 'to read anything yet. Flash its provisioning file and power it on first.',
                ],
            ]);
        }

        if (in_array((string) $device['status'], ['disabled', 'decommissioned', 'suspended'], true)) {
            throw new ValidationException([
                'device_row_id' => ['That terminal is ' . $device['status'] . ' and will not answer.'],
            ]);
        }

        $label = $label === '' ? 'Next card' : mb_substr($label, 0, 120);

        return $db->transaction(static function (Database $db) use (
            $deviceRowId, $requestedBy, $studentId, $label, $device
        ): array {
            // One at a time per terminal. Two open requests would race for the
            // card being held against the reader, and whichever was claimed
            // first would take a UID meant for the other.
            $open = $db->selectOne(
                "SELECT request_id, COALESCE(subject_label, 'a card') AS who
                   FROM rfid_enrollment_requests
                  WHERE device_row_id = :device
                    AND status IN ('pending','waiting')
                  ORDER BY request_id DESC LIMIT 1
                    FOR UPDATE",
                ['device' => $deviceRowId]
            );

            if ($open !== null) {
                throw new BusinessRuleException(
                    'CARD_ENROLLMENT_IN_PROGRESS',
                    sprintf('That terminal is already waiting for %s. Wait for it, or cancel it.', $open['who']),
                    ['request_id' => (int) $open['request_id']],
                    409
                );
            }

            $requestId = (int) $db->insert('rfid_enrollment_requests', [
                'student_id'    => $studentId !== null && $studentId > 0 ? $studentId : null,
                'subject_label' => $label,
                'device_row_id' => $deviceRowId,
                'status'        => 'pending',
                'stage'         => self::STAGE_WAITING,
                'message'       => 'Waiting for the terminal to pick this up…',
                'requested_by'  => $requestedBy,
                'expires_at'    => Clock::now()->modify('+' . self::ttlSeconds() . ' seconds')->format('Y-m-d H:i:s'),
                'created_at'    => Clock::nowString(),
                'updated_at'    => Clock::nowString(),
            ]);

            AuditService::log(
                'RFID_ENROLLMENT_REQUESTED',
                'rfid',
                'student',
                $studentId,
                null,
                ['device_id' => (string) $device['device_id'], 'request_id' => $requestId],
                sprintf('Card read requested for %s on terminal %s.', $label, $device['device_id']),
                'success',
                $requestedBy
            );

            return self::find($requestId) ?? [];
        });
    }

    // ---------------------------------------------------------- terminal ----

    /**
     * The terminal asking whether it has anything to read.
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
                "SELECT r.*, s.student_number,
                        COALESCE(CONCAT(s.first_name, ' ', s.last_name), r.subject_label) AS display_name
                   FROM rfid_enrollment_requests r
              LEFT JOIN students s ON s.student_id = r.student_id
                  WHERE r.device_row_id = :device
                    AND r.status IN ('pending','waiting')
                  ORDER BY r.request_id ASC LIMIT 1
                    FOR UPDATE",
                ['device' => $deviceRowId]
            );

            if ($request === null) {
                return null;
            }

            if ((string) $request['status'] === 'pending') {
                $message = 'Terminal ready — hold the card flat against the reader.';

                $db->update('rfid_enrollment_requests', [
                    'status'     => 'waiting',
                    'stage'      => self::STAGE_READY,
                    'message'    => $message,
                    'claimed_at' => Clock::nowString(),
                    'updated_at' => Clock::nowString(),
                ], ['request_id' => (int) $request['request_id']]);

                $request['status']  = 'waiting';
                $request['stage']   = self::STAGE_READY;
                $request['message'] = $message;
            }

            return $request;
        });
    }

    /**
     * A progress ping from the firmware while it waits.
     *
     * @return array<string,mixed>
     */
    public static function progress(int $requestId, int $deviceRowId, string $stage, ?string $message = null): array
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new ValidationException(['stage' => ['Unknown enrolment stage.']]);
        }

        $request = self::findForDevice($requestId, $deviceRowId);

        if (!in_array((string) $request['status'], ['pending', 'waiting'], true)) {
            throw new BusinessRuleException(
                'CARD_ENROLLMENT_CLOSED',
                'This card read is no longer open.',
                ['status' => (string) $request['status']],
                409
            );
        }

        Database::instance()->update('rfid_enrollment_requests', [
            'status'     => 'waiting',
            'stage'      => $stage,
            'message'    => $message ?? self::stageMessage($stage),
            'updated_at' => Clock::nowString(),
            // Each step the terminal reports is evidence somebody is standing
            // there working, so the window moves with them.
            'expires_at' => Clock::now()->modify('+' . self::ttlSeconds() . ' seconds')->format('Y-m-d H:i:s'),
        ], ['request_id' => $requestId]);

        return self::find($requestId) ?? [];
    }

    /**
     * The terminal reporting the UID it read.
     *
     * The card is checked against what is already issued before the browser
     * ever sees it, because "this card already belongs to Maria Reyes" is the
     * answer somebody standing at the reader needs immediately, not after they
     * have chosen a student and pressed save.
     *
     * @return array<string,mixed>
     */
    public static function capture(int $requestId, int $deviceRowId, string $cardUid): array
    {
        $cardUid = RfidService::normalise($cardUid);
        RfidService::assertValidUid($cardUid);

        $db      = Database::instance();
        $request = self::findForDevice($requestId, $deviceRowId);

        if (!in_array((string) $request['status'], ['pending', 'waiting'], true)) {
            throw new BusinessRuleException(
                'CARD_ENROLLMENT_CLOSED',
                'This card read is no longer open.',
                ['status' => (string) $request['status']],
                409
            );
        }

        $holder = $db->selectOne(
            "SELECT rc.status, rc.card_uid, s.student_id, s.student_number,
                    CONCAT(s.first_name, ' ', s.last_name) AS student_name
               FROM rfid_cards rc
          LEFT JOIN students s ON s.student_id = rc.student_id
              WHERE rc.card_uid = :uid",
            ['uid' => $cardUid]
        );

        $message = match (true) {
            $holder === null => 'Card read. Check it against the student and confirm.',
            (string) $holder['status'] === 'blacklisted'
                => 'This card is blacklisted and cannot be issued.',
            $holder['student_id'] !== null && (string) $holder['status'] === 'active'
                => sprintf(
                    'This card is already active for %s (%s). Issuing it again would move it.',
                    $holder['student_name'],
                    $holder['student_number']
                ),
            default => 'This card was issued before and is no longer active. It can be reissued.',
        };

        $db->update('rfid_enrollment_requests', [
            'status'      => 'captured',
            'stage'       => self::STAGE_DONE,
            'card_uid'    => $cardUid,
            'message'     => $message,
            'captured_at' => Clock::nowString(),
            'updated_at'  => Clock::nowString(),
            // From here the window is the holding window: the card has been
            // read and is waiting for a person, not for a reader.
            'expires_at'  => Clock::now()->modify('+' . self::holdSeconds() . ' seconds')->format('Y-m-d H:i:s'),
        ], ['request_id' => $requestId]);

        return self::find($requestId) ?? [];
    }

    /** The terminal reporting that no card arrived, or that the read failed. */
    public static function fail(int $requestId, int $deviceRowId, string $reason): array
    {
        $request = self::findForDevice($requestId, $deviceRowId);

        if (in_array((string) $request['status'], ['assigned', 'cancelled', 'expired'], true)) {
            return $request;
        }

        Database::instance()->update('rfid_enrollment_requests', [
            'status'     => 'failed',
            'message'    => mb_substr($reason, 0, 255),
            'updated_at' => Clock::nowString(),
        ], ['request_id' => $requestId]);

        return self::find($requestId) ?? [];
    }

    // ----------------------------------------------------------- browser ----

    /**
     * Issue the captured card to a student.
     *
     * Routed through RfidService::assign() rather than writing rfid_cards here,
     * so a card issued this way obeys the same rules as one typed in: one
     * active card per student, the previous one superseded rather than lost,
     * and the whole issuance history preserved.
     *
     * @return array{request:array<string,mixed>,rfid_id:int}
     */
    public static function assignCaptured(
        int $requestId,
        int $studentId,
        int $userId,
        ?string $notes = null,
        ?string $replacementReason = null,
        ?string $replacementNote = null
    ): array {
        $db      = Database::instance();
        $request = self::find($requestId);

        if ($request === null) {
            throw new ValidationException(['request_id' => ['Card read not found.']]);
        }

        if ((string) $request['status'] !== 'captured') {
            throw new BusinessRuleException(
                'CARD_NOT_CAPTURED',
                'No card has been read for this request yet.',
                ['status' => (string) $request['status']],
                409
            );
        }

        $cardUid = (string) $request['card_uid'];

        $rfidId = RfidService::assign(
            $studentId, $cardUid, $userId, $notes, $replacementReason, $replacementNote
        );

        $db->update('rfid_enrollment_requests', [
            'status'      => 'assigned',
            'student_id'  => $studentId,
            'assigned_at' => Clock::nowString(),
            'message'     => 'Card issued.',
            'updated_at'  => Clock::nowString(),
        ], ['request_id' => $requestId]);

        return ['request' => self::find($requestId) ?? [], 'rfid_id' => $rfidId];
    }

    /** Give up on a request, whether or not a card was read. */
    public static function cancel(int $requestId, int $userId): array
    {
        $request = self::find($requestId);

        if ($request === null) {
            throw new ValidationException(['request_id' => ['Card read not found.']]);
        }

        if (in_array((string) $request['status'], ['assigned', 'cancelled', 'expired'], true)) {
            return $request;
        }

        Database::instance()->update('rfid_enrollment_requests', [
            'status'     => 'cancelled',
            'message'    => 'Cancelled by the administrator.',
            'updated_at' => Clock::nowString(),
        ], ['request_id' => $requestId]);

        AuditService::log(
            'RFID_ENROLLMENT_CANCELLED',
            'rfid',
            'student',
            $request['student_id'] === null ? null : (int) $request['student_id'],
            null,
            ['request_id' => $requestId],
            'Card read cancelled.',
            'success',
            $userId
        );

        return self::find($requestId) ?? [];
    }

    // ------------------------------------------------------------ shared ----

    /** Terminals that can be asked to read a card. */
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
        return Database::instance()->selectOne(
            "SELECT r.*, s.first_name, s.last_name, s.student_number,
                    COALESCE(CONCAT(s.first_name, ' ', s.last_name), r.subject_label) AS display_name,
                    d.device_id, c.room_number
               FROM rfid_enrollment_requests r
          LEFT JOIN students   s ON s.student_id = r.student_id
               JOIN devices    d ON d.id = r.device_row_id
          LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE r.request_id = :id",
            ['id' => $requestId]
        );
    }

    /** @return array<string,mixed> */
    private static function findForDevice(int $requestId, int $deviceRowId): array
    {
        $request = self::find($requestId);

        // A terminal may only speak about a request addressed to itself. The
        // device chain has already proved which terminal is asking.
        if ($request === null || (int) $request['device_row_id'] !== $deviceRowId) {
            throw new ValidationException(['request_id' => ['That card read does not belong to this terminal.']]);
        }

        return $request;
    }

    /**
     * Close requests nobody is coming back to.
     *
     * Two different windows: one waiting for a reader, one waiting for a
     * person. A request still waiting for a card expires quickly, because it
     * is holding the terminal. One that already has a UID waits much longer,
     * because losing it would mean walking back to the reader with the card.
     */
    public static function expireStale(): void
    {
        Database::instance()->execute(
            "UPDATE rfid_enrollment_requests
                SET status  = 'expired',
                    message = CASE WHEN status = 'captured'
                                   THEN 'The card was read but never issued to a student.'
                                   ELSE 'No card was presented in time.' END,
                    updated_at = :now
              WHERE status IN ('pending','waiting','captured')
                AND expires_at < :now",
            ['now' => Clock::nowString()]
        );
    }

    private static function stageMessage(string $stage): string
    {
        return match ($stage) {
            self::STAGE_READY   => 'Terminal ready — hold the card flat against the reader.',
            self::STAGE_PRESENT => 'Waiting for a card…',
            self::STAGE_READING => 'Reading the card…',
            self::STAGE_DONE    => 'Card read.',
            default             => 'Waiting for the terminal to pick this up…',
        };
    }
}
