<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'enabled'    => true,
    'ws_host'    => Env::get('REALTIME_WS_HOST', '0.0.0.0'),
    'ws_port'    => (int) Env::get('REALTIME_WS_PORT', '8443'),
    'public_url' => Env::get('REALTIME_WS_PUBLIC_URL', 'wss://192.168.1.10:8443'),

    'tls' => [
        'enabled'   => true,
        'cert_file' => Env::get('REALTIME_TLS_CERT', '/etc/ssl/lsiams/server.crt'),
        'key_file'  => Env::get('REALTIME_TLS_KEY', '/etc/ssl/lsiams/server.key'),
    ],

    // Short-lived handshake tickets (Part 17.6). Cookies are unreliable for WS.
    'ticket' => [
        'ttl_seconds' => 60,
        'algo'        => 'sha256',
    ],

    'session_revalidate_seconds' => 60,
    'ping_interval_seconds'      => 25,
    'client_timeout_seconds'     => 70,
    'max_connections'            => 200,
    'max_frame_bytes'            => 128 * 1024,

    // Poll interval used by the WS process to drain `realtime_events`.
    'dispatch_poll_ms' => 250,

    'fallback' => [
        'sse_enabled'          => true,
        'poll_interval_ms'     => 3000,
        'replay_max_events'    => 500,
    ],

    'channels' => [
        'dashboard.admin' => ['roles' => ['administrator']],
        'security.alerts' => ['roles' => ['administrator']],
        'device.*'        => ['roles' => ['administrator']],
        'teacher.*'       => ['roles' => ['administrator', 'teacher'], 'owner_scoped' => true],
        'session.*'       => ['roles' => ['administrator', 'teacher'], 'owner_scoped' => true],
        'section.*'       => ['roles' => ['administrator', 'teacher'], 'owner_scoped' => true],
    ],
];
