<?php
declare(strict_types=1);

use App\Core\Env;

/*
 * Requests a terminal may make per minute. 0 switches the limit off.
 *
 * 240 is the default because this is a deployed system now: terminals run
 * unattended in classrooms, and one stuck in a retry loop should not be able
 * to saturate the server. See the note beside the 'device' bucket below for
 * where the number comes from — comfortably above the ~110 a busy minute
 * reaches, far below what a wedged terminal produces.
 */
$deviceRateLimit = Env::int('DEVICE_RATE_LIMIT', 240);

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

    // --- Unrecognised cards ------------------------------------------------
    // A card that belongs to nobody is either an enrolment that was never
    // finished or somebody at the reader trying cards, and an administrator
    // wants to know today either way. These two numbers are what stop the
    // alert becoming the category everybody mutes.
    'unknown_card' => [
        // The same UID raises at most one alert in this window: a card tapped
        // through a whole lesson is one problem, not forty.
        'alert_cooldown_minutes' => 60,

        // Past this many sightings the alert is a different statement — not a
        // louder copy of the first one — and earns a security event with it.
        'repeat_threshold' => 5,
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
        // Counted per terminal, not per IP (see RateLimitMiddleware::subject).
        //
        // 60/minute was below what one idle terminal legitimately sends, so
        // every terminal throttled itself within a minute of being switched on
        // and stayed throttled. The firmware's own cadence:
        //
        //     card-enrolment poll   every 2 s   = 30 / min
        //     fingerprint poll      every 2 s   = 30 / min
        //     heartbeat             every 30 s  =  2 / min
        //                                       ─────────
        //                            idle total = 62 / min
        //
        // Two over the limit before a single card is presented. A class then
        // adds a tap per student arriving and another leaving, so a busy
        // minute reaches roughly 110.
        //
        // It was switched off during bring-up, because the limit catches
        // exactly one thing — a terminal stuck in a retry loop — and while it
        // was on it made every other problem harder to see. A throttled
        // heartbeat marks the device offline, a throttled poll misses an
        // enrolment request, and a throttled verify leaves a teacher unable to
        // open a session, all of which look like the fault you were already
        // chasing.
        //
        // Bring-up is over and it is back on, at 240. Set DEVICE_RATE_LIMIT=0
        // to switch it off again while diagnosing a terminal, and put it back
        // afterwards.
        //
        // Nothing else is relaxed by this. Every device endpoint still demands
        // an HMAC signature over the request, a device id the server issued,
        // and a source address inside the device allowlist, so the cap only
        // ever governed how often a terminal that had already proved itself
        // could speak.
        //
        // 240 is the working value: comfortably above the ~110 a busy minute
        // reaches, far below the thousands a retry loop produces.
        'device'   => $deviceRateLimit > 0
            ? ['limit' => $deviceRateLimit, 'window' => 60]
            : null,

        // A terminal reporting the outcome of work this server asked it to do:
        // how an enrolment progressed, which slot it wrote, that it refused,
        // that it failed. Counted separately from the bucket above, and this is
        // not a convenience.
        //
        // Sharing one budget meant the polling path could spend it and the
        // reporting path would then be refused — which is not a throttle, it is
        // data loss. It happened during duplicate-fingerprint testing: the
        // terminal found the finger already enrolled, asked whose it was, was
        // answered 429, correctly refused to write, tried to report the refusal
        // and was answered 429 again. The board did everything right and the
        // administrator watching the screen saw "Scanning" until it timed out,
        // with no reason given anywhere.
        //
        // These endpoints cannot flood on their own. Each one may only speak
        // about an enrolment request an administrator created, there is at most
        // one open per terminal, and a whole enrolment spends about seven
        // requests. 120 a minute leaves room for back-to-back enrolments in a
        // busy registration session while still bounding a terminal stuck in a
        // retry loop, which is the single thing this limiter exists to catch.
        //
        // Follows DEVICE_RATE_LIMIT=0 so switching the cap off while diagnosing
        // a terminal switches off all of it, rather than leaving one bucket on
        // to confuse the next person.
        'device_report' => $deviceRateLimit > 0
            ? ['limit' => 120, 'window' => 60]
            : null,

        'login'    => ['limit' => 10,  'window' => 300],
        // Opening an attendance session on a password instead of a
        // fingerprint. Counted per account rather than per IP, because a
        // school runs behind one NAT address and one teacher mistyping their
        // password must not throttle the staffroom.
        //
        // The middleware counts requests, not failures, so the budget has to
        // cover the ways a teacher legitimately spends one: a mistype, a
        // caps-lock, an attempt made two minutes before the window opens, a
        // second class that needed disambiguating. Five ran out during
        // testing without a single wrong password being typed, and a failover
        // that locks out the person it exists for is not a failover.
        //
        // Ten still bounds guessing hard. An attacker here already holds the
        // teacher's signed-in browser — this gate is re-authentication, the
        // same thing requirePasswordConfirmation does elsewhere in the app
        // with no limit at all — and bcrypt at cost 12 makes ten guesses per
        // five minutes worth nothing against a 12-character minimum.
        'session_override' => ['limit' => 10, 'window' => 300],
        'api_user' => ['limit' => 300, 'window' => 60],
        'web'      => ['limit' => 600, 'window' => 60],
    ],

    // --- Biometrics --------------------------------------------------------
    'fingerprint' => [
        // The server's ceiling when handing out slots on a sensor. The common
        // AS608 holds 127; modules sold under the same name ship with 162 and
        // 1000. Terminals read their real capacity at boot, so this only needs
        // raising if you have more teachers than 127 AND sensors that can hold
        // them.
        'sensor_capacity' => Env::int('FINGERPRINT_SENSOR_CAPACITY', 127),

        // Which terminals are sent a given teacher's fingerprint.
        //
        //   'all'       every classroom terminal holds every enrolled teacher.
        //   'timetable' a terminal holds only the teachers scheduled into its
        //               own room.
        //
        // 'all' is the default because a school runs on exceptions. A
        // substitute covering a class in a room they never teach in, a lesson
        // moved to the hall at an hour's notice, a make-up class on a Saturday
        // — under 'timetable' none of those teachers can start their register
        // until somebody has edited the timetable first, and the reader gives
        // them the same NOT RECOGNISED as a stranger. Attendance stops for a
        // clerical reason.
        //
        // What 'timetable' buys is a smaller loss if a terminal is stolen off a
        // wall: the board carries its API key and HMAC secret in flash, and
        // under 'all' it carries every teacher's template with them, where
        // under 'timetable' it carries only the few who teach in that room.
        // Neither setting lets that board open a class — a session still needs
        // a schedule and a live signature — so what is at stake is the
        // biometric data itself, not access.
        //
        // Choose 'timetable' where terminals are in publicly reachable
        // corridors and the timetable is kept accurate enough to teach from.
        // Choose 'all' where a class must be able to start whatever the
        // timetable says.
        'sync_scope' => Env::get('FINGERPRINT_SYNC_SCOPE', 'all') === 'timetable'
            ? 'timetable'
            : 'all',
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
        // Two placeholders are filled in per response by App\Core\Csp, because
        // both depend on the request and this array is built once.
        //
        // {realtime_origin} is the WebSocket endpoint as a source: scheme, host
        // and port. The WebSocket lives on its own port, so it is never 'self',
        // and it has to name the host the browser actually used — a policy
        // pinned to localhost blocks every device on the LAN. Naming one origin
        // keeps the policy tight; a bare `wss:` would allow every WebSocket
        // endpoint on the internet.
        //
        // {nonce} authorises this response's inline scripts.
        // The layouts and the view-level script blocks are inline by design —
        // there is no bundler here — and a bare 'self' silently refused all of
        // them, which broke the CSRF bootstrap, the realtime URL and every
        // button whose handler lived in a view. 'unsafe-inline' would have
        // fixed that by removing the protection; the nonce keeps it.
        // The layouts and the view-level script blocks are inline by design —
        // there is no bundler here — and a bare 'self' silently refused all of
        // them, which broke the CSRF bootstrap, the realtime URL and every
        // button whose handler lived in a view. 'unsafe-inline' would have
        // fixed that by removing the protection; the nonce keeps it.
        //
        // style-src does allow 'unsafe-inline', and that is a deliberate,
        // narrower concession: a nonce only authorises <style> elements, and
        // the views carry ~230 inline style *attributes* — progress-bar widths,
        // chart bar heights, status-colour swatches — which no nonce can cover.
        // The residual risk is CSS-based data inference, not script execution;
        // script-src stays strict, which is where the injection risk actually
        // lives. Note that adding a nonce to style-src would make browsers
        // ignore 'unsafe-inline' entirely, so it deliberately has none.
        'Content-Security-Policy'   => "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-{nonce}'; font-src 'self'; connect-src 'self' {realtime_origin}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
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
