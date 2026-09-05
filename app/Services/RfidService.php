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
    /**
     * Why a card is being taken out of service, and how each reads on screen.
     *
     * A fixed list for the same reason every other reason in this system is
     * one: free text produces "lost", "Lost", "lost it" and "student lost
     * card" in a single term, and a school asked how many cards are
     * unaccounted for cannot be answered from it.
     *
     * @var array<string,string>
     */
    public const REPLACEMENT_REASONS = [
        'lost'         => 'Lost',
        'damaged'      => 'Damaged or not working',
        'stolen'       => 'Stolen',
        'not_returned' => 'Not returned by the student',
        'other'        => 'Other',
    ];

    /**
     * What the retired card becomes.
     *
     * All three are refused at the reader — a tap requires status 'active' —
     * so this is not about access. It is about the register being able to
     * answer which cards are unaccounted for, which 'replaced' cannot.
     *
     * 'stolen' ends as blacklisted rather than lost because the two differ in
     * what happens next: a lost card that turns up may reasonably be reissued,
     * and a stolen one may not. assign() refuses to issue a blacklisted card,
     * so the status is the enforcement.
     *
     * @var array<string,string>
     */
    private const REASON_TERMINAL_STATUS = [
        'lost'         => 'lost',
        'stolen'       => 'blacklisted',
        'damaged'      => 'replaced',
        'not_returned' => 'lost',
        'other'        => 'replaced',
    ];

    /**
     * Issue a card to a student, replacing whatever they hold now.
     *
     * $replacementReason is required exactly when a card is actually being
     * taken out of service — never for a student's first card, always for a
     * student who already holds one. Without it every retired card was
     * recorded as 'replaced' whatever had happened to it, so a card lying in a
     * corridor and a card in a bin read identically.
     *
     * Attendance is untouched by any of this: attendance_records reference the
     * student, never the card, and the UID stored alongside them is forensic.
     * The student's history simply continues onto the new card.
     */
    public static function assign(
        int $studentId,
        string $cardUid,
        int $userId,
        ?string $notes = null,
        ?string $replacementReason = null,
        ?string $replacementNote = null
    ): int {
        $replacementNote = $replacementNote === null ? null : trim($replacementNote);
        $replacementNote = ($replacementNote === null || $replacementNote === '')
            ? null
            : mb_substr($replacementNote, 0, 255);

        if ($replacementReason !== null && !isset(self::REPLACEMENT_REASONS[$replacementReason])) {
            throw new ValidationException([
                'replacement_reason' => ['Choose why the previous card is being taken out of service.'],
            ]);
        }

        if ($replacementReason === 'other' && $replacementNote === null) {
            throw new ValidationException([
                'replacement_note' => ['Recording this as Other needs a short note saying what happened.'],
            ]);
        }

        $cardUid = self::normalise($cardUid);
        self::assertValidUid($cardUid);

        $db = Database::instance();

        return (int) $db->transaction(static function (Database $db) use (
            $studentId, $cardUid, $userId, $notes, $replacementReason, $replacementNote
        ): int {
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

            // Re-issuing the very card the student already holds replaces
            // nothing — it is a no-op dressed as a replacement, and demanding a
            // reason for it would be nonsense.
            if ($current !== null && $existingCard !== null
                && (int) $current['rfid_id'] === (int) $existingCard['rfid_id']) {
                $current = null;
            }

            if ($current !== null) {
                // A card is only ever taken out of service for a reason, and
                // the reason decides what it becomes. Refusing without one is
                // the point: it is the difference between a register that can
                // say which cards are unaccounted for and one that cannot.
                if ($replacementReason === null) {
                    throw new ValidationException(['replacement_reason' => [sprintf(
                        'This student already holds card %s. Say why it is being taken out of service.',
                        (string) $current['card_uid']
                    )]]);
                }

                $db->update('rfid_cards', [
                    'status'             => self::REASON_TERMINAL_STATUS[$replacementReason],
                    'replacement_date'   => Clock::today(),
                    'replacement_reason' => $replacementReason,
                    'replacement_note'   => $replacementNote,
                    'updated_at'         => Clock::nowString(),
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
                $current === null ? null : [
                    'previous_uid'    => $current['card_uid'],
                    'previous_status' => (string) $current['status'],
                ],
                [
                    'card_uid'           => $cardUid,
                    'student_id'         => $studentId,
                    'replacement_reason' => $replacementReason,
                    'replacement_note'   => $replacementNote,
                ],
                $current === null
                    ? sprintf(
                        'Issued card %s to %s %s (%s).',
                        $cardUid,
                        $student['first_name'],
                        $student['last_name'],
                        $student['student_number']
                    )
                    : sprintf(
                        'Replaced card %s with %s for %s %s (%s) — %s%s. The old card is now %s. '
                        . 'Attendance history retained.',
                        (string) $current['card_uid'],
                        $cardUid,
                        $student['first_name'],
                        $student['last_name'],
                        $student['student_number'],
                        strtolower(self::REPLACEMENT_REASONS[$replacementReason]),
                        $replacementNote === null ? '' : ': ' . $replacementNote,
                        self::REASON_TERMINAL_STATUS[$replacementReason]
                    )
            );

            return $rfidId;
        });
    }

    /**
     * Return a card to stock so the plastic can be handed to somebody else.
     *
     * Physical cards are a finite supply. A school that ran a term with one
     * roll of pupils and archived them has a drawer of cards the system will
     * not let it re-issue: assign() refuses any UID already bearing another
     * student's id, and archiving a student deactivates their card without
     * ever clearing that id. The card is then locked to a person who has left,
     * with no way out of it from anywhere in the interface — and buying new
     * cards to work around a database field is not a fix.
     *
     * Releasing sets student_id to NULL and the status to inactive, which is
     * exactly the "stock card not yet issued" state the schema already
     * describes. assign() then treats it as new plastic.
     *
     * Nothing historical moves. attendance_records.rfid_uid and
     * rfid_logs.card_uid are snapshot strings rather than foreign keys, so
     * every tap the previous holder ever made keeps their name and their UID.
     * What changes is only who the registry says holds the card now.
     *
     * Refused while the holder is still an active student. Taking a card off
     * somebody still enrolled is a replacement, and the replacement flow exists
     * to demand a reason for it — routing round that with a release would turn
     * "lost card" into an untracked event.
     */
    public static function release(int $rfidId, int $userId, ?string $reason = null): void
    {
        $db   = Database::instance();
        $card = $db->selectOne(
            'SELECT rc.*, s.student_number, s.first_name, s.last_name, s.status AS student_status
               FROM rfid_cards rc
               LEFT JOIN students s ON s.student_id = rc.student_id
              WHERE rc.rfid_id = :id',
            ['id' => $rfidId]
        );

        if ($card === null) {
            throw new ValidationException(['rfid_id' => ['Card not found.']]);
        }

        if ($card['student_id'] === null) {
            throw new ValidationException(['rfid_id' => ['This card is already unassigned.']]);
        }

        if ((string) $card['status'] === 'blacklisted') {
            throw new ValidationException([
                'rfid_id' => ['This card is blacklisted. Take it off the blacklist first if it is genuinely to be reused.'],
            ]);
        }

        if ((string) $card['student_status'] === 'active') {
            throw new ValidationException(['rfid_id' => [sprintf(
                '%s %s is still enrolled. Issue them a replacement card instead, which records why this one is being withdrawn.',
                $card['first_name'],
                $card['last_name']
            )]]);
        }

        $db->update('rfid_cards', [
            'student_id' => null,
            // Remembered rather than erased. Clearing student_id and keeping
            // nothing left the card showing "unassigned" with no way to find
            // out whose it had been from anywhere a person actually looks.
            'released_student_id' => (int) $card['student_id'],
            'released_at'         => Clock::nowString(),
            'status'     => 'inactive',
            'notes'      => $reason ?? $card['notes'],
            'updated_at' => Clock::nowString(),
        ], ['rfid_id' => $rfidId]);

        AuditService::log(
            AuditService::RFID_RELEASED,
            'rfid',
            'rfid_card',
            $rfidId,
            ['student_id' => (int) $card['student_id'], 'status' => $card['status']],
            ['student_id' => null, 'status' => 'inactive', 'reason' => $reason],
            sprintf(
                'Card %s released from %s (%s) and returned to stock. %s',
                $card['card_uid'],
                trim((string) $card['first_name'] . ' ' . (string) $card['last_name']),
                $card['student_number'],
                $reason ?? ''
            ),
            'success',
            $userId
        );
    }

    /**
     * Every card still registered to a student who has left.
     *
     * @return list<array<string,mixed>>
     */
    public static function releasable(): array
    {
        return Database::instance()->select(
            "SELECT rc.rfid_id, rc.card_uid, rc.status,
                    s.student_number, s.first_name, s.last_name, s.status AS student_status
               FROM rfid_cards rc
               JOIN students s ON s.student_id = rc.student_id
              WHERE rc.status <> 'blacklisted'
                AND (s.status <> 'active' OR s.deleted_at IS NOT NULL)
              ORDER BY s.student_number"
        );
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
                 LEFT JOIN students prev ON prev.student_id = rc.released_student_id
                WHERE {$whereSql}";

        $total = (int) $db->scalar("SELECT COUNT(*) {$base}", $bindings);

        $rows = $db->select(
            // previous_uid is filled in below rather than joined here: the
            // replacement chain is a self-join on the same table, and resolving
            // it inline made the query noticeably harder to read for one column
            // that is null on almost every row.
            // student_status drives the Release action: a card is only
            // releasable once its holder has left.
            //
            // The tap counts are scoped to the student who actually holds the
            // card, not to the card itself. Counting per UID meant a card
            // re-issued after a term credited its new owner with every tap the
            // previous one had made — fifteen against a pupil who had not yet
            // used it once. The card's whole history is still readable in the
            // tap history view, which is per-UID on purpose: there the question
            // is what the plastic has done, not what the pupil has.
            "SELECT rc.*, s.student_number, s.first_name, s.last_name, s.photo_path,
                    s.status AS student_status, sec.section_code,
                    prev.student_number AS released_student_number,
                    prev.first_name     AS released_first_name,
                    prev.last_name      AS released_last_name,
                    (SELECT COUNT(*) FROM rfid_logs rl
                      WHERE rl.card_uid = rc.card_uid AND rl.student_id = rc.student_id) AS tap_count,
                    (SELECT MAX(rl2.created_at) FROM rfid_logs rl2
                      WHERE rl2.card_uid = rc.card_uid AND rl2.student_id = rc.student_id) AS last_tap_at
             {$base}
             ORDER BY rc.status = 'active' DESC, rc.updated_at DESC
             LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        // Every row carries the key, so a template can read it without having
        // to guard — only the replaced cards carry a value.
        foreach ($rows as $index => $row) {
            $rows[$index]['previous_uid'] = $row['replaced_rfid_id'] === null
                ? null
                : $db->scalar(
                    'SELECT card_uid FROM rfid_cards WHERE rfid_id = :id',
                    ['id' => (int) $row['replaced_rfid_id']]
                );
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

    /** Public so a UID arriving from a terminal is held to the same shape. */
    public static function assertValidUid(string $uid): void
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

    /**
     * Active students who hold no active card — the queue to work down after a
     * roster import.
     *
     * The summary has counted these for a long time; this returns the names
     * behind the count. Without it the only way to issue a card is to remember
     * who still needs one and type their name into a search box, which for a
     * freshly imported section means a few hundred searches and no way to tell
     * what is left.
     *
     * "No active card" rather than "no card": a student whose card was lost or
     * replaced has rows in rfid_cards but nothing they can tap with, and they
     * need a card exactly as much as somebody who has never held one.
     *
     * Ordered by section then name so the list reads in the same order as the
     * class list the cards are usually handed out from.
     *
     * @param  array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function studentsWithoutCard(array $filters, int $page, int $perPage): array
    {
        $where = [
            'st.deleted_at IS NULL',
            "st.status = 'active'",
            "NOT EXISTS (SELECT 1 FROM rfid_cards rc
                          WHERE rc.student_id = st.student_id AND rc.status = 'active')",
        ];

        $bindings = [];

        if (!empty($filters['section_id'])) {
            $where[]                = 'st.section_id = :section_id';
            $bindings['section_id'] = (int) $filters['section_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(st.student_number LIKE :search
                         OR CONCAT(st.first_name, ' ', st.last_name) LIKE :search
                         OR CONCAT(st.last_name, ' ', st.first_name) LIKE :search)";
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        $db   = Database::instance();
        $base = 'FROM students st
                 LEFT JOIN sections sec ON sec.section_id = st.section_id
                 LEFT JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
                WHERE ' . implode(' AND ', $where);

        $total = (int) $db->scalar("SELECT COUNT(*) {$base}", $bindings);

        $rows = $db->select(
            "SELECT st.student_id, st.student_number, st.first_name, st.middle_name,
                    st.last_name, st.suffix, st.section_id,
                    sec.section_code, gl.grade_level_code,
                    (SELECT COUNT(*) FROM rfid_cards rc2 WHERE rc2.student_id = st.student_id) AS previous_cards
             {$base}
             ORDER BY sec.section_code IS NULL, sec.section_code, st.last_name, st.first_name
             LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }
}
