<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Csp;
use App\Core\Flash;
use App\Core\Site;
use App\Services\CsrfService;

/**
 * Global view/controller helpers.
 *
 * `e()` is the one every template must use. It is deliberately terse so there
 * is no excuse for echoing a raw value.
 */

if (!function_exists('e')) {
    /** HTML-escape for output. ENT_QUOTES covers attribute contexts too. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(
                (string) json_encode($value, JSON_UNESCAPED_SLASHES),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** Escape for an unquoted-ish attribute context; also strips control chars. */
    function e_attr(mixed $value): string
    {
        return e(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value) ?? '');
    }
}

if (!function_exists('json_attr')) {
    /** Safely embed a PHP array as a JSON value inside an HTML attribute. */
    function json_attr(mixed $value): string
    {
        return e((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT));
    }
}

if (!function_exists('json_js')) {
    /**
     * Safely embed a PHP value as a JSON literal inside an inline <script>.
     *
     * Not json_attr(). That one HTML-escapes, which is right for
     * data-x='{"a":1}' and wrong inside a script element: HTML escaping turns
     * the JSON string delimiters into &quot;, and the browser hands script
     * content to the JS parser without decoding entities, so the whole block
     * dies with "Unexpected token '&'". Every inline script that used
     * json_attr() was a syntax error — invisible only because the
     * Content-Security-Policy was refusing to run those blocks at all.
     *
     * The hex flags are the actual protection here: they prevent a value
     * containing </script>, <!--, & or a quote from ending the element early
     * and turning attacker-controlled data into markup.
     */
    function json_js(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return CsrfService::token();
    }
}

if (!function_exists('csp_nonce')) {
    /**
     * The Content-Security-Policy nonce for this response.
     *
     * Every inline <script> and <style> block in the views must carry
     * nonce="<?= csp_nonce() ?>" or the browser will refuse to run it. See
     * App\Core\Csp for why the policy is built this way.
     */
    function csp_nonce(): string
    {
        return Csp::nonce();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return sprintf('<input type="hidden" name="_csrf" value="%s">', e(CsrfService::token()));
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return sprintf('<input type="hidden" name="_method" value="%s">', e(strtoupper($method)));
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Flash::old($key, $default);
    }
}

if (!function_exists('field_error')) {
    function field_error(string $field): ?string
    {
        return Flash::error_for($field);
    }
}

if (!function_exists('auth')) {
    /** @return array<string,mixed>|null */
    function auth(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return Site::url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $file    = (string) Config::get('app.paths.public') . '/assets/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : (string) Config::get('app.version', '1');

        return '/assets/' . ltrim($path, '/') . '?v=' . $version;
    }
}

if (!function_exists('active_class')) {
    /** @param string|list<string> $patterns */
    function active_class(string|array $patterns, string $class = 'active'): string
    {
        $path     = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $patterns = is_array($patterns) ? $patterns : [$patterns];

        foreach ($patterns as $pattern) {
            if ($path === $pattern || str_starts_with($path, rtrim($pattern, '/') . '/')) {
                return $class;
            }
        }

        return '';
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $value, string $format = 'M j, Y'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '—';
        }

        try {
            return Clock::parse($value)->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $value, string $format = 'M j, Y g:i A'): string
    {
        return format_date($value, $format);
    }
}

if (!function_exists('format_time')) {
    function format_time(?string $value, string $format = 'g:i A'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return Clock::parse(strlen($value) <= 8 ? '1970-01-01 ' . $value : $value)->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}

if (!function_exists('human_duration')) {
    function human_duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest === 0 ? $hours . ' hr' : sprintf('%d hr %d min', $hours, $rest);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'never';
        }

        try {
            $then    = Clock::parse($value);
            $seconds = Clock::now()->getTimestamp() - $then->getTimestamp();
        } catch (Throwable) {
            return '—';
        }

        return match (true) {
            $seconds < 5      => 'just now',
            $seconds < 60     => $seconds . 's ago',
            $seconds < 3600   => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400  => intdiv($seconds, 3600) . 'h ago',
            $seconds < 604800 => intdiv($seconds, 86400) . 'd ago',
            default           => $then->format('M j, Y'),
        };
    }
}

if (!function_exists('status_badge')) {
    /** Maps a domain status onto a Bootstrap badge class. */
    function status_badge(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'present', 'active', 'online', 'verified', 'accepted', 'success', 'completed', 'timed_out' => 'badge-success',
            'late', 'warning', 'pending', 'left early', 'left_early', 'incomplete', 'no_time_out'      => 'badge-warning',
            'absent', 'inactive', 'offline', 'failed', 'rejected', 'blocked', 'revoked', 'disabled'    => 'badge-danger',
            'excused', 'official business', 'official_business', 'archived', 'suspended'               => 'badge-info',
            default                                                                                    => 'badge-neutral',
        };
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts    = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials === '' ? '?' : $initials;
    }
}

if (!function_exists('percent')) {
    function percent(float|int|null $value, int $decimals = 1): string
    {
        return number_format((float) ($value ?? 0), $decimals) . '%';
    }
}

if (!function_exists('array_get')) {
    /** @param array<array-key,mixed> $array */
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        $cursor = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
