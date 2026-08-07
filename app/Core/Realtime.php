<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Where a browser should connect for live updates.
 *
 * Why this is not simply a config value
 * -------------------------------------
 * The realtime server listens on its own port, so its URL is never same-origin
 * and cannot be expressed as a relative path. A single hard-coded value works
 * only as long as every client reaches the server by the same name.
 *
 * On a school LAN they do not. The machine running the system opens the console
 * at http://localhost:8080; every other device — the head teacher's laptop, a
 * phone in the corridor — reaches it at http://192.168.1.x:8080. Handing those
 * devices "ws://localhost:8443" tells each of them to connect to *itself*,
 * which fails silently and leaves the connection indicator on "Connecting"
 * forever, with no error a non-technical administrator could act on.
 *
 * So the endpoint follows the host the browser actually used, unless an
 * explicit REALTIME_WS_PUBLIC_URL is configured — which is what a real
 * deployment behind a TLS-terminating reverse proxy needs, and which therefore
 * always wins.
 */
final class Realtime
{
    /**
     * The WebSocket URL to hand to a client, e.g. "ws://192.168.1.10:8443".
     *
     * Returns an empty string only when there is no configured URL and no
     * request to derive one from (the CLI worker, for instance).
     */
    public static function publicUrl(): string
    {
        $configured = trim((string) Config::get('realtime.public_url', ''));

        if ($configured !== '') {
            return $configured;
        }

        $host = Site::host();

        if ($host === null) {
            return '';
        }

        $port   = (int) Config::get('realtime.ws_port', 8443);
        $scheme = Config::get('realtime.tls.enabled', true) ? 'wss' : 'ws';

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    /**
     * The same endpoint reduced to a Content-Security-Policy source: scheme,
     * host and port, never a path — CSP sources may not carry one.
     *
     * Falls back to the bare schemes rather than to nothing, so a configuration
     * this code cannot parse degrades to a permissive policy instead of
     * blocking every live update with no visible cause.
     */
    public static function cspOrigin(): string
    {
        $url = self::publicUrl();

        if ($url === '') {
            return 'ws: wss:';
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'ws: wss:';
        }

        return sprintf(
            '%s://%s%s',
            $parts['scheme'],
            $parts['host'],
            isset($parts['port']) ? ':' . (int) $parts['port'] : ''
        );
    }

}
