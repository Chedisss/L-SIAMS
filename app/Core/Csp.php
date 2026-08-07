<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The Content-Security-Policy nonce for the current request.
 *
 * Why this exists
 * ---------------
 * The policy sets `script-src 'self'`, which forbids inline <script> blocks.
 * The layouts and roughly forty views legitimately need one: the per-page
 * bootstrap object (`window.__LSIAMS_CONFIG__`) carries the CSRF token, the
 * realtime URL and the idle-timeout values into the page, and the page-specific
 * behaviour lives in a <script> block at the bottom of each view.
 *
 * Without a nonce the browser silently refused every one of those blocks. The
 * bootstrap never ran, so `LSIAMS.config.csrfToken` stayed at its empty default,
 * so every AJAX POST presented an empty token and the server answered 419
 * "your session security token expired" — an error that describes a session
 * problem for what was really a policy problem. The realtime URL was likewise
 * never set, so the connection indicator sat on "Connecting" forever, and every
 * button whose handler lived in a view-level script did nothing at all.
 *
 * Relaxing the policy to 'unsafe-inline' would have fixed the symptoms and
 * removed the single most valuable defence this application has against
 * reflected XSS. A nonce keeps the defence: the browser executes exactly the
 * inline scripts this server emitted on this request, and nothing an attacker
 * manages to inject, because the attacker cannot know a value generated per
 * response from a CSPRNG.
 */
final class Csp
{
    private static ?string $nonce = null;

    /**
     * 128 bits from the CSPRNG, base64-encoded. The specification requires at
     * least 128 bits of entropy and a value that is unpredictable per response;
     * a counter, a timestamp or a session-stable value would all defeat the
     * mechanism, so this is generated once per request and never reused.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }

        return self::$nonce;
    }

    /**
     * Substitute the per-request values into a configured header string.
     *
     * The policy lives in config/security.php so it stays readable and
     * reviewable in one place; only the parts that genuinely vary per request —
     * the nonce, and the realtime origin, which follows the host the browser
     * used — are filled in here.
     */
    public static function apply(string $policy): string
    {
        if (str_contains($policy, '{nonce}')) {
            $policy = str_replace('{nonce}', self::nonce(), $policy);
        }

        if (str_contains($policy, '{realtime_origin}')) {
            $policy = str_replace('{realtime_origin}', Realtime::cspOrigin(), $policy);
        }

        return $policy;
    }

    /** Test seam: forget the nonce so a second request in-process gets a new one. */
    public static function reset(): void
    {
        self::$nonce = null;
    }
}
