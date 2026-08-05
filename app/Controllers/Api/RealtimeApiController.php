<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\RealtimeService;
use App\Services\SecurityLogService;

/**
 * Real-time transport endpoints (Part 17).
 *
 * Tier 1 (WebSocket) authenticates with a ticket minted here. Tiers 2 and 3
 * (SSE and long-polling) read the same `realtime_events` rows through `poll`
 * and `replay`, so no client can miss an event regardless of which tier it
 * ends up on.
 */
final class RealtimeApiController extends Controller
{
    /** POST /api/realtime/ticket — single-use, 60-second WebSocket handshake ticket. */
    public function ticket(Request $request): Response
    {
        return $this->json(RealtimeService::issueTicket(), 'Ticket issued.');
    }

    /**
     * GET /api/realtime/replay?channel=&since=
     *
     * Catch-up after a reconnect. Channel authorisation is re-checked here
     * rather than trusted from the query string.
     */
    public function replay(Request $request): Response
    {
        $channel = $request->string('channel', '');
        $since   = $request->int('since', 0);

        if ($channel === '') {
            return $this->fail('CHANNEL_REQUIRED', 'Specify a channel.', 422);
        }

        $granted = RealtimeService::channelsForCurrentUser();

        if (!RealtimeService::channelPermitted($granted, $channel)) {
            SecurityLogService::log(
                SecurityLogService::UNAUTHORIZED_CHANNEL_SUBSCRIBE,
                'high',
                sprintf('User "%s" attempted to replay channel "%s".', Auth::username() ?? '?', $channel),
                ['channel' => $channel, 'granted' => $granted]
            );

            return $this->fail('CHANNEL_FORBIDDEN', 'You are not permitted to subscribe to that channel.', 403);
        }

        $events = RealtimeService::replay($channel, $since);

        return $this->json([
            'channel'       => $channel,
            'since'         => $since,
            'events'        => $events,
            'last_sequence' => $events === [] ? $since : (int) end($events)['sequence'],
            'server_time'   => Clock::atom(),
        ]);
    }

    /**
     * GET /api/realtime/poll?cursor=
     *
     * Tier 3 fallback. Returns everything new across all the caller's channels.
     */
    public function poll(Request $request): Response
    {
        $cursor   = $request->int('cursor', 0);
        $channels = RealtimeService::channelsForCurrentUser();

        // Wildcards only mean something to the WS server's matcher; expand the
        // administrator's set into the concrete channels that actually exist.
        $concrete = [];

        foreach ($channels as $channel) {
            if (!str_ends_with($channel, '.*')) {
                $concrete[] = $channel;
                continue;
            }

            $prefix = substr($channel, 0, -1);

            $rows = \App\Core\Database::instance()->select(
                'SELECT channel FROM realtime_channel_cursors WHERE channel LIKE :prefix',
                ['prefix' => $prefix . '%']
            );

            foreach ($rows as $row) {
                $concrete[] = (string) $row['channel'];
            }
        }

        $result = RealtimeService::poll(array_values(array_unique($concrete)), $cursor);

        return $this->json([
            'events'      => $result['events'],
            'cursor'      => $result['cursor'],
            'channels'    => $concrete,
            'server_time' => Clock::atom(),
            'next_poll_ms' => (int) Config::get('realtime.fallback.poll_interval_ms', 3000),
        ]);
    }

    /**
     * GET /api/realtime/stream — Server-Sent Events (Tier 2).
     *
     * Streams directly rather than returning a Response, because the whole
     * point is to keep the connection open. Output buffering is disabled so
     * events reach the browser as they are written.
     */
    public function stream(Request $request): Response
    {
        if (!Config::get('realtime.fallback.sse_enabled', true)) {
            return $this->fail('SSE_DISABLED', 'Server-sent events are disabled.', 503);
        }

        $cursor   = $request->int('cursor', 0);
        $channels = RealtimeService::channelsForCurrentUser();
        $deadline = time() + 30; // reconnect every 30s so PHP-FPM workers recycle

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        echo "retry: 3000\n\n";
        flush();

        while (time() < $deadline) {
            if (connection_aborted() === 1) {
                break;
            }

            $result = RealtimeService::poll($channels, $cursor);

            foreach ($result['events'] as $event) {
                printf(
                    "id: %d\nevent: %s\ndata: %s\n\n",
                    $result['cursor'],
                    (string) ($event['event'] ?? 'message'),
                    (string) json_encode($event, JSON_UNESCAPED_SLASHES)
                );
            }

            $cursor = $result['cursor'];

            // Comment frame doubles as a keep-alive through idle proxies.
            echo ': ping ' . Clock::atom() . "\n\n";
            flush();

            sleep(2);
        }

        exit;
    }
}
