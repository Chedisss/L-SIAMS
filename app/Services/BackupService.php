<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use Throwable;

/**
 * Database backup, verification and restore (Part 6).
 *
 * The dump is produced in PHP rather than by shelling out to mysqldump, so the
 * feature works identically on Windows Server and Linux and needs no shell
 * access from the web user. Archives are gzipped and then encrypted with
 * AES-256-GCM, because a backup file is a complete copy of every attendance
 * record and every password hash in the school.
 */
final class BackupService
{
    private const MAGIC = "LSIAMSBK\x01";

    /**
     * @return array{backup_id:int,filename:string,size:int,checksum:string,tables:int,duration:float}
     */
    public static function create(string $triggerType = 'manual', ?int $userId = null): array
    {
        $started   = microtime(true);
        $directory = (string) Config::get('app.paths.backups');

        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $name     = sprintf('lsiams-%s-%s', $triggerType, Clock::now()->format('Ymd-His'));
        $filename = $name . '.sql.gz.enc';
        $path     = $directory . '/' . $filename;

        $backupId = (int) Database::instance()->insert('backups', [
            'backup_name'  => $name,
            'file_path'    => $filename,
            'trigger_type' => $triggerType,
            'status'       => 'running',
            'is_encrypted' => 1,
            'is_compressed' => 1,
            'created_by'   => $userId,
            'created_at'   => Clock::nowString(),
        ]);

        try {
            $dump   = self::dump($tableCount);
            $packed = self::pack($dump);

            file_put_contents($path, $packed);
            @chmod($path, 0600);

            $checksum = hash('sha256', $packed);
            $duration = microtime(true) - $started;

            Database::instance()->update('backups', [
                'file_size'        => strlen($packed),
                'checksum'         => $checksum,
                'table_count'      => $tableCount,
                'status'           => 'completed',
                'duration_seconds' => (int) round($duration),
            ], ['backup_id' => $backupId]);

            AuditService::log(
                AuditService::BACKUP_CREATED,
                'backup',
                'backup',
                $backupId,
                null,
                ['name' => $name, 'tables' => $tableCount, 'bytes' => strlen($packed)],
                sprintf('%s backup created (%d tables, %s).', ucfirst($triggerType), $tableCount, self::humanSize(strlen($packed))),
                'success',
                $userId
            );

            NotificationService::toAdministrators(
                'backup',
                'Backup completed',
                sprintf('%s — %d tables, %s.', $name, $tableCount, self::humanSize(strlen($packed))),
                'normal',
                '/admin/backup'
            );

            return [
                'backup_id' => $backupId,
                'filename'  => $filename,
                'size'      => strlen($packed),
                'checksum'  => $checksum,
                'tables'    => $tableCount,
                'duration'  => round($duration, 2),
            ];
        } catch (Throwable $e) {
            Database::instance()->update('backups', [
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ], ['backup_id' => $backupId]);

            Logger::critical('Backup failed', ['backup_id' => $backupId, 'error' => $e->getMessage()]);

            NotificationService::toAdministrators(
                'backup',
                'Backup failed',
                'The scheduled backup did not complete: ' . $e->getMessage(),
                'critical',
                '/admin/backup'
            );

            throw $e;
        }
    }

    /** Produce a restorable SQL dump of the whole schema and its data. */
    private static function dump(?int &$tableCount = null): string
    {
        $db     = Database::instance();
        $tables = $db->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');

        $sql = "-- L-SIAMS database backup\n"
            . '-- Generated: ' . Clock::atom() . "\n"
            . '-- Database: ' . Config::get('database.database') . "\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        $tableCount = 0;

        foreach ($tables as $row) {
            $tableName = (string) array_values($row)[0];
            $tableCount++;

            $create = $db->selectOne(sprintf('SHOW CREATE TABLE `%s`', $tableName));
            $sql   .= sprintf("DROP TABLE IF EXISTS `%s`;\n%s;\n\n", $tableName, (string) ($create['Create Table'] ?? ''));

            // Chunked so a large attendance_records table does not have to be
            // materialised in memory all at once.
            $offset    = 0;
            $chunkSize = 500;

            while (true) {
                $rows = $db->select(sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $tableName, $chunkSize, $offset));

                if ($rows === []) {
                    break;
                }

                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $values  = [];

                foreach ($rows as $record) {
                    $cells = [];

                    foreach ($record as $value) {
                        $cells[] = match (true) {
                            $value === null => 'NULL',
                            is_int($value), is_float($value) => (string) $value,
                            is_bool($value) => $value ? '1' : '0',
                            default => $db->pdo()->quote((string) $value),
                        };
                    }

                    $values[] = '(' . implode(', ', $cells) . ')';
                }

                $sql .= sprintf("INSERT INTO `%s` (%s) VALUES\n%s;\n", $tableName, $columns, implode(",\n", $values));

                $offset += $chunkSize;

                if (count($rows) < $chunkSize) {
                    break;
                }
            }

            $sql .= "\n";
        }

        // Views are recreated after the base tables they depend on exist.
        $views = $db->select('SHOW FULL TABLES WHERE Table_type = "VIEW"');

        foreach ($views as $row) {
            $viewName = (string) array_values($row)[0];
            $create   = $db->selectOne(sprintf('SHOW CREATE VIEW `%s`', $viewName));
            $body     = (string) preg_replace('/\sDEFINER\s*=\s*\S+/i', '', (string) ($create['Create View'] ?? ''));
            $sql     .= sprintf("DROP VIEW IF EXISTS `%s`;\n%s;\n\n", $viewName, $body);
        }

        // Triggers come last, after every row is already in place. Created any
        // earlier and the INSERTs above would fire them, writing audit rows for
        // a restore that never happened and tripping the guards that stop
        // attendance being rewritten.
        //
        // They are not optional decoration: the guards that make attendance,
        // audit logs, security logs and login history undeletable are triggers.
        // A dump without them restores a database that silently permits what
        // the system is built to refuse.
        foreach ($db->select('SHOW TRIGGERS') as $row) {
            $triggerName = (string) ($row['Trigger'] ?? '');

            if ($triggerName === '') {
                continue;
            }

            $create = $db->selectOne(sprintf('SHOW CREATE TRIGGER `%s`', $triggerName));
            $body   = (string) ($create['SQL Original Statement'] ?? '');

            if ($body === '') {
                continue;
            }

            // DEFINER names an account that need not exist on the machine the
            // backup is restored to, and restoring is not the moment to find
            // that out.
            $body = (string) preg_replace('/\sDEFINER\s*=\s*\S+/i', '', $body);

            $sql .= sprintf(
                "DROP TRIGGER IF EXISTS `%s`;\nDELIMITER \$\$\n%s\$\$\nDELIMITER ;\n\n",
                $triggerName,
                rtrim($body) . "\n"
            );
        }

        return $sql . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /**
     * Read a backup archive off disk and return the plain SQL inside it.
     *
     * Restoring through the admin pages needs the `backups` row that describes
     * the archive — which is itself in the database, so it is gone in exactly
     * the situation a backup exists for. This path needs nothing but the file
     * and the APP_KEY it was encrypted with.
     */
    public static function decryptFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ValidationException(['file' => ['No readable file at ' . $path . '.']]);
        }

        return self::unpack((string) file_get_contents($path));
    }

    /** gzip, then AES-256-GCM, with a magic header so the format is identifiable. */
    private static function pack(string $sql): string
    {
        $compressed = gzencode($sql, 9);

        if ($compressed === false) {
            throw new \RuntimeException('Backup compression failed.');
        }

        return self::MAGIC . Crypto::encrypt($compressed);
    }

    private static function unpack(string $payload): string
    {
        if (!str_starts_with($payload, self::MAGIC)) {
            throw new ValidationException(['file' => ['This file is not a recognised L-SIAMS backup.']]);
        }

        $ciphertext = substr($payload, strlen(self::MAGIC));
        $compressed = Crypto::decrypt($ciphertext);
        $sql        = gzdecode($compressed);

        if ($sql === false) {
            throw new ValidationException(['file' => ['The backup archive is corrupt.']]);
        }

        return $sql;
    }

    /**
     * Verify integrity without restoring: checksum, decryptability, and that
     * the SQL actually parses to a plausible dump.
     *
     * @return array{ok:bool,checks:array<string,bool>,message:string}
     */
    public static function verify(int $backupId): array
    {
        $backup = self::find($backupId);

        if ($backup === null) {
            throw new ValidationException(['backup_id' => ['Backup not found.']]);
        }

        $path   = (string) Config::get('app.paths.backups') . '/' . $backup['file_path'];
        $checks = ['file_exists' => is_readable($path)];

        if (!$checks['file_exists']) {
            return ['ok' => false, 'checks' => $checks, 'message' => 'The backup file is missing from disk.'];
        }

        $payload                = (string) file_get_contents($path);
        $checks['checksum']     = hash('sha256', $payload) === (string) $backup['checksum'];
        $checks['decryptable']  = false;
        $checks['well_formed']  = false;

        try {
            $sql                   = self::unpack($payload);
            $checks['decryptable'] = true;
            $checks['well_formed'] = str_contains($sql, 'CREATE TABLE') && str_contains($sql, 'attendance_records');
        } catch (Throwable) {
            // Leave the flags false; the caller reports the failure.
        }

        $ok = !in_array(false, $checks, true);

        if ($ok) {
            Database::instance()->update('backups', [
                'status'      => 'verified',
                'verified_at' => Clock::nowString(),
            ], ['backup_id' => $backupId]);
        }

        return [
            'ok'      => $ok,
            'checks'  => $checks,
            'message' => $ok
                ? 'Backup verified: checksum matches, archive decrypts, and the dump is well formed.'
                : 'Verification failed. Do not rely on this backup.',
        ];
    }

    /**
     * Restore a backup.
     *
     * Destructive and irreversible, so the caller must have re-authenticated
     * the administrator's password (enforced in the controller) and the backup
     * must verify first. A pre-restore safety backup is taken automatically.
     *
     * @return array{statements:int,safety_backup:string}
     */
    public static function restore(int $backupId, int $userId): array
    {
        $verification = self::verify($backupId);

        if (!$verification['ok']) {
            throw new ValidationException(['backup_id' => ['This backup failed verification and will not be restored.']]);
        }

        $backup = self::find($backupId);
        $path   = (string) Config::get('app.paths.backups') . '/' . $backup['file_path'];
        $sql    = self::unpack((string) file_get_contents($path));

        // Take a safety copy first — restoring the wrong archive is the most
        // expensive mistake available on this page.
        $safety = self::create('manual', $userId);

        $db         = Database::instance();
        $statements = self::splitStatements($sql);
        $executed   = 0;

        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($statements as $statement) {
                $trimmed = trim($statement);

                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }

                $db->pdo()->exec($trimmed);
                $executed++;
            }
        } finally {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        Database::instance()->update('backups', [
            'status'      => 'restored',
            'restored_at' => Clock::nowString(),
            'restored_by' => $userId,
        ], ['backup_id' => $backupId]);

        AuditService::log(
            AuditService::BACKUP_RESTORED,
            'backup',
            'backup',
            $backupId,
            null,
            ['statements' => $executed, 'safety_backup' => $safety['filename']],
            sprintf(
                'Database restored from %s (%d statements). Safety backup %s was taken first.',
                $backup['backup_name'],
                $executed,
                $safety['filename']
            ),
            'success',
            $userId
        );

        NotificationService::toAdministrators(
            'backup',
            'Database restored',
            sprintf('Restored from %s. A safety backup was taken beforehand.', $backup['backup_name']),
            'critical',
            '/admin/backup'
        );

        return ['statements' => $executed, 'safety_backup' => $safety['filename']];
    }

    /**
     * Split a dump into statements, honouring DELIMITER directives so a
     * trigger body is not torn apart at the semicolons inside it.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $delimiter  = ';';
        $segment    = '';

        foreach (preg_split('/\r\n|\n|\r/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
                foreach (self::scanStatements($segment, $delimiter) as $statement) {
                    $statements[] = $statement;
                }

                $segment   = '';
                $delimiter = $matches[1];
                continue;
            }

            $segment .= $line . "\n";
        }

        foreach (self::scanStatements($segment, $delimiter) as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * Split one delimiter run into statements, breaking only on a delimiter
     * that is not inside a quoted string. A naive explode(';') corrupts any
     * row containing a semicolon — an address field, for instance.
     *
     * @return list<string>
     */
    private static function scanStatements(string $sql, string $delimiter): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $quoteChar  = '';
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                    continue;
                }

                if ($char === $quoteChar) {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString  = true;
                $quoteChar = $char;
                $current  .= $char;
                continue;
            }

            if ($char === $delimiter[0] && substr($sql, $i, strlen($delimiter)) === $delimiter) {
                $statements[] = $current;
                $current      = '';
                $i           += strlen($delimiter) - 1;
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $backupId): ?array
    {
        return Database::instance()->selectOne(
            'SELECT b.*, u.username AS created_by_username
               FROM backups b
               LEFT JOIN users u ON u.user_id = b.created_by
              WHERE b.backup_id = :id',
            ['id' => $backupId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $limit = 50): array
    {
        return Database::instance()->select(
            'SELECT b.*, u.username AS created_by_username, r.username AS restored_by_username
               FROM backups b
               LEFT JOIN users u ON u.user_id = b.created_by
               LEFT JOIN users r ON r.user_id = b.restored_by
              ORDER BY b.backup_id DESC LIMIT ' . max(1, min($limit, 200))
        );
    }

    /** Download bytes for an existing backup. @return array{content:string,filename:string} */
    public static function download(int $backupId): array
    {
        $backup = self::find($backupId);

        if ($backup === null) {
            throw new ValidationException(['backup_id' => ['Backup not found.']]);
        }

        $path = (string) Config::get('app.paths.backups') . '/' . $backup['file_path'];

        if (!is_readable($path)) {
            throw new ValidationException(['backup_id' => ['The backup file is missing from disk.']]);
        }

        AuditService::log(
            'BACKUP_DOWNLOADED',
            'backup',
            'backup',
            $backupId,
            null,
            null,
            sprintf('Backup %s downloaded.', $backup['backup_name'])
        );

        return [
            'content'  => (string) file_get_contents($path),
            'filename' => (string) $backup['file_path'],
        ];
    }

    /** Retention sweep: keep the most recent $keep archives. */
    public static function pruneOld(int $keep = 30): int
    {
        $old = Database::instance()->select(
            "SELECT backup_id, file_path FROM backups
              WHERE status IN ('completed','verified')
              ORDER BY backup_id DESC
              LIMIT 500 OFFSET " . max(1, $keep)
        );

        $directory = (string) Config::get('app.paths.backups');
        $removed   = 0;

        foreach ($old as $backup) {
            $path = $directory . '/' . $backup['file_path'];

            if (is_file($path) && @unlink($path)) {
                $removed++;
            }

            Database::instance()->update(
                'backups',
                ['status' => 'failed', 'error_message' => 'Archive pruned by the retention policy.'],
                ['backup_id' => (int) $backup['backup_id']]
            );
        }

        return $removed;
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round((float) $bytes, $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }
}
