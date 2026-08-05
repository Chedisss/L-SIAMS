<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO wrapper for the whole application.
 *
 * Every query in L-SIAMS goes through here, and every one of them is a prepared
 * statement — there is no method on this class that accepts interpolated user
 * input. Transaction helpers implement the deadlock-retry policy from Part 17.2
 * so callers never have to reason about MySQL error 1213 themselves.
 */
final class Database
{
    private static ?Database $instance = null;

    private ?PDO $pdo = null;
    /** @var array<string,mixed> */
    private array $config;
    private int $transactionDepth = 0;
    private int $queryCount = 0;
    private float $queryTime = 0.0;

    /** @param array<string,mixed> $config */
    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array<string,mixed> $config */
            $config = Config::get('database', []);
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['database'],
            (string) $this->config['charset']
        );

        $isolation = (string) ($this->config['options']['isolation'] ?? 'READ-COMMITTED');
        $sqlMode   = (string) ($this->config['options']['sql_mode'] ?? 'STRICT_ALL_TABLES');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements: the server never sees a concatenated
            // query, which is what actually stops SQL injection.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => (bool) ($this->config['persistent'] ?? false),
            PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
                "SET SESSION TRANSACTION ISOLATION LEVEL %s, sql_mode='%s', time_zone='%s'",
                str_replace('-', ' ', $isolation),
                $sqlMode,
                self::mysqlTimezoneOffset()
            ),
        ];

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) $this->config['username'],
                (string) $this->config['password'],
                $options
            );
        } catch (PDOException $e) {
            // The DSN carries the credentials; never let it reach a log or a page.
            Logger::critical('Database connection failed', ['code' => $e->getCode()]);
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return $this->pdo;
    }

    private static function mysqlTimezoneOffset(): string
    {
        $tz     = new \DateTimeZone((string) Config::get('app.timezone', 'UTC'));
        $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));
        $sign   = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    /** @param array<string|int,mixed> $bindings */
    public function query(string $sql, array $bindings = []): PDOStatement
    {
        $started = microtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
        } catch (PDOException $e) {
            Logger::error('Query failed', [
                'sql'   => self::redactSql($sql),
                'code'  => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $this->queryCount++;
            $this->queryTime += microtime(true) - $started;
        }

        return $statement;
    }

    /** @param array<string|int,mixed> $bindings */
    private function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);

            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
                $type  = PDO::PARAM_STR;
            }

            $statement->bindValue($param, $value, $type);
        }
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->query($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->query($sql, $bindings)->fetchAll();

        return $rows;
    }

    /** @param array<string|int,mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->query($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int,mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->query($sql, $bindings)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): string
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        $this->query($sql, $data);

        return $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed>     $data
     * @param array<string,mixed>     $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new RuntimeException('update() requires both data and a where clause.');
        }

        $sets   = [];
        $params = [];

        foreach ($data as $column => $value) {
            $sets[]              = sprintf('`%s` = :set_%s', $column, $column);
            $params['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[]              = sprintf('`%s` = :where_%s', $column, $column);
            $params['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            implode(' AND ', $conditions)
        );

        return $this->execute($sql, $params);
    }

    // ---------------------------------------------------------------- txn --

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT sp_' . $this->transactionDepth);
        }

        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT sp_' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT sp_' . $this->transactionDepth);
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * Run a closure inside a transaction, retrying on deadlock / lock-wait
     * timeout with the backoff schedule from config. Any other exception rolls
     * back once and propagates — a partially-written attendance record is never
     * acceptable (Part 5, "never partially save attendance").
     *
     * @template T
     * @param  callable(Database):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        /** @var array{attempts:int,backoff_ms:list<int>} $retry */
        $retry    = Config::get('database.deadlock_retry', ['attempts' => 3, 'backoff_ms' => [50, 150, 400]]);
        $attempts = max(1, (int) $retry['attempts']);
        $backoff  = $retry['backoff_ms'];

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            } catch (Throwable $e) {
                $this->rollBack();

                if ($this->isRetryable($e) && $attempt < $attempts - 1) {
                    $sleepMs = $backoff[$attempt] ?? 400;
                    Logger::warning('Transaction deadlock, retrying', [
                        'attempt'  => $attempt + 1,
                        'sleep_ms' => $sleepMs,
                    ]);
                    usleep($sleepMs * 1000);
                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException('Transaction exhausted all retry attempts.');
    }

    /** MySQL 1213 = deadlock, 1205 = lock wait timeout. Both are safe to retry. */
    private function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return in_array($driverCode, [1213, 1205], true);
    }

    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    public static function duplicateKeyName(Throwable $e): ?string
    {
        if (!self::isDuplicateKey($e)) {
            return null;
        }

        // "Duplicate entry 'x' for key 'attendance_records.uq_session_student'"
        if (preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $parts = explode('.', $m[1]);

            return end($parts) ?: null;
        }

        return null;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function queryTimeMs(): float
    {
        return round($this->queryTime * 1000, 2);
    }

    private static function redactSql(string $sql): string
    {
        return substr(preg_replace('/\s+/', ' ', $sql) ?? $sql, 0, 500);
    }
}
