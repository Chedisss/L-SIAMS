<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Realtime;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Real-time event bus (Part 17.4 – 17.7).
 *
 * Events are written to `realtime_events` with a monotonic per-channel
 * sequence. The WebSocket process drains that table and fans out; SSE and
 * long-polling clients read the same rows. One write path, three transports —
 * which is what makes the replay-on-reconnect guarantee hold regardless of
 * which tier a client happens to be on.
 */
final class RealtimeService
{
    public const CHANNEL_ADMIN    = 'dashboard.admin';
    public const CHANNEL_SECURITY = 'security.alerts';

    public static function teacherChannel(int $teacherId): string
    {
        return 'teacher.' . $teacherId;
    }

    public static function sessionChannel(int|string $sessionId): string
    {
        return 'session.' . $sessionId;
    }

    public static function deviceChannel(string $deviceId): string
    {
        return 'device.' . $deviceId;
    }

    public static function sectionChannel(int $sectionId): string
    {
        return 'section.' . $sectionId;
    }

    /**
     * Append an event and return its per-channel sequence number.
     *
     * The cursor row is locked with an atomic UPDATE-then-SELECT rather than a
     * read-modify-write, so two concurrent taps on the same session can never
     * be handed the same sequence.
     *
     * @param array<string,mixed> $data
     */
    public static function publish(string $channel, string $eventType, array $data): int
    {
        try {
            $db = Database::instance();

            $db->execute(
                'INSERT INTO realtime_channel_cursors (channel, last_sequence)
                      VALUES (:channel, 1)
                 ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1',
                ['channel' => $channel]
            );

            $sequence = (int) $db->scalar(
                'SELECT last_sequence FROM realtime_channel_cursors WHERE channel = :channel',
                ['channel' => $channel]
            );

            $payload = [
                'event'     => $eventType,
                'channel'   => $channel,
                'timestamp' => Clock::atom(),
                'sequence'  => $sequence,
                'data'      => $data,
            ];

            $db->insert('realtime_events', [
                'channel'    => $channel,
                'sequence'   => $sequence,
                'event_type' => $eventType,
                'payload'    => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'created_at' => Clock::now()->format('Y-m-d H:i:s.v'),
            ]);

            return $sequence;
        } catch (Throwable $e) {
            // Losing a dashboard update must never fail the attendance write
            // that produced it. The record is already committed; the dashboard
            // will catch up on its next poll or replay.
            Logger::error('Realtime publish failed', [
                'channel' => $channel,
                'event'   => $eventType,
                'error'   => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Publish the same event to several channels (a tap belongs to the session,
     * the teacher, the section and the admin dashboard at once).
     *
     * @param list<string>        $channels
     * @param array<string,mixed> $data
     */
    public static function broadcast(array $channels, string $eventType, array $data): void
    {
        foreach (array_unique($channels) as $channel) {
            self::publish($channel, $eventType, $data);
        }
    }

    /**
     * Events after $sinceSequence on a channel, for reconnect replay.
     *
     * @return list<array<string,mixed>>
     */
    public static function replay(string $channel, int $sinceSequence, int $limit = 500): array
    {
        $limit = max(1, min($limit, (int) Config::get('realtime.fallback.replay_max_events', 500)));

        $rows = Database::instance()->select(
            'SELECT sequence, event_type, payload, created_at
               FROM realtime_events
              WHERE channel = :channel AND sequence > :since
              ORDER BY sequence ASC
              LIMIT ' . $limit,
            ['channel' => $channel, 'since' => $sinceSequence]
        );

        return array_map(
            static fn (array $row): array => json_decode((string) $row['payload'], true) ?? [],
            $rows
        );
    }

    /**
     * Events across several channels, ordered globally — used by the SSE and
     * long-polling fallbacks, which subscribe to everything a user may see.
     *
     * @param  list<string> $channels
     * @return array{events:list<array<string,mixed>>,cursor:int}
     */
    public static function poll(array $channels, int $sinceEventId, int $limit = 200): array
    {
        if ($channels === []) {
            return ['events' => [], 'cursor' => $sinceEventId];
        }

        $placeholders = [];
        $bindings     = ['since' => $sinceEventId];

        foreach (array_values($channels) as $index => $channel) {
            $placeholders[]          = ':ch' . $index;
            $bindings['ch' . $index] = $channel;
        }

        $rows = Database::instance()->select(
            'SELECT event_id, payload
               FROM realtime_events
              WHERE event_id > :since
                AND channel IN (' . implode(',', $placeholders) . ')
              ORDER BY event_id ASC
              LIMIT ' . max(1, min($limit, 500)),
            $bindings
        );

        $events = [];
        $cursor = $sinceEventId;

        foreach ($rows as $row) {
            $cursor   = (int) $row['event_id'];
            $decoded  = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return ['events' => $events, 'cursor' => $cursor];
    }

    // ------------------------------------------------------------ tickets --

    /**
     * Mint a single-use, 60-second WebSocket handshake ticket (Part 17.6).
     *
     * Cookies are not reliable across a WS handshake, and passing the session
     * token in a query string would put it in server logs. The ticket is
     * short-lived, single-use, bound to the session, and carries the exact
     * channel list the holder may subscribe to — the WS server never has to
     * re-derive authorisation.
     *
     * @return array{ticket:string,expires_at:string,channels:list<string>,url:string}
     */
    public static function issueTicket(): array
    {
        $user      = Auth::user();
        $sessionId = Auth::sessionId();

        if ($user === null || $sessionId === null) {
            throw new \App\Core\Exceptions\AuthenticationException();
        }

        $channels = self::channelsForCurrentUser();
        $ttl      = (int) Config::get('realtime.ticket.ttl_seconds', 60);
        $expires  = Clock::now()->modify('+' . $ttl . ' seconds');

        $payload = [
            'uid' => Auth::id(),
            'sid' => $sessionId,
            'rol' => Auth::role(),
            'chs' => $channels,
            'exp' => $expires->getTimestamp(),
            'jti' => Crypto::randomHex(12),
        ];

        $body      = Crypto::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = Crypto::base64UrlEncode(
            hash_hmac('sha256', $body, Crypto::realtimeTicketSecret(), true)
        );
        $ticket    = $body . '.' . $signature;

        Database::instance()->insert('realtime_tickets', [
            'ticket_hash' => hash('sha256', $ticket),
            'user_id'     => Auth::id(),
            'session_id'  => $sessionId,
            'channels'    => (string) json_encode($channels),
            'expires_at'  => $expires->format('Y-m-d H:i:s'),
            'created_at'  => Clock::nowString(),
        ]);

        return [
            'ticket'     => $ticket,
            'expires_at' => $expires->format(DATE_ATOM),
            'channels'   => $channels,
            'url'        => Realtime::publicUrl(),
        ];
    }

    /**
     * Verify and consume a ticket. Called by the WebSocket server process.
     *
     * @return array{user_id:int,session_id:int,role:string,channels:list<string>}|null
     */
    public static function consumeTicket(string $ticket): ?array
    {
        $parts = explode('.', $ticket);

        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;

        $expected = Crypto::base64UrlEncode(
            hash_hmac('sha256', $body, Crypto::realtimeTicketSecret(), true)
        );

        if (!hash_equals($expected, $signature)) {
            SecurityLogService::log(
                SecurityLogService::UNAUTHORIZED_CHANNEL_SUBSCRIBE,
                'high',
                'WebSocket ticket signature mismatch.'
            );

            return null;
        }

        /** @var array<string,mixed>|null $payload */
        $payload = json_decode(Crypto::base64UrlDecode($body), true);

        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < Clock::timestamp()) {
            return null;
        }

        // Single use: the UPDATE only matches while consumed_at is still NULL,
        // so a replayed ticket affects zero rows and is rejected.
        $hash    = hash('sha256', $ticket);
        $applied = Database::instance()->execute(
            'UPDATE realtime_tickets
                SET consumed_at = :now
              WHERE ticket_hash = :hash AND consumed_at IS NULL AND expires_at > :now',
            ['hash' => $hash, 'now' => Clock::nowString()]
        );

        if ($applied !== 1) {
            return null;
        }

        return [
            'user_id'    => (int) $payload['uid'],
            'session_id' => (int) $payload['sid'],
            'role'       => (string) $payload['rol'],
            'channels'   => array_values(array_map('strval', (array) ($payload['chs'] ?? []))),
        ];
    }

    /**
     * The exact set of channels the signed-in user may subscribe to.
     *
     * Authorisation is decided here, once, at ticket-issue time — never
     * client-side and never by trusting a subscribe frame.
     *
     * @return list<string>
     */
    public static function channelsForCurrentUser(): array
    {
        if (Auth::isAdmin()) {
            // Wildcards are expanded by the WS server against this same list.
            return [
                self::CHANNEL_ADMIN,
                self::CHANNEL_SECURITY,
                'device.*',
                'session.*',
                'teacher.*',
                'section.*',
                'user.' . Auth::id(),
            ];
        }

        $teacherId = Auth::teacherId();

        if ($teacherId === null) {
            return ['user.' . Auth::id()];
        }

        $channels = [
            self::teacherChannel($teacherId),
            'user.' . Auth::id(),
        ];

        // A teacher may watch their own open sessions and the sections they
        // teach — nothing else. Resolved server-side from real assignments.
        $sessions = Database::instance()->select(
            "SELECT session_id FROM attendance_sessions
              WHERE teacher_id = :teacher AND status = 'open'",
            ['teacher' => $teacherId]
        );

        foreach ($sessions as $session) {
            $channels[] = self::sessionChannel((int) $session['session_id']);
        }

        $sections = Database::instance()->select(
            'SELECT DISTINCT section_id FROM schedules
              WHERE teacher_id = :teacher AND status = \'active\' AND deleted_at IS NULL',
            ['teacher' => $teacherId]
        );

        foreach ($sections as $section) {
            $channels[] = self::sectionChannel((int) $section['section_id']);
        }

        return array_values(array_unique($channels));
    }

    /**
     * Does a granted channel list permit this exact channel?
     * Supports the `prefix.*` wildcard used for administrators.
     *
     * @param list<string> $granted
     */
    public static function channelPermitted(array $granted, string $channel): bool
    {
        foreach ($granted as $pattern) {
            if ($pattern === $channel) {
                return true;
            }

            if (str_ends_with($pattern, '.*')
                && str_starts_with($channel, substr($pattern, 0, -1))
            ) {
                return true;
            }
        }

        return false;
    }

    /** Retention sweep for the replay buffer. */
    public static function purgeOldEvents(int $hours): int
    {
        return Database::instance()->execute(
            'DELETE FROM realtime_events WHERE created_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)',
            ['hours' => $hours]
        );
    }

    public static function purgeExpiredTickets(): int
    {
        return Database::instance()->execute(
            'DELETE FROM realtime_tickets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
    }
}
