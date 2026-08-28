<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use SensitiveParameter;
use Throwable;

/**
 * Getting one enrolled finger onto every terminal a teacher teaches at.
 *
 * The sensor is the only thing that can match a finger, and it can only match
 * templates held in its own flash. So a teacher enrolled in Room 101 was a
 * stranger to the reader in Room 102 — nothing was wrong, there was simply
 * nothing there to match against.
 *
 * The fix is to move the template. At enrolment the terminal reads the
 * template back out of its sensor and uploads it; the terminals in the rooms
 * that teacher is timetabled into pull what they are missing and write it into
 * their own flash. From then on the same finger opens a session in any of them.
 *
 * Those rooms and no others — see scopeCondition(). A terminal hangs on a
 * corridor wall with its credentials in flash, and one carried away should be
 * worth the few teachers who work in that room rather than the whole staff.
 *
 * The cost is stated where it belongs — in migration 018 and in
 * docs/SECURITY.md — and it is real: this database now holds biometric
 * templates, which it deliberately did not before. They are encrypted with the
 * application key, so a stolen dump alone is not enough; a host compromise
 * that takes both the dump and APP_KEY is. Nothing here pretends otherwise.
 *
 * A template is the sensor's own feature vector, not an image, and cannot be
 * turned back into a fingerprint picture. That limits the harm. It is not a
 * reason to treat the column casually, which is why it is encrypted, never
 * logged, never returned to the browser, and only ever sent to a terminal that
 * has authenticated and signed its request.
 */
final class FingerprintSyncService
{
    /**
     * How many templates one sensor is assumed to hold.
     *
     * The common AS608 is 127; modules sold under the same name ship with 162
     * and 1000. The terminal reads its real capacity at boot and refuses a
     * slot beyond it, so this is only the server's allocation ceiling — set
     * FINGERPRINT_SENSOR_CAPACITY if your sensors hold more and you have more
     * teachers than this.
     */
    private static function capacity(): int
    {
        return max(1, (int) Config::get('security.fingerprint.sensor_capacity', 127));
    }

    /**
     * Record the template bytes captured during enrolment.
     *
     * Called from the enrolment completion path with what the sensor handed
     * back. A missing or malformed upload is not fatal — the enrolment itself
     * succeeded and that terminal works — so this records what it can and
     * leaves the teacher un-synced rather than failing the enrolment they just
     * stood through.
     *
     * @return bool whether the template was stored and can be synced onward
     */
    public static function captureTemplate(
        int $fingerprintId,
        int $deviceRowId,
        int $slot,
        #[SensitiveParameter] string $base64Template
    ): bool {
        $raw = base64_decode($base64Template, true);

        // A template is a fixed-size structure — 512 bytes on the AS608 family,
        // sometimes 768. Anything tiny is a truncated transfer, and writing a
        // truncated template to another sensor produces a finger that enrols
        // cleanly and never matches, which is the worst failure available here
        // because it looks like the teacher's finger is at fault.
        if ($raw === false || strlen($raw) < 256 || strlen($raw) > 2048) {
            Logger::warning('Fingerprint template upload rejected', [
                'fingerprint_id' => $fingerprintId,
                'device_row_id'  => $deviceRowId,
                'bytes'          => $raw === false ? 0 : strlen($raw),
            ]);

            return false;
        }

        $db = Database::instance();

        $db->update('fingerprint_templates', [
            'template_data'        => Crypto::encrypt($raw),
            'template_bytes'       => strlen($raw),
            'template_captured_at' => Clock::nowString(),
            'updated_at'           => Clock::nowString(),
        ], ['fingerprint_id' => $fingerprintId]);

        // The terminal that did the enrolling already holds it, by definition.
        self::recordSlot($fingerprintId, $deviceRowId, $slot, 'enrolled', 'present');

        // Every other active terminal now owes this teacher a copy.
        self::queueForOtherTerminals($fingerprintId, $deviceRowId);

        return true;
    }

    /**
     * Queue this template onto every terminal whose room this teacher teaches in.
     *
     * Queued, not pushed: the server never opens a connection to a terminal.
     * Terminals poll, which is the same shape as enrolment and card issuance,
     * and it means a terminal that is switched off simply collects its backlog
     * when it comes back rather than needing a retry mechanism of its own.
     *
     * Not every terminal — only the rooms the teacher is timetabled into. See
     * scopeCondition() for why that is the right set and not merely a smaller
     * one.
     */
    public static function queueForOtherTerminals(int $fingerprintId, ?int $exceptDeviceRowId = null): int
    {
        $db = Database::instance();

        $devices = $db->select(
            "SELECT d.id
               FROM devices d
               JOIN fingerprint_templates fp ON fp.fingerprint_id = :fp
              WHERE d.deleted_at IS NULL
                AND d.status NOT IN ('decommissioned','disabled')
                AND d.classroom_id IS NOT NULL
                AND d.enrollment_station = 0
                AND (:except IS NULL OR d.id <> :except2)
                AND " . self::scopeCondition('fp.teacher_id', 'd.classroom_id'),
            ['fp' => $fingerprintId, 'except' => $exceptDeviceRowId, 'except2' => $exceptDeviceRowId]
        );

        $queued = 0;

        foreach ($devices as $device) {
            $slot = self::allocateSlot((int) $device['id'], $fingerprintId);

            if ($slot === null) {
                continue;
            }

            if (self::recordSlot($fingerprintId, (int) $device['id'], $slot, 'synced', 'pending')) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * The rule deciding which sensors may hold a given teacher's finger.
     *
     * A session opens only for a lesson on that terminal's own timetable —
     * ScheduleService::activeForDevice() joins devices.classroom_id to
     * schedules.classroom_id, so a teacher with no schedule in a room can never
     * open a class there however well the reader knows them. A template sent to
     * such a terminal is therefore one that can never be used, and every one of
     * them is biometric data sitting in a box screwed to a corridor wall where
     * anybody can unscrew it.
     *
     * Sending only what a room can use makes a stolen terminal worth the four
     * teachers timetabled into that room rather than the whole staff. The cost
     * is that a substitute, or a class moved at short notice, is not known to
     * that reader until the schedule says so — the timetable becomes the thing
     * that grants access, which is what it already was for opening a session.
     *
     * Returned as SQL rather than a method call because every caller needs it
     * inside a larger query, and a version of this rule that drifted out of step
     * with the others would hand out templates nobody meant to send.
     */
    private static function scopeCondition(string $teacherColumn, string $classroomColumn): string
    {
        return "EXISTS (
                    SELECT 1 FROM schedules sch
                     WHERE sch.teacher_id   = {$teacherColumn}
                       AND sch.classroom_id = {$classroomColumn}
                       AND sch.status = 'active'
                       AND sch.deleted_at IS NULL
                )";
    }

    /**
     * The lowest slot on this sensor that nothing else claims.
     *
     * Returns the teacher's existing slot if they already have one here, so
     * re-queueing after a failure reuses the same number instead of leaking a
     * slot on every retry — the exact bug that made every re-enrolment orphan
     * a template earlier in this project.
     */
    public static function allocateSlot(int $deviceRowId, int $fingerprintId): ?int
    {
        $db = Database::instance();

        $existing = $db->scalar(
            'SELECT sensor_template_id FROM fingerprint_slots
              WHERE device_row_id = :device AND fingerprint_id = :fp',
            ['device' => $deviceRowId, 'fp' => $fingerprintId]
        );

        if ($existing !== null) {
            return (int) $existing;
        }

        $taken = $db->select(
            'SELECT sensor_template_id FROM fingerprint_slots
              WHERE device_row_id = :device ORDER BY sensor_template_id',
            ['device' => $deviceRowId]
        );

        $used = array_map(static fn (array $r): int => (int) $r['sensor_template_id'], $taken);
        $max  = self::capacity();

        for ($slot = 1; $slot <= $max; $slot++) {
            if (!in_array($slot, $used, true)) {
                return $slot;
            }
        }

        return null;
    }

    /** @return bool true when a row was created or revived */
    private static function recordSlot(
        int $fingerprintId,
        int $deviceRowId,
        int $slot,
        string $source,
        string $status
    ): bool {
        $db = Database::instance();

        $existing = $db->selectOne(
            'SELECT slot_row_id, status FROM fingerprint_slots
              WHERE device_row_id = :device AND fingerprint_id = :fp',
            ['device' => $deviceRowId, 'fp' => $fingerprintId]
        );

        if ($existing !== null) {
            // Already present and confirmed: leave it alone rather than
            // sending the terminal round again for a template it holds.
            if ((string) $existing['status'] === 'present' && $status !== 'present') {
                return false;
            }

            $db->update('fingerprint_slots', [
                'sensor_template_id' => $slot,
                'source'             => $source,
                'status'             => $status,
                'last_error'         => null,
                'synced_at'          => $status === 'present' ? Clock::nowString() : null,
                'updated_at'         => Clock::nowString(),
            ], ['slot_row_id' => (int) $existing['slot_row_id']]);

            return true;
        }

        $db->insert('fingerprint_slots', [
            'fingerprint_id'     => $fingerprintId,
            'device_row_id'      => $deviceRowId,
            'sensor_template_id' => $slot,
            'source'             => $source,
            'status'             => $status,
            'synced_at'          => $status === 'present' ? Clock::nowString() : null,
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);

        return true;
    }

    /**
     * Give one terminal the templates its own timetable calls for.
     *
     * queueForOtherTerminals() runs at the moment a teacher enrols, and it can
     * only queue onto the terminals that exist *then*. A terminal registered
     * afterwards was therefore born empty and stayed empty: every teacher
     * already enrolled was invisible to it, forever, and the only symptom was
     * a reader in the new room answering NOT RECOGNISED to a finger that
     * worked perfectly well down the corridor. Adding the second terminal in a
     * building is the ordinary case, not an edge one, so the backlog has to be
     * computed rather than remembered.
     *
     * Called when a terminal is registered and again whenever one asks for
     * work and there is none — so a terminal that arrived by any route at all,
     * including one restored from a backup or re-registered after a swap,
     * repairs itself on its next poll without anybody knowing to intervene.
     *
     * Teachers whose template_data is NULL are skipped and cannot be helped
     * here: they enrolled before the template was ever stored, the bytes exist
     * only in the sensor that captured them, and they need one more enrolment
     * before any of this can reach them. The Fingerprints page names them.
     *
     * @return int how many templates were queued
     */
    public static function reconcile(int $deviceRowId): int
    {
        $db = Database::instance();

        $device = $db->selectOne(
            "SELECT id, classroom_id FROM devices
              WHERE id = :id
                AND deleted_at IS NULL
                AND status NOT IN ('decommissioned','disabled')
                AND classroom_id IS NOT NULL
                AND enrollment_station = 0",
            ['id' => $deviceRowId]
        );

        // A desk-side enrolment scanner never verifies anybody, and a terminal
        // with no classroom can hold no session, so neither has any use for a
        // sensor full of other people's fingers.
        if ($device === null) {
            return 0;
        }

        // A timetable edit can take a teacher out of this room between the
        // template being queued and the terminal collecting it. Dropping the
        // queued row is not the same as deleting from a sensor — nothing was
        // written yet — so this is simply declining to send something we have
        // since decided not to send. Rows already written stay; withdrawing
        // those needs the sensor's cooperation and is a separate decision.
        $db->execute(
            "DELETE s FROM fingerprint_slots s
               JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
              WHERE s.device_row_id = :device
                AND s.status = 'pending'
                AND NOT " . self::scopeCondition('fp.teacher_id', ':classroom'),
            ['device' => $deviceRowId, 'classroom' => (int) $device['classroom_id']]
        );

        $missing = $db->select(
            "SELECT fp.fingerprint_id
               FROM fingerprint_templates fp
               JOIN teachers t ON t.teacher_id = fp.teacher_id
              WHERE fp.template_data IS NOT NULL
                AND fp.status = 'active'
                AND t.status = 'active'
                AND t.deleted_at IS NULL
                AND " . self::scopeCondition('fp.teacher_id', ':classroom') . "
                AND NOT EXISTS (
                    SELECT 1 FROM fingerprint_slots s
                     WHERE s.device_row_id = :device
                       AND s.fingerprint_id = fp.fingerprint_id
                )
              ORDER BY fp.fingerprint_id",
            ['device' => $deviceRowId, 'classroom' => (int) $device['classroom_id']]
        );

        $queued = 0;

        foreach ($missing as $row) {
            $fingerprintId = (int) $row['fingerprint_id'];
            $slot          = self::allocateSlot($deviceRowId, $fingerprintId);

            if ($slot === null) {
                // The sensor is full. Say so once, rather than silently
                // enrolling fewer teachers than the room has.
                Logger::warning('Fingerprint sync: sensor capacity reached', [
                    'device_row_id' => $deviceRowId,
                    'capacity'      => self::capacity(),
                ]);

                break;
            }

            if (self::recordSlot($fingerprintId, $deviceRowId, $slot, 'synced', 'pending')) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * What this terminal is missing, oldest first.
     *
     * One at a time. Writing a template is a multi-packet UART transfer that
     * blocks the terminal's loop, and a terminal that disappears for the
     * length of a twenty-template backlog is a terminal that misses taps. One
     * per poll costs two seconds each and keeps the reader responsive
     * throughout.
     *
     * @return array{fingerprint_id:int,slot:int,teacher:string,template:string}|null
     */
    public static function nextPendingFor(int $deviceRowId): ?array
    {
        $row = self::pendingRow($deviceRowId);

        // Nothing queued is the moment to ask whether anything *should* be.
        // Putting it here rather than only at registration is what makes the
        // backlog self-repairing: a terminal added after the teachers were
        // enrolled asks for work, is told there is none, and the act of
        // answering discovers the eight templates it never received. The
        // reconciliation runs only when the queue is empty, so a terminal that
        // is up to date pays one indexed query per poll and nothing more.
        if ($row === null && self::reconcile($deviceRowId) > 0) {
            $row = self::pendingRow($deviceRowId);
        }

        if ($row === null) {
            return null;
        }

        try {
            $plain = Crypto::decrypt((string) $row['template_data']);
        } catch (Throwable $e) {
            // A template that will not decrypt is not something a terminal can
            // be asked to retry — the key changed, or the row was tampered
            // with. Fail the slot so it stops being offered every two seconds.
            self::markFailed($deviceRowId, (int) $row['sensor_template_id'], 'Template could not be decrypted.');

            return null;
        }

        return [
            'fingerprint_id' => (int) $row['fingerprint_id'],
            'slot'           => (int) $row['sensor_template_id'],
            'teacher'        => (string) $row['teacher_name'],
            'template'       => base64_encode($plain),
            'bytes'          => (int) $row['template_bytes'],
        ];
    }

    /**
     * The scope test is repeated here, and deliberately so.
     *
     * reconcile() drops queued rows that have fallen out of scope, but it is
     * housekeeping and this is the door. A template leaves the server here or
     * nowhere, so the rule that decides whether it may is checked at the point
     * of handing it over — not on the strength of a row written earlier under
     * conditions that have since changed.
     *
     * @return array<string,mixed>|null
     */
    private static function pendingRow(int $deviceRowId): ?array
    {
        return Database::instance()->selectOne(
            "SELECT s.fingerprint_id, s.sensor_template_id, fp.template_data, fp.template_bytes,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM fingerprint_slots s
               JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
               JOIN teachers t               ON t.teacher_id = fp.teacher_id
               JOIN devices d                ON d.id = s.device_row_id
              WHERE s.device_row_id = :device
                AND s.status = 'pending'
                AND fp.template_data IS NOT NULL
                AND fp.status = 'active'
                AND t.status = 'active'
                AND t.deleted_at IS NULL
                AND " . self::scopeCondition('fp.teacher_id', 'd.classroom_id') . "
              ORDER BY s.created_at
              LIMIT 1",
            ['device' => $deviceRowId]
        );
    }

    public static function markStored(int $deviceRowId, int $slot): void
    {
        Database::instance()->execute(
            "UPDATE fingerprint_slots
                SET status = 'present', last_error = NULL, synced_at = :now, updated_at = :now2
              WHERE device_row_id = :device AND sensor_template_id = :slot",
            ['now' => Clock::nowString(), 'now2' => Clock::nowString(),
             'device' => $deviceRowId, 'slot' => $slot]
        );
    }

    public static function markFailed(int $deviceRowId, int $slot, string $reason): void
    {
        Database::instance()->execute(
            "UPDATE fingerprint_slots
                SET status = 'failed', last_error = :reason, updated_at = :now
              WHERE device_row_id = :device AND sensor_template_id = :slot",
            ['reason' => mb_substr($reason, 0, 255), 'now' => Clock::nowString(),
             'device' => $deviceRowId, 'slot' => $slot]
        );
    }

    /**
     * How far each terminal is from holding every template.
     *
     * Read by the Fingerprints page, so an administrator can see that Room 102
     * is four templates behind rather than discovering it when a teacher
     * cannot open their class.
     *
     * Enrolment scanners are left out: they verify nobody, so "0 of 8" against
     * one would be a shortfall that never mattered and never cleared.
     *
     * @return list<array<string,mixed>>
     */
    public static function terminalStatus(): array
    {
        // Only slots for templates that can actually be distributed are
        // counted. Migration 018 backfilled a slot row for every enrolment that
        // already existed, including the ones whose bytes were never captured;
        // counting those made a terminal read "2 of 0", which is not a
        // shortfall or a surplus but a sentence that means nothing. Those
        // teachers are reported separately, by awaitingRecapture(), where the
        // action is re-enrolment rather than waiting for a sync.
        //
        // `expected` is per room, not per school: a terminal is owed the
        // teachers its own timetable names and nobody else, so "2 of 2" in a
        // room used by two teachers is complete even while the school has
        // forty. `stale` is the other side of that — templates the sensor still
        // holds for teachers no longer timetabled there. Nothing removes those
        // automatically, so the page has to say they are there.
        $rows = Database::instance()->select(
            "SELECT d.id AS device_row_id, d.device_id, d.status AS device_status,
                    d.claim_status, c.room_number,
                    COALESCE(SUM(s.status = 'present' AND s.in_scope), 0) AS present,
                    COALESCE(SUM(s.status = 'pending' AND s.in_scope), 0) AS pending,
                    COALESCE(SUM(s.status = 'failed'  AND s.in_scope), 0) AS failed,
                    COALESCE(SUM(s.status = 'present' AND NOT s.in_scope), 0) AS stale,
                    (
                      SELECT COUNT(*)
                        FROM fingerprint_templates fpx
                        JOIN teachers tx ON tx.teacher_id = fpx.teacher_id
                       WHERE fpx.template_data IS NOT NULL
                         AND fpx.status = 'active'
                         AND tx.status = 'active'
                         AND tx.deleted_at IS NULL
                         AND " . self::scopeCondition('fpx.teacher_id', 'd.classroom_id') . "
                    ) AS expected,
                    d.sensor_template_count
               FROM devices d
          LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
          LEFT JOIN (
                     SELECT s.device_row_id, s.status,
                            " . self::scopeCondition('fp.teacher_id', 'dv.classroom_id') . " AS in_scope
                       FROM fingerprint_slots s
                       JOIN devices dv               ON dv.id = s.device_row_id
                       JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
                       JOIN teachers t               ON t.teacher_id = fp.teacher_id
                      WHERE fp.template_data IS NOT NULL
                        AND fp.status = 'active'
                        AND t.status = 'active'
                        AND t.deleted_at IS NULL
                    ) s ON s.device_row_id = d.id
              WHERE d.deleted_at IS NULL
                AND d.status NOT IN ('decommissioned','disabled')
                AND d.enrollment_station = 0
                AND d.classroom_id IS NOT NULL
              GROUP BY d.id, d.device_id, d.status, d.claim_status, c.room_number,
                       d.classroom_id, d.sensor_template_count
              ORDER BY c.room_number, d.device_id"
        );

        foreach ($rows as $index => $row) {
            $expected = (int) $row['expected'];
            $present  = (int) $row['present'];

            // Anything neither present nor queued is a template this terminal
            // has no row for at all — the state a terminal added after the
            // enrolments sits in until its next poll reconciles it.
            $rows[$index]['missing']  = max(0, $expected - $present - (int) $row['pending'] - (int) $row['failed']);
            // A room nobody is timetabled into needs nothing, and is complete
            // by having nothing — not stuck at zero.
            $rows[$index]['complete'] = $present >= $expected;
        }

        return $rows;
    }

    /** How many enrolments are in a state where they can be copied at all. */
    public static function syncableCount(): int
    {
        return (int) Database::instance()->scalar(
            "SELECT COUNT(*)
               FROM fingerprint_templates fp
               JOIN teachers t ON t.teacher_id = fp.teacher_id
              WHERE fp.template_data IS NOT NULL
                AND fp.status = 'active'
                AND t.status = 'active'
                AND t.deleted_at IS NULL"
        );
    }

    /**
     * Teachers whose enrolment predates template storage.
     *
     * Their bytes live only in the sensor that captured them and cannot be
     * recovered from a slot number, so no amount of syncing will reach them —
     * they work on the terminal they enrolled at and nowhere else until
     * somebody enrols them once more. Migration 018 promised this page would
     * name them; this is the query that lets it.
     *
     * @return list<array<string,mixed>>
     */
    public static function awaitingRecapture(): array
    {
        return Database::instance()->select(
            "SELECT fp.fingerprint_id, t.teacher_id, t.employee_number,
                    t.first_name, t.last_name, d.device_id AS enrolled_on,
                    c.room_number
               FROM fingerprint_templates fp
               JOIN teachers t         ON t.teacher_id = fp.teacher_id
          LEFT JOIN devices d          ON d.id = fp.enrolled_device_row_id
          LEFT JOIN classrooms c       ON c.classroom_id = d.classroom_id
              WHERE fp.template_data IS NULL
                AND fp.status = 'active'
                AND t.status = 'active'
                AND t.deleted_at IS NULL
              ORDER BY t.last_name, t.first_name"
        );
    }
}
