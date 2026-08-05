<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Thin active-record-ish base for table access.
 *
 * Deliberately small: complex reads live in the Services layer as explicit SQL
 * so the query a page runs is readable in one place, and indexes can be
 * reasoned about. What this base does provide is the soft-delete and timestamp
 * discipline that Part 5 requires of every table.
 */
abstract class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected bool $timestamps   = true;
    protected bool $softDeletes  = false;
    /** @var list<string> */
    protected array $fillable = [];

    protected Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    public function table(): string
    {
        return $this->table;
    }

    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    /** @return array<string,mixed>|null */
    public function find(int|string $id): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `%s` = :id', $this->table, $this->primaryKey);

        if ($this->softDeletes) {
            $sql .= ' AND deleted_at IS NULL';
        }

        return $this->db->selectOne($sql . ' LIMIT 1', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findIncludingDeleted(int|string $id): ?array
    {
        return $this->db->selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :id LIMIT 1', $this->table, $this->primaryKey),
            ['id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findBy(string $column, mixed $value): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `%s` = :value', $this->table, self::column($column));

        if ($this->softDeletes) {
            $sql .= ' AND deleted_at IS NULL';
        }

        return $this->db->selectOne($sql . ' LIMIT 1', ['value' => $value]);
    }

    /**
     * @param  array<string,mixed> $conditions
     * @return list<array<string,mixed>>
     */
    public function where(array $conditions, string $orderBy = '', int $limit = 0): array
    {
        $clauses  = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $clauses[]            = sprintf('`%s` = :%s', self::column($column), $column);
            $bindings[$column]    = $value;
        }

        if ($this->softDeletes) {
            $clauses[] = 'deleted_at IS NULL';
        }

        $sql = sprintf(
            'SELECT * FROM `%s`%s',
            $this->table,
            $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses)
        );

        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . self::orderBy($orderBy);
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $this->db->select($sql, $bindings);
    }

    /** @return list<array<string,mixed>> */
    public function all(string $orderBy = ''): array
    {
        return $this->where([], $orderBy);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): string
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $now = Clock::nowString();
            $data['created_at'] ??= $now;
            $data['updated_at'] ??= $now;
        }

        return $this->db->insert($this->table, $data);
    }

    /** @param array<string,mixed> $data */
    public function updateById(int|string $id, array $data): int
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $data['updated_at'] = Clock::nowString();
        }

        if ($data === []) {
            return 0;
        }

        return $this->db->update($this->table, $data, [$this->primaryKey => $id]);
    }

    /**
     * Soft delete when the table supports it.
     *
     * Attendance, audit logs, security logs and login history have
     * $softDeletes = false AND no destroy() call anywhere — Part 5 forbids
     * removing them by any route, including this one.
     */
    public function archive(int|string $id): int
    {
        if (!$this->softDeletes) {
            throw new \LogicException(static::class . ' does not support archiving.');
        }

        return $this->db->update(
            $this->table,
            ['deleted_at' => Clock::nowString(), 'updated_at' => Clock::nowString()],
            [$this->primaryKey => $id]
        );
    }

    public function restore(int|string $id): int
    {
        if (!$this->softDeletes) {
            throw new \LogicException(static::class . ' does not support restoring.');
        }

        return $this->db->update(
            $this->table,
            ['deleted_at' => null, 'updated_at' => Clock::nowString()],
            [$this->primaryKey => $id]
        );
    }

    /** @param array<string,mixed> $conditions */
    public function count(array $conditions = []): int
    {
        $clauses  = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $clauses[]         = sprintf('`%s` = :%s', self::column($column), $column);
            $bindings[$column] = $value;
        }

        if ($this->softDeletes) {
            $clauses[] = 'deleted_at IS NULL';
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM `%s`%s',
            $this->table,
            $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses)
        );

        return (int) $this->db->scalar($sql, $bindings);
    }

    public function exists(string $column, mixed $value, int|string|null $ignoreId = null): bool
    {
        $sql      = sprintf('SELECT 1 FROM `%s` WHERE `%s` = :value', $this->table, self::column($column));
        $bindings = ['value' => $value];

        if ($ignoreId !== null) {
            $sql .= sprintf(' AND `%s` <> :ignore', $this->primaryKey);
            $bindings['ignore'] = $ignoreId;
        }

        return $this->db->scalar($sql . ' LIMIT 1', $bindings) !== null;
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function filterFillable(array $data): array
    {
        if ($this->fillable === []) {
            return $data;
        }

        $allowed = array_merge($this->fillable, ['created_at', 'updated_at', 'deleted_at']);

        return array_intersect_key($data, array_flip($allowed));
    }

    protected static function column(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', $name);
    }

    /** Whitelists "column direction" pairs so sort parameters can come from query strings safely. */
    protected static function orderBy(string $expression): string
    {
        $parts = [];

        foreach (explode(',', $expression) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            [$column, $direction] = array_pad(preg_split('/\s+/', $chunk) ?: [], 2, 'ASC');
            $direction            = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
            $parts[]              = sprintf('`%s` %s', self::column((string) $column), $direction);
        }

        return $parts === [] ? '1' : implode(', ', $parts);
    }

    public function db(): Database
    {
        return $this->db;
    }
}
