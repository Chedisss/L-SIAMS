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
            // Bounded, because the unbounded default is not a timeout at all —
            // it is the operating system's, and a host that accepts the SYN and
            // then says nothing leaves the process waiting minutes with no
            // output. That is what a launcher sitting on "Checking the
            // database..." forever actually is. Failing in seconds turns it
            // into a message somebody can act on.
            PDO::ATTR_TIMEOUT            => max(1, (int) ($this->config['connect_timeout'] ?? 5)),
            // Only assignable system variables belong here. The isolation level
            // is set separately below: `SET SESSION TRANSACTION ISOLATION LEVEL`
            // is its own statement form and cannot be comma-chained with
            // variable assignments, and the variable that would express it is
            // spelled differently across MySQL and MariaDB versions.
            PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
                "SET SESSION sql_mode='%s', time_zone='%s'",
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

            // READ COMMITTED is a correctness requirement, not a preference:
            // the tap engine takes explicit row locks in a fixed order, and
            // REPEATABLE READ would add gap locks that turn concurrent taps on
            // adjacent students into avoidable lock waits. A connection that
            // could not be set to it must not be handed out.
            $this->pdo->exec(sprintf(
                'SET SESSION TRANSACTION ISOLATION LEVEL %s',
                self::isolationClause($isolation)
            ));
        } catch (PDOException $e) {
            // The DSN carries the credentials; never let it reach a log or a page.
            Logger::critical('Database connection failed', ['code' => $e->getCode()]);
            $this->pdo = null;
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return $this->pdo;
    }

    /**
     * Map a configured isolation level onto the SQL clause spelling.
     *
     * Allowlisted rather than interpolated: this value reaches the server
     * inside a statement that cannot be parameterised, so an unrecognised
     * setting falls back to the safe default instead of being passed through.
     */
    private static function isolationClause(string $isolation): string
    {
        return match (strtoupper(str_replace([' ', '_'], '-', trim($isolation)))) {
            'READ-UNCOMMITTED' => 'READ UNCOMMITTED',
            'REPEATABLE-READ'  => 'REPEATABLE READ',
            'SERIALIZABLE'     => 'SERIALIZABLE',
            default            => 'READ COMMITTED',
        };
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

        [$sql, $bindings] = self::expandRepeatedPlaceholders($sql, $bindings);

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

    /**
     * Give every occurrence of a repeated named placeholder its own name.
     *
     * Emulated prepares let `:now` appear twice in one statement and bind once.
     * Native prepares — which this class insists on, because they are what
     * actually stops SQL injection — do not: the driver sends each marker to the
     * server separately, and a second occurrence with no binding of its own
     * fails with HY093 "Invalid parameter number".
     *
     * Rather than forbid the pattern and rely on every author remembering, the
     * rewrite happens here: `:now` used three times becomes `:now`, `:now__2`,
     * `:now__3`, each bound to the same value. Call sites stay readable, native
     * prepares stay on, and a query that reads naturally cannot fail at runtime
     * for a reason that has nothing to do with its logic.
     *
     * Only genuine placeholders are touched. A `:` inside a quoted literal is
     * skipped, as is `::` (a cast), so rewriting cannot alter what the statement
     * means.
     *
     * @param  array<string|int,mixed> $bindings
     * @return array{0:string, 1:array<string|int,mixed>}
     */
    private static function expandRepeatedPlaceholders(string $sql, array $bindings): array
    {
        // Positional bindings have no names to collide, and a statement with no
        // colon cannot contain a named placeholder. Both are the common case.
        if ($bindings === [] || !str_contains($sql, ':') || array_is_list($bindings)) {
            return [$sql, $bindings];
        }

        $seen  = [];
        $extra = [];

        $rewritten = preg_replace_callback(
            // A quoted literal or a comment is consumed by the first two
            // alternatives so its contents can never match as a placeholder.
            '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|::|:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m) use (&$seen, &$extra, $bindings): string {
                // No captured name: this was a string literal or a cast.
                if (!isset($m[1]) || $m[1] === '') {
                    return $m[0];
                }

                $name  = $m[1];
                $count = ($seen[$name] = ($seen[$name] ?? 0) + 1);

                if ($count === 1) {
                    return ':' . $name;
                }

                // Only rewrite when a value was actually supplied under this
                // name; otherwise leave it alone so the driver reports the
                // missing binding rather than this silently inventing one.
                if (!array_key_exists($name, $bindings) && !array_key_exists(':' . $name, $bindings)) {
                    return $m[0];
                }

                $alias         = $name . '__' . $count;
                $extra[$alias] = $bindings[$name] ?? $bindings[':' . $name];

                return ':' . $alias;
            },
            $sql
        );

        // preg_replace_callback returns null only on a PCRE failure; if that
        // ever happened, running the original statement is the safe fallback.
        if ($rewritten === null || $extra === []) {
            return [$sql, $bindings];
        }

        return [$rewritten, $bindings + $extra];
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

    /**
     * Does this table actually have this column?
     *
     * For the narrow case of a schema that is behind the code — the server
     * files updated but `migrate` not yet run, which happens whenever someone
     * pulls and skips the database step, or restores an older backup over a
     * newer checkout.
     *
     * It exists because of a real outage. A terminal's heartbeat began
     * reporting two new optional fields; on an un-migrated database the
     * UPDATE naming those columns threw, and the endpoint answered 500. The
     * heartbeat is the terminal's lifeline — it is how the server knows the
     * device is alive at all — so an optional telemetry field must never be
     * able to take it down. Skipping a column the schema cannot hold loses
     * one detail; failing the request loses the device.
     *
     * Use this only for genuinely optional data. Anything the system depends
     * on should fail loudly against a schema that cannot store it, and a
     * misspelled required column silently vanishing is far worse than an
     * error.
     *
     * Cached per process: the answer cannot change while a request runs, and
     * this is called on a path that executes every thirty seconds per
     * terminal.
     *
     * @var array<string,bool>|null
     */
    private ?array $columnCache = null;

    public function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if ($this->columnCache === null) {
            $this->columnCache = [];
        }

        if (!array_key_exists($key, $this->columnCache)) {
            $this->columnCache[$key] = $this->scalar(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = :t
                    AND COLUMN_NAME  = :c
                  LIMIT 1',
                ['t' => $table, 'c' => $column]
            ) !== null;
        }

        return $this->columnCache[$key];
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
