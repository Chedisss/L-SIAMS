<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Logger;
use SensitiveParameter;

/**
 * API key generation, storage, verification and lifecycle (Part 19.4).
 *
 * Storage model, and why:
 *   key_id                – plaintext. It is a public lookup handle, indexed so
 *                           verification is an O(1) primary-key style read.
 *   secret_hash           – SHA-256(secret || server pepper). The secret itself
 *                           is never stored, so a database dump alone cannot
 *                           authenticate a device.
 *   hmac_secret_encrypted – AES-256-GCM. The server must be able to *read* this
 *                           one back in order to recompute request signatures,
 *                           so it is encrypted rather than hashed; the key lives
 *                           in APP_KEY, outside the database and outside the
 *                           web root.
 *
 * On the choice of SHA-256 over bcrypt for the key secret: devices authenticate
 * on every single tap, and bcrypt at cost 12 costs ~250 ms per verification,
 * which would collapse under the 100 taps/minute target in Part 17.1. The
 * secret is a 256-bit CSPRNG value with no dictionary structure, so a fast hash
 * is not a weakness here — there is nothing to guess. User passwords are the
 * opposite case and stay on bcrypt (see Core\Hash).
 */
final class ApiKeyService
{
    /**
     * Generate a key pair for a device. The plaintext values in the return are
     * the only copy that will ever exist — they are shown once and then
     * unrecoverable.
     *
     * @return array{api_key_id:int,key_id:string,api_key:string,hmac_secret:string,secret_last_four:string}
     */
    public static function generateForDevice(int $deviceRowId, ?int $createdBy = null, ?int $rotatedFrom = null): array
    {
        $config = (array) Config::get('security.api_key', []);

        $keyId  = ((string) ($config['key_id_prefix'] ?? 'lsk_'))
            . Crypto::randomBase62((int) ($config['key_id_bytes'] ?? 8));
        $secret = Crypto::randomBase62((int) ($config['secret_bytes'] ?? 32));
        $hmac   = Crypto::randomBase62((int) ($config['hmac_secret_bytes'] ?? 32));

        $apiKey = $keyId . '.' . $secret;

        $apiKeyId = (int) Database::instance()->insert('api_keys', [
            'key_id'                => $keyId,
            'device_row_id'         => $deviceRowId,
            'secret_hash'           => Crypto::hashSecret($secret, Crypto::apiKeyPepper()),
            'secret_last_four'      => substr($secret, -4),
            'hmac_secret_encrypted' => Crypto::encrypt($hmac),
            'scope'                 => 'device',
            'status'                => 'active',
            'rotated_from'          => $rotatedFrom,
            'created_by'            => $createdBy ?? Auth::id(),
            'created_at'            => Clock::nowString(),
            'updated_at'            => Clock::nowString(),
        ]);

        self::recordHistory($apiKeyId, $deviceRowId, $rotatedFrom === null ? 'generated' : 'rotated', null, $createdBy);

        AuditService::log(
            $rotatedFrom === null ? AuditService::API_KEY_GENERATED : AuditService::API_KEY_ROTATED,
            'devices',
            'api_key',
            $apiKeyId,
            null,
            // The key_id is safe to record; the secret must never appear here.
            ['key_id' => $keyId, 'device_row_id' => $deviceRowId],
            'API key ' . ($rotatedFrom === null ? 'generated' : 'rotated') . ' for device #' . $deviceRowId
        );

        return [
            'api_key_id'       => $apiKeyId,
            'key_id'           => $keyId,
            'api_key'          => $apiKey,
            'hmac_secret'      => $hmac,
            'secret_last_four' => substr($secret, -4),
        ];
    }

    /**
     * Verify a presented key.
     *
     * @return array{key:array<string,mixed>,hmac_secret:string}|null null on any failure
     */
    public static function verify(#[SensitiveParameter] string $presentedKey, string $sourceIp): ?array
    {
        // 1. Split at the separator.
        $parts = explode('.', $presentedKey, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$keyId, $secret] = $parts;

        // 2. Indexed lookup by the public handle.
        $key = Database::instance()->selectOne(
            'SELECT * FROM api_keys WHERE key_id = :key_id LIMIT 1',
            ['key_id' => $keyId]
        );

        if ($key === null) {
            return null;
        }

        // 3. Constant-time comparison. Never `==` on a secret.
        if (!Crypto::verifySecret($secret, (string) $key['secret_hash'], Crypto::apiKeyPepper())) {
            return null;
        }

        // 4. Lifecycle checks.
        if (!self::isUsable($key)) {
            return null;
        }

        if (!self::ipPermitted($key, $sourceIp)) {
            return null;
        }

        try {
            $hmacSecret = Crypto::decrypt((string) $key['hmac_secret_encrypted']);
        } catch (\Throwable $e) {
            Logger::critical('HMAC secret could not be decrypted', [
                'api_key_id' => $key['api_key_id'],
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        return ['key' => $key, 'hmac_secret' => $hmacSecret];
    }

    /** @param array<string,mixed> $key */
    private static function isUsable(array $key): bool
    {
        $status = (string) $key['status'];
        $now    = Clock::now();

        if ($status === 'revoked') {
            SecurityLogService::log(
                SecurityLogService::API_KEY_REVOKED_USE,
                'high',
                sprintf('Revoked API key %s was presented.', $key['key_id']),
                ['api_key_id' => $key['api_key_id']],
                $key['device_row_id'] === null ? null : (int) $key['device_row_id']
            );

            return false;
        }

        if ($status === 'suspended') {
            return false;
        }

        // A key being rotated stays valid until its grace window closes, so the
        // device can be reflashed without downtime (Part 19.4 lifecycle table).
        if ($status === 'rotating') {
            $grace = $key['grace_expires_at'];

            if ($grace === null || Clock::parse((string) $grace) < $now) {
                self::expire((int) $key['api_key_id'], 'Rotation grace period elapsed.');

                return false;
            }
        }

        if ($key['expires_at'] !== null && Clock::parse((string) $key['expires_at']) < $now) {
            SecurityLogService::log(
                SecurityLogService::API_KEY_EXPIRED,
                'medium',
                sprintf('Expired API key %s was presented.', $key['key_id']),
                ['api_key_id' => $key['api_key_id']]
            );

            self::expire((int) $key['api_key_id'], 'Key reached its expiry date.');

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $key */
    private static function ipPermitted(array $key, string $sourceIp): bool
    {
        if ($key['device_row_id'] === null) {
            return true;
        }

        $allowlist = Database::instance()->scalar(
            'SELECT ip_allowlist FROM devices WHERE id = :id',
            ['id' => (int) $key['device_row_id']]
        );

        if (!is_string($allowlist) || trim($allowlist) === '') {
            return true;
        }

        foreach (array_map('trim', explode(',', $allowlist)) as $cidr) {
            if ($cidr !== '' && NetworkService::ipInCidr($sourceIp, $cidr)) {
                return true;
            }
        }

        SecurityLogService::log(
            SecurityLogService::API_KEY_IP_ANOMALY,
            'high',
            sprintf('API key %s presented from %s, outside its allowlist.', $key['key_id'], $sourceIp),
            ['api_key_id' => $key['api_key_id'], 'allowlist' => $allowlist],
            (int) $key['device_row_id']
        );

        return false;
    }

    /**
     * Canonical string for the request signature (Part 19.4).
     *
     * Field order is fixed and every element is length-delimited by the newline
     * separator, so no two different requests can produce the same canonical
     * form. The body is hashed rather than included so large payloads stay cheap
     * to sign on an ESP32.
     */
    public static function canonicalString(
        string $method,
        string $path,
        string $deviceId,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $deviceId,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function verifySignature(
        string $canonical,
        string $presentedSignature,
        #[SensitiveParameter] string $hmacSecret
    ): bool {
        return Crypto::verifyHmac($canonical, $presentedSignature, $hmacSecret);
    }

    /**
     * Record a successful use. Deliberately fire-and-forget on failure: a
     * telemetry write must not add latency to, or fail, an attendance tap.
     */
    public static function recordUse(int $apiKeyId, string $ip): void
    {
        try {
            Database::instance()->execute(
                'UPDATE api_keys
                    SET last_used_at = :now,
                        last_used_ip = :ip,
                        use_count = use_count + 1,
                        consecutive_signature_failures = 0
                  WHERE api_key_id = :id',
                ['now' => Clock::nowString(), 'ip' => $ip, 'id' => $apiKeyId]
            );
        } catch (\Throwable $e) {
            Logger::warning('API key use counter update failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A valid key with an invalid signature means a bug or an attack — never
     * ordinary noise. After enough of them the key revokes itself.
     */
    public static function recordSignatureFailure(int $apiKeyId, ?int $deviceRowId, string $keyIdLabel): void
    {
        $db = Database::instance();

        $db->execute(
            'UPDATE api_keys SET consecutive_signature_failures = consecutive_signature_failures + 1
              WHERE api_key_id = :id',
            ['id' => $apiKeyId]
        );

        $failures = (int) $db->scalar(
            'SELECT consecutive_signature_failures FROM api_keys WHERE api_key_id = :id',
            ['id' => $apiKeyId]
        );

        $threshold = (int) Config::get('security.api_key.auto_revoke.consecutive_signature_failures', 10);

        SecurityLogService::log(
            SecurityLogService::SIGNATURE_INVALID,
            'high',
            sprintf('Invalid HMAC signature for key %s (failure %d of %d).', $keyIdLabel, $failures, $threshold),
            ['api_key_id' => $apiKeyId, 'failures' => $failures],
            $deviceRowId
        );

        if ($failures >= $threshold) {
            self::revoke(
                $apiKeyId,
                sprintf('Automatically revoked after %d consecutive signature failures.', $failures),
                null,
                true
            );
        }
    }

    /**
     * Issue a replacement key while keeping the old one alive for the grace
     * window, so a technician can reflash the terminal without downtime.
     *
     * @return array{api_key_id:int,key_id:string,api_key:string,hmac_secret:string,secret_last_four:string}
     */
    public static function rotate(int $deviceRowId, ?int $userId = null): array
    {
        $db          = Database::instance();
        $graceHours  = (int) Config::get('security.api_key.rotation_grace_hours', 24);
        $graceExpiry = Clock::now()->modify('+' . $graceHours . ' hours')->format('Y-m-d H:i:s');

        return $db->transaction(static function (Database $db) use ($deviceRowId, $userId, $graceExpiry): array {
            $current = $db->selectOne(
                "SELECT api_key_id FROM api_keys
                  WHERE device_row_id = :device AND status = 'active'
                  ORDER BY api_key_id DESC LIMIT 1",
                ['device' => $deviceRowId]
            );

            $rotatedFrom = $current === null ? null : (int) $current['api_key_id'];

            if ($rotatedFrom !== null) {
                $db->update('api_keys', [
                    'status'           => 'rotating',
                    'grace_expires_at' => $graceExpiry,
                ], ['api_key_id' => $rotatedFrom]);
            }

            return self::generateForDevice($deviceRowId, $userId, $rotatedFrom);
        });
    }

    public static function revoke(int $apiKeyId, string $reason, ?int $userId = null, bool $automatic = false): void
    {
        $db  = Database::instance();
        $key = $db->selectOne('SELECT * FROM api_keys WHERE api_key_id = :id', ['id' => $apiKeyId]);

        if ($key === null || (string) $key['status'] === 'revoked') {
            return;
        }

        $db->update('api_keys', [
            'status'        => 'revoked',
            'revoked_at'    => Clock::nowString(),
            'revoked_by'    => $userId ?? Auth::id(),
            'revoke_reason' => mb_substr($reason, 0, 255),
        ], ['api_key_id' => $apiKeyId]);

        $deviceRowId = $key['device_row_id'] === null ? null : (int) $key['device_row_id'];

        if ($deviceRowId !== null) {
            // A device with no usable key cannot record attendance; marking it
            // offline immediately is honest rather than leaving a green dot on
            // the dashboard for something that is about to start failing.
            $db->update('devices', ['status' => 'offline'], ['id' => $deviceRowId]);
        }

        self::recordHistory($apiKeyId, $deviceRowId, $automatic ? 'auto_revoked' : 'revoked', $reason, $userId);

        AuditService::log(
            AuditService::API_KEY_REVOKED,
            'devices',
            'api_key',
            $apiKeyId,
            ['status' => $key['status']],
            ['status' => 'revoked', 'reason' => $reason],
            'API key revoked: ' . $reason
        );

        NotificationService::toAdministrators(
            'device',
            'API key revoked',
            sprintf('Key %s was revoked. %s', $key['key_id'], $reason),
            'high',
            '/admin/devices'
        );
    }

    public static function suspend(int $apiKeyId, string $reason, ?int $userId = null): void
    {
        Database::instance()->update('api_keys', ['status' => 'suspended'], ['api_key_id' => $apiKeyId]);
        self::recordHistory($apiKeyId, null, 'suspended', $reason, $userId);
    }

    public static function resume(int $apiKeyId, ?int $userId = null): void
    {
        Database::instance()->update('api_keys', ['status' => 'active'], ['api_key_id' => $apiKeyId]);
        self::recordHistory($apiKeyId, null, 'resumed', null, $userId);
    }

    private static function expire(int $apiKeyId, string $reason): void
    {
        Database::instance()->update('api_keys', ['status' => 'expired'], ['api_key_id' => $apiKeyId]);
        self::recordHistory($apiKeyId, null, 'expired', $reason, null);
    }

    private static function recordHistory(
        int $apiKeyId,
        ?int $deviceRowId,
        string $event,
        ?string $reason,
        ?int $userId
    ): void {
        try {
            Database::instance()->insert('api_key_history', [
                'api_key_id'    => $apiKeyId,
                'device_row_id' => $deviceRowId,
                'event'         => $event,
                'reason'        => $reason === null ? null : mb_substr($reason, 0, 255),
                'performed_by'  => $userId ?? Auth::id(),
                'ip_address'    => RequestContext::ip(),
                'created_at'    => Clock::nowString(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('API key history write failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function historyForDevice(int $deviceRowId): array
    {
        return Database::instance()->select(
            'SELECT h.*, k.key_id, k.secret_last_four, k.status AS key_status,
                    k.last_used_at, k.use_count, u.username AS performed_by_username
               FROM api_key_history h
               JOIN api_keys k ON k.api_key_id = h.api_key_id
               LEFT JOIN users u ON u.user_id = h.performed_by
              WHERE h.device_row_id = :device OR k.device_row_id = :device
              ORDER BY h.history_id DESC',
            ['device' => $deviceRowId]
        );
    }

    /** Keys older than the rotation recommendation, for the monthly report. @return list<array<string,mixed>> */
    public static function agingKeys(?int $days = null): array
    {
        $days = $days ?? (int) Config::get('security.api_key.age_rotate_days', 365);

        return Database::instance()->select(
            "SELECT k.api_key_id, k.key_id, k.secret_last_four, k.created_at, k.last_used_at,
                    DATEDIFF(NOW(), k.created_at) AS age_days,
                    d.device_id, d.device_name, c.room_number
               FROM api_keys k
               LEFT JOIN devices d    ON d.id = k.device_row_id
               LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
              WHERE k.status = 'active' AND k.created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
              ORDER BY k.created_at ASC",
            ['days' => $days]
        );
    }

    /** Masked form for display: `lsk_7Kd93Bx2.••••••••Er`. */
    public static function maskedDisplay(string $keyId, string $lastFour): string
    {
        return $keyId . '.' . str_repeat('•', 8) . substr($lastFour, -2);
    }

    /**
     * Sweep the rotation grace windows. Run from the maintenance worker so an
     * old key auto-revokes on time even if no request ever presents it again.
     */
    public static function expireGracePeriods(): int
    {
        $rows = Database::instance()->select(
            "SELECT api_key_id FROM api_keys
              WHERE status = 'rotating' AND grace_expires_at IS NOT NULL AND grace_expires_at < NOW()"
        );

        foreach ($rows as $row) {
            self::expire((int) $row['api_key_id'], 'Rotation grace period elapsed.');
        }

        return count($rows);
    }
}
