<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use RuntimeException;

final class SchoolYearService
{
    private static ?int $cachedId = null;

    public static function currentId(): int
    {
        if (self::$cachedId !== null) {
            return self::$cachedId;
        }

        $id = Database::instance()->scalar(
            "SELECT school_year_id FROM school_years WHERE is_current = 1 AND status = 'active' LIMIT 1"
        );

        if ($id === null) {
            // Fall back to the year whose date range contains today, so a
            // forgotten "is_current" flag degrades gracefully rather than
            // taking enrolment and scheduling offline.
            $id = Database::instance()->scalar(
                'SELECT school_year_id FROM school_years
                  WHERE :today BETWEEN start_date AND end_date
                  ORDER BY start_date DESC LIMIT 1',
                ['today' => Clock::today()]
            );
        }

        if ($id === null) {
            throw new RuntimeException('No school year is configured. Create one under Academic Setup.');
        }

        return self::$cachedId = (int) $id;
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        return Database::instance()->selectOne(
            'SELECT * FROM school_years WHERE school_year_id = :id',
            ['id' => self::currentId()]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::instance()->select('SELECT * FROM school_years ORDER BY start_date DESC');
    }

    public static function setCurrent(int $schoolYearId): void
    {
        Database::instance()->transaction(static function (Database $db) use ($schoolYearId): void {
            $db->execute('UPDATE school_years SET is_current = 0 WHERE is_current = 1');
            $db->update('school_years', ['is_current' => 1, 'status' => 'active'], ['school_year_id' => $schoolYearId]);
        });

        self::$cachedId = $schoolYearId;

        AuditService::log(
            'SCHOOL_YEAR_CHANGED',
            'academic_setup',
            'school_year',
            $schoolYearId,
            null,
            ['is_current' => 1],
            'Active school year changed.'
        );
    }

    public static function resetCache(): void
    {
        self::$cachedId = null;
    }
}
