<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Realtime;
use App\Core\Site;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;

/**
 * ESP32 terminal registration, provisioning, heartbeat and offline sync
 * (Parts 4 and 19.3).
 */
final class DeviceService
{
    /**
     * Register a terminal and mint its credentials.
     *
     * The plaintext key material returned here is displayed exactly once and is
     * then unrecoverable — the caller must hand it to the administrator in the
     * same response or lose it.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function register(array $data, int $userId): array
    {
        $db  = Database::instance();
        $mac = NetworkService::normaliseMac((string) ($data['mac_address'] ?? ''));

        if (!NetworkService::isValidMac($mac)) {
            throw new ValidationException(['mac_address' => ['Enter a MAC address such as AA:BB:CC:DD:EE:FF.']]);
        }

        $deviceId = trim((string) ($data['device_id'] ?? '')) !== ''
            ? strtoupper(trim((string) $data['device_id']))
            : self::nextDeviceId();

        if (preg_match('/^[A-Z0-9-]{3,32}$/', $deviceId) !== 1) {
            throw new ValidationException(['device_id' => ['Device ID may contain uppercase letters, digits and hyphens only.']]);
        }

        // Uniqueness is checked against every device including decommissioned
        // ones, so a retired identifier can never be silently reused.
        if ($db->scalar('SELECT 1 FROM devices WHERE device_id = :id LIMIT 1', ['id' => $deviceId]) !== null) {
            throw new ValidationException(['device_id' => ['This Device ID is already registered.']]);
        }

        $existingMac = $db->selectOne('SELECT id, device_id, status FROM devices WHERE mac_address = :mac', ['mac' => $mac]);

        if ($existingMac !== null) {
            if (in_array((string) $existingMac['status'], ['decommissioned', 'disabled'], true)
                && (bool) ($data['confirm_mac_reuse'] ?? false)
            ) {
                AuditService::log(
                    AuditService::DEVICE_MAC_REUSE,
                    'devices',
                    'device',
                    (int) $existingMac['id'],
                    ['previous_device_id' => $existingMac['device_id']],
                    ['new_device_id' => $deviceId],
                    'MAC address reused from a retired device after administrator confirmation.'
                );

                // Free the unique index by clearing the retired row's MAC.
                $db->update('devices', ['mac_address' => 'RETIRED-' . substr(Crypto::randomHex(6), 0, 11)], ['id' => (int) $existingMac['id']]);
            } else {
                throw new ValidationException(['mac_address' => [sprintf(
                    'This MAC address is already registered to device %s (%s).%s',
                    $existingMac['device_id'],
                    $existingMac['status'],
                    in_array((string) $existingMac['status'], ['decommissioned', 'disabled'], true)
                        ? ' Confirm MAC reuse to continue.'
                        : ''
                )]]);
            }
        }

        $classroomId = isset($data['classroom_id']) && (int) $data['classroom_id'] > 0
            ? (int) $data['classroom_id']
            : null;

        $role = in_array((string) ($data['device_role'] ?? 'both'), ['entry', 'exit', 'both'], true)
            ? (string) $data['device_role']
            : 'both';

        if ($classroomId !== null) {
            self::assertClassroomSlotFree($classroomId, $role, null);
        }

        return $db->transaction(static function (Database $db) use ($data, $userId, $deviceId, $mac, $classroomId, $role): array {
            $deviceRowId = (int) $db->insert('devices', [
                'device_id'     => $deviceId,
                'device_name'   => mb_substr((string) $data['device_name'], 0, 120),
                'mac_address'   => $mac,
                'serial_number' => $data['serial_number'] ?? null,
                'classroom_id'  => $classroomId,
                'device_role'   => $role,
                'firmware_version' => (string) ($data['firmware_version'] ?? '1.0.0'),
                'ip_allowlist'  => $data['ip_allowlist'] ?? null,
                'timezone'      => (string) ($data['timezone'] ?? Config::get('app.timezone', 'Asia/Manila')),
                'heartbeat_interval_sec' => (int) ($data['heartbeat_interval_sec'] ?? Config::get('attendance.device.heartbeat_interval_sec', 30)),
                'sync_interval_sec'      => (int) ($data['sync_interval_sec'] ?? Config::get('attendance.device.sync_interval_sec', 60)),
                'offline_queue_limit'    => (int) ($data['offline_queue_limit'] ?? Config::get('attendance.device.offline_queue_limit', 500)),
                'status'        => 'pending',
                'claim_status'  => 'unclaimed',
                'location_note' => $data['location_note'] ?? null,
                'registered_by' => $userId,
                'created_at'    => Clock::nowString(),
                'updated_at'    => Clock::nowString(),
            ]);

            $credentials = ApiKeyService::generateForDevice($deviceRowId, $userId);
            $claim       = self::issueClaimToken($db, $deviceRowId, $userId);

            $db->insert('device_logs', [
                'device_row_id'  => $deviceRowId,
                'device_id_text' => $deviceId,
                'event'          => 'boot',
                'severity'       => 'info',
                'message'        => 'Device registered and awaiting first-boot claim.',
                'ip_address'     => RequestContext::ip(),
                'created_at'     => Clock::nowString(),
            ]);

            AuditService::log(
                AuditService::DEVICE_REGISTERED,
                'devices',
                'device',
                $deviceRowId,
                null,
                ['device_id' => $deviceId, 'mac_address' => $mac, 'classroom_id' => $classroomId, 'role' => $role],
                sprintf('Terminal %s registered.', $deviceId)
            );

            return [
                'device_row_id' => $deviceRowId,
                'device_id'     => $deviceId,
                'mac_address'   => $mac,
                'credentials'   => $credentials,
                'claim'         => $claim,
            ];
        });
    }

    private static function assertClassroomSlotFree(int $classroomId, string $role, ?int $ignoreDeviceRowId): void
    {
        $sql = "SELECT device_id FROM devices
                 WHERE classroom_id = :classroom
                   AND deleted_at IS NULL
                   AND status IN ('pending','active','offline','suspended')
                   AND (device_role = :role OR device_role = 'both' OR :role = 'both')";

        $bindings = ['classroom' => $classroomId, 'role' => $role];

        if ($ignoreDeviceRowId !== null) {
            $sql .= ' AND id <> :ignore';
            $bindings['ignore'] = $ignoreDeviceRowId;
        }

        $existing = Database::instance()->scalar($sql . ' LIMIT 1', $bindings);

        if ($existing !== null) {
            throw new ValidationException(['classroom_id' => [sprintf(
                'This classroom already has an active terminal (%s) covering the %s role.',
                $existing,
                $role
            )]]);
        }
    }

    /** @return array{token:string,expires_at:string} */
    private static function issueClaimToken(Database $db, int $deviceRowId, ?int $userId): array
    {
        $ttlHours = (int) Config::get('security.api_key.claim_token_ttl_hours', 24);
        $token    = 'clm_' . Crypto::randomBase62(12);
        $expires  = Clock::now()->modify('+' . $ttlHours . ' hours');

        $db->insert('device_claims', [
            'device_row_id'    => $deviceRowId,
            'claim_token_hash' => hash('sha256', $token),
            'expires_at'       => $expires->format('Y-m-d H:i:s'),
            'created_by'       => $userId,
            'created_at'       => Clock::nowString(),
        ]);

        return ['token' => $token, 'expires_at' => $expires->format(DATE_ATOM)];
    }

    /**
     * First-boot credential activation. Single use; an unclaimed device stays
     * `pending` and cannot record attendance.
     *
     * @return array<string,mixed>
     */
    public static function claim(string $claimToken, string $deviceId, string $mac, string $ip): array
    {
        $db = Database::instance();

        return $db->transaction(static function (Database $db) use ($claimToken, $deviceId, $mac, $ip): array {
            $claim = $db->selectOne(
                'SELECT c.*, d.device_id, d.mac_address, d.status
                   FROM device_claims c
                   JOIN devices d ON d.id = c.device_row_id
                  WHERE c.claim_token_hash = :hash
                  LIMIT 1 FOR UPDATE',
                ['hash' => hash('sha256', $claimToken)]
            );

            if ($claim === null) {
                SecurityLogService::log(
                    SecurityLogService::CLAIM_TOKEN_INVALID,
                    'high',
                    sprintf('Unknown claim token presented by device "%s" from %s.', $deviceId, $ip),
                    ['device_id' => $deviceId, 'mac' => $mac]
                );

                throw new BusinessRuleException('CLAIM_TOKEN_INVALID', 'Invalid claim token.', [], 403);
            }

            if ($claim['claimed_at'] !== null) {
                SecurityLogService::log(
                    SecurityLogService::CLAIM_TOKEN_INVALID,
                    'high',
                    sprintf('Claim token for device %s was replayed from %s.', $claim['device_id'], $ip),
                    ['device_row_id' => (int) $claim['device_row_id']]
                );

                throw new BusinessRuleException('CLAIM_TOKEN_USED', 'This claim token has already been used.', [], 409);
            }

            if (Clock::parse((string) $claim['expires_at']) < Clock::now()) {
                $db->update('devices', ['claim_status' => 'expired'], ['id' => (int) $claim['device_row_id']]);

                throw new BusinessRuleException('CLAIM_TOKEN_EXPIRED', 'This claim token has expired. Regenerate the provisioning file.', [], 410);
            }

            // Identity must match what was registered — a valid token presented
            // by different hardware means the provisioning file leaked.
            $presentedMac = NetworkService::normaliseMac($mac);

            if ((string) $claim['device_id'] !== $deviceId || (string) $claim['mac_address'] !== $presentedMac) {
                SecurityLogService::log(
                    SecurityLogService::DEVICE_SPOOFING,
                    'critical',
                    sprintf(
                        'Claim token for %s presented with mismatched identity (device "%s", MAC %s) from %s.',
                        $claim['device_id'],
                        $deviceId,
                        $presentedMac,
                        $ip
                    ),
                    ['expected_mac' => $claim['mac_address']],
                    (int) $claim['device_row_id']
                );

                throw new BusinessRuleException('CLAIM_IDENTITY_MISMATCH', 'Device identity does not match the registration.', [], 403);
            }

            $now = Clock::nowString();

            $db->update('device_claims', [
                'claimed_at'  => $now,
                'claimed_ip'  => $ip,
                'claimed_mac' => $presentedMac,
            ], ['claim_id' => (int) $claim['claim_id']]);

            $db->update('devices', [
                'claim_status' => 'claimed',
                'claimed_at'   => $now,
                'status'       => 'active',
                'ip_address'   => $ip,
                'updated_at'   => $now,
            ], ['id' => (int) $claim['device_row_id']]);

            $db->insert('device_logs', [
                'device_row_id'  => (int) $claim['device_row_id'],
                'device_id_text' => $deviceId,
                'event'          => 'claim',
                'severity'       => 'info',
                'message'        => 'Credentials claimed on first boot.',
                'ip_address'     => $ip,
                'created_at'     => $now,
            ]);

            AuditService::log(
                AuditService::DEVICE_CLAIMED,
                'devices',
                'device',
                (int) $claim['device_row_id'],
                ['claim_status' => 'unclaimed'],
                ['claim_status' => 'claimed', 'ip' => $ip],
                sprintf('Terminal %s claimed its credentials from %s.', $deviceId, $ip)
            );

            NotificationService::toAdministrators(
                'device',
                'Terminal activated',
                sprintf('%s completed first-boot activation from %s.', $deviceId, $ip),
                'normal',
                '/admin/devices'
            );

            return [
                'device_id'    => $deviceId,
                'claim_status' => 'claimed',
                'server_time'  => Clock::atom(),
            ];
        });
    }

    /**
     * Provisioning file (Part 19.5). Generated in memory and streamed; never
     * written to disk on the server.
     *
     * @param array<string,mixed> $credentials
     * @param array{token:string,expires_at:string} $claim
     * @return array<string,mixed>
     */
    public static function buildProvisioningFile(int $deviceRowId, array $credentials, array $claim): array
    {
        $device = Database::instance()->selectOne(
            'SELECT d.*, c.room_number
               FROM devices d
               LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE d.id = :id',
            ['id' => $deviceRowId]
        );

        if ($device === null) {
            throw new ValidationException(['device' => ['Device not found.']]);
        }

        return [
            'schema_version'  => '1.0',
            'device_id'       => (string) $device['device_id'],
            'device_name'     => (string) $device['device_name'],
            'classroom_id'    => $device['classroom_id'] === null ? null : (int) $device['classroom_id'],
            'classroom_name'  => $device['room_number'] === null ? null : 'Room ' . $device['room_number'],
            'device_role'     => (string) $device['device_role'],
            'api_key'         => (string) $credentials['api_key'],
            'hmac_secret'     => (string) $credentials['hmac_secret'],
            'server_url'      => Site::url(),
            // Certificate pinning defeats a LAN man-in-the-middle even if an
            // attacker can persuade the device to resolve the server elsewhere.
            'server_fingerprint' => self::serverCertificateFingerprint(),
            'ws_url'          => Realtime::publicUrl(),
            'heartbeat_interval_sec' => (int) $device['heartbeat_interval_sec'],
            'sync_interval_sec'      => (int) $device['sync_interval_sec'],
            'offline_queue_limit'    => (int) $device['offline_queue_limit'],
            'timezone'        => (string) $device['timezone'],
            'claim_token'     => $claim['token'],
            'claim_expires_at' => $claim['expires_at'],
        ];
    }

    /** SHA256 fingerprint of the server's TLS certificate, for pinning. */
    public static function serverCertificateFingerprint(): ?string
    {
        $certFile = (string) Config::get('realtime.tls.cert_file', '');

        if ($certFile === '' || !is_readable($certFile)) {
            return null;
        }

        $pem = (string) file_get_contents($certFile);
        $der = self::pemToDer($pem);

        return $der === null ? null : 'SHA256:' . strtoupper(hash('sha256', $der));
    }

    private static function pemToDer(string $pem): ?string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m) !== 1) {
            return null;
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);

        return $der === false ? null : $der;
    }

    /** Regenerate a claim token for a device that was never activated in time. */
    public static function regenerateClaim(int $deviceRowId, int $userId): array
    {
        $db = Database::instance();

        return $db->transaction(static function (Database $db) use ($deviceRowId, $userId): array {
            $db->update('devices', ['claim_status' => 'unclaimed', 'claimed_at' => null], ['id' => $deviceRowId]);

            return self::issueClaimToken($db, $deviceRowId, $userId);
        });
    }

    // ------------------------------------------------------- heartbeat ----

    /**
     * @param  array<string,mixed> $device
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function heartbeat(array $device, array $payload): array
    {
        $db          = Database::instance();
        $deviceRowId = (int) $device['id'];
        $now         = Clock::nowString();

        $queueDepth = (int) ($payload['queue'] ?? $payload['queue_count'] ?? 0);
        $wasOffline = (string) $device['status'] === 'offline';

        $db->update('devices', [
            'last_heartbeat_at' => $now,
            'last_seen_ip'      => RequestContext::ip(),
            'ip_address'        => RequestContext::ip(),
            'firmware_version'  => (string) ($payload['firmware'] ?? $device['firmware_version']),
            'wifi_signal'       => isset($payload['wifi_signal']) ? (int) $payload['wifi_signal'] : null,
            'queue_depth'       => $queueDepth,
            'uptime_seconds'    => (int) ($payload['uptime'] ?? 0),
            'battery_percent'   => isset($payload['battery']) ? (int) $payload['battery'] : null,
            'status'            => 'active',
            'updated_at'        => $now,
        ], ['id' => $deviceRowId]);

        $db->insert('device_heartbeats', [
            'device_row_id'    => $deviceRowId,
            'firmware_version' => (string) ($payload['firmware'] ?? $device['firmware_version']),
            'wifi_signal'      => isset($payload['wifi_signal']) ? (int) $payload['wifi_signal'] : null,
            'queue_depth'      => $queueDepth,
            'uptime_seconds'   => (int) ($payload['uptime'] ?? 0),
            'battery_percent'  => isset($payload['battery']) ? (int) $payload['battery'] : null,
            'free_heap_bytes'  => isset($payload['free_heap']) ? (int) $payload['free_heap'] : null,
            'ip_address'       => RequestContext::ip(),
            'created_at'       => $now,
        ]);

        if ($wasOffline) {
            self::announceRecovery($device);
        }

        $warningDepth = (int) Config::get('attendance.device.queue_warning_depth', 100);

        if ($queueDepth >= $warningDepth) {
            RealtimeService::publish(
                RealtimeService::deviceChannel((string) $device['device_id']),
                'device.queue_warning',
                ['device_id' => (string) $device['device_id'], 'queue' => $queueDepth]
            );

            NotificationService::toAdministrators(
                'device',
                'Offline queue growing',
                sprintf('Terminal %s is holding %d unsynchronised records.', $device['device_id'], $queueDepth),
                'high',
                '/admin/devices'
            );
        }

        RealtimeService::publish(
            RealtimeService::deviceChannel((string) $device['device_id']),
            'device.heartbeat',
            [
                'device_id'   => (string) $device['device_id'],
                'wifi_signal' => $payload['wifi_signal'] ?? null,
                'queue'       => $queueDepth,
                'firmware'    => $payload['firmware'] ?? null,
                'at'          => Clock::atom(),
            ]
        );

        $session = $db->selectOne(
            "SELECT session_id, session_code, scheduled_end, expires_at
               FROM attendance_sessions WHERE device_row_id = :id AND status = 'open'",
            ['id' => $deviceRowId]
        );

        return [
            'server_time'     => Clock::atom(),
            'server_epoch'    => Clock::timestamp(),
            'active_session'  => $session === null ? null : [
                'session_id'  => (string) $session['session_code'],
                'expires_at'  => Clock::parse((string) $session['expires_at'])->format(DATE_ATOM),
            ],
            'heartbeat_interval_sec' => (int) $device['heartbeat_interval_sec'],
            'sync_interval_sec'      => (int) $device['sync_interval_sec'],
            'should_sync'     => $queueDepth > 0,
            'device_locked'   => $device['locked_until'] !== null
                && Clock::parse((string) $device['locked_until']) > Clock::now(),
        ];
    }

    /** @param array<string,mixed> $device */
    private static function announceRecovery(array $device): void
    {
        Database::instance()->insert('device_logs', [
            'device_row_id'  => (int) $device['id'],
            'device_id_text' => (string) $device['device_id'],
            'event'          => 'online',
            'severity'       => 'info',
            'message'        => 'Terminal reconnected.',
            'ip_address'     => RequestContext::ip(),
            'created_at'     => Clock::nowString(),
        ]);

        RealtimeService::broadcast(
            [RealtimeService::CHANNEL_ADMIN, RealtimeService::deviceChannel((string) $device['device_id'])],
            'device.online',
            ['device_id' => (string) $device['device_id'], 'at' => Clock::atom()]
        );

        NotificationService::toAdministrators(
            'device',
            'Terminal back online',
            sprintf('%s reconnected to the server.', $device['device_id']),
            'normal',
            '/admin/devices'
        );
    }

    /**
     * Mark devices offline once their heartbeat lapses. Driven by the
     * maintenance worker, since a device that has stopped talking cannot tell
     * us it has stopped talking.
     */
    public static function markStaleDevicesOffline(): int
    {
        $db      = Database::instance();
        $timeout = (int) Config::get('attendance.device.offline_after_sec', 90);

        $stale = $db->select(
            "SELECT id, device_id, last_heartbeat_at FROM devices
              WHERE status = 'active'
                AND claim_status = 'claimed'
                AND deleted_at IS NULL
                AND (last_heartbeat_at IS NULL OR last_heartbeat_at < DATE_SUB(NOW(), INTERVAL :timeout SECOND))",
            ['timeout' => $timeout]
        );

        foreach ($stale as $device) {
            $db->update('devices', ['status' => 'offline'], ['id' => (int) $device['id']]);

            $db->insert('device_logs', [
                'device_row_id'  => (int) $device['id'],
                'device_id_text' => (string) $device['device_id'],
                'event'          => 'offline',
                'severity'       => 'warning',
                'message'        => sprintf('No heartbeat for more than %d seconds.', $timeout),
                'created_at'     => Clock::nowString(),
            ]);

            RealtimeService::broadcast(
                [RealtimeService::CHANNEL_ADMIN, RealtimeService::deviceChannel((string) $device['device_id'])],
                'device.offline',
                [
                    'device_id'      => (string) $device['device_id'],
                    'last_heartbeat' => $device['last_heartbeat_at'],
                    'at'             => Clock::atom(),
                ]
            );

            NotificationService::toAdministrators(
                'device',
                'Terminal offline',
                sprintf('%s has not sent a heartbeat for over %d seconds.', $device['device_id'], $timeout),
                'high',
                '/admin/devices'
            );

            AuditService::log(
                'DEVICE_OFFLINE',
                'devices',
                'device',
                (int) $device['id'],
                ['status' => 'active'],
                ['status' => 'offline'],
                'Marked offline after heartbeat timeout.'
            );
        }

        return count($stale);
    }

    // ------------------------------------------------------------- sync ----

    /**
     * Replay an offline queue.
     *
     * Records are processed oldest-first and each carries its own request_id, so
     * a partially-uploaded batch can be retried wholesale without producing
     * duplicates. One bad record does not abort the batch: it is reported back
     * with its reason and the rest still land.
     *
     * @param  array<string,mixed>      $device
     * @param  list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public static function syncQueue(array $device, array $records): array
    {
        $accepted  = 0;
        $duplicate = 0;
        $rejected  = [];

        usort($records, static function (array $a, array $b): int {
            return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
        });

        foreach ($records as $record) {
            $requestId = isset($record['request_id']) ? (string) $record['request_id'] : null;
            $uid       = (string) ($record['uid'] ?? $record['rfid_uid'] ?? '');
            $timestamp = (string) ($record['timestamp'] ?? $record['time'] ?? '');

            if ($uid === '') {
                $rejected[] = ['request_id' => $requestId, 'code' => 'MISSING_UID', 'message' => 'Record has no card UID.'];
                continue;
            }

            // Original timestamps are preserved (Part 4 synchronisation rules) —
            // a record captured at 08:05 must not become 09:20 because that is
            // when the network came back.
            $occurredAt = null;

            if ($timestamp !== '') {
                try {
                    $occurredAt = Clock::parse($timestamp);
                } catch (\Throwable) {
                    $occurredAt = null;
                }
            }

            if ($requestId !== null && IdempotencyService::has($requestId)) {
                $duplicate++;
                continue;
            }

            try {
                AttendanceService::tap($device, $uid, $requestId, $record['intent'] ?? null, $occurredAt);
                $accepted++;

                Database::instance()->execute(
                    'UPDATE attendance_records SET synced_offline = 1 WHERE request_id = :rid',
                    ['rid' => $requestId]
                );
            } catch (BusinessRuleException $e) {
                if (in_array($e->errorCode(), ['DUPLICATE_TIME_IN', 'ALREADY_COMPLETE', 'DUPLICATE_REQUEST'], true)) {
                    $duplicate++;
                } else {
                    $rejected[] = [
                        'request_id' => $requestId,
                        'code'       => $e->errorCode(),
                        'message'    => $e->getMessage(),
                    ];
                }
            } catch (\Throwable $e) {
                Logger::error('Offline sync record failed', [
                    'device'     => $device['device_id'],
                    'request_id' => $requestId,
                    'error'      => $e->getMessage(),
                ]);

                $rejected[] = [
                    'request_id' => $requestId,
                    'code'       => 'SYNC_ERROR',
                    'message'    => 'Record could not be processed; it will be retried.',
                    'retry'      => true,
                ];
            }
        }

        $db = Database::instance();

        $db->insert('device_logs', [
            'device_row_id'  => (int) $device['id'],
            'device_id_text' => (string) $device['device_id'],
            'event'          => $rejected === [] ? 'sync_completed' : 'sync_failed',
            'severity'       => $rejected === [] ? 'info' : 'warning',
            'message'        => sprintf('Synchronised %d records (%d duplicates, %d rejected).',
                $accepted, $duplicate, count($rejected)),
            'context_json'   => (string) json_encode(['rejected' => array_slice($rejected, 0, 20)]),
            'ip_address'     => RequestContext::ip(),
            'created_at'     => Clock::nowString(),
        ]);

        AuditService::log(
            AuditService::ATTENDANCE_SYNCED,
            'devices',
            'device',
            (int) $device['id'],
            null,
            ['accepted' => $accepted, 'duplicate' => $duplicate, 'rejected' => count($rejected)],
            sprintf('Offline queue synchronised from %s.', $device['device_id'])
        );

        if ($accepted > 0 || $duplicate > 0) {
            NotificationService::toAdministrators(
                'device',
                'Offline synchronisation complete',
                sprintf('%s uploaded %d records (%d already recorded).', $device['device_id'], $accepted, $duplicate),
                'normal',
                '/admin/devices'
            );
        }

        return [
            'accepted'  => $accepted,
            'duplicate' => $duplicate,
            'rejected'  => $rejected,
            'processed' => $accepted + $duplicate + count($rejected),
        ];
    }

    // ------------------------------------------------------ maintenance ----

    public static function nextDeviceId(): string
    {
        $year   = Clock::now()->format('Y');
        $prefix = 'DEV-' . $year . '-';

        $last = Database::instance()->scalar(
            'SELECT device_id FROM devices WHERE device_id LIKE :prefix ORDER BY id DESC LIMIT 1',
            ['prefix' => $prefix . '%']
        );

        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed>|null */
    public static function findByPublicId(string $deviceId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT * FROM devices WHERE device_id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $deviceId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function fleet(): array
    {
        return Database::instance()->select('SELECT * FROM v_device_status ORDER BY room_number, device_id');
    }

    /** @return array<string,int> */
    public static function fleetSummary(): array
    {
        $rows = Database::instance()->select(
            'SELECT health, COUNT(*) AS total FROM v_device_status GROUP BY health'
        );

        $summary = ['online' => 0, 'warning' => 0, 'offline' => 0, 'disabled' => 0, 'pending' => 0];

        foreach ($rows as $row) {
            $summary[(string) $row['health']] = (int) $row['total'];
        }

        $summary['total'] = array_sum($summary);

        return $summary;
    }

    public static function setStatus(int $deviceRowId, string $status, string $reason, ?int $userId = null): void
    {
        $db     = Database::instance();
        $device = $db->selectOne('SELECT * FROM devices WHERE id = :id', ['id' => $deviceRowId]);

        if ($device === null) {
            throw new ValidationException(['device' => ['Device not found.']]);
        }

        // A terminal takes itself active by claiming its key on first boot, and
        // v_device_status reports "pending" for anything unclaimed regardless of
        // this column. Letting the button through would write 'active' to a row
        // whose badge cannot move, report success, and leave the administrator
        // clicking it again — so say plainly what actually activates a terminal.
        if ($status === 'active' && (string) $device['claim_status'] !== 'claimed') {
            throw new ValidationException([
                'status' => [
                    'This terminal has not completed first-boot activation, so it cannot be set active '
                    . 'from here. Download its provisioning file, flash it to the board and power it on — '
                    . 'it claims its key and becomes active by itself.',
                ],
            ]);
        }

        if (in_array($status, ['disabled', 'decommissioned'], true)) {
            $openSession = $db->scalar(
                "SELECT session_id FROM attendance_sessions WHERE device_row_id = :id AND status = 'open'",
                ['id' => $deviceRowId]
            );

            if ($openSession !== null) {
                throw new ValidationException([
                    'device' => ['This terminal has an attendance session open right now. Close it first.'],
                ]);
            }
        }

        $db->update('devices', ['status' => $status, 'updated_at' => Clock::nowString()], ['id' => $deviceRowId]);

        if ($status === 'decommissioned') {
            $keys = $db->select(
                "SELECT api_key_id FROM api_keys WHERE device_row_id = :id AND status IN ('active','rotating')",
                ['id' => $deviceRowId]
            );

            foreach ($keys as $key) {
                ApiKeyService::revoke((int) $key['api_key_id'], 'Device decommissioned: ' . $reason, $userId);
            }
        }

        AuditService::log(
            $status === 'decommissioned' ? AuditService::DEVICE_DECOMMISSIONED : AuditService::DEVICE_UPDATED,
            'devices',
            'device',
            $deviceRowId,
            ['status' => $device['status']],
            ['status' => $status, 'reason' => $reason],
            sprintf('Terminal %s set to %s. %s', $device['device_id'], $status, $reason),
            'success',
            $userId
        );
    }

    /** Purge heartbeat history past the retention window (90 days, Part 5). */
    public static function purgeOldHeartbeats(int $days): int
    {
        return Database::instance()->execute(
            'DELETE FROM device_heartbeats WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $days]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function logs(int $deviceRowId, int $limit = 100): array
    {
        return Database::instance()->select(
            'SELECT * FROM device_logs WHERE device_row_id = :id ORDER BY log_id DESC LIMIT ' . max(1, min($limit, 500)),
            ['id' => $deviceRowId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function heartbeatHistory(int $deviceRowId, int $hours = 24): array
    {
        return Database::instance()->select(
            'SELECT created_at, wifi_signal, queue_depth, uptime_seconds, battery_percent
               FROM device_heartbeats
              WHERE device_row_id = :id AND created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
              ORDER BY heartbeat_id DESC LIMIT 500',
            ['id' => $deviceRowId, 'hours' => $hours]
        );
    }
}
