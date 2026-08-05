<?php
declare(strict_types=1);

namespace App\Core;

/**
 * One-request message and old-input storage, backed by the PHP session.
 *
 * Used for post-redirect-get flows on the non-AJAX paths (login, password
 * change, bulk imports). AJAX paths return toasts in the JSON envelope instead.
 */
final class Flash
{
    private const KEY       = '_lsiams_flash';
    private const OLD_INPUT = '_lsiams_old_input';
    private const ERRORS    = '_lsiams_errors';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = (array) Config::get('security.session.cookie', []);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (string) ($config['path'] ?? '/'),
            'secure'   => (bool) ($config['secure'] ?? true),
            'httponly' => true,
            'samesite' => (string) ($config['samesite'] ?? 'Strict'),
        ]);

        session_name('lsiams_ui');
        @session_start();
    }

    public static function add(string $type, string $message): void
    {
        self::start();
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('danger', $message);
    }

    public static function warning(string $message): void
    {
        self::add('warning', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /** @return list<array{type:string,message:string}> */
    public static function pull(): array
    {
        self::start();
        /** @var list<array{type:string,message:string}> $messages */
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        return $messages;
    }

    /** @param array<string,mixed> $input */
    public static function withInput(array $input): void
    {
        self::start();
        unset($input['password'], $input['password_confirmation'], $input['current_password'], $input['_csrf']);
        $_SESSION[self::OLD_INPUT] = $input;
    }

    public static function old(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[self::OLD_INPUT][$key] ?? $default;
    }

    public static function clearOld(): void
    {
        self::start();
        unset($_SESSION[self::OLD_INPUT]);
    }

    /** @param array<string,list<string>> $errors */
    public static function withErrors(array $errors): void
    {
        self::start();
        $_SESSION[self::ERRORS] = $errors;
    }

    /** @return array<string,list<string>> */
    public static function errors(): array
    {
        self::start();
        /** @var array<string,list<string>> $errors */
        $errors = $_SESSION[self::ERRORS] ?? [];

        return $errors;
    }

    public static function clearErrors(): void
    {
        self::start();
        unset($_SESSION[self::ERRORS]);
    }

    public static function error_for(string $field): ?string
    {
        $errors = self::errors();

        return $errors[$field][0] ?? null;
    }
}
