<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;

/**
 * Network-layer helpers: CIDR matching for the IP allowlist and the blocked-IP
 * register.
 */
final class NetworkService
{
    /** True when $ip falls inside $cidr. Handles bare addresses and both families. */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);

        if ($cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits            = (int) $bits;

        $ipBinary     = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        // Comparing an IPv4 address against an IPv6 range (or vice versa) is
        // meaningless, not a match.
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes    = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainderBits)) - 1) & 0xFF;

        return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }

    /** @param list<string> $cidrs */
    public static function ipInAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function isBlocked(string $ip): bool
    {
        $row = Database::instance()->selectOne(
            "SELECT block_id, expires_at FROM blocked_ips
              WHERE ip_address = :ip AND status = 'active' LIMIT 1",
            ['ip' => $ip]
        );

        if ($row === null) {
            return false;
        }

        // A lapsed temporary block lifts itself rather than lingering.
        if ($row['expires_at'] !== null && Clock::parse((string) $row['expires_at']) < Clock::now()) {
            Database::instance()->update('blocked_ips', ['status' => 'lifted'], ['block_id' => (int) $row['block_id']]);

            return false;
        }

        return true;
    }

    public static function block(string $ip, string $reason, ?int $userId = null, ?int $minutes = null, bool $auto = false): void
    {
        $expires = $minutes === null
            ? null
            : Clock::now()->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');

        Database::instance()->execute(
            'INSERT INTO blocked_ips (ip_address, reason, blocked_by, auto_blocked, expires_at, status)
                  VALUES (:ip, :reason, :user, :auto, :expires, \'active\')
             ON DUPLICATE KEY UPDATE
                  reason = VALUES(reason),
                  blocked_by = VALUES(blocked_by),
                  auto_blocked = VALUES(auto_blocked),
                  expires_at = VALUES(expires_at),
                  status = \'active\'',
            [
                'ip'      => $ip,
                'reason'  => mb_substr($reason, 0, 255),
                'user'    => $userId,
                'auto'    => $auto ? 1 : 0,
                'expires' => $expires,
            ]
        );

        SecurityLogService::log(
            SecurityLogService::BLOCKED_IP_ATTEMPT,
            'high',
            sprintf('IP %s blocked: %s', $ip, $reason),
            ['ip' => $ip, 'expires_at' => $expires, 'automatic' => $auto]
        );

        AuditService::log(
            'IP_BLOCKED',
            'security',
            'blocked_ip',
            $ip,
            null,
            ['reason' => $reason, 'expires_at' => $expires],
            'IP address blocked.'
        );
    }

    public static function unblock(string $ip, ?int $userId = null): void
    {
        Database::instance()->update('blocked_ips', ['status' => 'lifted'], ['ip_address' => $ip]);

        AuditService::log(
            'IP_UNBLOCKED',
            'security',
            'blocked_ip',
            $ip,
            ['status' => 'active'],
            ['status' => 'lifted'],
            'IP block lifted.',
            'success',
            $userId
        );
    }

    /** @return list<array<string,mixed>> */
    public static function blockedIps(): array
    {
        return Database::instance()->select(
            'SELECT b.*, u.username AS blocked_by_username
               FROM blocked_ips b
               LEFT JOIN users u ON u.user_id = b.blocked_by
              ORDER BY b.created_at DESC'
        );
    }

    public static function isDeviceBlocked(?int $deviceRowId, ?string $deviceIdText): bool
    {
        if ($deviceRowId === null && $deviceIdText === null) {
            return false;
        }

        return Database::instance()->scalar(
            "SELECT 1 FROM blocked_devices
              WHERE status = 'active'
                AND (device_row_id = :row_id OR device_id_text = :text)
              LIMIT 1",
            ['row_id' => $deviceRowId, 'text' => $deviceIdText]
        ) !== null;
    }

    public static function blockDevice(?int $deviceRowId, ?string $deviceIdText, string $reason, bool $auto = false): void
    {
        Database::instance()->insert('blocked_devices', [
            'device_row_id'  => $deviceRowId,
            'device_id_text' => $deviceIdText,
            'reason'         => mb_substr($reason, 0, 255),
            'blocked_by'     => \App\Core\Auth::id(),
            'auto_blocked'   => $auto ? 1 : 0,
            'status'         => 'active',
            'created_at'     => Clock::nowString(),
        ]);

        SecurityLogService::log(
            SecurityLogService::DEVICE_BLOCKED,
            'high',
            sprintf('Device %s blocked: %s', $deviceIdText ?? ('#' . $deviceRowId), $reason),
            ['device_row_id' => $deviceRowId],
            $deviceRowId,
            $deviceIdText
        );
    }

    /** MAC normalisation so comparisons and the unique index agree. */
    public static function normaliseMac(string $mac): string
    {
        $clean = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        if (strlen($clean) !== 12) {
            return strtoupper(trim($mac));
        }

        return implode(':', str_split($clean, 2));
    }

    public static function isValidMac(string $mac): bool
    {
        return preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', self::normaliseMac($mac)) === 1;
    }
}
