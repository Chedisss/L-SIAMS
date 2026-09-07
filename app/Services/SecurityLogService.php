<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Security event log — separate from the audit trail by design.
 *
 * The audit trail answers "who changed what". This answers "what was attempted
 * against us". Keeping them apart means an administrator reviewing suspicious
 * activity is not wading through routine record edits, and the security
 * dashboard can compute a risk level from a clean signal.
 */
final class SecurityLogService
{
    // Device / API layer
    public const DEVICE_UNKNOWN            = 'DEVICE_UNKNOWN';
    public const API_KEY_INVALID           = 'API_KEY_INVALID';
    public const API_KEY_EXPIRED           = 'API_KEY_EXPIRED';
    public const API_KEY_REVOKED_USE       = 'API_KEY_REVOKED_USE';
    public const API_KEY_IP_ANOMALY        = 'API_KEY_IP_ANOMALY';
    public const SIGNATURE_INVALID         = 'SIGNATURE_INVALID';
    public const TIMESTAMP_EXPIRED         = 'TIMESTAMP_EXPIRED';
    public const REPLAY_DETECTED           = 'REPLAY_DETECTED';
    public const DEVICE_CLASSROOM_MISMATCH = 'DEVICE_CLASSROOM_MISMATCH';
    public const DEVICE_SPOOFING           = 'DEVICE_SPOOFING';
    public const DEVICE_BLOCKED            = 'DEVICE_BLOCKED';
    public const CLAIM_TOKEN_INVALID       = 'CLAIM_TOKEN_INVALID';

    // Web / application layer
    public const LOGIN_FAILED              = 'LOGIN_FAILED';
    public const ACCOUNT_LOCKED            = 'ACCOUNT_LOCKED';
    public const PERMISSION_VIOLATION      = 'PERMISSION_VIOLATION';
    public const UNAUTHORIZED_ACCESS       = 'UNAUTHORIZED_ACCESS';
    public const CSRF_FAILURE              = 'CSRF_FAILURE';
    public const SESSION_HIJACK_SUSPECTED  = 'SESSION_HIJACK_SUSPECTED';
    public const RATE_LIMIT_EXCEEDED       = 'RATE_LIMIT_EXCEEDED';
    public const BLOCKED_IP_ATTEMPT        = 'BLOCKED_IP_ATTEMPT';
    public const IP_NOT_ALLOWLISTED        = 'IP_NOT_ALLOWLISTED';
    public const SQL_INJECTION_ATTEMPT     = 'SQL_INJECTION_ATTEMPT';
    public const XSS_ATTEMPT               = 'XSS_ATTEMPT';
    public const MALICIOUS_UPLOAD          = 'MALICIOUS_UPLOAD';
    public const UNAUTHORIZED_CHANNEL_SUBSCRIBE = 'UNAUTHORIZED_CHANNEL_SUBSCRIBE';

    // Domain-integrity events (Part 14)
    public const CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED = 'CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED';
    public const GRADE_LEVEL_MISMATCH_BLOCKED        = 'GRADE_LEVEL_MISMATCH_BLOCKED';
    public const FINGERPRINT_FAILURE_THRESHOLD       = 'FINGERPRINT_FAILURE_THRESHOLD';
    public const UNKNOWN_RFID                        = 'UNKNOWN_RFID';
    public const SECTION_MISMATCH_REPEATED           = 'SECTION_MISMATCH_REPEATED';

    // Biometric override. Not a failure — a teacher whose finger will not read
    // is meant to be able to open their class — but a session opened without a
    // scan is a control that did not run, and controls that did not run belong
    // where somebody reviews them rather than only in the audit trail among
    // the routine record edits.
    // A scan matched a slot number that is enrolled on a DIFFERENT terminal.
    // Slot numbers are per-sensor, so this is never a valid identification —
    // it is a stale template, a reflashed sensor, or a deleted enrolment row.
    public const FINGERPRINT_SLOT_CROSS_DEVICE       = 'FINGERPRINT_SLOT_CROSS_DEVICE';

    public const BIOMETRIC_OVERRIDE_USED             = 'BIOMETRIC_OVERRIDE_USED';
    public const BIOMETRIC_OVERRIDE_REFUSED          = 'BIOMETRIC_OVERRIDE_REFUSED';

    /** @param array<string,mixed> $context */
    public static function log(
        string $event,
        string $severity,
        string $description,
        array $context = [],
        ?int $deviceRowId = null,
        ?string $deviceIdText = null
    ): void {
        try {
            Database::instance()->insert('security_logs', [
                'event'          => $event,
                'severity'       => in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'low',
                'description'    => mb_substr($description, 0, 500),
                'context_json'   => $context === [] ? null : (string) json_encode(
                    Logger::redactContext($context),
                    JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                ),
                'source_ip'      => RequestContext::ip(),
                'user_id'        => Auth::id(),
                'device_row_id'  => $deviceRowId,
                'device_id_text' => $deviceIdText,
                'endpoint'       => RequestContext::path(),
                'http_method'    => RequestContext::method(),
                'user_agent'     => RequestContext::userAgent(),
                'created_at'     => Clock::nowString(),
            ]);
        } catch (Throwable $e) {
            Logger::critical('SECURITY LOG WRITE FAILED', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // High and critical events reach an administrator immediately rather
        // than waiting to be discovered on the security page.
        if (in_array($severity, ['high', 'critical'], true)) {
            NotificationService::toAdministrators(
                'security',
                'Security alert: ' . $event,
                $description,
                $severity === 'critical' ? 'critical' : 'high',
                '/admin/security'
            );

            RealtimeService::publish('security.alerts', 'security.alert', [
                'event'       => $event,
                'severity'    => $severity,
                'description' => $description,
                'source_ip'   => RequestContext::ip(),
                'at'          => Clock::atom(),
            ]);
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

        foreach (['event' => 's.event', 'severity' => 's.severity', 'resolution_status' => 's.resolution_status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]         = "{$column} = :{$key}";
                $bindings[$key]  = (string) $filters[$key];
            }
        }

        if (!empty($filters['date_from'])) {
            $where[]               = 's.created_at >= :date_from';
            $bindings['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]             = 's.created_at <= :date_to';
            $bindings['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[]            = '(s.description LIKE :search OR s.source_ip LIKE :search OR s.event LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $db       = Database::instance();

        $total = (int) $db->scalar("SELECT COUNT(*) FROM security_logs s WHERE {$whereSql}", $bindings);
        $rows  = $db->select(
            "SELECT s.*, u.username, d.device_id AS device_public_id
               FROM security_logs s
               LEFT JOIN users u   ON u.user_id = s.user_id
               LEFT JOIN devices d ON d.id = s.device_row_id
              WHERE {$whereSql}
              ORDER BY s.security_id DESC
              LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public static function resolve(int $securityId, int $userId, string $status, ?string $notes): void
    {
        Database::instance()->update('security_logs', [
            'resolution_status' => $status,
            'resolved_by'       => $userId,
            'resolved_at'       => Clock::nowString(),
            'admin_notes'       => $notes === null ? null : mb_substr($notes, 0, 1000),
        ], ['security_id' => $securityId]);

        AuditService::log(
            'SECURITY_EVENT_RESOLVED',
            'security',
            'security_log',
            $securityId,
            null,
            ['resolution_status' => $status],
            'Security event marked ' . $status
        );
    }

    /**
     * Resolve a set of events in one statement.
     *
     * @param  list<int> $securityIds
     * @return int        how many rows were actually changed
     */
    public static function resolveMany(array $securityIds, int $userId, string $status, ?string $notes): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $securityIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return 0;
        }

        $db           = Database::instance();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        // One UPDATE for the whole set. The content-freeze trigger still runs
        // per row, so a batch can no more rewrite an event than a single resolve
        // can — it only touches the triage columns named here.
        $changed = $db->execute(
            "UPDATE security_logs
                SET resolution_status = ?, resolved_by = ?, resolved_at = ?, admin_notes = ?
              WHERE security_id IN ($placeholders)",
            array_merge(
                [$status, $userId, Clock::nowString(), $notes === null ? null : mb_substr($notes, 0, 1000)],
                $ids
            )
        );

        // One audit row for the batch, naming the ids, rather than flooding the
        // trail with an entry per event — the count and the list are the fact
        // worth keeping.
        AuditService::log(
            'SECURITY_EVENT_RESOLVED',
            'security',
            'security_log',
            null,
            null,
            ['resolution_status' => $status, 'security_ids' => $ids, 'count' => $changed],
            sprintf('%d security event(s) marked %s in bulk', $changed, $status)
        );

        return $changed;
    }

    /**
     * Rolls the last 24 hours into the counters and risk level the security
     * dashboard renders.
     *
     * @return array<string,mixed>
     */
    public static function dashboardSummary(): array
    {
        $db    = Database::instance();
        $since = Clock::now()->modify('-24 hours')->format('Y-m-d H:i:s');

        $counts = $db->select(
            'SELECT event, severity, COUNT(*) AS total
               FROM security_logs
              WHERE created_at >= :since
              GROUP BY event, severity',
            ['since' => $since]
        );

        $byEvent    = [];
        $bySeverity = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];

        foreach ($counts as $row) {
            $byEvent[(string) $row['event']] = ($byEvent[(string) $row['event']] ?? 0) + (int) $row['total'];
            $bySeverity[(string) $row['severity']] = ($bySeverity[(string) $row['severity']] ?? 0) + (int) $row['total'];
        }

        // Weighted so a single critical event outranks a flood of low ones.
        $score = $bySeverity['low'] * 1
            + $bySeverity['medium'] * 3
            + $bySeverity['high'] * 10
            + $bySeverity['critical'] * 25;

        $risk = match (true) {
            $score >= 60 => 'critical',
            $score >= 30 => 'high',
            $score >= 15 => 'elevated',
            $score >= 5  => 'guarded',
            default      => 'normal',
        };

        return [
            'failed_logins'        => $byEvent[self::LOGIN_FAILED] ?? 0,
            'account_lockouts'     => $byEvent[self::ACCOUNT_LOCKED] ?? 0,
            'unknown_rfid'         => $byEvent[self::UNKNOWN_RFID] ?? 0,
            'fingerprint_failures' => $byEvent[self::FINGERPRINT_FAILURE_THRESHOLD] ?? 0,
            'invalid_api_keys'     => $byEvent[self::API_KEY_INVALID] ?? 0,
            'invalid_signatures'   => $byEvent[self::SIGNATURE_INVALID] ?? 0,
            'replay_attempts'      => $byEvent[self::REPLAY_DETECTED] ?? 0,
            'unknown_devices'      => $byEvent[self::DEVICE_UNKNOWN] ?? 0,
            'permission_violations' => $byEvent[self::PERMISSION_VIOLATION] ?? 0,
            'csrf_failures'        => $byEvent[self::CSRF_FAILURE] ?? 0,
            'rate_limited'         => $byEvent[self::RATE_LIMIT_EXCEEDED] ?? 0,
            'cross_department_blocked' => $byEvent[self::CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED] ?? 0,
            'grade_level_blocked'  => $byEvent[self::GRADE_LEVEL_MISMATCH_BLOCKED] ?? 0,
            'by_severity'          => $bySeverity,
            'risk_score'           => $score,
            'risk_level'           => $risk,
            'blocked_ips'          => (int) $db->scalar("SELECT COUNT(*) FROM blocked_ips WHERE status = 'active'"),
            'blocked_devices'      => (int) $db->scalar("SELECT COUNT(*) FROM blocked_devices WHERE status = 'active'"),
            'open_events'          => (int) $db->scalar("SELECT COUNT(*) FROM security_logs WHERE resolution_status = 'open'"),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 10): array
    {
        return Database::instance()->select(
            'SELECT security_id, event, severity, description, source_ip, resolution_status, created_at
               FROM security_logs
              ORDER BY security_id DESC
              LIMIT ' . max(1, min($limit, 100))
        );
    }

    /** Hourly counts for the security trend chart. @return list<array<string,mixed>> */
    public static function trend(int $hours = 24): array
    {
        return Database::instance()->select(
            'SELECT DATE_FORMAT(created_at, \'%Y-%m-%d %H:00\') AS bucket,
                    severity,
                    COUNT(*) AS total
               FROM security_logs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
              GROUP BY bucket, severity
              ORDER BY bucket',
            ['hours' => max(1, min($hours, 168))]
        );
    }
}
