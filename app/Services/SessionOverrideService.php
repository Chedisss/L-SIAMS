<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Hash;
use SensitiveParameter;

/**
 * Opening an attendance session without a fingerprint.
 *
 * A fingerprint is the right primary control and the wrong only control. Wet
 * hands, a cut, a burn, a plaster, a sensor that died overnight — any of them
 * leaves a teacher in front of a full class unable to open the register, and
 * the cost of that is not the teacher's inconvenience but a whole class whose
 * attendance is never recorded.
 *
 * The failover is the teacher's own account password, typed at their own
 * dashboard. It proves the same fact the finger proves — this teacher is here,
 * now, deliberately — through a credential the school has already issued and
 * already relies on for everything else that account can do.
 *
 * What it does not relax is everything else. Read the checks below against
 * FingerprintService::verify(): the class must be scheduled at this minute, in
 * this room, with this teacher assigned to it, and the room's terminal must
 * exist. Only the proof of identity changes, and the session records which
 * proof was used, so nobody auditing attendance has to guess.
 */
final class SessionOverrideService
{
    /**
     * Open the teacher's current class after verifying their password.
     *
     * @param  int      $teacherId  from the session, never from input
     * @param  int      $userId     from the session, never from input
     * @param  int|null $scheduleId optional narrowing when two of the
     *                              teacher's classes overlap
     * @return array<string,mixed>
     */
    public static function openWithPassword(
        int $teacherId,
        int $userId,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $confirmation,
        ?int $scheduleId = null
    ): array {
        self::assertBothFieldsAgree($password, $confirmation);

        $teacher = self::teacher($teacherId, $userId);
        $live    = self::liveClass($teacherId, $scheduleId);

        // Password last, and only once everything else has passed. Checking it
        // first would disclose nothing a teacher cannot read off their own
        // timetable page, but it would burn a bcrypt verification on every
        // malformed request, which is a free way to load the server.
        self::assertPassword($userId, $teacherId, $live, $password);

        $session = AttendanceSessionService::open(
            $live['device'],
            $teacher,
            $live['schedule'],
            null,
            null,
            'password',
            $userId
        );

        self::record($teacher, $live, $session, $userId);

        return $session;
    }

    /**
     * Both boxes must match.
     *
     * Typing a password twice adds no security — that pattern belongs to
     * *setting* a password, where there is nothing to check the typing
     * against, and here the stored hash already catches a typo. What it does
     * add is deliberateness: this control exists to be used rarely, and a
     * double entry is hard to perform by accident or in passing on somebody
     * else's unlocked screen.
     *
     * hash_equals because the two values are compared at all: length-leaking
     * comparison of anything derived from a password is not a habit worth
     * making exceptions to.
     */
    private static function assertBothFieldsAgree(
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $confirmation
    ): void {
        $errors = [];

        if ($password === '') {
            $errors['password'] = ['Enter your account password.'];
        }

        if ($confirmation === '') {
            $errors['password_confirmation'] = ['Type your password a second time to confirm.'];
        }

        if ($errors === [] && !hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = ['The two passwords do not match.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Confirm your password to open the session.');
        }
    }

    /**
     * The signed-in teacher's own record.
     *
     * Both identifiers come from the session, and this insists they still
     * describe each other: a teacher row whose user_id has been reassigned is
     * not a teacher this account may act as.
     *
     * @return array<string,mixed>
     */
    private static function teacher(int $teacherId, int $userId): array
    {
        $teacher = Database::instance()->selectOne(
            'SELECT teacher_id, user_id, employee_number, first_name, last_name, status
               FROM teachers
              WHERE teacher_id = :id AND deleted_at IS NULL',
            ['id' => $teacherId]
        );

        if ($teacher === null || (int) ($teacher['user_id'] ?? 0) !== $userId) {
            throw new BusinessRuleException(
                'TEACHER_NOT_FOUND',
                'This account is not linked to a teacher record.',
                [],
                403
            );
        }

        if ((string) $teacher['status'] !== 'active') {
            throw new BusinessRuleException(
                'TEACHER_INACTIVE',
                'This teacher account is not active.',
                [],
                403
            );
        }

        return [
            'teacher_id'      => (int) $teacher['teacher_id'],
            'first_name'      => (string) $teacher['first_name'],
            'last_name'       => (string) $teacher['last_name'],
            'employee_number' => (string) $teacher['employee_number'],
        ];
    }

    /**
     * The class this teacher may open right now, and the terminal in its room.
     *
     * This is ScheduleService::openableForDevice() asked from the other end.
     * The fingerprint path starts from a terminal and asks which of its
     * schedules belongs to the scanned teacher; here the teacher is known and
     * the terminal has to be found.
     *
     * The two must agree on WHICH lessons may be opened, or the password
     * failover becomes a way around the rule the scan enforces. Both refusals
     * are therefore applied here too: a period that has ENDED may not be
     * opened even though its tap-out window is still running, and a period
     * that has NOT YET STARTED may not be opened while another class is being
     * taught in the room. Without the first, the previous teacher could take
     * the room from the one whose lesson is running; without the second, the
     * next teacher could — both by typing a password instead of scanning.
     *
     * The query still returns those rows, and the two reasons come back as
     * columns rather than being filtered out in SQL, so a teacher who is
     * refused is told which of the two it was instead of the flat "no class
     * scheduled" that fits neither.
     *
     * The join to devices is LEFT rather than INNER so a room with no terminal
     * produces a sentence about the missing terminal instead of the same
     * "no class is scheduled" a teacher gets at the wrong time of day.
     *
     * @return array{schedule:array<string,mixed>,device:array<string,mixed>}
     */
    private static function liveClass(int $teacherId, ?int $scheduleId): array
    {
        $now = Clock::now();

        $rows = Database::instance()->select(
            "SELECT sch.*, s.subject_code, s.subject_name, sec.section_code, sec.section_name,
                    c.room_number, gl.grade_level_name,
                    dev.id AS device_row_id, dev.device_id, dev.mac_address,
                    dev.status AS device_status, dev.claim_status,
                    -- Why a row may not be openable. Both are carried back
                    -- rather than filtered out in SQL so the teacher standing
                    -- at the terminal is told which of the two it is.
                    (TIME(:now3) > sch.end_time) AS lesson_over,
                    (SELECT MAX(running.end_time)
                       FROM schedules running
                      WHERE running.classroom_id = sch.classroom_id
                        AND running.schedule_id <> sch.schedule_id
                        AND running.day_of_week  = :day2
                        AND running.status       = 'active'
                        AND running.deleted_at IS NULL
                        AND TIME(:now4) >= running.start_time
                        AND TIME(:now5) <= running.end_time) AS room_busy_until
               FROM schedules sch
               JOIN subjects s      ON s.subject_id = sch.subject_id
               JOIN sections sec    ON sec.section_id = sch.section_id
               JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
               JOIN classrooms c    ON c.classroom_id = sch.classroom_id
               LEFT JOIN devices dev ON dev.classroom_id = sch.classroom_id
                                    AND dev.deleted_at IS NULL
                                    AND dev.status NOT IN ('decommissioned','disabled')
              WHERE sch.teacher_id  = :teacher
                AND sch.day_of_week = :day
                AND sch.status      = 'active'
                AND sch.deleted_at IS NULL
                AND TIME(:now)  >= SUBTIME(sch.start_time, SEC_TO_TIME(sch.time_in_window_open * 60))
                AND TIME(:now2) <= ADDTIME(sch.end_time,   SEC_TO_TIME(sch.time_out_window_close * 60))
              ORDER BY sch.start_time,
                       -- A room with an entry/exit pair has two rows. Prefer
                       -- the one that has actually claimed its key and is
                       -- reporting in, so the session is bound to the terminal
                       -- the students will be tapping on.
                       CASE WHEN dev.claim_status = 'claimed' AND dev.status = 'active' THEN 0
                            WHEN dev.claim_status = 'claimed' THEN 1
                            ELSE 2 END,
                       dev.id",
            [
                'teacher' => $teacherId,
                'day'     => $now->format('l'),
                'day2'    => $now->format('l'),
                'now'     => $now->format('H:i:s'),
                'now2'    => $now->format('H:i:s'),
                'now3'    => $now->format('H:i:s'),
                'now4'    => $now->format('H:i:s'),
                'now5'    => $now->format('H:i:s'),
            ]
        );

        if ($scheduleId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['schedule_id'] === $scheduleId
            ));
        }

        if ($rows === []) {
            throw new BusinessRuleException(
                'NO_ACTIVE_SCHEDULE',
                'You have no class scheduled in its attendance window right now. A session can only be '
                . 'opened between the tap-in and tap-out windows of a class you are assigned to.',
                [],
                403
            );
        }

        // Of the schedules in their window, the ones that may actually be
        // OPENED. The rest stay in $rows only to explain themselves below.
        $over     = static fn (array $row): bool => (int) $row['lesson_over'] === 1;
        $openable = array_values(array_filter(
            $rows,
            static fn (array $row): bool => !$over($row) && $row['room_busy_until'] === null
        ));

        if ($openable === []) {
            // Both reasons can be true of the same row — a finished period whose
            // room the next class has already started in. "Your class ended" is
            // the one that tells the teacher something they can act on, so it
            // wins.
            $ended = array_values(array_filter($rows, $over));

            if ($ended !== []) {
                throw new BusinessRuleException(
                    'CLASS_ALREADY_ENDED',
                    sprintf(
                        'Your %s class ended at %s. Its tap-out window is still open so students can tap out '
                        . 'of a session that is already running, but a new session cannot be opened for a '
                        . 'period that is over.',
                        (string) $ended[0]['subject_code'],
                        substr((string) $ended[0]['end_time'], 0, 5)
                    ),
                    [],
                    403
                );
            }

            $blocked = $rows[0];

            throw new BusinessRuleException(
                'CLASSROOM_IN_USE',
                sprintf(
                    'Room %s is still teaching the class scheduled until %s. Your session can be opened once '
                    . 'that period ends — a room can only run one class at a time.',
                    (string) $blocked['room_number'],
                    substr((string) $blocked['room_busy_until'], 0, 5)
                ),
                [],
                409
            );
        }

        $schedule = $openable[0];

        if ($schedule['device_row_id'] === null) {
            throw new BusinessRuleException(
                'NO_TERMINAL_IN_ROOM',
                sprintf(
                    'No attendance terminal is registered in Room %s, so students would have nothing to tap. '
                    . 'Ask an administrator to register the terminal for this room.',
                    (string) $schedule['room_number']
                ),
                [],
                409
            );
        }

        $device = [
            'id'          => (int) $schedule['device_row_id'],
            'device_id'   => (string) $schedule['device_id'],
            'mac_address' => (string) $schedule['mac_address'],
        ];

        unset(
            $schedule['device_row_id'], $schedule['device_id'], $schedule['mac_address'],
            $schedule['device_status'], $schedule['claim_status'],
            $schedule['lesson_over'], $schedule['room_busy_until']
        );

        return ['schedule' => $schedule, 'device' => $device];
    }

    /**
     * Verify the teacher's own password against their own stored hash.
     *
     * A wrong password is logged as a refused override rather than a generic
     * failed login, because the two mean different things to whoever reads the
     * security page: one is somebody guessing at the door, the other is
     * somebody standing at a teacher's unlocked dashboard guessing at a
     * control that opens attendance for a class.
     *
     * @param array{schedule:array<string,mixed>,device:array<string,mixed>} $live
     */
    private static function assertPassword(
        int $userId,
        int $teacherId,
        array $live,
        #[SensitiveParameter] string $password
    ): void {
        $account = Database::instance()->selectOne(
            'SELECT password_hash, status FROM users WHERE user_id = :id AND deleted_at IS NULL',
            ['id' => $userId]
        );

        // An account locked or disabled since this browser session started
        // gets its own answer rather than "wrong password", which would be a
        // lie that sends the teacher off retyping a password that is correct.
        if ($account !== null && (string) $account['status'] !== 'active') {
            throw new BusinessRuleException(
                'ACCOUNT_NOT_ACTIVE',
                'This account is no longer active. Sign in again, or ask an administrator.',
                [],
                403
            );
        }

        $hash = $account === null ? null : (string) $account['password_hash'];

        if ($hash === null || !Hash::verify($password, $hash)) {
            SecurityLogService::log(
                SecurityLogService::BIOMETRIC_OVERRIDE_REFUSED,
                'high',
                'Password refused for a fingerprint override on an attendance session.',
                [
                    'user_id'     => $userId,
                    'teacher_id'  => $teacherId,
                    'schedule_id' => (int) $live['schedule']['schedule_id'],
                    'device_id'   => (string) $live['device']['device_id'],
                ],
                (int) $live['device']['id'],
                (string) $live['device']['device_id']
            );

            throw new ValidationException(
                ['password' => ['That is not your account password.']],
                'Password incorrect. The session was not opened.',
                'OVERRIDE_PASSWORD_INCORRECT'
            );
        }
    }

    /**
     * Leave the trail.
     *
     * Two entries, on purpose. The audit log answers "who opened this
     * session"; the security log answers "which sessions were opened with the
     * biometric control skipped". Only the second is a question an
     * administrator reviewing controls actually asks, and it is unanswerable
     * from a trail that files this next to routine record edits.
     *
     * @param array<string,mixed>                                            $teacher
     * @param array{schedule:array<string,mixed>,device:array<string,mixed>} $live
     * @param array<string,mixed>                                            $session
     */
    private static function record(array $teacher, array $live, array $session, int $userId): void
    {
        $who = sprintf('%s %s', $teacher['first_name'], $teacher['last_name']);

        AuditService::log(
            AuditService::ATTENDANCE_SESSION_OVERRIDE,
            'attendance',
            'attendance_session',
            (int) $session['session_id'],
            null,
            [
                'session_code'  => (string) $session['session_code'],
                'teacher_id'    => (int) $teacher['teacher_id'],
                'schedule_id'   => (int) $live['schedule']['schedule_id'],
                'device_id'     => (string) $live['device']['device_id'],
                'opened_method' => 'password',
            ],
            sprintf(
                '%s opened attendance session %s for %s with %s in Room %s using their account password, '
                . 'without a fingerprint scan.',
                $who,
                (string) $session['session_code'],
                (string) ($live['schedule']['subject_code'] ?? ''),
                (string) ($live['schedule']['section_code'] ?? ''),
                (string) ($live['schedule']['room_number'] ?? '?')
            )
        );

        // 'medium', not 'high'. High and critical entries are meant to bring an
        // administrator running, and a teacher with a wet finger at nine in
        // the morning is not an incident. It is an event worth being able to
        // count and review, which is what medium is for. A *refused* attempt
        // is high, because that one is somebody trying.
        SecurityLogService::log(
            SecurityLogService::BIOMETRIC_OVERRIDE_USED,
            'medium',
            sprintf(
                'Attendance session %s opened by password override (fingerprint not used) by %s.',
                (string) $session['session_code'],
                $who
            ),
            [
                'user_id'      => $userId,
                'teacher_id'   => (int) $teacher['teacher_id'],
                'session_code' => (string) $session['session_code'],
                'schedule_id'  => (int) $live['schedule']['schedule_id'],
                'room_number'  => (string) ($live['schedule']['room_number'] ?? ''),
            ],
            (int) $live['device']['id'],
            (string) $live['device']['device_id']
        );

        NotificationService::toAdministrators(
            'attendance',
            'Session opened without a fingerprint',
            sprintf(
                '%s opened %s (%s with %s, Room %s) using their password because the fingerprint could not be read.',
                $who,
                (string) $session['session_code'],
                (string) ($live['schedule']['subject_code'] ?? ''),
                (string) ($live['schedule']['section_code'] ?? ''),
                (string) ($live['schedule']['room_number'] ?? '?')
            ),
            'normal',
            '/admin/attendance/sessions'
        );
    }
}
