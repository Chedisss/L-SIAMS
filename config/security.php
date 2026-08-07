<?php
declare(strict_types=1);

use App\Core\Env;

if (!function_exists('realtime_csp_origin')) {
    /**
     * The realtime endpoint as a Content-Security-Policy source.
     *
     * Returns just the scheme, host and port — "ws://localhost:8443" — because
     * a CSP source may not carry a path. Falls back to the two schemes rather
     * than to nothing if the URL is unset or unparseable, so a misconfiguration
     * degrades to the old permissive behaviour instead of silently blocking
     * every live update with no clue as to why.
     */
    function realtime_csp_origin(): string
    {
        $url = trim((string) Env::get('REALTIME_WS_PUBLIC_URL', ''));

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

return [
    // --- Passwords ---------------------------------------------------------
    'password' => [
        // bcrypt cost 12 for humans: low-entropy secrets must be slow to verify.
        'algo'          => PASSWORD_BCRYPT,
        'options'       => ['cost' => 12],
        'min_length'    => 12,
        'require_upper' => true,
        'require_lower' => true,
        'require_digit' => true,
        'require_symbol' => true,
        'blocklist_file' => BASE_PATH . '/config/common-passwords.txt',
        'history_depth' => 5,
    ],

    // --- Sessions (Part 18) ------------------------------------------------
    'session' => [
        'cookie_name'             => Env::get('SESSION_COOKIE_NAME', 'lsiams_session'),
        'idle_timeout_minutes'    => (int) Env::get('SESSION_IDLE_TIMEOUT_MINUTES', '10'),
        'idle_timeout_min_allowed' => 5,
        'idle_timeout_max_allowed' => 60,
        'absolute_timeout_hours'  => (int) Env::get('SESSION_ABSOLUTE_TIMEOUT_HOURS', '8'),
        'warning_seconds_before'  => 60,
        'activity_ping_debounce'  => 60,
        'max_concurrent_per_user' => 3,
        'token_bytes'             => 32,          // 256-bit CSPRNG session tokens
        'cookie' => [
            'secure'    => Env::bool('SESSION_COOKIE_SECURE', true),
            'httponly'  => true,
            'samesite'  => 'Strict',
            'path'      => '/',
            'host_prefix' => true,                // use __Host- when secure + path=/
        ],
        'bind_user_agent' => true,
        'bind_ip_prefix'  => true,                // /24 for IPv4, /64 for IPv6
    ],

    // --- Login -------------------------------------------------------------
    'login' => [
        'max_attempts'        => 5,
        'attempt_window_min'  => 15,
        'lockout_minutes'     => 15,
        'force_change_on_first_login' => true,
    ],

    // --- API keys / device auth (Part 19) ----------------------------------
    'api_key' => [
        'secret_bytes'          => 32,   // 256-bit
        'key_id_bytes'          => 8,
        'hmac_secret_bytes'     => 32,
        'key_id_prefix'         => 'lsk_',
        'rotation_grace_hours'  => 24,
        'claim_token_ttl_hours' => 24,
        'age_warning_days'      => 180,
        'age_rotate_days'       => 365,
        'auto_revoke' => [
            'consecutive_signature_failures' => 10,
            'distinct_ips_per_hour'          => 3,
        ],
    ],

    // --- Request signing / replay protection -------------------------------
    'request' => [
        'timestamp_skew_seconds' => 30,
        'nonce_retention_hours'  => 24,
        'idempotency_retention_hours' => 24,
        'signature_header' => 'X-LSIAMS-Signature',
        'headers' => [
            'device_id'  => 'X-LSIAMS-Device-Id',
            'api_key'    => 'X-LSIAMS-Api-Key',
            'timestamp'  => 'X-LSIAMS-Timestamp',
            'nonce'      => 'X-LSIAMS-Nonce',
            'request_id' => 'X-LSIAMS-Request-Id',
            'passive'    => 'X-Passive-Request',
        ],
        'max_body_bytes' => 256 * 1024,
    ],

    // --- Rate limiting -----------------------------------------------------
    'rate_limit' => [
        'device'   => ['limit' => 60,  'window' => 60,  'burst' => 30],
        'login'    => ['limit' => 10,  'window' => 300],
        'api_user' => ['limit' => 300, 'window' => 60],
        'web'      => ['limit' => 600, 'window' => 60],
    ],

    // --- Network -----------------------------------------------------------
    'network' => [
        'trusted_web_cidrs'    => Env::list('TRUSTED_WEB_CIDRS', ['192.168.0.0/16', '10.0.0.0/8', '172.16.0.0/12', '127.0.0.0/8']),
        'trusted_device_cidrs' => Env::list('TRUSTED_DEVICE_CIDRS', ['192.168.0.0/16', '10.0.0.0/8', '172.16.0.0/12', '127.0.0.0/8']),
        'trust_proxy'          => Env::bool('TRUST_PROXY', false),
        'enforce_https'        => true,
    ],

    // --- Response headers --------------------------------------------------
    'headers' => [
        // connect-src carries the realtime origin explicitly rather than a bare
        // scheme. The WebSocket lives on its own port, so it is never 'self',
        // and a deployment served over plain HTTP for a demo would have its
        // ws:// connection blocked by a policy that only allowed wss:. Naming
        // the configured origin keeps the policy tight — one host and port,
        // not every wss: endpoint on the internet — and correct in both setups.
        'Content-Security-Policy'   => "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self' " . realtime_csp_origin() . "; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
        'X-Frame-Options'           => 'DENY',
        'X-Content-Type-Options'    => 'nosniff',
        'Referrer-Policy'           => 'same-origin',
        'Permissions-Policy'        => 'geolocation=(), microphone=(), camera=(), payment=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],

    // --- Severity thresholds for the security dashboard --------------------
    'risk' => [
        'low'      => 0,
        'guarded'  => 5,
        'elevated' => 15,
        'high'     => 30,
        'critical' => 60,
    ],
];
