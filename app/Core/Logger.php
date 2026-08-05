<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * File logger with secret redaction.
 *
 * Part 19.4 requires that API key material never appears in any log. Rather
 * than trusting every call site to remember that, redaction happens centrally
 * here: any context key that looks like a credential is masked before the line
 * is written, and any value shaped like an L-SIAMS key is masked wherever it
 * appears in the message.
 */
final class Logger
{
    public const DEBUG     = 'debug';
    public const INFO      = 'info';
    public const WARNING   = 'warning';
    public const ERROR     = 'error';
    public const CRITICAL  = 'critical';

    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'passwd', 'secret', 'api_key', 'apikey',
        'hmac_secret', 'token', 'session_token', 'claim_token', 'signature',
        'authorization', 'cookie', 'csrf', 'pepper', 'app_key', 'private_key',
        'fingerprint_template', 'db_pass',
    ];

    private static ?string $channel = null;

    public static function setChannel(?string $channel): void
    {
        self::$channel = $channel;
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        if (Config::get('app.debug', false)) {
            self::write(self::DEBUG, $message, $context);
        }
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write(self::INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write(self::WARNING, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function critical(string $message, array $context = []): void
    {
        self::write(self::CRITICAL, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function exception(Throwable $e, array $context = []): void
    {
        self::write(self::ERROR, $e->getMessage(), $context + [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $directory = (string) Config::get('app.paths.logs', BASE_PATH . '/storage/logs');

        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $file = sprintf('%s/%s-%s.log', $directory, self::$channel ?? 'app', date('Y-m-d'));

        $line = sprintf(
            "[%s] %s.%s: %s %s%s",
            date('Y-m-d H:i:s'),
            self::$channel ?? 'app',
            strtoupper($level),
            self::redactString($message),
            $context === [] ? '' : json_encode(self::redactContext($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            PHP_EOL
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0640);
    }

    /**
     * @param  array<array-key,mixed> $context
     * @return array<array-key,mixed>
     */
    public static function redactContext(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $lower = is_string($key) ? strtolower($key) : '';

            $isSensitive = $lower !== '' && array_reduce(
                self::SENSITIVE_KEYS,
                static fn (bool $carry, string $needle): bool => $carry || str_contains($lower, $needle),
                false
            );

            if ($isSensitive) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value)  => self::redactContext($value),
                is_string($value) => self::redactString($value),
                is_scalar($value), $value === null => $value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                default => '[' . get_debug_type($value) . ']',
            };
        }

        return $clean;
    }

    /** Masks anything shaped like `lsk_xxxxxxxx.<secret>` regardless of context. */
    public static function redactString(string $value): string
    {
        return (string) preg_replace('/\b(lsk_[A-Za-z0-9]{6,})\.[A-Za-z0-9]{8,}/', '$1.[REDACTED]', $value);
    }
}
