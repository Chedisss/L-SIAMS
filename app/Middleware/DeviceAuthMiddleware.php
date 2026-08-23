<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiKeyService;
use App\Services\NetworkService;
use App\Services\SecurityLogService;
use PDOException;

/**
 * Device authentication — steps 1 to 6 of the Part 15.2 validation sequence,
 * plus the request-signing checks from Part 19.4.
 *
 *   1. Device registered and active      → DEVICE_UNKNOWN
 *   2. API key valid and unexpired       → API_KEY_INVALID
 *   3. HMAC signature valid              → SIGNATURE_INVALID
 *   4. Timestamp within ±30s             → TIMESTAMP_EXPIRED
 *   5. Nonce unused                      → REPLAY_DETECTED
 *   6. Device bound to the classroom     → DEVICE_CLASSROOM_MISMATCH
 *
 * Every failure is a security-log entry and a machine-readable code the
 * firmware can render. None of them ever discloses which of the checks failed
 * in a way that would help an attacker map the surface — the codes are
 * deliberately coarse to the caller and precise only in the log.
 */
final class DeviceAuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $headers  = (array) Config::get('security.request.headers', []);
        $deviceId = trim((string) ($request->header((string) ($headers['device_id'] ?? 'X-LSIAMS-Device-Id')) ?? ''));
        $apiKey   = trim((string) ($request->header((string) ($headers['api_key'] ?? 'X-LSIAMS-Api-Key')) ?? ''));
        $timestamp = trim((string) ($request->header((string) ($headers['timestamp'] ?? 'X-LSIAMS-Timestamp')) ?? ''));
        $nonce    = trim((string) ($request->header((string) ($headers['nonce'] ?? 'X-LSIAMS-Nonce')) ?? ''));
        $signature = trim((string) ($request->header((string) Config::get('security.request.signature_header', 'X-LSIAMS-Signature')) ?? ''));

        if ($deviceId === '' || $apiKey === '') {
            $this->reject('DEVICE_UNKNOWN', 'Device credentials are missing.', 401, [
                'event'    => SecurityLogService::DEVICE_UNKNOWN,
                'severity' => 'medium',
                'message'  => 'Device request arrived without identification headers.',
            ]);
        }

        // --- 1. Device registered and active ---------------------------------
        $device = Database::instance()->selectOne(
            'SELECT * FROM devices WHERE device_id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $deviceId]
        );

        if ($device === null) {
            $this->reject('DEVICE_UNKNOWN', 'This device is not registered.', 401, [
                'event'     => SecurityLogService::DEVICE_UNKNOWN,
                'severity'  => 'high',
                'message'   => sprintf('Unregistered device "%s" attempted %s.', $deviceId, $request->path()),
                'device_id' => $deviceId,
            ]);
        }

        if (NetworkService::isDeviceBlocked((int) $device['id'], $deviceId)) {
            $this->reject('DEVICE_BLOCKED', 'This device has been blocked.', 403, [
                'event'     => SecurityLogService::DEVICE_BLOCKED,
                'severity'  => 'high',
                'message'   => sprintf('Blocked device "%s" attempted %s.', $deviceId, $request->path()),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        if (in_array((string) $device['status'], ['disabled', 'decommissioned', 'suspended'], true)) {
            $this->reject('DEVICE_DISABLED', 'This device has been disabled.', 403, [
                'event'     => SecurityLogService::DEVICE_UNKNOWN,
                'severity'  => 'medium',
                'message'   => sprintf('Disabled device "%s" attempted %s.', $deviceId, $request->path()),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        // The claim endpoint is the one place an unclaimed device may speak; it
        // authenticates with its claim token instead of an active session.
        if ((string) $device['claim_status'] !== 'claimed' && !str_starts_with($request->path(), '/api/device/claim')) {
            $this->reject('DEVICE_UNCLAIMED', 'This device has not completed activation.', 403, [
                'event'     => SecurityLogService::DEVICE_UNKNOWN,
                'severity'  => 'medium',
                'message'   => sprintf('Unclaimed device "%s" attempted %s.', $deviceId, $request->path()),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        // --- 2. API key valid and unexpired ----------------------------------
        $verified = ApiKeyService::verify($apiKey, $request->ip());

        if ($verified === null) {
            $this->reject('API_KEY_INVALID', 'Device authentication failed.', 401, [
                'event'     => SecurityLogService::API_KEY_INVALID,
                'severity'  => 'high',
                'message'   => sprintf('Invalid API key presented for device "%s" from %s.', $deviceId, $request->ip()),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        // The key is genuine and the server cannot read the secret paired with
        // it — an APP_KEY that no longer matches the database, not a bad
        // credential. Named separately so the terminal stops advising a
        // re-download, which masks the condition instead of naming it, and so
        // the security log does not record a legitimate device as an attacker.
        if (isset($verified['undecryptable'])) {
            $this->reject(
                'SERVER_KEY_MISMATCH',
                'This server cannot decrypt its own copy of this device\'s secret. '
                . 'APP_KEY in .env does not match the key this database was encrypted with. '
                . 'Restore the original .env, or re-register the terminal.',
                401,
                [
                    'event'     => SecurityLogService::API_KEY_INVALID,
                    // Not "high". Nothing hostile happened: a valid device
                    // presented a valid key to a server that has lost the
                    // means to read it. Filing it as an attack buries a
                    // configuration fault among the intrusion attempts.
                    'severity'  => 'medium',
                    'message'   => sprintf(
                        'Device "%s" presented a valid key whose HMAC secret could not be decrypted. '
                        . 'APP_KEY does not match this database.',
                        $deviceId
                    ),
                    'device_id' => $deviceId,
                    'row_id'    => (int) $device['id'],
                ]
            );
        }

        // A key that authenticates but belongs to a different device is the
        // signature of a stolen credential being replayed from other hardware.
        if ((int) $verified['key']['device_row_id'] !== (int) $device['id']) {
            $this->reject('API_KEY_INVALID', 'Device authentication failed.', 401, [
                'event'     => SecurityLogService::DEVICE_SPOOFING,
                'severity'  => 'critical',
                'message'   => sprintf(
                    'API key belonging to device #%d was presented as device "%s" from %s.',
                    (int) $verified['key']['device_row_id'],
                    $deviceId,
                    $request->ip()
                ),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        // --- 4. Timestamp within the permitted skew --------------------------
        // Checked before the signature so a device with a badly wrong clock gets
        // an actionable answer rather than an opaque signature failure.
        $skew = (int) Config::get('security.request.timestamp_skew_seconds', 30);

        if ($timestamp === '') {
            $this->reject('TIMESTAMP_EXPIRED', 'Request timestamp is missing.', 401, [
                'event'     => SecurityLogService::TIMESTAMP_EXPIRED,
                'severity'  => 'medium',
                'message'   => sprintf('Device "%s" sent a request without a timestamp.', $deviceId),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        $requestTime = $this->parseTimestamp($timestamp);

        if ($requestTime === null || abs(Clock::timestamp() - $requestTime) > $skew) {
            $this->reject('TIMESTAMP_EXPIRED', 'Request timestamp is outside the permitted window.', 401, [
                'event'     => SecurityLogService::TIMESTAMP_EXPIRED,
                'severity'  => 'medium',
                'message'   => sprintf(
                    'Device "%s" sent a timestamp %s seconds from server time.',
                    $deviceId,
                    $requestTime === null ? 'unparseable' : (string) (Clock::timestamp() - $requestTime)
                ),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ], ['server_time' => Clock::atom(), 'server_epoch' => Clock::timestamp()]);
        }

        // --- 3. HMAC signature -----------------------------------------------
        if ($signature === '' || $nonce === '') {
            $this->reject('SIGNATURE_INVALID', 'Request signature is missing.', 401, [
                'event'     => SecurityLogService::SIGNATURE_INVALID,
                'severity'  => 'high',
                'message'   => sprintf('Device "%s" sent an unsigned request to %s.', $deviceId, $request->path()),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        $canonical = ApiKeyService::canonicalString(
            $request->method(),
            $request->path(),
            $deviceId,
            $timestamp,
            $nonce,
            $request->rawBody()
        );

        if (!ApiKeyService::verifySignature($canonical, $signature, $verified['hmac_secret'])) {
            // A valid key with a bad signature means either a firmware bug or an
            // attack — never ordinary noise, so it escalates towards auto-revoke.
            ApiKeyService::recordSignatureFailure(
                (int) $verified['key']['api_key_id'],
                (int) $device['id'],
                (string) $verified['key']['key_id']
            );

            throw new HttpException(401, 'SIGNATURE_INVALID', 'Request signature verification failed.');
        }

        // --- 5. Nonce unused --------------------------------------------------
        $this->consumeNonce((int) $device['id'], $nonce, $deviceId, $request);

        // --- 6. Device bound to a classroom -----------------------------------
        if ($device['classroom_id'] === null && $this->requiresClassroom($request)) {
            $this->reject('DEVICE_CLASSROOM_MISMATCH', 'This device is not assigned to a classroom.', 409, [
                'event'     => SecurityLogService::DEVICE_CLASSROOM_MISMATCH,
                'severity'  => 'medium',
                'message'   => sprintf('Device "%s" has no classroom assignment but attempted %s.', $deviceId, $request->path()),
                'device_id' => $deviceId,
                'row_id'    => (int) $device['id'],
            ]);
        }

        ApiKeyService::recordUse((int) $verified['key']['api_key_id'], $request->ip());

        $device['api_key_id'] = (int) $verified['key']['api_key_id'];
        Auth::setDevice($device);

        return $next($request);
    }

    /**
     * The unique key uq_device_nonce is the enforcement. Inserting and catching
     * the collision is race-free; a "check then insert" would not be.
     */
    private function consumeNonce(int $deviceRowId, string $nonce, string $deviceId, Request $request): void
    {
        try {
            Database::instance()->insert('device_nonces', [
                'device_row_id' => $deviceRowId,
                'nonce'         => mb_substr($nonce, 0, 64),
                'used_at'       => Clock::nowString(),
            ]);
        } catch (PDOException $e) {
            if (!Database::isDuplicateKey($e)) {
                throw $e;
            }

            SecurityLogService::log(
                SecurityLogService::REPLAY_DETECTED,
                'high',
                sprintf(
                    'Replayed nonce "%s" from device "%s" at %s (%s).',
                    $nonce,
                    $deviceId,
                    $request->ip(),
                    $request->path()
                ),
                ['nonce' => $nonce],
                $deviceRowId,
                $deviceId
            );

            throw new HttpException(409, 'REPLAY_DETECTED', 'This request has already been processed.');
        }
    }

    /**
     * A classroom is required to *attribute* something, and only then.
     *
     * Attendance is the whole reason the check exists: a tap or a verified
     * finger becomes a record against a room, and a terminal that cannot say
     * which room must not be recording anything. Everything else a terminal
     * does is administrative and has no room to be wrong about.
     *
     * Enrolment is the case that made this matter. A terminal can be marked an
     * enrolment station so it can sit on the registrar's desk rather than in a
     * classroom — and then every enrolment poll it made was refused with
     * DEVICE_CLASSROOM_MISMATCH, because the desk is not a room. The two
     * behaviours contradicted each other and the enrolment one is right.
     *
     * Clock sync is exempt for a blunter reason: the timestamp on a signed
     * request has to be within thirty seconds of the server's, so a terminal
     * that cannot ask the time cannot sign anything at all, including the
     * requests that would tell it what was wrong.
     */
    private function requiresClassroom(Request $request): bool
    {
        $exemptions = [
            '/api/device/heartbeat',
            '/api/device/claim',
            '/api/device/status',
            '/api/device/time',
            '/api/fingerprint/enrollment',
            '/api/rfid/enrollment',
        ];

        foreach ($exemptions as $exempt) {
            if (str_starts_with($request->path(), $exempt)) {
                return false;
            }
        }

        return true;
    }

    private function parseTimestamp(string $timestamp): ?int
    {
        if (ctype_digit($timestamp)) {
            return (int) $timestamp;
        }

        $parsed = strtotime($timestamp);

        return $parsed === false ? null : $parsed;
    }

    /**
     * @param  array<string,mixed> $log
     * @param  array<string,mixed> $extra
     * @throws HttpException
     */
    private function reject(string $code, string $message, int $status, array $log, array $extra = []): never
    {
        SecurityLogService::log(
            (string) $log['event'],
            (string) $log['severity'],
            (string) $log['message'],
            [],
            isset($log['row_id']) ? (int) $log['row_id'] : null,
            isset($log['device_id']) ? (string) $log['device_id'] : null
        );

        throw new HttpException($status, $code, $message, $extra);
    }
}
