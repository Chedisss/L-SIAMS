<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Immutable view of the current HTTP request.
 *
 * Controllers never touch $_GET / $_POST / $_SERVER directly; they ask this
 * object, which normalises header names, decodes JSON bodies once, and resolves
 * the client IP according to the proxy-trust setting rather than blindly
 * believing X-Forwarded-For.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,string> */
    private array $headers;
    /** @var array<string,array<string,mixed>> */
    private array $files;
    /** @var array<string,string> */
    private array $routeParams = [];
    private string $rawBody;
    private string $method;
    private string $path;

    private function __construct()
    {
        $this->method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->path    = self::resolvePath();
        $this->headers = self::collectHeaders();
        $this->query   = $_GET;
        $this->files   = $_FILES;
        $this->rawBody = '';
        $this->body    = [];

        $this->hydrateBody();
        $this->applyMethodOverride();
    }

    public static function capture(): self
    {
        return new self();
    }

    private static function resolvePath(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Support deployment in a sub-directory without rewriting every link.
        $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array<string,string> */
    private static function collectHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server]) && is_string($_SERVER[$server])) {
                $headers[$name] = $_SERVER[$server];
            }
        }

        return $headers;
    }

    private function hydrateBody(): void
    {
        if ($this->method === 'GET' || $this->method === 'HEAD') {
            return;
        }

        $maxBytes = (int) Config::get('security.request.max_body_bytes', 262144);
        $declared = (int) ($this->headers['content-length'] ?? 0);

        if ($declared > $maxBytes) {
            Response::json([
                'success' => false,
                'code'    => 'PAYLOAD_TOO_LARGE',
                'message' => 'Request body exceeds the permitted size.',
            ], 413)->send();
            exit;
        }

        $contentType = strtolower($this->headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw           = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
            $this->rawBody = $raw;

            if (strlen($raw) > $maxBytes) {
                Response::json([
                    'success' => false,
                    'code'    => 'PAYLOAD_TOO_LARGE',
                    'message' => 'Request body exceeds the permitted size.',
                ], 413)->send();
                exit;
            }

            $decoded = json_decode($raw, true);

            if ($raw !== '' && !is_array($decoded)) {
                Response::json([
                    'success' => false,
                    'code'    => 'MALFORMED_JSON',
                    'message' => 'Request body is not valid JSON.',
                ], 400)->send();
                exit;
            }

            $this->body = is_array($decoded) ? $decoded : [];

            return;
        }

        $this->body = $_POST;

        if ($this->body === [] && in_array($this->method, ['PUT', 'PATCH', 'DELETE'], true)) {
            $raw           = (string) file_get_contents('php://input', false, null, 0, $maxBytes);
            $this->rawBody = $raw;
            parse_str($raw, $parsed);
            $this->body = $parsed;
        }
    }

    /** Browsers can only send GET/POST; `_method` lets forms reach PUT/DELETE routes. */
    private function applyMethodOverride(): void
    {
        if ($this->method !== 'POST') {
            return;
        }

        $override = strtoupper((string) ($this->body['_method'] ?? $this->headers['x-http-method-override'] ?? ''));

        if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            $this->method = $override;
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return is_numeric($value) ? ((int) $value === 1) : $default;
    }

    /** @return list<mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body + $this->query;
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @param  list<string> $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $all  = $this->all();
        $out  = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $out[$key] = $all[$key];
            }
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeInt(string $key, int $default = 0): int
    {
        $value = $this->routeParams[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string,string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Client IP.
     *
     * X-Forwarded-For is only consulted when `security.network.trust_proxy` is
     * on. Otherwise the header is attacker-controlled and honouring it would let
     * anyone forge their way past the IP allowlist and rate limiter.
     */
    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if (!Config::get('security.network.trust_proxy', false)) {
            return $remote;
        }

        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded === null) {
            return $remote;
        }

        $first = trim(explode(',', $forwarded)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remote;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent', '') ?? '', 0, 500);
    }

    public function isSecure(): bool
    {
        // One implementation, in Site, so the HTTPS redirect, the cookie flags
        // and the derived base URL can never disagree about whether this
        // request arrived over TLS.
        return Site::isSecure();
    }

    /** True when the request is an XHR/fetch call rather than a document navigation. */
    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '') ?? '') === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        if (str_starts_with($this->path, '/api/')) {
            return true;
        }
        if ($this->isAjax()) {
            return true;
        }

        $accept = strtolower($this->header('accept', '') ?? '');

        return str_contains($accept, 'application/json');
    }

    /**
     * Part 18.4: background refreshes must not extend a session. The front-end
     * marks them, and SessionTimeoutMiddleware honours the marker.
     */
    public function isPassive(): bool
    {
        $header = (string) Config::get('security.request.headers.passive', 'X-Passive-Request');

        return strtolower($this->header($header, '') ?? '') === 'true';
    }

    public function url(): string
    {
        return Site::url() . $this->path;
    }

    public function fullPathWithQuery(): string
    {
        return $this->query === [] ? $this->path : $this->path . '?' . http_build_query($this->query);
    }
}
