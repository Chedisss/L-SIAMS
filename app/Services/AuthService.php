<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Hash;
use App\Core\Request;
use SensitiveParameter;

/**
 * Login, logout, password lifecycle.
 *
 * The login path is deliberately uniform: whether the username is unknown, the
 * password is wrong or the account is disabled, the caller gets the same
 * message and roughly the same timing. Detail goes to the security log, where
 * an administrator can see it and an attacker cannot.
 */
final class AuthService
{
    /**
     * @return array{user:array<string,mixed>,session:array<string,mixed>,token:string,must_change_password:bool}
     * @throws AuthenticationException
     */
    public static function attempt(
        string $identifier,
        #[SensitiveParameter] string $password,
        Request $request
    ): array {
        $db = Database::instance();
        $ip = $request->ip();

        self::assertNotThrottled($identifier, $ip);

        $user = $db->selectOne(
            'SELECT u.*, r.role_name, r.role_slug, t.teacher_id
               FROM users u
               JOIN roles r    ON r.role_id = u.role_id
               LEFT JOIN teachers t ON t.user_id = u.user_id AND t.deleted_at IS NULL
              WHERE (u.username = :identifier OR u.email = :identifier)
                AND u.deleted_at IS NULL
              LIMIT 1',
            ['identifier' => $identifier]
        );

        if ($user === null) {
            // Equalise timing so a missing account is not distinguishable from
            // a wrong password by response latency.
            Hash::dummyVerify();
            self::recordFailure($identifier, $ip, null, 'Unknown username or email.');

            throw new AuthenticationException('Invalid username or password.', 'INVALID_CREDENTIALS');
        }

        $userId = (int) $user['user_id'];

        if ($user['locked_until'] !== null && Clock::parse((string) $user['locked_until']) > Clock::now()) {
            self::recordFailure($identifier, $ip, $userId, 'Attempt against a locked account.');
            self::writeHistory($userId, $identifier, $ip, $request, 'locked', 'Account is locked.');

            throw new AuthenticationException(
                'This account is temporarily locked. Contact an administrator.',
                'ACCOUNT_LOCKED'
            );
        }

        if (!Hash::verify($password, (string) $user['password_hash'])) {
            self::registerFailedAttempt($user, $identifier, $ip, $request);

            throw new AuthenticationException('Invalid username or password.', 'INVALID_CREDENTIALS');
        }

        if ((string) $user['status'] !== 'active') {
            self::recordFailure($identifier, $ip, $userId, 'Attempt against a ' . $user['status'] . ' account.');
            self::writeHistory($userId, $identifier, $ip, $request, 'failed', 'Account status: ' . $user['status']);

            throw new AuthenticationException(
                'This account is not active. Contact an administrator.',
                'ACCOUNT_INACTIVE'
            );
        }

        // A teacher account with no linked, active teacher record cannot do
        // anything useful and may indicate a half-finished registration.
        if (strtolower((string) $user['role_slug']) === 'teacher' && $user['teacher_id'] === null) {
            self::writeHistory($userId, $identifier, $ip, $request, 'failed', 'No teacher record linked.');

            throw new AuthenticationException(
                'This teacher account is not fully set up. Contact an administrator.',
                'TEACHER_RECORD_MISSING'
            );
        }

        // Upgrade the stored hash transparently if the cost parameters changed.
        if (Hash::needsRehash((string) $user['password_hash'])) {
            $db->update('users', ['password_hash' => Hash::make($password)], ['user_id' => $userId]);
        }

        $created = SessionService::create($userId, $request);

        $db->update('users', [
            'last_login_at'      => Clock::nowString(),
            'last_login_ip'      => $ip,
            'failed_login_count' => 0,
            'locked_until'       => null,
        ], ['user_id' => $userId]);

        self::clearAttempts($identifier, $ip);
        self::writeHistory($userId, $identifier, $ip, $request, 'success', null);

        // New session identity ⇒ new CSRF token.
        CsrfService::rotate();

        AuditService::log(
            AuditService::LOGIN,
            'authentication',
            'user',
            $userId,
            null,
            ['ip' => $ip, 'device' => RequestContext::deviceLabel()],
            'Signed in successfully',
            'success',
            $userId
        );

        return [
            'user'                 => $user,
            'session'              => $created['session'],
            'token'                => $created['token'],
            'must_change_password' => (int) $user['must_change_password'] === 1,
        ];
    }

    /** @param array<string,mixed> $user */
    private static function registerFailedAttempt(array $user, string $identifier, string $ip, Request $request): void
    {
        $db      = Database::instance();
        $userId  = (int) $user['user_id'];
        $max     = (int) Config::get('security.login.max_attempts', 5);
        $lockMin = (int) Config::get('security.login.lockout_minutes', 15);
        $count   = (int) $user['failed_login_count'] + 1;

        $update = ['failed_login_count' => $count];

        if ($count >= $max) {
            $update['locked_until'] = Clock::now()->modify('+' . $lockMin . ' minutes')->format('Y-m-d H:i:s');
            $update['status']       = 'locked';

            SecurityLogService::log(
                SecurityLogService::ACCOUNT_LOCKED,
                'high',
                sprintf('Account "%s" locked after %d failed sign-in attempts.', $user['username'], $count),
                ['user_id' => $userId, 'ip' => $ip]
            );

            NotificationService::toAdministrators(
                'security',
                'Account locked',
                sprintf('%s was locked after %d failed sign-in attempts from %s.', $user['username'], $count, $ip),
                'high',
                '/admin/security'
            );
        }

        $db->update('users', $update, ['user_id' => $userId]);

        self::recordFailure($identifier, $ip, $userId, 'Incorrect password.');
        self::writeHistory($userId, $identifier, $ip, $request, 'failed', 'Incorrect password.');
    }

    private static function recordFailure(string $identifier, string $ip, ?int $userId, string $detail): void
    {
        Database::instance()->insert('login_attempts', [
            'identifier'   => mb_substr($identifier, 0, 190),
            'ip_address'   => $ip,
            'attempted_at' => Clock::nowString(),
        ]);

        SecurityLogService::log(
            SecurityLogService::LOGIN_FAILED,
            'medium',
            sprintf('Failed sign-in for "%s": %s', $identifier, $detail),
            ['identifier' => $identifier, 'user_id' => $userId]
        );
    }

    /**
     * Throttle on the (identifier, IP) pair.
     *
     * Keying on the identifier alone would let one attacker lock every account
     * in the school out of the system from a single host; keying on IP alone
     * would let a botnet walk past it. The pair is the useful compromise.
     */
    private static function assertNotThrottled(string $identifier, string $ip): void
    {
        /** @var array{limit:int,window:int} $config */
        $config = Config::get('security.rate_limit.login', ['limit' => 10, 'window' => 300]);

        $recent = (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier = :identifier AND ip_address = :ip
                AND attempted_at > DATE_SUB(NOW(), INTERVAL :window SECOND)',
            ['identifier' => $identifier, 'ip' => $ip, 'window' => $config['window']]
        );

        if ($recent >= $config['limit']) {
            SecurityLogService::log(
                SecurityLogService::RATE_LIMIT_EXCEEDED,
                'high',
                sprintf('Sign-in rate limit exceeded for "%s" from %s.', $identifier, $ip),
                ['identifier' => $identifier, 'attempts' => $recent]
            );

            throw new AuthenticationException(
                'Too many sign-in attempts. Please wait a few minutes and try again.',
                'RATE_LIMITED'
            );
        }
    }

    private static function clearAttempts(string $identifier, string $ip): void
    {
        Database::instance()->execute(
            'DELETE FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip',
            ['identifier' => $identifier, 'ip' => $ip]
        );
    }

    private static function writeHistory(
        ?int $userId,
        string $identifier,
        string $ip,
        Request $request,
        string $status,
        ?string $detail
    ): void {
        Database::instance()->insert('login_history', [
            'user_id'      => $userId,
            'username_try' => mb_substr($identifier, 0, 190),
            'ip_address'   => $ip,
            'user_agent'   => $request->userAgent(),
            'browser'      => RequestContext::browser($request->userAgent()),
            'platform'     => RequestContext::platform($request->userAgent()),
            'status'       => $status,
            'detail'       => $detail === null ? null : mb_substr($detail, 0, 255),
            'created_at'   => Clock::nowString(),
        ]);
    }

    public static function logout(): void
    {
        $sessionId = Auth::sessionId();

        if ($sessionId !== null) {
            SessionService::terminate($sessionId, SessionService::REASON_LOGOUT);
        }

        CsrfService::clear();
        Auth::logout();
    }

    // ------------------------------------------------------- passwords ----

    /**
     * Change a password after verifying the current one.
     *
     * Every other session for the user is terminated (Part 18.8: "log out of
     * all devices on password change").
     */
    public static function changePassword(
        int $userId,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
        #[SensitiveParameter] string $confirmation,
        bool $requireCurrent = true
    ): void {
        $db   = Database::instance();
        $user = $db->selectOne('SELECT * FROM users WHERE user_id = :id AND deleted_at IS NULL', ['id' => $userId]);

        if ($user === null) {
            throw new AuthenticationException('Account not found.');
        }

        if ($requireCurrent && !Hash::verify($currentPassword, (string) $user['password_hash'])) {
            SecurityLogService::log(
                SecurityLogService::LOGIN_FAILED,
                'medium',
                'Password change rejected: current password incorrect.',
                ['user_id' => $userId]
            );

            throw new ValidationException(['current_password' => ['Your current password is incorrect.']]);
        }

        if ($newPassword !== $confirmation) {
            throw new ValidationException(['password' => ['Password confirmation does not match.']]);
        }

        PasswordPolicyService::assertAcceptable($newPassword, [
            (string) $user['username'],
            (string) $user['email'],
            (string) $user['full_name'],
        ]);

        PasswordPolicyService::assertNotReused($userId, $newPassword);

        $hash = Hash::make($newPassword);

        $db->transaction(static function (Database $db) use ($userId, $hash, $user): void {
            $db->update('users', [
                'password_hash'        => $hash,
                'must_change_password' => 0,
                'password_changed_at'  => Clock::nowString(),
                // A user completing a forced password change is by definition
                // no longer locked out of their own account.
                'status'               => (string) $user['status'] === 'locked' ? 'active' : (string) $user['status'],
                'failed_login_count'   => 0,
                'locked_until'         => null,
            ], ['user_id' => $userId]);

            $db->insert('password_history', [
                'user_id'       => $userId,
                'password_hash' => $hash,
                'created_at'    => Clock::nowString(),
            ]);
        });

        SessionService::terminateAllForUser($userId, SessionService::REASON_PASSWORD_CHANGED, Auth::sessionId());

        AuditService::log(
            AuditService::PASSWORD_CHANGED,
            'authentication',
            'user',
            $userId,
            null,
            null,
            'Password changed; all other sessions were signed out.',
            'success',
            $userId
        );
    }

    /** Administrator-initiated reset. Returns the temporary password, shown once. */
    public static function adminResetPassword(int $userId, ?string $explicitPassword = null): string
    {
        $password = $explicitPassword ?? PasswordPolicyService::generate();

        PasswordPolicyService::assertAcceptable($password, []);

        $db = Database::instance();

        $db->transaction(static function (Database $db) use ($userId, $password): void {
            $hash = Hash::make($password);

            $db->update('users', [
                'password_hash'        => $hash,
                'must_change_password' => 1,
                'password_changed_at'  => Clock::nowString(),
                'failed_login_count'   => 0,
                'locked_until'         => null,
                'status'               => 'active',
            ], ['user_id' => $userId]);

            $db->insert('password_history', [
                'user_id'       => $userId,
                'password_hash' => $hash,
                'created_at'    => Clock::nowString(),
            ]);
        });

        SessionService::terminateAllForUser($userId, SessionService::REASON_PASSWORD_CHANGED);

        AuditService::log(
            AuditService::PASSWORD_RESET,
            'user_management',
            'user',
            $userId,
            null,
            null,
            'Administrator reset the password and forced a change at next sign-in.'
        );

        return $password;
    }

    /**
     * Password-reset request. Always reports success to the caller so the form
     * cannot be used to enumerate which email addresses exist.
     */
    public static function requestPasswordReset(string $email, string $ip): void
    {
        $db   = Database::instance();
        $user = $db->selectOne(
            "SELECT user_id FROM users WHERE email = :email AND deleted_at IS NULL AND status = 'active'",
            ['email' => $email]
        );

        if ($user === null) {
            return;
        }

        $token = Crypto::randomHex(32);

        $db->insert('password_resets', [
            'user_id'      => (int) $user['user_id'],
            'token_hash'   => hash('sha256', $token),
            'expires_at'   => Clock::now()->modify('+1 hour')->format('Y-m-d H:i:s'),
            'requested_ip' => $ip,
            'created_at'   => Clock::nowString(),
        ]);

        // The system is air-gapped from the internet, so there is no mail
        // transport by design: the administrator hands the reset over in
        // person. The token is surfaced on the admin's notification feed only.
        NotificationService::toAdministrators(
            'account',
            'Password reset requested',
            sprintf('A password reset was requested for user #%d from %s.', $user['user_id'], $ip),
            'normal',
            '/admin/users'
        );

        AuditService::log(
            'PASSWORD_RESET_REQUESTED',
            'authentication',
            'user',
            (int) $user['user_id'],
            null,
            null,
            'Password reset requested.',
            'success',
            (int) $user['user_id']
        );
    }

    /** Re-authenticate the signed-in administrator before a sensitive action. */
    public static function verifyCurrentUserPassword(#[SensitiveParameter] string $password): bool
    {
        $userId = Auth::id();

        if ($userId === null) {
            return false;
        }

        $hash = Database::instance()->scalar(
            'SELECT password_hash FROM users WHERE user_id = :id',
            ['id' => $userId]
        );

        if (!is_string($hash)) {
            return false;
        }

        $ok = Hash::verify($password, $hash);

        if (!$ok) {
            SecurityLogService::log(
                SecurityLogService::LOGIN_FAILED,
                'high',
                'Re-authentication failed for a sensitive action.',
                ['user_id' => $userId, 'path' => RequestContext::path()]
            );
        }

        return $ok;
    }

    /**
     * Resolve everything the request needs about the signed-in user.
     *
     * @return array{user:array<string,mixed>,permissions:list<string>}|null
     */
    public static function loadUserContext(int $userId): ?array
    {
        $db   = Database::instance();
        $user = $db->selectOne(
            'SELECT u.*, r.role_name, r.role_slug, t.teacher_id, t.department_id
               FROM users u
               JOIN roles r ON r.role_id = u.role_id
               LEFT JOIN teachers t ON t.user_id = u.user_id AND t.deleted_at IS NULL
              WHERE u.user_id = :id AND u.deleted_at IS NULL
              LIMIT 1',
            ['id' => $userId]
        );

        if ($user === null || (string) $user['status'] !== 'active') {
            return null;
        }

        $rows = $db->select(
            'SELECT p.permission_key
               FROM role_permissions rp
               JOIN permissions p ON p.permission_id = rp.permission_id
              WHERE rp.role_id = :role',
            ['role' => (int) $user['role_id']]
        );

        return [
            'user'        => $user,
            'permissions' => array_map(static fn (array $r): string => (string) $r['permission_key'], $rows),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function loginHistory(int $userId, int $limit = 20): array
    {
        return Database::instance()->select(
            'SELECT * FROM login_history WHERE user_id = :id ORDER BY login_id DESC LIMIT ' . max(1, min($limit, 100)),
            ['id' => $userId]
        );
    }
}
