#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * L-SIAMS WebSocket server (Part 17.4, Tier 1).
 *
 * Written against raw stream sockets rather than Ratchet or a Node sidecar,
 * for the same reason as the PDF and XLSX writers: this runs on an isolated
 * school LAN where `composer install` and `npm install` may be impossible, and
 * the IT staff need to be able to redeploy from a USB stick.
 *
 * Responsibilities:
 *   · terminate WSS using the same certificate as the web application
 *   · authenticate each connection with a single-use ticket (Part 17.6)
 *   · re-validate every connection's session once a minute, closing 4001 when
 *     the session has ended — so a browser logged out elsewhere stops
 *     receiving attendance data immediately
 *   · drain `realtime_events` and fan out to subscribers, filtered by the
 *     channel list the ticket granted (never by anything the client asserts)
 *
 * Run as a service:
 *   systemd:        docs/deploy/lsiams-realtime.service
 *   Windows:        install with NSSM, see docs/DEPLOYMENT.md
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This server must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\RealtimeService;
use App\Services\SecurityLogService;
use App\Services\SettingsService;

Logger::setChannel('realtime');

final class WebSocketServer
{
    private const OPCODE_TEXT   = 0x1;
    private const OPCODE_CLOSE  = 0x8;
    private const OPCODE_PING   = 0x9;
    private const OPCODE_PONG   = 0xA;

    /** @var resource */
    private $listener;
    /** @var array<int,array<string,mixed>> */
    private array $clients = [];
    private int $lastEventId = 0;
    private float $lastRevalidate = 0.0;
    private bool $running = true;

    public function run(): void
    {
        $host = (string) Config::get('realtime.ws_host', '0.0.0.0');
        $port = (int) Config::get('realtime.ws_port', 8443);

        $context = stream_context_create($this->contextOptions());

        $scheme = Config::get('realtime.tls.enabled', true) && $this->certificateAvailable()
            ? 'tls'
            : 'tcp';

        $this->listener = @stream_socket_server(
            sprintf('%s://%s:%d', $scheme, $host, $port),
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($this->listener === false) {
            $this->out(sprintf('Could not bind %s://%s:%d — %s', $scheme, $host, $port, $errorMessage), 'red');
            exit(1);
        }

        stream_set_blocking($this->listener, false);

        $this->out('');
        $this->out('  L-SIAMS realtime server', 'bold');
        $this->out(sprintf('  Listening on %s://%s:%d', $scheme, $host, $port), 'cyan');

        if ($scheme === 'tcp') {
            $this->out('  ⚠ TLS certificate not found — running plaintext. Do not use in production.', 'yellow');
        }

        $this->out('  Press Ctrl+C to stop.', 'grey');
        $this->out('');

        // Start from the current tail so a restart does not replay history to
        // everyone; clients that missed events ask for a replay themselves.
        $this->lastEventId = (int) Database::instance()->scalar(
            'SELECT COALESCE(MAX(event_id), 0) FROM realtime_events'
        );

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->shutdown());
            pcntl_signal(SIGTERM, fn () => $this->shutdown());
        }

        $pollMs = (int) Config::get('realtime.dispatch_poll_ms', 250);

        while ($this->running) {
            $this->acceptConnections();
            $this->readClients();
            $this->dispatchEvents();
            $this->maintain();

            usleep($pollMs * 1000);
        }

        $this->out('Shutting down…', 'yellow');

        foreach (array_keys($this->clients) as $id) {
            $this->closeClient($id, 1001, 'Server shutting down');
        }

        fclose($this->listener);
    }

    /** @return array<string,mixed> */
    private function contextOptions(): array
    {
        if (!$this->certificateAvailable()) {
            return [];
        }

        return [
            'ssl' => [
                'local_cert'          => (string) Config::get('realtime.tls.cert_file'),
                'local_pk'            => (string) Config::get('realtime.tls.key_file'),
                'allow_self_signed'   => true,
                'verify_peer'         => false,
                'verify_peer_name'    => false,
                // TLS 1.2 minimum, per Part 6.
                'crypto_method'       => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
                'disable_compression' => true,
            ],
        ];
    }

    private function certificateAvailable(): bool
    {
        $cert = (string) Config::get('realtime.tls.cert_file', '');
        $key  = (string) Config::get('realtime.tls.key_file', '');

        return $cert !== '' && $key !== '' && is_readable($cert) && is_readable($key);
    }

    private function acceptConnections(): void
    {
        $socket = @stream_socket_accept($this->listener, 0, $peer);

        if ($socket === false) {
            return;
        }

        $max = (int) Config::get('realtime.max_connections', 200);

        if (count($this->clients) >= $max) {
            @fwrite($socket, "HTTP/1.1 503 Service Unavailable\r\n\r\n");
            @fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);

        $id = (int) $socket;

        $this->clients[$id] = [
            'socket'      => $socket,
            'peer'        => $peer,
            'handshaken'  => false,
            'buffer'      => '',
            'user_id'     => null,
            'session_id'  => null,
            'role'        => null,
            'channels'    => [],
            'connected_at' => microtime(true),
            'last_seen'   => microtime(true),
            'last_check'  => microtime(true),
        ];
    }

    private function readClients(): void
    {
        foreach ($this->clients as $id => $client) {
            $data = @fread($client['socket'], 65536);

            if ($data === false || ($data === '' && feof($client['socket']))) {
                $this->closeClient($id, 1001, 'Client disconnected');
                continue;
            }

            if ($data === '') {
                continue;
            }

            $this->clients[$id]['buffer']    .= $data;
            $this->clients[$id]['last_seen']  = microtime(true);

            if (!$this->clients[$id]['handshaken']) {
                $this->attemptHandshake($id);
                continue;
            }

            $this->consumeFrames($id);
        }
    }

    private function attemptHandshake(int $id): void
    {
        $buffer = $this->clients[$id]['buffer'];

        if (!str_contains($buffer, "\r\n\r\n")) {
            // Refuse an oversized pre-handshake buffer rather than growing
            // memory on a client that will never complete.
            if (strlen($buffer) > 16384) {
                $this->closeClient($id, 1009, 'Handshake too large');
            }

            return;
        }

        [$headerBlock] = explode("\r\n\r\n", $buffer, 2);
        $lines         = explode("\r\n", $headerBlock);
        $requestLine   = array_shift($lines) ?? '';
        $headers       = [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $key = $headers['sec-websocket-key'] ?? '';

        if ($key === '' || !str_contains(strtolower($headers['upgrade'] ?? ''), 'websocket')) {
            $this->reject($id, 400, 'Not a WebSocket request');

            return;
        }

        // Ticket comes from the query string (Part 17.6): cookies do not
        // travel reliably through a WS handshake, and putting the session
        // token here would leave it in access logs.
        $query = parse_url(explode(' ', $requestLine)[1] ?? '/', PHP_URL_QUERY) ?? '';
        parse_str($query, $params);

        $ticket = (string) ($params['ticket'] ?? '');

        if ($ticket === '') {
            $this->reject($id, 401, 'Ticket required');

            return;
        }

        $identity = RealtimeService::consumeTicket($ticket);

        if ($identity === null) {
            SecurityLogService::log(
                SecurityLogService::UNAUTHORIZED_CHANNEL_SUBSCRIBE,
                'high',
                sprintf('WebSocket handshake rejected from %s: invalid or reused ticket.', $this->clients[$id]['peer'])
            );

            $this->reject($id, 401, 'Invalid ticket');

            return;
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . $accept . "\r\n\r\n";

        @fwrite($this->clients[$id]['socket'], $response);

        $this->clients[$id]['handshaken'] = true;
        $this->clients[$id]['buffer']     = '';
        $this->clients[$id]['user_id']    = $identity['user_id'];
        $this->clients[$id]['session_id'] = $identity['session_id'];
        $this->clients[$id]['role']       = $identity['role'];
        $this->clients[$id]['channels']   = $identity['channels'];

        $this->send($id, [
            'type'        => 'welcome',
            'channels'    => $identity['channels'],
            'server_time' => Clock::atom(),
        ]);

        $this->out(sprintf(
            '+ %s connected as user #%d (%s) — %d channel(s), %d client(s) total',
            $this->clients[$id]['peer'],
            $identity['user_id'],
            $identity['role'],
            count($identity['channels']),
            count($this->clients)
        ), 'green');
    }

    private function reject(int $id, int $status, string $message): void
    {
        $body = sprintf("HTTP/1.1 %d %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $status, $message);
        @fwrite($this->clients[$id]['socket'], $body);
        $this->closeClient($id, 1008, $message, false);
    }

    private function consumeFrames(int $id): void
    {
        while (true) {
            $frame = $this->decodeFrame($this->clients[$id]['buffer']);

            if ($frame === null) {
                return;
            }

            $this->clients[$id]['buffer'] = substr($this->clients[$id]['buffer'], $frame['length']);

            switch ($frame['opcode']) {
                case self::OPCODE_CLOSE:
                    $this->closeClient($id, 1000, 'Client closed');

                    return;

                case self::OPCODE_PING:
                    $this->sendRaw($id, $frame['payload'], self::OPCODE_PONG);
                    break;

                case self::OPCODE_PONG:
                    break;

                case self::OPCODE_TEXT:
                    $this->handleMessage($id, $frame['payload']);
                    break;
            }
        }
    }

    private function handleMessage(int $id, string $payload): void
    {
        $message = json_decode($payload, true);

        if (!is_array($message)) {
            return;
        }

        $type = (string) ($message['type'] ?? '');

        if ($type === 'ping') {
            $this->send($id, ['type' => 'pong', 'server_time' => Clock::atom()]);

            return;
        }

        if ($type === 'subscribe') {
            $channel = (string) ($message['channel'] ?? '');

            // Authorisation was decided when the ticket was issued. A subscribe
            // frame can only ever narrow that set, never widen it.
            if (!RealtimeService::channelPermitted($this->clients[$id]['channels'], $channel)) {
                SecurityLogService::log(
                    SecurityLogService::UNAUTHORIZED_CHANNEL_SUBSCRIBE,
                    'high',
                    sprintf(
                        'User #%d attempted to subscribe to "%s", which their ticket does not grant.',
                        (int) $this->clients[$id]['user_id'],
                        $channel
                    ),
                    ['granted' => $this->clients[$id]['channels']]
                );

                $this->send($id, [
                    'type'    => 'error',
                    'code'    => 'CHANNEL_FORBIDDEN',
                    'message' => 'You are not permitted to subscribe to that channel.',
                ]);

                $this->closeClient($id, 4003, 'Channel forbidden');

                return;
            }

            $this->send($id, ['type' => 'subscribed', 'channel' => $channel]);
        }
    }

    /**
     * Drain new rows from `realtime_events` and fan out to the clients whose
     * granted channel list covers each event's channel.
     */
    private function dispatchEvents(): void
    {
        if ($this->clients === []) {
            // Nothing connected: advance the cursor so a later connection does
            // not receive a backlog it never asked for.
            $this->lastEventId = (int) Database::instance()->scalar(
                'SELECT COALESCE(MAX(event_id), 0) FROM realtime_events'
            );

            return;
        }

        try {
            $rows = Database::instance()->select(
                'SELECT event_id, channel, payload FROM realtime_events
                  WHERE event_id > :since
                  ORDER BY event_id ASC
                  LIMIT 200',
                ['since' => $this->lastEventId]
            );
        } catch (Throwable $e) {
            Logger::error('Realtime dispatch query failed', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($rows as $row) {
            $this->lastEventId = (int) $row['event_id'];
            $channel           = (string) $row['channel'];
            $payload           = (string) $row['payload'];
            $delivered         = 0;

            foreach ($this->clients as $id => $client) {
                if (!$client['handshaken']) {
                    continue;
                }

                if (!RealtimeService::channelPermitted($client['channels'], $channel)) {
                    continue;
                }

                $this->sendRaw($id, $payload, self::OPCODE_TEXT);
                $delivered++;
            }

            if ($delivered > 0) {
                $this->out(sprintf('→ %s to %d client(s)', $channel, $delivered), 'grey');
            }
        }
    }

    /**
     * Periodic housekeeping: session re-validation, ping, idle reaping.
     *
     * Re-validating means a user signed out elsewhere — or timed out — stops
     * receiving live attendance data within a minute, not whenever they happen
     * to reload (Part 17.6).
     */
    private function maintain(): void
    {
        $now = microtime(true);

        if ($now - $this->lastRevalidate < (float) Config::get('realtime.session_revalidate_seconds', 60)) {
            return;
        }

        $this->lastRevalidate = $now;

        $timeout = (float) Config::get('realtime.client_timeout_seconds', 70);

        foreach ($this->clients as $id => $client) {
            if (!$client['handshaken']) {
                if ($now - $client['connected_at'] > 15) {
                    $this->closeClient($id, 1002, 'Handshake timeout');
                }

                continue;
            }

            if ($now - $client['last_seen'] > $timeout) {
                $this->closeClient($id, 1001, 'Idle timeout');

                continue;
            }

            try {
                $alive = Database::instance()->scalar(
                    'SELECT 1 FROM user_sessions
                      WHERE session_id = :id AND terminated_at IS NULL AND idle_expires_at > NOW()',
                    ['id' => (int) $client['session_id']]
                );
            } catch (Throwable) {
                continue;
            }

            if ($alive === null) {
                $this->send($id, ['type' => 'session_expired']);
                $this->closeClient($id, 4001, 'SESSION_EXPIRED');

                $this->out(sprintf('- user #%d session ended — connection closed', (int) $client['user_id']), 'yellow');

                continue;
            }

            $this->sendRaw($id, '', self::OPCODE_PING);
        }
    }

    /** @param array<string,mixed> $payload */
    private function send(int $id, array $payload): void
    {
        $this->sendRaw($id, (string) json_encode($payload, JSON_UNESCAPED_SLASHES), self::OPCODE_TEXT);
    }

    private function sendRaw(int $id, string $payload, int $opcode): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $written = @fwrite($this->clients[$id]['socket'], $this->encodeFrame($payload, $opcode));

        if ($written === false) {
            $this->closeClient($id, 1011, 'Write failed');
        }
    }

    private function encodeFrame(string $payload, int $opcode): string
    {
        $length = strlen($payload);
        $frame  = chr(0x80 | $opcode);

        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }

        return $frame . $payload;
    }

    /** @return array{opcode:int,payload:string,length:int}|null */
    private function decodeFrame(string $buffer): ?array
    {
        $bufferLength = strlen($buffer);

        if ($bufferLength < 2) {
            return null;
        }

        $firstByte  = ord($buffer[0]);
        $secondByte = ord($buffer[1]);

        $opcode  = $firstByte & 0x0F;
        $masked  = (bool) ($secondByte & 0x80);
        $length  = $secondByte & 0x7F;
        $offset  = 2;

        if ($length === 126) {
            if ($bufferLength < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($bufferLength < 10) {
                return null;
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        $maxFrame = (int) Config::get('realtime.max_frame_bytes', 131072);

        if ($length > $maxFrame) {
            return ['opcode' => self::OPCODE_CLOSE, 'payload' => '', 'length' => $bufferLength];
        }

        $maskLength = $masked ? 4 : 0;

        if ($bufferLength < $offset + $maskLength + $length) {
            return null;
        }

        $mask   = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskLength;

        $payload = substr($buffer, $offset, $length);

        // Browser-to-server frames are always masked; unmasking is mandatory.
        if ($masked) {
            $unmasked = '';

            for ($i = 0; $i < $length; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }

            $payload = $unmasked;
        }

        return ['opcode' => $opcode, 'payload' => $payload, 'length' => $offset + $length];
    }

    private function closeClient(int $id, int $code, string $reason, bool $sendClose = true): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        if ($sendClose && $this->clients[$id]['handshaken']) {
            $payload = pack('n', $code) . substr($reason, 0, 120);
            @fwrite($this->clients[$id]['socket'], $this->encodeFrame($payload, self::OPCODE_CLOSE));
        }

        @fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
    }

    private function shutdown(): void
    {
        $this->running = false;
    }

    private function out(string $message, string $colour = ''): void
    {
        $colours = [
            'red' => "\033[31m", 'green' => "\033[32m", 'yellow' => "\033[33m",
            'cyan' => "\033[36m", 'grey' => "\033[90m", 'bold' => "\033[1m",
        ];

        $prefix = $colours[$colour] ?? '';
        $suffix = $prefix === '' ? '' : "\033[0m";

        fwrite(STDOUT, sprintf('[%s] %s%s%s%s', date('H:i:s'), $prefix, $message, $suffix, PHP_EOL));
    }
}

SettingsService::hydrate();
(new WebSocketServer())->run();
