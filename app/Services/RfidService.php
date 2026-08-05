<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use PDOException;

/**
 * RFID card issuance, replacement and triage of unknown cards.
 *
 * The rule that shapes this file: a replacement card must retain the student's
 * attendance history. That works because attendance_records reference the
 * student, never the card — the UID is stored alongside for forensics only. So
 * replacing a card is a two-row operation and the history simply continues.
 */
final class RfidService
{
    public static function assign(int $studentId, string $cardUid, int $userId, ?string $notes = null): int
    {
        $cardUid = self::normalise($cardUid);
        self::assertValidUid($cardUid);

        $db = Database::instance();

        return (int) $db->transaction(static function (Database $db) use ($studentId, $cardUid, $userId, $notes): int {
            $student = $db->selectOne(
                'SELECT student_id, student_number, first_name, last_name, section_id, status
                   FROM students WHERE student_id = :id AND deleted_at IS NULL',
                ['id' => $studentId]
            );

            if ($student === null) {
                throw new ValidationException(['student_id' => ['Student not found.']]);
            }

            // Part 13.4: no section, no card. A card issued to a student who is
            // not in a section could never be used successfully anyway, since
            // every tap resolves against the session's section.
            if ((int) $student['section_id'] <= 0) {
                throw new ValidationException([
                    'student_id' => ['This student is not assigned to a section and cannot be issued a card.'],
                ]);
            }

            if ((string) $student['status'] !== 'active') {
                throw new ValidationException([
                    'student_id' => ['Only active students may be issued an RFID card.'],
                ]);
            }

            $existingCard = $db->selectOne(
                'SELECT rc.*, s.student_number, s.first_name, s.last_name
                   FROM rfid_cards rc
                   LEFT JOIN students s ON s.student_id = rc.student_id
                  WHERE rc.card_uid = :uid',
                ['uid' => $cardUid]
            );

            if ($existingCard !== null) {
                if ((string) $existingCard['status'] === 'blacklisted') {
                    throw new ValidationException([
                        'card_uid' => ['This card is blacklisted and cannot be issued.'],
                    ]);
                }

                if ($existingCard['student_id'] !== null && (int) $existingCard['student_id'] !== $studentId) {
                    throw new ValidationException(['card_uid' => [sprintf(
                        'This card UID is already assigned to %s %s (%s).',
                        $existingCard['first_name'],
                        $existingCard['last_name'],
                        $existingCard['student_number']
                    )]]);
                }
            }

            // Retire any card the student already holds. The generated column
            // uq_one_active_card_per_student would reject a second active row
            // regardless, but doing it explicitly gives a clean history chain.
            $current = $db->selectOne(
                "SELECT * FROM rfid_cards WHERE student_id = :student AND status = 'active' FOR UPDATE",
                ['student' => $studentId]
            );

            if ($current !== null) {
                $db->update('rfid_cards', [
                    'status'           => 'replaced',
                    'replacement_date' => Clock::today(),
                    'updated_at'       => Clock::nowString(),
                ], ['rfid_id' => (int) $current['rfid_id']]);
            }

            try {
                if ($existingCard !== null) {
                    $rfidId = (int) $existingCard['rfid_id'];

                    $db->update('rfid_cards', [
                        'student_id'       => $studentId,
                        'status'           => 'active',
                        'issue_date'       => Clock::today(),
                        'replaced_rfid_id' => $current === null ? null : (int) $current['rfid_id'],
                        'notes'            => $notes,
                        'issued_by'        => $userId,
                        'updated_at'       => Clock::nowString(),
                    ], ['rfid_id' => $rfidId]);
                } else {
                    $rfidId = (int) $db->insert('rfid_cards', [
                        'student_id'       => $studentId,
                        'card_uid'         => $cardUid,
                        'issue_date'       => Clock::today(),
                        'replaced_rfid_id' => $current === null ? null : (int) $current['rfid_id'],
                        'status'           => 'active',
                        'notes'            => $notes,
                        'issued_by'        => $userId,
                        'created_at'       => Clock::nowString(),
                        'updated_at'       => Clock::nowString(),
                    ]);
                }
            } catch (PDOException $e) {
                if (Database::isDuplicateKey($e)) {
                    throw new ValidationException(['card_uid' => ['This card UID is already registered.']]);
                }

                throw $e;
            }

            // Resolve the triage entry if this UID had been seen as unknown.
            $db->execute(
                "UPDATE unknown_rfid_logs
                    SET resolution = 'assigned', resolved_by = :user, resolved_at = :now
                  WHERE card_uid = :uid AND resolution = 'pending'",
                ['uid' => $cardUid, 'user' => $userId, 'now' => Clock::nowString()]
            );

            AuditService::log(
                $current === null ? AuditService::RFID_ASSIGNED : AuditService::RFID_REPLACED,
                'rfid',
                'rfid_card',
                $rfidId,
                $current === null ? null : ['previous_uid' => $current['card_uid']],
                ['card_uid' => $cardUid, 'student_id' => $studentId],
                sprintf(
                    '%s card %s for %s %s (%s). Attendance history retained.',
                    $current === null ? 'Issued' : 'Replaced',
                    $cardUid,
                    $student['first_name'],
                    $student['last_name'],
                    $student['student_number']
                )
            );

            return $rfidId;
        });
    }

    public static function setStatus(int $rfidId, string $status, int $userId, ?string $reason = null): void
    {
        $allowed = ['active', 'inactive', 'lost', 'blacklisted'];

        if (!in_array($status, $allowed, true)) {
            throw new ValidationException(['status' => ['Invalid card status.']]);
        }

        $db   = Database::instance();
        $card = $db->selectOne('SELECT * FROM rfid_cards WHERE rfid_id = :id', ['id' => $rfidId]);

        if ($card === null) {
            throw new ValidationException(['rfid_id' => ['Card not found.']]);
        }

        // Reactivating requires that the student has no other live card, or the
        // one-active-card-per-student invariant would be violated.
        if ($status === 'active' && $card['student_id'] !== null) {
            $other = $db->scalar(
                "SELECT card_uid FROM rfid_cards
                  WHERE student_id = :student AND status = 'active' AND rfid_id <> :id LIMIT 1",
                ['student' => (int) $card['student_id'], 'id' => $rfidId]
            );

            if ($other !== null) {
                throw new ValidationException(['status' => [sprintf(
                    'This student already holds active card %s. Deactivate it first.',
                    $other
                )]]);
            }
        }

        $db->update('rfid_cards', [
            'status'     => $status,
            'notes'      => $reason ?? $card['notes'],
            'updated_at' => Clock::nowString(),
        ], ['rfid_id' => $rfidId]);

        $action = match ($status) {
            'blacklisted' => AuditService::RFID_BLACKLISTED,
            'active'      => 'RFID_REACTIVATED',
            default       => AuditService::RFID_DEACTIVATED,
        };

        AuditService::log(
            $action,
            'rfid',
            'rfid_card',
            $rfidId,
            ['status' => $card['status']],
            ['status' => $status, 'reason' => $reason],
            sprintf('Card %s set to %s. %s', $card['card_uid'], $status, $reason ?? ''),
            'success',
            $userId
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

        if (!empty($filters['status'])) {
            $where[]            = 'rc.status = :status';
            $bindings['status'] = (string) $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(rc.card_uid LIKE :search
                         OR s.student_number LIKE :search
                         OR CONCAT(s.first_name, \' \', s.last_name) LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['section_id'])) {
            $where[]                = 's.section_id = :section_id';
            $bindings['section_id'] = (int) $filters['section_id'];
        }

        $whereSql = implode(' AND ', $where);
        $db       = Database::instance();

        $base = "FROM rfid_cards rc
                 LEFT JOIN students s ON s.student_id = rc.student_id
                 LEFT JOIN sections sec ON sec.section_id = s.section_id
                WHERE {$whereSql}";

        $total = (int) $db->scalar("SELECT COUNT(*) {$base}", $bindings);

        $rows = $db->select(
            "SELECT rc.*, s.student_number, s.first_name, s.last_name, s.photo_path,
                    sec.section_code, prev.card_uid AS previous_uid,
                    (SELECT COUNT(*) FROM rfid_logs rl WHERE rl.card_uid = rc.card_uid) AS tap_count,
                    (SELECT MAX(rl2.created_at) FROM rfid_logs rl2 WHERE rl2.card_uid = rc.card_uid) AS last_tap_at
             {$base}
             ORDER BY rc.status = 'active' DESC, rc.updated_at DESC
             LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        // The replacement chain is a self-join; pulling it inline keeps the
        // main query readable.
        foreach ($rows as $index => $row) {
            if ($row['replaced_rfid_id'] !== null) {
                $rows[$index]['previous_uid'] = $db->scalar(
                    'SELECT card_uid FROM rfid_cards WHERE rfid_id = :id',
                    ['id' => (int) $row['replaced_rfid_id']]
                );
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function tapHistory(string $cardUid, int $limit = 100): array
    {
        return Database::instance()->select(
            'SELECT rl.*, d.device_id, c.room_number, ases.session_code,
                    sec.section_code AS session_section_code,
                    ssec.section_code AS student_section_code
               FROM rfid_logs rl
               LEFT JOIN devices d               ON d.id = rl.device_row_id
               LEFT JOIN classrooms c            ON c.classroom_id = d.classroom_id
               LEFT JOIN attendance_sessions ases ON ases.session_id = rl.session_id
               LEFT JOIN sections sec            ON sec.section_id = rl.session_section_id
               LEFT JOIN sections ssec           ON ssec.section_id = rl.student_section_id
              WHERE rl.card_uid = :uid
              ORDER BY rl.log_id DESC LIMIT ' . max(1, min($limit, 500)),
            ['uid' => self::normalise($cardUid)]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function unknownCards(string $resolution = 'pending'): array
    {
        return Database::instance()->select(
            'SELECT u.*, d.device_id, c.room_number, usr.username AS resolved_by_username
               FROM unknown_rfid_logs u
               LEFT JOIN devices d    ON d.id = u.device_row_id
               LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
               LEFT JOIN users usr    ON usr.user_id = u.resolved_by
              WHERE u.resolution = :resolution
              ORDER BY u.last_seen_at DESC',
            ['resolution' => $resolution]
        );
    }

    public static function resolveUnknown(int $unknownId, string $resolution, int $userId, ?string $notes = null): void
    {
        $db      = Database::instance();
        $unknown = $db->selectOne('SELECT * FROM unknown_rfid_logs WHERE unknown_id = :id', ['id' => $unknownId]);

        if ($unknown === null) {
            throw new ValidationException(['unknown_id' => ['Unknown card entry not found.']]);
        }

        if ($resolution === 'blacklisted') {
            $db->execute(
                "INSERT INTO rfid_cards (card_uid, issue_date, status, notes, issued_by, created_at, updated_at)
                      VALUES (:uid, :today, 'blacklisted', :notes, :user, :now, :now)
                 ON DUPLICATE KEY UPDATE status = 'blacklisted', notes = VALUES(notes), updated_at = VALUES(updated_at)",
                [
                    'uid'   => (string) $unknown['card_uid'],
                    'today' => Clock::today(),
                    'notes' => $notes ?? 'Blacklisted from unknown-card triage.',
                    'user'  => $userId,
                    'now'   => Clock::nowString(),
                ]
            );
        }

        $db->update('unknown_rfid_logs', [
            'resolution'  => $resolution,
            'resolved_by' => $userId,
            'resolved_at' => Clock::nowString(),
            'notes'       => $notes,
        ], ['unknown_id' => $unknownId]);

        AuditService::log(
            'UNKNOWN_RFID_RESOLVED',
            'rfid',
            'unknown_rfid',
            $unknownId,
            ['resolution' => 'pending'],
            ['resolution' => $resolution, 'card_uid' => $unknown['card_uid']],
            sprintf('Unknown card %s marked %s.', $unknown['card_uid'], $resolution),
            'success',
            $userId
        );
    }

    public static function normalise(string $uid): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $uid));
    }

    private static function assertValidUid(string $uid): void
    {
        if (preg_match('/^[0-9A-F]{8,32}$/', $uid) !== 1) {
            throw new ValidationException([
                'card_uid' => ['Card UID must be 8–32 hexadecimal characters (as read from an MFRC522).'],
            ]);
        }
    }

    /** @return array<string,int> */
    public static function summary(): array
    {
        $db = Database::instance();

        return [
            'total'        => (int) $db->scalar('SELECT COUNT(*) FROM rfid_cards'),
            'active'       => (int) $db->scalar("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active'"),
            'inactive'     => (int) $db->scalar("SELECT COUNT(*) FROM rfid_cards WHERE status = 'inactive'"),
            'lost'         => (int) $db->scalar("SELECT COUNT(*) FROM rfid_cards WHERE status = 'lost'"),
            'blacklisted'  => (int) $db->scalar("SELECT COUNT(*) FROM rfid_cards WHERE status = 'blacklisted'"),
            'unassigned_students' => (int) $db->scalar(
                "SELECT COUNT(*) FROM students st
                  WHERE st.deleted_at IS NULL AND st.status = 'active'
                    AND NOT EXISTS (SELECT 1 FROM rfid_cards rc
                                     WHERE rc.student_id = st.student_id AND rc.status = 'active')"
            ),
            'unknown_pending' => (int) $db->scalar("SELECT COUNT(*) FROM unknown_rfid_logs WHERE resolution = 'pending'"),
        ];
    }
}
