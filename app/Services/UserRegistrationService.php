<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Hash;
use App\Core\Site;
use PDOException;
use SensitiveParameter;

/**
 * User registration (Part 19.2).
 *
 * Credentials are shown exactly once, on screen, and are then unrecoverable —
 * only resettable. The plaintext password never touches a log, a notification,
 * an email or the audit trail; only the fact that an account was created does.
 */
final class UserRegistrationService
{
    /**
     * @param  array<string,mixed> $data
     * @return array{user_id:int,teacher_id:?int,username:string,password:string,generated:bool}
     */
    public static function register(array $data, int $createdBy): array
    {
        $db       = Database::instance();
        $roleSlug = strtolower((string) ($data['role'] ?? 'teacher'));

        if (!in_array($roleSlug, ['administrator', 'teacher'], true)) {
            throw new ValidationException(['role' => ['Choose Administrator or Teacher.']]);
        }

        $role = $db->selectOne('SELECT * FROM roles WHERE role_slug = :slug', ['slug' => $roleSlug]);

        if ($role === null) {
            throw new ValidationException(['role' => ['That role is not configured.']]);
        }

        $username = self::normaliseUsername((string) ($data['username'] ?? ''));
        $email    = mb_strtolower(trim((string) ($data['email'] ?? '')));

        self::assertUsernameAvailable($username);
        self::assertEmailAvailable($email);

        $generated = empty($data['password']);
        $password  = $generated
            ? PasswordPolicyService::generate()
            : (string) $data['password'];

        if (!$generated && (string) ($data['password_confirmation'] ?? '') !== $password) {
            throw new ValidationException(['password' => ['Password confirmation does not match.']]);
        }

        PasswordPolicyService::assertAcceptable($password, [
            $username,
            $email,
            (string) ($data['first_name'] ?? ''),
            (string) ($data['last_name'] ?? ''),
        ]);

        $fullName = trim(sprintf(
            '%s %s',
            (string) ($data['first_name'] ?? ''),
            (string) ($data['last_name'] ?? '')
        ));

        $forceChange = (bool) ($data['force_password_change'] ?? Config::get('security.login.force_change_on_first_login', true));

        return $db->transaction(static function (Database $db) use (
            $data, $role, $username, $email, $password, $fullName, $forceChange, $createdBy, $roleSlug, $generated
        ): array {
            $hash = Hash::make($password);

            // Registration captures the fingerprint before the record exists,
            // so an account created through the form arrives already enrolled
            // and is active from the start. The inactive path remains for the
            // account created without one — an account that cannot open a
            // session is better represented as inactive than active-but-useless.
            $fingerprintRequestId = isset($data['fingerprint_request_id'])
                ? (int) $data['fingerprint_request_id']
                : 0;

            $requiresFingerprint = $roleSlug === 'teacher'
                && $fingerprintRequestId <= 0
                && empty($data['skip_fingerprint_requirement']);

            $status = $requiresFingerprint ? 'inactive' : 'active';

            try {
                $userId = (int) $db->insert('users', [
                    'role_id'              => (int) $role['role_id'],
                    'username'             => $username,
                    'email'                => $email,
                    'password_hash'        => $hash,
                    'full_name'            => $fullName !== '' ? $fullName : $username,
                    'status'               => $status,
                    'must_change_password' => $forceChange ? 1 : 0,
                    'password_changed_at'  => Clock::nowString(),
                    'photo_path'           => empty($data['photo_path']) ? null : (string) $data['photo_path'],
                    'created_by'           => $createdBy,
                    'created_at'           => Clock::nowString(),
                    'updated_at'           => Clock::nowString(),
                ]);
            } catch (PDOException $e) {
                if (Database::isDuplicateKey($e)) {
                    $key = Database::duplicateKeyName($e);

                    throw new ValidationException(
                        $key === 'uq_users_email'
                            ? ['email' => ['This email address is already registered.']]
                            : ['username' => ['This username is already taken.']]
                    );
                }

                throw $e;
            }

            $db->insert('password_history', [
                'user_id'       => $userId,
                'password_hash' => $hash,
                'created_at'    => Clock::nowString(),
            ]);

            $teacherId = null;

            if ($roleSlug === 'teacher') {
                $teacherId = TeacherService::create([
                    'employee_number' => (string) $data['employee_number'],
                    'user_id'         => $userId,
                    'department_id'   => (int) $data['department_id'],
                    'first_name'      => (string) $data['first_name'],
                    'middle_name'     => $data['middle_name'] ?? null,
                    'last_name'       => (string) $data['last_name'],
                    'suffix'          => $data['suffix'] ?? null,
                    'email'           => $email,
                    'phone'           => $data['phone'] ?? null,
                    'position'        => $data['position'] ?? null,
                    'photo_path'      => $data['photo_path'] ?? null,
                    'status'          => 'active',
                    'hired_at'        => $data['hired_at'] ?? null,
                    'grade_level_ids' => $data['grade_level_ids'] ?? [],
                    'subject_ids'     => $data['subject_ids'] ?? [],
                    'section_ids'     => $data['section_ids'] ?? [],
                ], $createdBy);
            }

            // Inside the transaction on purpose: a registration that fails after
            // this point must not leave the slot marked as belonging to a
            // teacher who was rolled back out of existence.
            if ($teacherId !== null && $fingerprintRequestId > 0) {
                FingerprintEnrollmentService::bind($fingerprintRequestId, $teacherId, $createdBy);
            }

            AuditService::log(
                AuditService::USER_REGISTERED,
                'user_management',
                'user',
                $userId,
                null,
                // Every field except the password, exactly as Part 19.2 requires.
                [
                    'username'   => $username,
                    'email'      => $email,
                    'role'       => $roleSlug,
                    'full_name'  => $fullName,
                    'status'     => $status,
                    'teacher_id' => $teacherId,
                    'force_password_change' => $forceChange,
                ],
                sprintf('%s account "%s" created.', ucfirst($roleSlug), $username)
            );

            if ($requiresFingerprint) {
                NotificationService::toAdministrators(
                    'account',
                    'Fingerprint enrolment pending',
                    sprintf(
                        'The account for %s is inactive until their fingerprint is enrolled.',
                        $fullName !== '' ? $fullName : $username
                    ),
                    'normal',
                    '/admin/fingerprints'
                );
            }

            return [
                'user_id'    => $userId,
                'teacher_id' => $teacherId,
                'username'   => $username,
                // Returned once for the credential slip, then discarded.
                'password'   => $password,
                'generated'  => $generated,
                'status'     => $status,
            ];
        });
    }

    /** @param array<string,mixed> $data */
    public static function updateUser(int $userId, array $data, int $actorId): void
    {
        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM users WHERE user_id = :id AND deleted_at IS NULL', ['id' => $userId]);

        if ($existing === null) {
            throw new ValidationException(['user_id' => ['User not found.']]);
        }

        $update = [];

        if (isset($data['email'])) {
            $email = mb_strtolower(trim((string) $data['email']));

            if ($email !== (string) $existing['email']) {
                self::assertEmailAvailable($email, $userId);
                $update['email'] = $email;
            }
        }

        if (isset($data['full_name'])) {
            $update['full_name'] = trim((string) $data['full_name']);
        }

        if (isset($data['status']) && in_array((string) $data['status'], ['active', 'inactive', 'locked', 'archived'], true)) {
            $update['status'] = (string) $data['status'];
        }

        if (!empty($data['photo_path'])) {
            $update['photo_path'] = (string) $data['photo_path'];
        }

        if ($update === []) {
            return;
        }

        $update['updated_at'] = Clock::nowString();

        $db->update('users', $update, ['user_id' => $userId]);

        // Any status other than active means the user must not keep a live
        // session; unlocking a locked account also clears the counters.
        //
        // locked_until is cleared either way, which keeps one invariant true
        // across the whole system: a non-null locked_until means an automatic
        // lock, applied by AuthService after too many failed passwords, and
        // that is the only kind that expires by itself. A lock an administrator
        // applied here is a decision, holds until another administrator lifts
        // it, and must never be undone by a stale timestamp left behind by an
        // earlier run of failed attempts.
        if (isset($update['status'])) {
            $db->update('users', ['failed_login_count' => 0, 'locked_until' => null], ['user_id' => $userId]);

            if ($update['status'] !== 'active') {
                SessionService::terminateAllForUser($userId, SessionService::REASON_ADMIN);
            }
        }

        AuditService::logChange(
            AuditService::USER_UPDATED,
            'user_management',
            'user',
            $userId,
            $existing,
            $update,
            ['updated_at', 'created_at', 'password_hash'],
            sprintf('Account "%s" updated.', $existing['username'])
        );
    }

    public static function archive(int $userId, int $actorId): void
    {
        $db   = Database::instance();
        $user = $db->selectOne('SELECT * FROM users WHERE user_id = :id', ['id' => $userId]);

        if ($user === null) {
            throw new ValidationException(['user_id' => ['User not found.']]);
        }

        if ($userId === $actorId) {
            throw new ValidationException(['user_id' => ['You cannot archive your own account.']]);
        }

        // Removing the last administrator would lock everyone out of the system
        // permanently, with no recovery path short of direct database access.
        if (strtolower((string) self::roleSlug($userId)) === 'administrator') {
            $remaining = (int) $db->scalar(
                "SELECT COUNT(*) FROM users u
                   JOIN roles r ON r.role_id = u.role_id
                  WHERE r.role_slug = 'administrator'
                    AND u.status = 'active'
                    AND u.deleted_at IS NULL
                    AND u.user_id <> :id",
                ['id' => $userId]
            );

            if ($remaining === 0) {
                throw new ValidationException([
                    'user_id' => ['This is the last active administrator account and cannot be archived.'],
                ]);
            }
        }

        $db->update('users', [
            'status'     => 'archived',
            'deleted_at' => Clock::nowString(),
            'updated_at' => Clock::nowString(),
        ], ['user_id' => $userId]);

        SessionService::terminateAllForUser($userId, SessionService::REASON_ADMIN);

        AuditService::log(
            AuditService::USER_ARCHIVED,
            'user_management',
            'user',
            $userId,
            ['status' => $user['status']],
            ['status' => 'archived'],
            sprintf('Account "%s" archived and all sessions terminated.', $user['username']),
            'success',
            $actorId
        );
    }

    /**
     * Undo an archive.
     *
     * Archiving is deliberately not a delete — the row, its login history and
     * every audit entry pointing at it stay exactly where they were, and only
     * `deleted_at` hides the account from the lists. That makes the reverse a
     * matter of clearing two columns, and there is no good reason to make an
     * administrator open the database to do it.
     *
     * The account comes back inactive rather than active: it has had no
     * password check and no session since it was archived, and quietly
     * restoring sign-in as a side effect of un-hiding a row is not something
     * anyone asked for. An explicit status change follows.
     */
    public static function restore(int $userId, int $actorId): void
    {
        $db   = Database::instance();
        $user = $db->selectOne('SELECT * FROM users WHERE user_id = :id', ['id' => $userId]);

        if ($user === null) {
            throw new ValidationException(['user_id' => ['User not found.']]);
        }

        if ($user['deleted_at'] === null && (string) $user['status'] !== 'archived') {
            throw new ValidationException(['user_id' => ['That account is not archived.']]);
        }

        // The username and email are unique across the whole table, archived
        // rows included, so a restore cannot collide — but a *replacement*
        // account created in the meantime would have been refused those values,
        // which means checking here would be checking something impossible.
        $db->update('users', [
            'status'     => 'inactive',
            'deleted_at' => null,
            'updated_at' => Clock::nowString(),
        ], ['user_id' => $userId]);

        AuditService::log(
            AuditService::USER_RESTORED,
            'user_management',
            'user',
            $userId,
            ['status' => $user['status'], 'deleted_at' => $user['deleted_at']],
            ['status' => 'inactive', 'deleted_at' => null],
            sprintf(
                'Account "%s" restored from the archive. It is inactive until an administrator '
                . 'sets it active, and its password is unchanged.',
                $user['username']
            ),
            'success',
            $actorId
        );
    }

    private static function roleSlug(int $userId): string
    {
        return (string) Database::instance()->scalar(
            'SELECT r.role_slug FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id',
            ['id' => $userId]
        );
    }

    public static function normaliseUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    /** Username suggestion: first initial + last name, de-duplicated numerically. */
    public static function suggestUsername(string $firstName, string $lastName): string
    {
        $base = mb_strtolower(
            (string) preg_replace('/[^a-z0-9]/i', '', mb_substr(trim($firstName), 0, 1) . trim($lastName))
        );

        if ($base === '') {
            $base = 'user';
        }

        $base       = mb_substr($base, 0, 28);
        $candidate  = $base;
        $suffix     = 1;

        while (Database::instance()->scalar('SELECT 1 FROM users WHERE username = :u LIMIT 1', ['u' => $candidate]) !== null) {
            $candidate = $base . (++$suffix);
        }

        return $candidate;
    }

    public static function isUsernameAvailable(string $username): bool
    {
        $username = self::normaliseUsername($username);

        if (preg_match('/^[a-z0-9._-]{4,32}$/', $username) !== 1) {
            return false;
        }

        return Database::instance()->scalar(
            'SELECT 1 FROM users WHERE username = :u LIMIT 1',
            ['u' => $username]
        ) === null;
    }

    private static function assertUsernameAvailable(string $username): void
    {
        if (preg_match('/^[a-z0-9._-]{4,32}$/', $username) !== 1) {
            throw new ValidationException([
                'username' => ['Username must be 4–32 characters using letters, numbers, dot, underscore or hyphen.'],
            ]);
        }

        // Uniqueness spans archived accounts too: recycling a username would
        // make historical audit entries ambiguous about who did what.
        if (Database::instance()->scalar('SELECT 1 FROM users WHERE username = :u LIMIT 1', ['u' => $username]) !== null) {
            throw new ValidationException(['username' => ['This username is already taken.']]);
        }
    }

    private static function assertEmailAvailable(string $email, ?int $ignoreUserId = null): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(['email' => ['Enter a valid email address.']]);
        }

        $sql      = 'SELECT 1 FROM users WHERE email = :e';
        $bindings = ['e' => $email];

        if ($ignoreUserId !== null) {
            $sql .= ' AND user_id <> :id';
            $bindings['id'] = $ignoreUserId;
        }

        if (Database::instance()->scalar($sql . ' LIMIT 1', $bindings) !== null) {
            throw new ValidationException(['email' => ['This email address is already registered.']]);
        }
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where    = ['1=1'];
        $bindings = [];

        if (empty($filters['include_archived'])) {
            $where[] = 'u.deleted_at IS NULL';
        }

        if (!empty($filters['search'])) {
            $where[]            = '(u.username LIKE :search OR u.email LIKE :search OR u.full_name LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['role'])) {
            $where[]          = 'r.role_slug = :role';
            $bindings['role'] = (string) $filters['role'];
        }

        if (!empty($filters['status'])) {
            $where[]            = 'u.status = :status';
            $bindings['status'] = (string) $filters['status'];
        }

        $whereSql = implode(' AND ', $where);
        $db       = Database::instance();

        // Joins first, then the WHERE clause: interpolating a pre-built
        // "FROM … WHERE …" fragment and appending further joins after it is a
        // syntax error, so the two halves are kept separate.
        $base = 'FROM users u
                 JOIN roles r            ON r.role_id = u.role_id
                 LEFT JOIN teachers t    ON t.user_id = u.user_id AND t.deleted_at IS NULL
                 LEFT JOIN departments d ON d.department_id = t.department_id
                 WHERE ' . $whereSql;

        return [
            'total' => (int) $db->scalar("SELECT COUNT(*) {$base}", $bindings),
            'rows'  => $db->select(
                "SELECT u.user_id, u.username, u.email, u.full_name, u.status, u.last_login_at,
                        u.must_change_password, u.created_at, u.photo_path,
                        r.role_name, r.role_slug,
                        t.teacher_id, t.employee_number, d.department_name,
                        (SELECT COUNT(*) FROM user_sessions s
                          WHERE s.user_id = u.user_id AND s.terminated_at IS NULL
                            AND s.idle_expires_at > NOW()) AS active_sessions
                 {$base}
                 ORDER BY u.created_at DESC
                 LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
                $bindings
            ),
        ];
    }

    /**
     * Printable credential slip. Rendered once, never persisted server-side.
     */
    public static function credentialSlip(string $username, #[SensitiveParameter] string $password, string $role, string $fullName): string
    {
        return implode("\n", [
            '════════════════════════════════════════════',
            '  ' . SettingsService::schoolName(),
            '  L-SIAMS ACCOUNT CREDENTIALS',
            '════════════════════════════════════════════',
            '',
            '  Name      : ' . $fullName,
            '  Role      : ' . ucfirst($role),
            '  Username  : ' . $username,
            '  Password  : ' . $password,
            '',
            '  Sign in at: ' . Site::url(),
            '',
            '  You will be asked to change this password',
            '  the first time you sign in.',
            '',
            '  This slip is the only copy of the password.',
            '  Store it securely and destroy it once the',
            '  password has been changed.',
            '',
            '  Issued: ' . Clock::now()->format('M j, Y g:i A'),
            '════════════════════════════════════════════',
        ]);
    }
}
