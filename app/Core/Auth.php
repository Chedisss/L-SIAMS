<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Per-request identity context.
 *
 * Populated by AuthMiddleware after SessionService has verified the session
 * token server-side. Nothing reads identity from a cookie or a request
 * parameter — only from here, and only after middleware has run.
 */
final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    /** @var array<string,mixed>|null */
    private static ?array $session = null;
    /** @var list<string>|null */
    private static ?array $permissions = null;
    /** @var array<string,mixed>|null */
    private static ?array $device = null;

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session
     * @param list<string>        $permissions
     */
    public static function login(array $user, array $session, array $permissions): void
    {
        self::$user        = $user;
        self::$session     = $session;
        self::$permissions = $permissions;
    }

    public static function logout(): void
    {
        self::$user        = null;
        self::$session     = null;
        self::$permissions = null;
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::$user['user_id']) ? (int) self::$user['user_id'] : null;
    }

    public static function username(): ?string
    {
        return isset(self::$user['username']) ? (string) self::$user['username'] : null;
    }

    public static function fullName(): string
    {
        if (self::$user === null) {
            return 'Guest';
        }

        $name = trim((string) (self::$user['full_name'] ?? ''));

        return $name !== '' ? $name : (string) (self::$user['username'] ?? 'User');
    }

    public static function role(): ?string
    {
        return isset(self::$user['role_name']) ? strtolower((string) self::$user['role_name']) : null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'administrator';
    }

    public static function isTeacher(): bool
    {
        return self::role() === 'teacher';
    }

    /** The teachers.teacher_id linked to this user account, when the user is a teacher. */
    public static function teacherId(): ?int
    {
        $id = self::$user['teacher_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public static function sessionId(): ?int
    {
        return isset(self::$session['session_id']) ? (int) self::$session['session_id'] : null;
    }

    /** @return array<string,mixed>|null */
    public static function session(): ?array
    {
        return self::$session;
    }

    /** @param array<string,mixed> $session */
    public static function refreshSession(array $session): void
    {
        self::$session = $session;
    }

    public static function can(string $permission): bool
    {
        if (self::$user === null) {
            return false;
        }

        // Administrators hold every permission by definition; the permission
        // table still exists so future roles (Registrar, Principal) can be
        // added without touching authorisation call sites.
        if (self::isAdmin()) {
            return true;
        }

        return in_array($permission, self::$permissions ?? [], true);
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        return self::$permissions ?? [];
    }

    // ---------------------------------------------------------- device auth --

    /** @param array<string,mixed> $device */
    public static function setDevice(array $device): void
    {
        self::$device = $device;
    }

    /** @return array<string,mixed>|null */
    public static function device(): ?array
    {
        return self::$device;
    }

    public static function deviceId(): ?string
    {
        return isset(self::$device['device_id']) ? (string) self::$device['device_id'] : null;
    }

    public static function deviceRowId(): ?int
    {
        return isset(self::$device['id']) ? (int) self::$device['id'] : null;
    }

    public static function reset(): void
    {
        self::$user        = null;
        self::$session     = null;
        self::$permissions = null;
        self::$device      = null;
    }
}
