<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Immutable audit trail (Part 6, "Audit logs must be immutable through the
 * normal UI").
 *
 * There is deliberately no update() or delete() method on this class, the table
 * carries BEFORE UPDATE / BEFORE DELETE triggers that raise SQLSTATE 45000, and
 * the application database user holds only INSERT and SELECT on it. Three
 * independent layers, because non-repudiation is the whole point.
 */
final class AuditService
{
    // Canonical action names. Anything logged from application code should be
    // one of these so the audit page can filter reliably.
    public const LOGIN                       = 'LOGIN';
    public const LOGOUT                      = 'LOGOUT';
    public const LOGIN_FAILED                = 'LOGIN_FAILED';
    public const SESSION_TIMEOUT             = 'SESSION_TIMEOUT';
    public const SESSION_TERMINATED          = 'SESSION_TERMINATED';
    public const PASSWORD_CHANGED            = 'PASSWORD_CHANGED';
    public const PASSWORD_RESET              = 'PASSWORD_RESET';
    public const USER_REGISTERED             = 'USER_REGISTERED';
    public const USER_UPDATED                = 'USER_UPDATED';
    public const USER_ARCHIVED               = 'USER_ARCHIVED';
    public const USER_RESTORED               = 'USER_RESTORED';
    public const STUDENT_CREATED             = 'STUDENT_CREATED';
    public const STUDENT_UPDATED             = 'STUDENT_UPDATED';
    public const STUDENT_ARCHIVED            = 'STUDENT_ARCHIVED';
    public const STUDENT_SECTION_TRANSFER    = 'STUDENT_SECTION_TRANSFER';
    public const STUDENTS_IMPORTED           = 'STUDENTS_IMPORTED';
    public const TEACHER_CREATED             = 'TEACHER_CREATED';
    public const TEACHER_UPDATED             = 'TEACHER_UPDATED';
    public const TEACHER_DEPARTMENT_CHANGED  = 'TEACHER_DEPARTMENT_CHANGED';
    public const TEACHER_GRADE_LEVELS_CHANGED = 'TEACHER_GRADE_LEVELS_CHANGED';
    public const DEPARTMENT_CREATED          = 'DEPARTMENT_CREATED';
    public const DEPARTMENT_UPDATED          = 'DEPARTMENT_UPDATED';
    public const DEPARTMENT_ARCHIVED         = 'DEPARTMENT_ARCHIVED';
    public const SECTION_CREATED             = 'SECTION_CREATED';
    public const SECTION_UPDATED             = 'SECTION_UPDATED';
    public const SECTION_ARCHIVED            = 'SECTION_ARCHIVED';
    public const SCHEDULE_CREATED            = 'SCHEDULE_CREATED';
    public const SCHEDULE_UPDATED            = 'SCHEDULE_UPDATED';
    public const SCHEDULE_ARCHIVED           = 'SCHEDULE_ARCHIVED';
    public const SUBJECT_CREATED             = 'SUBJECT_CREATED';
    public const SUBJECT_UPDATED             = 'SUBJECT_UPDATED';
    public const CLASSROOM_CREATED           = 'CLASSROOM_CREATED';
    public const CLASSROOM_UPDATED           = 'CLASSROOM_UPDATED';
    public const RFID_ASSIGNED               = 'RFID_ASSIGNED';
    public const RFID_REPLACED               = 'RFID_REPLACED';
    public const RFID_DEACTIVATED            = 'RFID_DEACTIVATED';
    public const RFID_BLACKLISTED            = 'RFID_BLACKLISTED';
    public const FINGERPRINT_ENROLLED        = 'FINGERPRINT_ENROLLED';
    public const FINGERPRINT_DELETED         = 'FINGERPRINT_DELETED';
    public const FINGERPRINT_VERIFIED        = 'FINGERPRINT_VERIFIED';
    public const FINGERPRINT_FAILED          = 'FINGERPRINT_FAILED';
    public const DEVICE_REGISTERED           = 'DEVICE_REGISTERED';
    public const DEVICES_BULK_REGISTERED     = 'DEVICES_BULK_REGISTERED';
    public const DEVICE_UPDATED              = 'DEVICE_UPDATED';
    public const DEVICE_CLAIMED              = 'DEVICE_CLAIMED';
    public const DEVICE_DECOMMISSIONED       = 'DEVICE_DECOMMISSIONED';
    public const DEVICE_PROVISIONING_DOWNLOADED = 'DEVICE_PROVISIONING_DOWNLOADED';
    public const DEVICE_MAC_REUSE            = 'DEVICE_MAC_REUSE';
    public const API_KEY_GENERATED           = 'API_KEY_GENERATED';
    public const API_KEY_ROTATED             = 'API_KEY_ROTATED';
    public const API_KEY_REVOKED             = 'API_KEY_REVOKED';
    public const ATTENDANCE_SESSION_OPENED   = 'ATTENDANCE_SESSION_OPENED';
    public const ATTENDANCE_SESSION_CLOSED   = 'ATTENDANCE_SESSION_CLOSED';
    public const ATTENDANCE_RECORDED         = 'ATTENDANCE_RECORDED';
    public const ATTENDANCE_REJECTED         = 'ATTENDANCE_REJECTED';
    public const ATTENDANCE_MODIFIED         = 'ATTENDANCE_MODIFIED';
    public const ATTENDANCE_SYNCED           = 'ATTENDANCE_SYNCED';
    public const SETTINGS_UPDATED            = 'SETTINGS_UPDATED';
    public const BACKUP_CREATED              = 'BACKUP_CREATED';
    public const BACKUP_RESTORED             = 'BACKUP_RESTORED';
    public const REPORT_GENERATED            = 'REPORT_GENERATED';
    public const EXCEPTION_GRANTED           = 'EXCEPTION_GRANTED';
    public const EXCEPTION_REVOKED           = 'EXCEPTION_REVOKED';

    /**
     * @param array<string,mixed>|null $oldValue
     * @param array<string,mixed>|null $newValue
     */
    public static function log(
        string $action,
        string $module,
        ?string $recordType = null,
        int|string|null $recordId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $description = null,
        string $result = 'success',
        ?int $userId = null
    ): void {
        try {
            Database::instance()->insert('audit_logs', [
                'user_id'      => $userId ?? Auth::id(),
                'actor_label'  => Auth::check() ? Auth::fullName() : ($userId !== null ? 'user#' . $userId : 'SYSTEM'),
                'role_name'    => Auth::role(),
                'action'       => $action,
                'module'       => $module,
                'record_type'  => $recordType,
                'record_id'    => $recordId === null ? null : (string) $recordId,
                'old_value'    => $oldValue === null ? null : self::encode($oldValue),
                'new_value'    => $newValue === null ? null : self::encode($newValue),
                'description'  => $description === null ? null : mb_substr($description, 0, 500),
                'ip_address'   => RequestContext::ip(),
                'user_agent'   => RequestContext::userAgent(),
                'device_label' => RequestContext::deviceLabel(),
                'session_id'   => Auth::sessionId(),
                'result'       => $result,
                'created_at'   => Clock::nowString(),
            ]);
        } catch (Throwable $e) {
            // An audit write must never take down the operation it describes,
            // but a silent loss is unacceptable — fall back to the file log.
            Logger::critical('AUDIT WRITE FAILED', [
                'action' => $action,
                'module' => $module,
                'record' => $recordId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience for the extremely common "field-level diff" case: only the
     * columns that actually changed are recorded, which keeps the audit page
     * readable and the JSON small.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param list<string>        $ignore
     */
    public static function logChange(
        string $action,
        string $module,
        string $recordType,
        int|string $recordId,
        array $before,
        array $after,
        array $ignore = ['updated_at', 'created_at', 'password_hash'],
        ?string $description = null
    ): void {
        $oldDiff = [];
        $newDiff = [];

        foreach ($after as $key => $newValue) {
            if (in_array($key, $ignore, true)) {
                continue;
            }

            $oldValue = $before[$key] ?? null;

            if ((string) $oldValue !== (string) $newValue) {
                $oldDiff[$key] = $oldValue;
                $newDiff[$key] = $newValue;
            }
        }

        if ($newDiff === []) {
            return;
        }

        self::log($action, $module, $recordType, $recordId, $oldDiff, $newDiff, $description);
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode(
            Logger::redactContext($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where    = ['1=1'];
        $bindings = [];

        if (!empty($filters['user_id'])) {
            $where[]             = 'a.user_id = :user_id';
            $bindings['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[]            = 'a.action = :action';
            $bindings['action'] = (string) $filters['action'];
        }
        if (!empty($filters['module'])) {
            $where[]            = 'a.module = :module';
            $bindings['module'] = (string) $filters['module'];
        }
        if (!empty($filters['date_from'])) {
            $where[]               = 'a.created_at >= :date_from';
            $bindings['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]             = 'a.created_at <= :date_to';
            $bindings['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[]            = '(a.description LIKE :search OR a.record_id LIKE :search OR a.actor_label LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $db       = Database::instance();

        $total = (int) $db->scalar("SELECT COUNT(*) FROM audit_logs a WHERE {$whereSql}", $bindings);

        $rows = $db->select(
            "SELECT a.*, u.username
               FROM audit_logs a
               LEFT JOIN users u ON u.user_id = a.user_id
              WHERE {$whereSql}
              ORDER BY a.created_at DESC, a.audit_id DESC
              LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 10): array
    {
        return Database::instance()->select(
            'SELECT a.audit_id, a.action, a.module, a.actor_label, a.role_name,
                    a.ip_address, a.result, a.description, a.created_at
               FROM audit_logs a
              ORDER BY a.audit_id DESC
              LIMIT ' . max(1, min($limit, 100))
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forRecord(string $recordType, int|string $recordId, int $limit = 50): array
    {
        return Database::instance()->select(
            'SELECT * FROM audit_logs
              WHERE record_type = :type AND record_id = :id
              ORDER BY audit_id DESC LIMIT ' . max(1, min($limit, 200)),
            ['type' => $recordType, 'id' => (string) $recordId]
        );
    }

    /** @return list<string> */
    public static function distinctActions(): array
    {
        $rows = Database::instance()->select('SELECT DISTINCT action FROM audit_logs ORDER BY action');

        return array_map(static fn (array $r): string => (string) $r['action'], $rows);
    }

    /** @return list<string> */
    public static function distinctModules(): array
    {
        $rows = Database::instance()->select('SELECT DISTINCT module FROM audit_logs ORDER BY module');

        return array_map(static fn (array $r): string => (string) $r['module'], $rows);
    }
}
