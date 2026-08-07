<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Where this installation is reachable, from the point of view of whoever is
 * asking.
 *
 * APP_URL is a single value, and a single value is wrong the moment the system
 * is used the way it is meant to be used. The machine running it opens the
 * console at http://localhost:8080; the head teacher's laptop and the phones in
 * the corridor reach the same server at http://192.168.1.x:8080. Anything built
 * from a hard-coded "localhost" — a device's provisioning payload, a link in a
 * generated credential slip, the referer check after a login — is then either
 * wrong or actively broken for every device except the one at the keyboard.
 *
 * So APP_URL is honoured when it is set, and otherwise the base URL follows the
 * request. A deployment behind a reverse proxy sets it and never reaches the
 * derivation; a school running the system straight off one PC sets nothing and
 * it simply works from every device.
 */
final class Site
{
    /**
     * The base URL for the current request: "http://192.168.1.10:8080".
     *
     * Empty only when APP_URL is unset and there is no request to derive from,
     * which is the CLI case.
     */
    public static function url(): string
    {
        $configured = rtrim((string) Config::get('app.url', ''), '/');

        if ($configured !== '') {
            return $configured;
        }

        $authority = self::authority();

        if ($authority === null) {
            return '';
        }

        return (self::isSecure() ? 'https://' : 'http://') . $authority;
    }

    /**
     * The host the browser used, with its port if one was given.
     *
     * The Host header is client-controlled, so it is validated as a hostname or
     * IP literal (with an optional numeric port) and rejected otherwise. The
     * residual exposure is narrow: the value is only ever reflected back to the
     * client that sent it. Deployments where that is not good enough set
     * APP_URL and never reach this code.
     */
    public static function authority(): ?string
    {
        $header = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($header === '' || strlen($header) > 255) {
            return null;
        }

        // [::1]:8080 — an IPv6 literal is bracketed, so the colons inside it
        // are not port separators.
        if (str_starts_with($header, '[')) {
            $end = strpos($header, ']');

            if ($end === false || filter_var(substr($header, 1, $end - 1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }

            $port = substr($header, $end + 1);

            return ($port === '' || preg_match('/^:\d{1,5}$/', $port) === 1) ? $header : null;
        }

        if (preg_match('/^([A-Za-z0-9]([A-Za-z0-9\-.]{0,251}[A-Za-z0-9])?)(:(\d{1,5}))?$/', $header, $m) !== 1) {
            return null;
        }

        if (isset($m[4]) && ((int) $m[4] < 1 || (int) $m[4] > 65535)) {
            return null;
        }

        return $header;
    }

    /** The host without its port — what a CSP source or a WebSocket URL needs. */
    public static function host(): ?string
    {
        $authority = self::authority();

        if ($authority === null) {
            return null;
        }

        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');

            return $end === false ? null : substr($authority, 0, $end + 1);
        }

        $host = strtok($authority, ':');

        return $host === false || $host === '' ? null : $host;
    }

    public static function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Only believed behind a proxy the operator has told us to trust;
        // otherwise any client could assert it and turn off the HTTPS redirect.
        if (Config::get('security.network.trust_proxy', false)) {
            return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }

        return false;
    }
}
