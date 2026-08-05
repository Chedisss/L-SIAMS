<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\DeviceService;
use App\Services\ScheduleService;

/**
 * Device-facing endpoints (Part 4).
 *
 * Everything except /claim reaches here only after DeviceAuthMiddleware has
 * verified the device, its key, the signature, the timestamp and the nonce.
 * The authenticated device row is on Auth::device().
 */
final class DeviceApiController extends Controller
{
    /**
     * Post-boot handshake: confirms identity and hands back the clock and the
     * schedules this terminal may open today.
     */
    public function authenticate(Request $request): Response
    {
        $device = Auth::device();

        Database::instance()->insert('device_logs', [
            'device_row_id'  => (int) $device['id'],
            'device_id_text' => (string) $device['device_id'],
            'event'          => 'auth_success',
            'severity'       => 'info',
            'message'        => 'Device authenticated after boot.',
            'ip_address'     => $request->ip(),
            'created_at'     => Clock::nowString(),
        ]);

        $schedules = $device['classroom_id'] === null
            ? []
            : ScheduleService::activeForDevice((int) $device['id']);

        return $this->json([
            'device_id'     => (string) $device['device_id'],
            'device_name'   => (string) $device['device_name'],
            'device_role'   => (string) $device['device_role'],
            'classroom_id'  => $device['classroom_id'] === null ? null : (int) $device['classroom_id'],
            // Server time is authoritative; the firmware sets its RTC from this.
            'server_time'   => Clock::atom(),
            'server_epoch'  => Clock::timestamp(),
            'timezone'      => (string) $device['timezone'],
            'heartbeat_interval_sec' => (int) $device['heartbeat_interval_sec'],
            'sync_interval_sec'      => (int) $device['sync_interval_sec'],
            'offline_queue_limit'    => (int) $device['offline_queue_limit'],
            'schedules'     => array_map(static fn (array $s): array => [
                'schedule_id'  => (int) $s['schedule_id'],
                'teacher_id'   => (int) $s['teacher_id'],
                'teacher_name' => (string) $s['teacher_name'],
                'subject_code' => (string) $s['subject_code'],
                'section_code' => (string) $s['section_code'],
                'start_time'   => substr((string) $s['start_time'], 0, 5),
                'end_time'     => substr((string) $s['end_time'], 0, 5),
            ], $schedules),
            'active_session' => $this->activeSession((int) $device['id']),
        ], 'Device authenticated.');
    }

    /** Every 30 seconds (± jitter). Returns the device's marching orders. */
    public function heartbeat(Request $request): Response
    {
        $device = Auth::device();

        $result = DeviceService::heartbeat($device, [
            'firmware'    => $request->string('firmware', (string) $device['firmware_version']),
            'wifi_signal' => $request->has('wifi_signal') ? $request->int('wifi_signal') : null,
            'queue'       => $request->int('queue', $request->int('queue_count', 0)),
            'uptime'      => $request->int('uptime', 0),
            'battery'     => $request->has('battery') ? $request->int('battery') : null,
            'free_heap'   => $request->has('free_heap') ? $request->int('free_heap') : null,
        ]);

        return $this->json($result, 'Heartbeat received.');
    }

    /**
     * First-boot credential activation.
     *
     * The only device endpoint that runs without an active session, because the
     * device has not been activated yet — it authenticates with its single-use
     * claim token instead.
     */
    public function claim(Request $request): Response
    {
        $data = $this->validate($request, [
            'claim_token' => 'required|string|max:64|slug',
            'device_id'   => 'required|string|max:32|code',
            'mac_address' => 'required|mac',
        ]);

        $result = DeviceService::claim(
            (string) $data['claim_token'],
            (string) $data['device_id'],
            (string) $data['mac_address'],
            $request->ip()
        );

        return $this->json($result, 'Device activated.');
    }

    public function status(Request $request): Response
    {
        $device = Auth::device();

        $row = Database::instance()->selectOne(
            'SELECT * FROM v_device_status WHERE device_row_id = :id',
            ['id' => (int) $device['id']]
        );

        return $this->json([
            'device_id'      => (string) $device['device_id'],
            'health'         => (string) ($row['health'] ?? 'unknown'),
            'server_time'    => Clock::atom(),
            'server_epoch'   => Clock::timestamp(),
            'active_session' => $this->activeSession((int) $device['id']),
            'locked'         => $device['locked_until'] !== null
                && Clock::parse((string) $device['locked_until']) > Clock::now(),
            'queue_depth'    => (int) $device['queue_depth'],
        ]);
    }

    /**
     * Offline queue upload (Part 4 synchronisation rules).
     *
     * Original timestamps are preserved, duplicates are reported rather than
     * re-recorded, and a rejected record comes back with the reason so the
     * firmware can decide whether to drop it or retry.
     */
    public function sync(Request $request): Response
    {
        $device  = Auth::device();
        $records = $request->array('records');

        if ($records === []) {
            return $this->json(['accepted' => 0, 'duplicate' => 0, 'rejected' => []], 'Nothing to synchronise.');
        }

        $limit = (int) Config::get('attendance.device.offline_queue_limit', 500);

        if (count($records) > $limit) {
            return $this->fail(
                'BATCH_TOO_LARGE',
                sprintf('Send at most %d records per batch.', $limit),
                422
            );
        }

        $normalised = [];

        foreach ($records as $record) {
            if (is_array($record)) {
                $normalised[] = $record;
            }
        }

        $result = DeviceService::syncQueue($device, $normalised);

        return $this->json($result, sprintf(
            'Synchronised %d record(s); %d were already recorded, %d rejected.',
            $result['accepted'],
            $result['duplicate'],
            count($result['rejected'])
        ));
    }

    /** Firmware-reported events: boot, restart, WiFi loss, errors. */
    public function log(Request $request): Response
    {
        $device = Auth::device();

        $allowed = [
            'boot', 'shutdown', 'restart', 'wifi_connected', 'wifi_lost', 'sync_started',
            'sync_completed', 'sync_failed', 'error', 'queue_warning', 'queue_full',
            'time_sync', 'firmware_update',
        ];

        $event = $request->string('event', 'error');

        if (!in_array($event, $allowed, true)) {
            return $this->fail('INVALID_EVENT', 'Unrecognised device event.', 422);
        }

        Database::instance()->insert('device_logs', [
            'device_row_id'  => (int) $device['id'],
            'device_id_text' => (string) $device['device_id'],
            'event'          => $event,
            'severity'       => in_array($request->string('severity', 'info'), ['info', 'warning', 'error', 'critical'], true)
                ? $request->string('severity', 'info')
                : 'info',
            'message'        => mb_substr($request->string('message', ''), 0, 500) ?: null,
            'context_json'   => $request->has('context')
                ? (string) json_encode($request->input('context'), JSON_PARTIAL_OUTPUT_ON_ERROR)
                : null,
            'ip_address'     => $request->ip(),
            'created_at'     => Clock::nowString(),
        ]);

        return $this->json([], 'Event logged.');
    }

    /** Clock synchronisation, called at boot and every six hours. */
    public function time(Request $request): Response
    {
        return $this->json([
            'server_time'  => Clock::atom(),
            'server_epoch' => Clock::timestamp(),
            'timezone'     => (string) Config::get('app.timezone'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function activeSession(int $deviceRowId): ?array
    {
        $session = Database::instance()->selectOne(
            "SELECT s.session_code, s.scheduled_start, s.scheduled_end, s.expires_at,
                    sec.section_code, sub.subject_code,
                    sch.late_threshold_minutes, sch.time_in_window_close,
                    sch.time_out_window_open, sch.minimum_dwell_minutes,
                    (SELECT COUNT(*) FROM attendance_records ar
                      WHERE ar.session_id = s.session_id AND ar.time_in IS NOT NULL) AS timed_in,
                    (SELECT COUNT(*) FROM attendance_records ar2
                      WHERE ar2.session_id = s.session_id AND ar2.time_out IS NOT NULL) AS timed_out,
                    s.roster_count
               FROM attendance_sessions s
               JOIN sections sec  ON sec.section_id = s.section_id
               JOIN subjects sub  ON sub.subject_id = s.subject_id
               JOIN schedules sch ON sch.schedule_id = s.schedule_id
              WHERE s.device_row_id = :id AND s.status = 'open'
              LIMIT 1",
            ['id' => $deviceRowId]
        );

        if ($session === null) {
            return null;
        }

        return [
            'session_id'    => (string) $session['session_code'],
            'section'       => (string) $session['section_code'],
            'subject'       => (string) $session['subject_code'],
            'scheduled_end' => Clock::parse((string) $session['scheduled_end'])->format(DATE_ATOM),
            'expires_at'    => Clock::parse((string) $session['expires_at'])->format(DATE_ATOM),
            'timed_in'      => (int) $session['timed_in'],
            'timed_out'     => (int) $session['timed_out'],
            'roster'        => (int) $session['roster_count'],
            'minimum_dwell_minutes' => (int) $session['minimum_dwell_minutes'],
        ];
    }
}
