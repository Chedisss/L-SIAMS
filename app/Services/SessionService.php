<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Server-authoritative web sessions (Part 18).
 *
 * The browser holds an opaque 256-bit token; every fact about the session —
 * when it last saw activity, when it expires, whether it has been terminated —
 * lives in `user_sessions`. A tampered client-side timer therefore buys an
 * attacker exactly nothing, which is the whole design goal.
 */
final class SessionService
{
    public const REASON_LOGOUT           = 'logout';
    public const REASON_IDLE             = 'idle_timeout';
    public const REASON_ABSOLUTE         = 'absolute_timeout';
    public const REASON_ADMIN            = 'admin_terminated';
    public const REASON_PASSWORD_CHANGED = 'password_changed';
    public const REASON_CONCURRENT       = 'concurrent_limit';
    public const REASON_HIJACK           = 'hijack_suspected';

    /**
     * Create a session and return the raw token (the only time it exists in
     * plaintext anywhere).
     *
     * @return array{token:string,session:array<string,mixed>}
     */
    public static function create(int $userId, Request $request): array
    {
        $db      = Database::instance();
        $now     = Clock::now();
        $idleMin = self::idleTimeoutMinutes();
        $absHrs  = (int) Config::get('security.session.absolute_timeout_hours', 8);

        $token     = Crypto::randomHex((int) Config::get('security.session.token_bytes', 32));
        $tokenHash = hash('sha256', $token);

        self::enforceConcurrentLimit($userId);

        $sessionId = (int) $db->insert('user_sessions', [
            'user_id'             => $userId,
            'token_hash'          => $tokenHash,
            'ip_address'          => $request->ip(),
            'ip_prefix'           => RequestContext::ipPrefix($request->ip()),
            'user_agent'          => $request->userAgent(),
            'user_agent_hash'     => hash('sha256', $request->userAgent()),
            'device_label'        => RequestContext::deviceLabel(),
            'browser_label'       => RequestContext::browser(),
            'created_at'          => $now->format('Y-m-d H:i:s'),
            'last_activity_at'    => $now->format('Y-m-d H:i:s'),
            'idle_expires_at'     => $now->modify('+' . $idleMin . ' minutes')->format('Y-m-d H:i:s'),
            'absolute_expires_at' => $now->modify('+' . $absHrs . ' hours')->format('Y-m-d H:i:s'),
        ]);

        /** @var array<string,mixed> $session */
        $session = $db->selectOne('SELECT * FROM user_sessions WHERE session_id = :id', ['id' => $sessionId]) ?? [];

        return ['token' => $token, 'session' => $session];
    }

    /**
     * Resolve a raw token to its session row (any state — the caller decides
     * what to do about expiry).
     *
     * @return array<string,mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        return Database::instance()->selectOne(
            'SELECT * FROM user_sessions WHERE token_hash = :hash LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );
    }

    /**
     * Extend the idle deadline. Only called for requests that count as real
     * user activity (Part 18.4) — passive polling never reaches here.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed> the refreshed row
     */
    public static function touch(array $session): array
    {
        $now     = Clock::now();
        $idleMin = self::idleTimeoutMinutes();
        $expires = $now->modify('+' . $idleMin . ' minutes');

        Database::instance()->update('user_sessions', [
            'last_activity_at' => $now->format('Y-m-d H:i:s'),
            'idle_expires_at'  => $expires->format('Y-m-d H:i:s'),
        ], ['session_id' => (int) $session['session_id']]);

        $session['last_activity_at'] = $now->format('Y-m-d H:i:s');
        $session['idle_expires_at']  = $expires->format('Y-m-d H:i:s');

        return $session;
    }

    /**
     * Terminate a session completely.
     *
     * "Delete the server-side session data (do not merely mark it)" — the token
     * hash is overwritten with a random value so the presented cookie can never
     * match again, while the row itself survives for the audit trail.
     */
    public static function terminate(int $sessionId, string $reason, bool $writeHistory = true): void
    {
        $db      = Database::instance();
        $session = $db->selectOne('SELECT * FROM user_sessions WHERE session_id = :id', ['id' => $sessionId]);

        if ($session === null || $session['terminated_at'] !== null) {
            return;
        }

        $now = Clock::now();

        $db->update('user_sessions', [
            'terminated_at'      => $now->format('Y-m-d H:i:s'),
            'termination_reason' => $reason,
            'token_hash'         => hash('sha256', 'terminated:' . Crypto::randomHex(32)),
        ], ['session_id' => $sessionId]);

        // Any WebSocket connection bound to this session dies with it.
        $db->execute('DELETE FROM realtime_tickets WHERE session_id = :id', ['id' => $sessionId]);

        RealtimeService::publish('user.' . (int) $session['user_id'], 'session.terminated', [
            'session_id' => $sessionId,
            'reason'     => $reason,
        ]);

        if ($writeHistory) {
            $duration = $now->getTimestamp() - Clock::parse((string) $session['created_at'])->getTimestamp();

            $db->insert('login_history', [
                'user_id'    => (int) $session['user_id'],
                'ip_address' => (string) $session['ip_address'],
                'user_agent' => $session['user_agent'],
                'browser'    => $session['browser_label'],
                'platform'   => RequestContext::platform((string) ($session['user_agent'] ?? '')),
                'status'     => match ($reason) {
                    self::REASON_IDLE     => 'idle_timeout',
                    self::REASON_ABSOLUTE => 'absolute_timeout',
                    self::REASON_ADMIN    => 'admin_terminated',
                    default               => 'logout',
                },
                'detail'                => 'Session ended: ' . $reason,
                'session_duration_sec'  => max(0, $duration),
                'created_at'            => $now->format('Y-m-d H:i:s'),
            ]);
        }

        AuditService::log(
            $reason === self::REASON_LOGOUT ? AuditService::LOGOUT : AuditService::SESSION_TIMEOUT,
            'authentication',
            'user_session',
            $sessionId,
            null,
            ['reason' => $reason],
            'Session ended (' . $reason . ')',
            'success',
            (int) $session['user_id']
        );
    }

    public static function terminateAllForUser(int $userId, string $reason, ?int $exceptSessionId = null): int
    {
        $sql      = 'SELECT session_id FROM user_sessions WHERE user_id = :user AND terminated_at IS NULL';
        $bindings = ['user' => $userId];

        if ($exceptSessionId !== null) {
            $sql .= ' AND session_id <> :except';
            $bindings['except'] = $exceptSessionId;
        }

        $rows = Database::instance()->select($sql, $bindings);

        foreach ($rows as $row) {
            self::terminate((int) $row['session_id'], $reason);
        }

        return count($rows);
    }

    /**
     * Enforce security.max_concurrent_sessions_per_user by retiring the oldest
     * sessions, and tell the user it happened.
     */
    private static function enforceConcurrentLimit(int $userId): void
    {
        $max = (int) Config::get('security.session.max_concurrent_per_user', 3);

        if ($max < 1) {
            return;
        }

        $active = Database::instance()->select(
            'SELECT session_id FROM user_sessions
              WHERE user_id = :user AND terminated_at IS NULL AND idle_expires_at > :now
              ORDER BY last_activity_at ASC',
            ['user' => $userId, 'now' => Clock::nowString()]
        );

        $excess = count($active) - ($max - 1);

        for ($i = 0; $i < $excess; $i++) {
            self::terminate((int) $active[$i]['session_id'], self::REASON_CONCURRENT);
        }

        if ($excess > 0) {
            NotificationService::toUser(
                $userId,
                'security',
                'Older session signed out',
                sprintf(
                    'You reached the limit of %d concurrent sessions, so your least recently used session was signed out.',
                    $max
                ),
                'normal'
            );
        }
    }

    /** @return array{expired:bool,reason:?string} */
    public static function evaluate(array $session): array
    {
        $now = Clock::now();

        if ($session['terminated_at'] !== null) {
            return ['expired' => true, 'reason' => (string) ($session['termination_reason'] ?? 'terminated')];
        }

        if (Clock::parse((string) $session['absolute_expires_at']) < $now) {
            return ['expired' => true, 'reason' => self::REASON_ABSOLUTE];
        }

        if (Clock::parse((string) $session['idle_expires_at']) < $now) {
            return ['expired' => true, 'reason' => self::REASON_IDLE];
        }

        return ['expired' => false, 'reason' => null];
    }

    /** Seconds until this session expires from inactivity; 0 once past. */
    public static function secondsRemaining(array $session): int
    {
        $expires = Clock::parse((string) $session['idle_expires_at'])->getTimestamp();

        return max(0, $expires - Clock::timestamp());
    }

    public static function idleTimeoutMinutes(): int
    {
        $minutes = (int) Config::get('security.session.idle_timeout_minutes', 10);
        $min     = (int) Config::get('security.session.idle_timeout_min_allowed', 5);
        $max     = (int) Config::get('security.session.idle_timeout_max_allowed', 60);

        return max($min, min($max, $minutes));
    }

    // ------------------------------------------------------------ cookies --

    public static function cookieName(): string
    {
        $name   = (string) Config::get('security.session.cookie_name', 'lsiams_session');
        $secure = (bool) Config::get('security.session.cookie.secure', true);
        $prefix = (bool) Config::get('security.session.cookie.host_prefix', true);

        // __Host- is only valid with Secure, Path=/ and no Domain. Applying it
        // over plain HTTP would make the browser silently drop the cookie.
        return $secure && $prefix ? '__Host-' . $name : $name;
    }

    public static function attachCookie(Response $response, string $token): Response
    {
        return $response->withCookie(self::cookieName(), $token, [
            'expires'  => 0, // session cookie: the server owns the real lifetime
            'path'     => (string) Config::get('security.session.cookie.path', '/'),
            'secure'   => (bool) Config::get('security.session.cookie.secure', true),
            'httponly' => true,
            'samesite' => (string) Config::get('security.session.cookie.samesite', 'Strict'),
        ]);
    }

    public static function clearCookie(Response $response): Response
    {
        return $response->withCookie(self::cookieName(), '', [
            'expires'  => time() - 3600,
            'path'     => (string) Config::get('security.session.cookie.path', '/'),
            'secure'   => (bool) Config::get('security.session.cookie.secure', true),
            'httponly' => true,
            'samesite' => (string) Config::get('security.session.cookie.samesite', 'Strict'),
        ]);
    }

    public static function tokenFromRequest(Request $request): ?string
    {
        $token = $_COOKIE[self::cookieName()] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    // ------------------------------------------------------ admin listing --

    /** @return list<array<string,mixed>> */
    public static function activeSessions(): array
    {
        return Database::instance()->select(
            "SELECT s.session_id, s.user_id, u.username, u.full_name, r.role_name,
                    s.ip_address, s.device_label, s.browser_label, s.created_at,
                    s.last_activity_at, s.idle_expires_at, s.absolute_expires_at,
                    TIMESTAMPDIFF(SECOND, s.last_activity_at, NOW()) AS idle_seconds,
                    TIMESTAMPDIFF(SECOND, NOW(), s.idle_expires_at) AS expires_in_seconds
               FROM user_sessions s
               JOIN users u ON u.user_id = s.user_id
               JOIN roles r ON r.role_id = u.role_id
              WHERE s.terminated_at IS NULL AND s.idle_expires_at > NOW()
              ORDER BY s.last_activity_at DESC"
        );
    }

    /** Housekeeping sweep — closes sessions whose deadline has passed. */
    public static function expireStaleSessions(): int
    {
        $rows = Database::instance()->select(
            'SELECT session_id, idle_expires_at, absolute_expires_at
               FROM user_sessions
              WHERE terminated_at IS NULL
                AND (idle_expires_at < :now OR absolute_expires_at < :now)
              LIMIT 500',
            ['now' => Clock::nowString()]
        );

        foreach ($rows as $row) {
            $reason = Clock::parse((string) $row['absolute_expires_at']) < Clock::now()
                ? self::REASON_ABSOLUTE
                : self::REASON_IDLE;

            self::terminate((int) $row['session_id'], $reason);
        }

        return count($rows);
    }
}
