<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use PDOException;

/**
 * Request idempotency (Part 17.3).
 *
 * Every device request carries a request_id generated on the ESP32 and
 * persisted in its offline queue. A repeat of that id returns the stored
 * response verbatim instead of reprocessing — which is what makes retrying
 * after a network timeout completely safe, and therefore what makes the offline
 * queue trustworthy.
 */
final class IdempotencyService
{
    /** @return array{code:int,body:array<string,mixed>}|null */
    public static function replay(string $requestId): ?array
    {
        $row = Database::instance()->selectOne(
            'SELECT response_code, response_body FROM processed_requests WHERE request_id = :id LIMIT 1',
            ['id' => $requestId]
        );

        if ($row === null) {
            return null;
        }

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $row['response_body'], true) ?? [];

        return ['code' => (int) $row['response_code'], 'body' => $body];
    }

    public static function has(string $requestId): bool
    {
        return Database::instance()->scalar(
            'SELECT 1 FROM processed_requests WHERE request_id = :id LIMIT 1',
            ['id' => $requestId]
        ) !== null;
    }

    /**
     * Store a response against its request id.
     *
     * A duplicate-key collision here means a concurrent identical request won
     * the race; that is the mechanism working, not an error, so it is swallowed.
     *
     * @param array<string,mixed> $body
     */
    public static function remember(string $requestId, ?int $deviceRowId, string $endpoint, int $code, array $body): void
    {
        try {
            Database::instance()->insert('processed_requests', [
                'request_id'    => $requestId,
                'device_row_id' => $deviceRowId,
                'endpoint'      => mb_substr($endpoint, 0, 120),
                'response_code' => $code,
                'response_body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'created_at'    => Clock::nowString(),
            ]);
        } catch (PDOException $e) {
            if (!Database::isDuplicateKey($e)) {
                Logger::warning('Idempotency record write failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public static function purgeOld(?int $hours = null): int
    {
        $hours = $hours ?? (int) Config::get('security.request.idempotency_retention_hours', 24);

        return Database::instance()->execute(
            'DELETE FROM processed_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)',
            ['hours' => $hours]
        );
    }
}
