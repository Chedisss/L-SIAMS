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
 * template back out of its sensor and uploads it; every other terminal pulls
 * what it is missing and writes it into its own flash. From then on the same
 * finger opens a session in any room.
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
     * Queue this template onto every other terminal that does not have it.
     *
     * Queued, not pushed: the server never opens a connection to a terminal.
     * Terminals poll, which is the same shape as enrolment and card issuance,
     * and it means a terminal that is switched off simply collects its backlog
     * when it comes back rather than needing a retry mechanism of its own.
     */
    public static function queueForOtherTerminals(int $fingerprintId, ?int $exceptDeviceRowId = null): int
    {
        $db = Database::instance();

        $devices = $db->select(
            "SELECT id FROM devices
              WHERE deleted_at IS NULL
                AND status NOT IN ('decommissioned','disabled')
                AND classroom_id IS NOT NULL
                AND (:except IS NULL OR id <> :except2)",
            ['except' => $exceptDeviceRowId, 'except2' => $exceptDeviceRowId]
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
        $row = Database::instance()->selectOne(
            "SELECT s.fingerprint_id, s.sensor_template_id, fp.template_data, fp.template_bytes,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM fingerprint_slots s
               JOIN fingerprint_templates fp ON fp.fingerprint_id = s.fingerprint_id
               JOIN teachers t               ON t.teacher_id = fp.teacher_id
              WHERE s.device_row_id = :device
                AND s.status = 'pending'
                AND fp.template_data IS NOT NULL
                AND fp.status = 'active'
                AND t.status = 'active'
                AND t.deleted_at IS NULL
              ORDER BY s.created_at
              LIMIT 1",
            ['device' => $deviceRowId]
        );

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
     * @return list<array<string,mixed>>
     */
    public static function terminalStatus(): array
    {
        return Database::instance()->select(
            "SELECT d.id AS device_row_id, d.device_id, d.status AS device_status,
                    c.room_number,
                    SUM(s.status = 'present') AS present,
                    SUM(s.status = 'pending') AS pending,
                    SUM(s.status = 'failed')  AS failed,
                    d.sensor_template_count
               FROM devices d
          LEFT JOIN classrooms c        ON c.classroom_id = d.classroom_id
          LEFT JOIN fingerprint_slots s ON s.device_row_id = d.id
              WHERE d.deleted_at IS NULL
                AND d.status NOT IN ('decommissioned','disabled')
              GROUP BY d.id, d.device_id, d.status, c.room_number, d.sensor_template_count
              ORDER BY c.room_number, d.device_id"
        );
    }
}
