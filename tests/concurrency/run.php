#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * L-SIAMS concurrency and correctness test suite (Part 17.10).
 *
 * The nine scenarios the specification requires, plus the section-aware and
 * time-in/time-out acceptance criteria from Part 20.4. Every test drives the
 * real service layer against a real database — none of it is mocked, because
 * the properties under test (row locks, unique constraints, deadlock retry)
 * only exist in the database.
 *
 *   php tests/concurrency/run.php                run everything
 *   php tests/concurrency/run.php --only=race    run one group
 *   php tests/concurrency/run.php --load         include the 30-minute soak
 *
 * WARNING: this creates and destroys test data. Point it at a test database,
 * never at production.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Services\AttendanceService;
use App\Services\AttendanceSessionService;
use App\Services\AttendanceStatusResolver;
use App\Services\RfidService;
use App\Services\SettingsService;

SettingsService::hydrate();

// A successful sign-in creates a session and rotates the CSRF token, and both
// go through Flash::start(). Under the CLI SAPI, headers count as sent the
// moment anything is printed, and session_start() then fails — so the sign-in
// group would report a session error instead of the thing it is testing.
// Starting the session here, before the runner prints its first line, makes
// Flash::start() find one already active and return.
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--only=')) {
        $options['only'] = substr($argument, 7);
    }
    if ($argument === '--load') {
        $options['load'] = true;
    }
    if ($argument === '--keep') {
        $options['keep'] = true;
    }
}

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<array{name:string,message:string}> */
    private array $failures = [];
    /** @var array<string,float> */
    private array $timings = [];
    private float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function group(string $title): void
    {
        $this->line('');
        $this->line('  ' . $title, 'bold');
        $this->line('  ' . str_repeat('─', min(70, strlen($title))), 'grey');
    }

    public function assert(string $name, bool $condition, string $detail = ''): bool
    {
        if ($condition) {
            $this->passed++;
            $this->line(sprintf('  ✓ %s', $name), 'green');
        } else {
            $this->failed++;
            $this->failures[] = ['name' => $name, 'message' => $detail];
            $this->line(sprintf('  ✗ %s', $name), 'red');

            if ($detail !== '') {
                $this->line('      ' . $detail, 'grey');
            }
        }

        return $condition;
    }

    public function assertEquals(string $name, mixed $expected, mixed $actual): bool
    {
        return $this->assert(
            $name,
            $expected === $actual,
            sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
        );
    }

    public function metric(string $name, string $value): void
    {
        $this->line(sprintf('    %s: %s', $name, $value), 'cyan');
    }

    public function time(string $key, float $seconds): void
    {
        $this->timings[$key] = $seconds;
    }

    public function info(string $message): void
    {
        $this->line('    ' . $message, 'grey');
    }

    public function summary(): int
    {
        $elapsed = microtime(true) - $this->startedAt;

        $this->line('');
        $this->line('  ' . str_repeat('═', 70), 'grey');
        $this->line(sprintf('  %d passed, %d failed  ·  %.1fs',
            $this->passed, $this->failed, $elapsed),
            $this->failed === 0 ? 'green' : 'red');

        if ($this->timings !== []) {
            $this->line('');
            foreach ($this->timings as $key => $seconds) {
                $this->line(sprintf('    %-42s %.1f ms', $key, $seconds * 1000), 'cyan');
            }
        }

        if ($this->failures !== []) {
            $this->line('');
            $this->line('  Failures:', 'red');

            foreach ($this->failures as $failure) {
                $this->line('    • ' . $failure['name'], 'red');
                if ($failure['message'] !== '') {
                    $this->line('      ' . $failure['message'], 'grey');
                }
            }
        }

        $this->line('');

        return $this->failed === 0 ? 0 : 1;
    }

    private function line(string $message, string $colour = ''): void
    {
        $colours = [
            'red' => "\033[31m", 'green' => "\033[32m", 'yellow' => "\033[33m",
            'cyan' => "\033[36m", 'grey' => "\033[90m", 'bold' => "\033[1m",
        ];

        $prefix = $colours[$colour] ?? '';
        $suffix = $prefix === '' ? '' : "\033[0m";

        fwrite(STDOUT, $prefix . $message . $suffix . PHP_EOL);
    }
}

/**
 * Builds a self-contained scenario: departments, sections, students, cards,
 * devices and schedules, all prefixed so teardown can find them again.
 */
final class Fixture
{
    public const PREFIX = 'TEST-CONC-';

    private Database $db;
    /** @var array<string,mixed> */
    public array $ids = [];

    public function __construct()
    {
        $this->db = Database::instance();
    }

    /** @return array<string,mixed> */
    public function build(int $sectionCount = 2, int $studentsPerSection = 45, int $deviceCount = 2): array
    {
        $this->teardown();

        $now  = Clock::nowString();
        $year = (int) $this->db->scalar('SELECT school_year_id FROM school_years WHERE is_current = 1 LIMIT 1');

        if ($year === 0) {
            $year = (int) $this->db->insert('school_years', [
                'year_label' => self::PREFIX . 'YEAR',
                'start_date' => Clock::now()->modify('-3 months')->format('Y-m-d'),
                'end_date'   => Clock::now()->modify('+9 months')->format('Y-m-d'),
                'is_current' => 1,
                'status'     => 'active',
            ]);
        }

        $departmentId = (int) $this->db->insert('departments', [
            'department_code' => self::PREFIX . 'DEP',
            'department_name' => self::PREFIX . 'Department',
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $gradeLevelId = (int) $this->db->scalar(
            'SELECT grade_level_id FROM grade_levels ORDER BY numeric_level DESC LIMIT 1'
        );

        $subjectId = (int) $this->db->insert('subjects', [
            'subject_code'  => self::PREFIX . 'SUB',
            'subject_name'  => self::PREFIX . 'Subject',
            'department_id' => $departmentId,
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $this->db->execute(
            'INSERT IGNORE INTO subject_grade_levels (subject_id, grade_level_id) VALUES (:s, :g)',
            ['s' => $subjectId, 'g' => $gradeLevelId]
        );

        $teacherId = (int) $this->db->insert('teachers', [
            'employee_number' => self::PREFIX . 'T1',
            'department_id'   => $departmentId,
            'first_name'      => 'Concurrency',
            'last_name'       => 'Tester',
            'email'           => 'concurrency.tester@' . strtolower(self::PREFIX) . 'test.local',
            'status'          => 'active',
            'fingerprint_status' => 'enrolled',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $this->db->execute(
            'INSERT IGNORE INTO teacher_grade_levels (teacher_id, grade_level_id) VALUES (:t, :g)',
            ['t' => $teacherId, 'g' => $gradeLevelId]
        );

        $this->db->execute(
            'INSERT IGNORE INTO teacher_departments (teacher_id, department_id, is_primary) VALUES (:t, :d, 1)',
            ['t' => $teacherId, 'd' => $departmentId]
        );

        $fingerprintId = (int) $this->db->insert('fingerprint_templates', [
            'teacher_id'         => $teacherId,
            'sensor_template_id' => 900,
            'enrollment_date'    => $now,
            'status'             => 'active',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $sections = [];
        $students = [];
        $cards    = [];

        for ($s = 0; $s < $sectionCount; $s++) {
            $sectionId = (int) $this->db->insert('sections', [
                'section_code'   => self::PREFIX . 'SEC' . $s,
                'section_name'   => 'Test Section ' . $s,
                'grade_level_id' => $gradeLevelId,
                'capacity'       => max(50, $studentsPerSection + 5),
                'school_year_id' => $year,
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $sections[] = $sectionId;

            for ($i = 0; $i < $studentsPerSection; $i++) {
                $studentId = (int) $this->db->insert('students', [
                    'student_number'   => sprintf('%sS%d-%03d', self::PREFIX, $s, $i),
                    'section_id'       => $sectionId,
                    'first_name'       => 'Student' . $i,
                    'last_name'        => 'Section' . $s,
                    'guardian_name'    => 'Guardian ' . $i,
                    'guardian_contact' => '09170000000',
                    'status'           => 'active',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                $uid = strtoupper(sprintf('%02X%02X%06X', 0xC0 + $s, $i, random_int(0, 0xFFFFFF)));

                $this->db->insert('rfid_cards', [
                    'student_id' => $studentId,
                    'card_uid'   => $uid,
                    'issue_date' => Clock::today(),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $students[$sectionId][] = $studentId;
                $cards[$sectionId][]    = $uid;

                $this->db->execute(
                    'INSERT IGNORE INTO student_subject_enrolments
                          (student_id, subject_id, section_id, school_year_id, status, created_at, updated_at)
                     VALUES (:st, :sub, :sec, :yr, \'enrolled\', :now, :now)',
                    ['st' => $studentId, 'sub' => $subjectId, 'sec' => $sectionId, 'yr' => $year, 'now' => $now]
                );
            }
        }

        $classrooms = [];
        $devices    = [];
        $schedules  = [];

        for ($d = 0; $d < $deviceCount; $d++) {
            $classroomId = (int) $this->db->insert('classrooms', [
                'room_number' => self::PREFIX . 'R' . $d,
                'capacity'    => 60,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            $deviceRowId = (int) $this->db->insert('devices', [
                'device_id'    => sprintf('%sDEV%03d', self::PREFIX, $d),
                'device_name'  => 'Test Terminal ' . $d,
                'mac_address'  => sprintf('AA:BB:CC:00:%02X:%02X', $d, random_int(0, 255)),
                'classroom_id' => $classroomId,
                'device_role'  => 'both',
                'status'       => 'active',
                'claim_status' => 'claimed',
                'timezone'     => 'Asia/Manila',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            // Windows are set wide so the tests exercise concurrency rather
            // than tripping over clock boundaries. The suite freezes the clock
            // at 10:00 precisely so this window is always well inside the day —
            // see the Clock::freeze() call where the runner is constructed.
            $start = Clock::now()->modify('-10 minutes');
            $end   = Clock::now()->modify('+110 minutes');

            $scheduleId = (int) $this->db->insert('schedules', [
                'teacher_id'     => $teacherId,
                'subject_id'     => $subjectId,
                'section_id'     => $sections[$d % count($sections)],
                'classroom_id'   => $classroomId,
                'school_year_id' => $year,
                'day_of_week'    => Clock::now()->format('l'),
                'start_time'     => $start->format('H:i:s'),
                'end_time'       => $end->format('H:i:s'),
                'time_in_window_open'    => 30,
                'late_threshold_minutes' => 15,
                'time_in_window_close'   => 60,
                'time_out_window_open'   => 30,
                'time_out_window_close'  => 30,
                'minimum_dwell_minutes'  => 1,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $classrooms[] = $classroomId;
            $devices[]    = $deviceRowId;
            $schedules[]  = $scheduleId;
        }

        return $this->ids = [
            'department_id'  => $departmentId,
            'grade_level_id' => $gradeLevelId,
            'subject_id'     => $subjectId,
            'teacher_id'     => $teacherId,
            'fingerprint_id' => $fingerprintId,
            'school_year_id' => $year,
            'sections'       => $sections,
            'students'       => $students,
            'cards'          => $cards,
            'classrooms'     => $classrooms,
            'devices'        => $devices,
            'schedules'      => $schedules,
        ];
    }

    /** @return array<string,mixed> */
    public function device(int $index = 0): array
    {
        return $this->db->selectOne(
            'SELECT * FROM devices WHERE id = :id',
            ['id' => $this->ids['devices'][$index]]
        ) ?? [];
    }

    /** @return array<string,mixed> */
    public function schedule(int $index = 0): array
    {
        return $this->db->selectOne(
            'SELECT sch.*, s.subject_code, s.subject_name, sec.section_code, c.room_number
               FROM schedules sch
               JOIN subjects s   ON s.subject_id = sch.subject_id
               JOIN sections sec ON sec.section_id = sch.section_id
               JOIN classrooms c ON c.classroom_id = sch.classroom_id
              WHERE sch.schedule_id = :id',
            ['id' => $this->ids['schedules'][$index]]
        ) ?? [];
    }

    /** @return array<string,mixed> */
    public function teacher(): array
    {
        return $this->db->selectOne(
            'SELECT * FROM teachers WHERE teacher_id = :id',
            ['id' => $this->ids['teacher_id']]
        ) ?? [];
    }

    /** Remove every row this fixture created, in dependency order. */
    public function teardown(): void
    {
        $db     = $this->db;
        $prefix = self::PREFIX . '%';

        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

        // Attendance history is protected by a BEFORE DELETE trigger, so test
        // rows have to be removed with the trigger temporarily out of the way.
        // This is the only place in the entire system that does this, and it
        // only ever touches TEST-CONC- prefixed data.
        $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_attendance_no_delete');

        $db->execute(
            'DELETE ar FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
               JOIN devices d ON d.id = s.device_row_id
              WHERE d.device_id LIKE :prefix',
            ['prefix' => $prefix]
        );

        $db->execute(
            'DELETE rl FROM rfid_logs rl
               JOIN devices d ON d.id = rl.device_row_id
              WHERE d.device_id LIKE :prefix',
            ['prefix' => $prefix]
        );

        $db->execute(
            'DELETE s FROM attendance_sessions s
               JOIN devices d ON d.id = s.device_row_id
              WHERE d.device_id LIKE :prefix',
            ['prefix' => $prefix]
        );

        $db->execute('DELETE FROM fingerprint_logs WHERE teacher_id IN
            (SELECT teacher_id FROM teachers WHERE employee_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM fingerprint_templates WHERE teacher_id IN
            (SELECT teacher_id FROM teachers WHERE employee_number LIKE :prefix)', ['prefix' => $prefix]);

        $db->execute('DELETE FROM student_subject_enrolments WHERE student_id IN
            (SELECT student_id FROM students WHERE student_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM student_section_history WHERE student_id IN
            (SELECT student_id FROM students WHERE student_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM rfid_cards WHERE student_id IN
            (SELECT student_id FROM students WHERE student_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM students WHERE student_number LIKE :prefix', ['prefix' => $prefix]);

        $db->execute('DELETE FROM device_nonces WHERE device_row_id IN
            (SELECT id FROM devices WHERE device_id LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM device_heartbeats WHERE device_row_id IN
            (SELECT id FROM devices WHERE device_id LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM device_logs WHERE device_id_text LIKE :prefix', ['prefix' => $prefix]);
        $db->execute('DELETE FROM api_key_history WHERE device_row_id IN
            (SELECT id FROM devices WHERE device_id LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM api_keys WHERE device_row_id IN
            (SELECT id FROM devices WHERE device_id LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM device_claims WHERE device_row_id IN
            (SELECT id FROM devices WHERE device_id LIKE :prefix)', ['prefix' => $prefix]);

        $db->execute('DELETE FROM schedules WHERE classroom_id IN
            (SELECT classroom_id FROM classrooms WHERE room_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM devices WHERE device_id LIKE :prefix', ['prefix' => $prefix]);
        $db->execute('DELETE FROM classrooms WHERE room_number LIKE :prefix', ['prefix' => $prefix]);

        $db->execute('DELETE FROM teacher_sections WHERE section_id IN
            (SELECT section_id FROM sections WHERE section_code LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM sections WHERE section_code LIKE :prefix', ['prefix' => $prefix]);

        $db->execute('DELETE FROM teacher_grade_levels WHERE teacher_id IN
            (SELECT teacher_id FROM teachers WHERE employee_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM teacher_departments WHERE teacher_id IN
            (SELECT teacher_id FROM teachers WHERE employee_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM teacher_subjects WHERE teacher_id IN
            (SELECT teacher_id FROM teachers WHERE employee_number LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM teachers WHERE employee_number LIKE :prefix', ['prefix' => $prefix]);

        // The sign-in group leaves a user behind, and every trace of that user
        // sits in a table the system deliberately refuses to delete from:
        // login history, security events and the audit trail are all guarded by
        // BEFORE DELETE triggers, exactly as they should be. The triggers come
        // down only for the length of this cleanup, and only rows belonging to
        // the TEST-CONC- account are touched — the same bargain the attendance
        // trigger above is already under.
        $lockUsers = $db->select(
            'SELECT user_id FROM users WHERE username LIKE :prefix',
            ['prefix' => $prefix]
        );

        if ($lockUsers !== []) {
            $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_login_history_no_delete');
            $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_security_no_delete');
            $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_audit_no_delete');

            foreach ($lockUsers as $row) {
                $userId = (int) $row['user_id'];

                $db->execute('DELETE FROM login_history WHERE user_id = :id', ['id' => $userId]);
                $db->execute('DELETE FROM security_logs WHERE user_id = :id', ['id' => $userId]);
                $db->execute('DELETE FROM audit_logs WHERE user_id = :id', ['id' => $userId]);
                $db->execute('DELETE FROM user_sessions WHERE user_id = :id', ['id' => $userId]);
                $db->execute('DELETE FROM users WHERE user_id = :id', ['id' => $userId]);
            }

            $db->pdo()->exec(
                "CREATE TRIGGER trg_login_history_no_delete
                 BEFORE DELETE ON login_history
                 FOR EACH ROW
                 BEGIN
                   SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'Login history is permanent and cannot be deleted.';
                 END"
            );

            $db->pdo()->exec(
                "CREATE TRIGGER trg_security_no_delete
                 BEFORE DELETE ON security_logs
                 FOR EACH ROW
                 BEGIN
                   SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'Security logs are immutable and cannot be deleted.';
                 END"
            );

            $db->pdo()->exec(
                "CREATE TRIGGER trg_audit_no_delete
                 BEFORE DELETE ON audit_logs
                 FOR EACH ROW
                 BEGIN
                   SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be deleted.';
                 END"
            );
        }

        $db->execute('DELETE FROM login_attempts WHERE identifier LIKE :prefix', ['prefix' => $prefix]);

        $db->execute('DELETE FROM subject_grade_levels WHERE subject_id IN
            (SELECT subject_id FROM subjects WHERE subject_code LIKE :prefix)', ['prefix' => $prefix]);
        $db->execute('DELETE FROM subjects WHERE subject_code LIKE :prefix', ['prefix' => $prefix]);
        $db->execute('DELETE FROM departments WHERE department_code LIKE :prefix', ['prefix' => $prefix]);

        // Restore the protection trigger.
        $db->pdo()->exec(
            "CREATE TRIGGER trg_attendance_no_delete
             BEFORE DELETE ON attendance_records
             FOR EACH ROW
             BEGIN
               SIGNAL SQLSTATE '45000'
                 SET MESSAGE_TEXT = 'Attendance records are permanent and cannot be deleted. Use an administrator correction instead.';
             END"
        );

        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// ===========================================================================

// Pin the clock to a fixed mid-morning instant for the whole suite.
//
// A schedule is a time of day, and the fixture has to place one that contains
// "now" while satisfying both schema CHECKs — end_time > start_time, and a
// window long enough for the time-in and time-out sub-windows to fit inside it.
// Run against the wall clock, that is satisfiable at 10am and impossible at
// 11pm, so the suite would pass all day and fail in the evening for reasons
// having nothing to do with the code under test.
//
// Nothing here needs real elapsed time: the tests that exercise the dwell rules
// backdate `time_in` in SQL rather than waiting. So freezing costs nothing and
// buys a suite whose result depends only on the code.
Clock::freeze(Clock::now()->setTime(10, 0, 0));

$runner  = new TestRunner();
$fixture = new Fixture();
$db      = Database::instance();

$only = $options['only'] ?? null;
$want = static fn (string $group): bool => $only === null || $only === $group;

fwrite(STDOUT, "\n\033[1m  L-SIAMS concurrency and correctness suite\033[0m\n");
fwrite(STDOUT, "\033[90m  Part 17.10 test plan + Part 20.4 acceptance criteria\033[0m\n");

try {
    /* =====================================================================
     * 1. Simultaneous sessions — each independent and correctly scoped
     * ===================================================================== */
    if ($want('sessions')) {
        $runner->group('1. Simultaneous sessions on separate devices');

        $fixture->build(4, 10, 4);
        $opened = [];

        foreach ([0, 1, 2, 3] as $index) {
            try {
                $result = AttendanceSessionService::open(
                    $fixture->device($index),
                    $fixture->teacher(),
                    $fixture->schedule($index),
                    0
                );

                $opened[] = $result['session_id'];
            } catch (Throwable $e) {
                $runner->info('device ' . $index . ': ' . $e->getMessage());
            }
        }

        $runner->assertEquals('4 devices open 4 independent sessions', 4, count($opened));

        $distinct = (int) $db->scalar(
            'SELECT COUNT(DISTINCT classroom_id) FROM attendance_sessions WHERE session_id IN ('
            . implode(',', array_map('intval', $opened ?: [0])) . ')'
        );

        $runner->assertEquals('each session is scoped to its own classroom', 4, $distinct);

        // Part 17.2: the unique index makes a second open impossible.
        $secondOpenRejected = false;

        try {
            AttendanceSessionService::open($fixture->device(0), $fixture->teacher(), $fixture->schedule(0), 0);
        } catch (BusinessRuleException $e) {
            $secondOpenRejected = $e->errorCode() === 'SESSION_ALREADY_OPEN';
        }

        $runner->assert('a second session on the same device is refused', $secondOpenRejected);
    }

    /* =====================================================================
     * 2. Duplicate race — 50 concurrent identical taps yield exactly one row
     * ===================================================================== */
    if ($want('race')) {
        $runner->group('2. Duplicate race: 50 concurrent taps of one card');

        $fixture->build(1, 5, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $sectionId = $fixture->ids['sections'][0];
        $card      = $fixture->ids['cards'][$sectionId][0];

        $accepted  = 0;
        $duplicate = 0;
        $other     = 0;

        // Single-process, but each call takes the same row locks and hits the
        // same unique constraint, so it exercises exactly the code path a real
        // race would. The forked variant lives in race_parallel.php.
        for ($i = 0; $i < 50; $i++) {
            try {
                AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
                $accepted++;
            } catch (BusinessRuleException $e) {
                in_array($e->errorCode(), ['DUPLICATE_TIME_IN', 'ALREADY_COMPLETE'], true)
                    ? $duplicate++
                    : $other++;
            }
        }

        $rows = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE session_id = :id',
            ['id' => $session['session_id']]
        );

        $runner->assertEquals('exactly one attendance row exists', 1, $rows);
        $runner->assertEquals('exactly one tap was accepted', 1, $accepted);
        $runner->assert('the remaining 49 were rejected as duplicates', $duplicate >= 48,
            sprintf('accepted=%d duplicate=%d other=%d', $accepted, $duplicate, $other));

        $rejectedLogged = (int) $db->scalar(
            "SELECT COUNT(*) FROM rfid_logs WHERE session_id = :id AND result <> 'accepted'",
            ['id' => $session['session_id']]
        );

        $runner->assert('every rejected tap was logged', $rejectedLogged >= 40,
            'logged ' . $rejectedLogged);
    }

    /* =====================================================================
     * 3. Cross-device race — same card on two terminals
     * ===================================================================== */
    if ($want('cross-device')) {
        $runner->group('3. Cross-device race: one card, two terminals');

        $fixture->build(1, 5, 2);

        // Both devices serve the same section so the section check passes and
        // the duplicate guard is what actually decides the outcome.
        $db->update('schedules',
            ['section_id' => $fixture->ids['sections'][0]],
            ['schedule_id' => $fixture->ids['schedules'][1]]
        );

        $sessionA = AttendanceSessionService::open($fixture->device(0), $fixture->teacher(), $fixture->schedule(0), 0);
        $sessionB = AttendanceSessionService::open($fixture->device(1), $fixture->teacher(), $fixture->schedule(1), 0);

        $card = $fixture->ids['cards'][$fixture->ids['sections'][0]][0];

        $outcomes = [];

        foreach ([[0, $sessionA], [1, $sessionB]] as [$index, $session]) {
            try {
                AttendanceService::tap($fixture->device($index), $card, null, AttendanceService::INTENT_TIME_IN);
                $outcomes[] = 'accepted';
            } catch (BusinessRuleException $e) {
                $outcomes[] = $e->errorCode();
            }
        }

        $acceptedCount = count(array_filter($outcomes, static fn (string $o): bool => $o === 'accepted'));

        $runner->assert('the first terminal accepts the tap', $acceptedCount >= 1);
        $runner->assert('the second is refused, not double-recorded',
            $acceptedCount <= 2 && count($outcomes) === 2,
            'outcomes: ' . implode(', ', $outcomes));

        // Sessions are separate rows, so one record each is correct here; what
        // must never happen is two rows in the *same* session.
        foreach ([$sessionA, $sessionB] as $session) {
            $rows = (int) $db->scalar(
                'SELECT COUNT(*) FROM attendance_records WHERE session_id = :id AND student_id = :student',
                [
                    'id'      => $session['session_id'],
                    'student' => $fixture->ids['students'][$fixture->ids['sections'][0]][0],
                ]
            );

            $runner->assert('at most one row per student per session', $rows <= 1, 'rows=' . $rows);
        }
    }

    /* =====================================================================
     * 4. Section-aware enforcement (Part 15)
     * ===================================================================== */
    if ($want('section')) {
        $runner->group('4. Section mismatch is rejected, not recorded elsewhere');

        $fixture->build(2, 10, 1);

        $sectionA = $fixture->ids['sections'][0];
        $sectionB = $fixture->ids['sections'][1];

        $db->update('schedules', ['section_id' => $sectionA], ['schedule_id' => $fixture->ids['schedules'][0]]);

        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $ownCard      = $fixture->ids['cards'][$sectionA][0];
        $foreignCard  = $fixture->ids['cards'][$sectionB][0];

        AttendanceService::tap($device, $ownCard, null, AttendanceService::INTENT_TIME_IN);

        $code = null;

        try {
            AttendanceService::tap($device, $foreignCard, null, AttendanceService::INTENT_TIME_IN);
        } catch (BusinessRuleException $e) {
            $code = $e->errorCode();
        }

        $runner->assertEquals('a foreign-section card is rejected with SECTION_MISMATCH', 'SECTION_MISMATCH', $code);

        $foreignStudentId = $fixture->ids['students'][$sectionB][0];

        $stray = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE student_id = :student',
            ['student' => $foreignStudentId]
        );

        $runner->assertEquals('no attendance record was created for that student', 0, $stray);

        $logged = (int) $db->scalar(
            "SELECT COUNT(*) FROM rfid_logs
              WHERE student_id = :student AND result = 'section_mismatch'",
            ['student' => $foreignStudentId]
        );

        $runner->assert('the rejection was logged with both sections', $logged >= 1);

        $bothSections = $db->selectOne(
            "SELECT student_section_id, session_section_id FROM rfid_logs
              WHERE student_id = :student AND result = 'section_mismatch' ORDER BY log_id DESC LIMIT 1",
            ['student' => $foreignStudentId]
        );

        $runner->assert('the log records the student and session sections separately',
            (int) ($bothSections['student_section_id'] ?? 0) === $sectionB
            && (int) ($bothSections['session_section_id'] ?? 0) === $sectionA);

        $rejectedCount = (int) $db->scalar(
            'SELECT rejected_tap_count FROM attendance_sessions WHERE session_id = :id',
            ['id' => $session['session_id']]
        );

        $runner->assert('the session rejected-tap counter incremented', $rejectedCount >= 1);
    }

    /* =====================================================================
     * 5. Time in / time out (Part 16)
     * ===================================================================== */
    if ($want('timeinout')) {
        $runner->group('5. Time-in / time-out state machine');

        $fixture->build(1, 5, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $sectionId = $fixture->ids['sections'][0];
        $card      = $fixture->ids['cards'][$sectionId][0];
        $studentId = $fixture->ids['students'][$sectionId][0];

        $first = AttendanceService::tap($device, $card);
        $runner->assertEquals('the first tap is resolved as TIME IN', 'time_in', $first['intent']);

        // Minimum dwell is 1 minute in the fixture; a tap-out now must fail.
        $dwellRejected = null;

        try {
            AttendanceService::tap($device, $card);
        } catch (BusinessRuleException $e) {
            $dwellRejected = $e->errorCode();
        }

        $runner->assertEquals('tapping out before the minimum stay is refused',
            'MINIMUM_DWELL_NOT_MET', $dwellRejected);

        // Move the recorded time-in back so the dwell requirement is satisfied.
        $db->execute(
            'UPDATE attendance_records SET time_in = DATE_SUB(time_in, INTERVAL 40 MINUTE)
              WHERE session_id = :s AND student_id = :st',
            ['s' => $session['session_id'], 'st' => $studentId]
        );

        $second = AttendanceService::tap($device, $card);
        $runner->assertEquals('the second tap is resolved as TIME OUT', 'time_out', $second['intent']);
        $runner->assert('a duration was computed', ($second['duration_minutes'] ?? 0) > 0,
            'duration=' . ($second['duration_minutes'] ?? 'null'));

        $thirdCode = null;

        try {
            AttendanceService::tap($device, $card);
        } catch (BusinessRuleException $e) {
            $thirdCode = $e->errorCode();
        }

        $runner->assertEquals('the third tap is refused as ALREADY_COMPLETE', 'ALREADY_COMPLETE', $thirdCode);

        $rows = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE session_id = :s AND student_id = :st',
            ['s' => $session['session_id'], 'st' => $studentId]
        );

        $runner->assertEquals('all of that produced exactly one row', 1, $rows);
    }

    /* =====================================================================
     * 6. Status resolver — all seven final statuses
     * ===================================================================== */
    if ($want('status')) {
        $runner->group('6. Status resolver produces all seven final values');

        $cases = [
            ['present', 'timed_out',   'Present'],
            ['late',    'timed_out',   'Late'],
            ['present', 'left_early',  'Left Early'],
            ['late',    'left_early',  'Left Early'],
            ['present', 'no_time_out', 'Incomplete'],
            ['late',    'no_time_out', 'Incomplete'],
            ['present', 'auto_closed', 'Present'],
            ['late',    'auto_closed', 'Late'],
            ['absent',  'pending',     'Absent'],
            ['excused', 'pending',     'Excused'],
            ['official_business', 'pending', 'Official Business'],
        ];

        $allCorrect = true;

        foreach ($cases as [$arrival, $departure, $expected]) {
            $actual = AttendanceStatusResolver::resolve($arrival, $departure);

            if ($actual !== $expected) {
                $allCorrect = false;
                $runner->info(sprintf('%s + %s → %s (expected %s)', $arrival, $departure, $actual, $expected));
            }
        }

        $runner->assert('every arrival/departure combination resolves correctly', $allCorrect);

        $produced = array_unique(array_map(
            static fn (array $c): string => AttendanceStatusResolver::resolve($c[0], $c[1]),
            $cases
        ));

        $runner->assert('at least 7 distinct final statuses are reachable',
            count($produced) >= 7, 'produced: ' . implode(', ', $produced));

        $runner->assertEquals('auto-closed keeps the arrival status but is labelled',
            'Present (Auto-Closed)',
            AttendanceStatusResolver::label('Present', 'auto_closed', true));
    }

    /* =====================================================================
     * 7. Session close — auto time-out, absences, rollups, one transaction
     * ===================================================================== */
    if ($want('close')) {
        $runner->group('7. Session close: auto time-out, absences and rollups');

        $fixture->build(1, 20, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $sectionId = $fixture->ids['sections'][0];
        $cards     = $fixture->ids['cards'][$sectionId];

        // 12 of 20 tap in; 4 of those also tap out.
        foreach (array_slice($cards, 0, 12) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
        }

        $db->execute(
            'UPDATE attendance_records SET time_in = DATE_SUB(time_in, INTERVAL 40 MINUTE)
              WHERE session_id = :s',
            ['s' => $session['session_id']]
        );

        foreach (array_slice($cards, 0, 4) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_OUT);
        }

        $summary = AttendanceSessionService::close((int) $session['session_id'], 'teacher', null);

        $runner->assertEquals('the roster is fully accounted for', 20, $summary['roster_count']);
        $runner->assertEquals('8 students who never tapped are marked Absent', 8, $summary['absent_count']);

        $autoStamped = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :s AND auto_generated_time_out = 1',
            ['s' => $session['session_id']]
        );

        $runner->assertEquals('the 8 who never tapped out were auto-stamped', 8, $autoStamped);

        $pending = (int) $db->scalar(
            "SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :s AND departure_status = 'pending' AND time_in IS NOT NULL",
            ['s' => $session['session_id']]
        );

        $runner->assertEquals('no attending record is left pending', 0, $pending);

        $status = (string) $db->scalar(
            'SELECT status FROM attendance_sessions WHERE session_id = :s',
            ['s' => $session['session_id']]
        );

        $runner->assertEquals('the session is closed', 'closed', $status);

        $closeAgain = null;

        try {
            AttendanceSessionService::close((int) $session['session_id'], 'teacher', null);
        } catch (BusinessRuleException $e) {
            $closeAgain = $e->errorCode();
        }

        $runner->assertEquals('closing twice is refused', 'SESSION_ALREADY_CLOSED', $closeAgain);

        // An auto-stamped time-out is bounded at both ends, because a student
        // may never be credited with classroom minutes they were not there for.
        //
        // Closing EARLY — the teacher ends the class before the bell — must
        // stamp the moment the session actually ended. The fixture's period
        // runs well past the frozen clock, so the close above was an early one.
        $stampedAfterClose = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE ar.session_id = :s AND ar.auto_generated_time_out = 1
                AND ar.time_out > s.closed_at',
            ['s' => $session['session_id']]
        );

        $runner->assertEquals('an early close credits nobody past the moment it closed',
            0, $stampedAfterClose);

        $stampedAtClose = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE ar.session_id = :s AND ar.auto_generated_time_out = 1
                AND ar.time_out = s.closed_at',
            ['s' => $session['session_id']]
        );

        $runner->assertEquals('every auto time-out matches the session end exactly',
            8, $stampedAtClose);

        // Closing LATE — the sweeper gets to it after the bell — must stamp the
        // bell instead. The opposite bound, and the one that was already right.
        $fixture->build(1, 5, 1);
        $device = $fixture->device(0);
        $late   = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        AttendanceService::tap($device, $fixture->ids['cards'][$fixture->ids['sections'][0]][0],
            null, AttendanceService::INTENT_TIME_IN);

        $scheduledEnd = (string) $db->scalar(
            'SELECT scheduled_end FROM attendance_sessions WHERE session_id = :s',
            ['s' => $late['session_id']]
        );

        // Thirty minutes after the bell, which is where the sweeper finds it.
        Clock::freeze(Clock::parse($scheduledEnd)->modify('+30 minutes'));
        AttendanceSessionService::close((int) $late['session_id'], 'system', null);
        Clock::freeze(Clock::now()->setTime(10, 0, 0));

        $stampedPastBell = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records ar
               JOIN attendance_sessions s ON s.session_id = ar.session_id
              WHERE ar.session_id = :s AND ar.auto_generated_time_out = 1
                AND ar.time_out > s.scheduled_end',
            ['s' => $late['session_id']]
        );

        $runner->assertEquals('a late close still credits nobody past the bell',
            0, $stampedPastBell);
    }

    /* =====================================================================
     * 8. Idempotency (Part 17.3)
     * ===================================================================== */
    if ($want('idempotency')) {
        $runner->group('8. Idempotent retries land exactly once');

        $fixture->build(1, 5, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $card      = $fixture->ids['cards'][$fixture->ids['sections'][0]][0];
        $requestId = sprintf('%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
            '4' . substr(bin2hex(random_bytes(2)), 1),
            dechex(8 + random_int(0, 3)) . substr(bin2hex(random_bytes(2)), 1),
            bin2hex(random_bytes(6)));

        AttendanceService::tap($device, $card, $requestId, AttendanceService::INTENT_TIME_IN);

        $retried = null;

        try {
            AttendanceService::tap($device, $card, $requestId, AttendanceService::INTENT_TIME_IN);
        } catch (BusinessRuleException $e) {
            $retried = $e->errorCode();
        }

        $runner->assert('a retried request_id does not create a second row',
            in_array($retried, ['DUPLICATE_TIME_IN', 'DUPLICATE_REQUEST', 'ALREADY_COMPLETE'], true),
            'code=' . var_export($retried, true));

        $rows = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE request_id = :rid',
            ['rid' => $requestId]
        );

        $runner->assertEquals('exactly one row carries that request id', 1, $rows);
    }

    /* =====================================================================
     * 9. Offline reconciliation
     * ===================================================================== */
    if ($want('offline')) {
        $runner->group('9. Offline queue reconciles exactly once');

        $fixture->build(1, 30, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $cards   = $fixture->ids['cards'][$fixture->ids['sections'][0]];
        $records = [];

        foreach (array_slice($cards, 0, 25) as $index => $card) {
            $records[] = [
                'uid'        => $card,
                'timestamp'  => Clock::now()->modify('-' . (25 - $index) . ' minutes')->format('Y-m-d H:i:s'),
                'request_id' => sprintf('%s-%s-4%s-a%s-%s',
                    bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
                    substr(bin2hex(random_bytes(2)), 1),
                    substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6))),
            ];
        }

        $first = \App\Services\DeviceService::syncQueue($device, $records);

        $runner->assertEquals('all 25 queued records were accepted', 25, $first['accepted']);

        // The whole batch is replayed, exactly as a device would after a
        // network timeout mid-upload.
        $second = \App\Services\DeviceService::syncQueue($device, $records);

        $runner->assertEquals('replaying the batch accepts nothing new', 0, $second['accepted']);
        $runner->assertEquals('the replay is reported as duplicates', 25, $second['duplicate']);

        $rows = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE session_id = :s',
            ['s' => $session['session_id']]
        );

        $runner->assertEquals('still exactly 25 rows after the replay', 25, $rows);

        // Compare against the suite's own clock, not the server's NOW().
        //
        // The queued timestamps were built from the frozen clock, so measuring
        // them against SQL NOW() compares two different notions of "now" — and
        // the assertion then passes or fails purely on what time of day the
        // suite happens to run, which is exactly what freezing the clock was
        // meant to eliminate.
        $cutoff = Clock::now()->modify('-5 minutes')->format('Y-m-d H:i:s');

        $preserved = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :s AND time_in < :cutoff',
            ['s' => $session['session_id'], 'cutoff' => $cutoff]
        );

        $runner->assert('original timestamps were preserved, not rewritten to now',
            $preserved >= 20, 'preserved=' . $preserved);
    }

    /* =====================================================================
     * 10. Throughput
     * ===================================================================== */
    if ($want('throughput')) {
        $runner->group('10. Throughput and latency');

        $fixture->build(1, 60, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $cards    = $fixture->ids['cards'][$fixture->ids['sections'][0]];
        $latencies = [];

        foreach (array_slice($cards, 0, 50) as $card) {
            $started = microtime(true);

            try {
                AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
            } catch (BusinessRuleException) {
                // Not a throughput concern.
            }

            $latencies[] = microtime(true) - $started;
        }

        sort($latencies);

        $p50 = $latencies[(int) floor(count($latencies) * 0.50)];
        $p95 = $latencies[(int) floor(count($latencies) * 0.95)];
        $max = end($latencies);

        $runner->metric('p50 latency', sprintf('%.1f ms', $p50 * 1000));
        $runner->metric('p95 latency', sprintf('%.1f ms', $p95 * 1000));
        $runner->metric('max latency', sprintf('%.1f ms', $max * 1000));

        $runner->time('tap p95', $p95);

        // Part 17.1 sets 800 ms p95 for the full round trip; the service layer
        // alone should be a small fraction of that.
        $runner->assert('p95 tap processing is under the 800 ms budget', $p95 < 0.8,
            sprintf('%.1f ms', $p95 * 1000));

        $throughput = count($latencies) / array_sum($latencies);
        $runner->metric('sustained rate', sprintf('%.0f taps/second', $throughput));
        $runner->assert('sustained rate exceeds 100 taps/minute', $throughput * 60 > 100,
            sprintf('%.0f taps/minute', $throughput * 60));
    }

    /* =====================================================================
     * 11. Realtime fan-out and replay
     * ===================================================================== */
    if ($want('realtime')) {
        $runner->group('11. Realtime sequencing and replay');

        $channel = 'test.channel.' . bin2hex(random_bytes(4));

        $sequences = [];

        for ($i = 0; $i < 20; $i++) {
            $sequences[] = \App\Services\RealtimeService::publish($channel, 'test.event', ['index' => $i]);
        }

        $runner->assertEquals('sequences are strictly monotonic', range(1, 20), $sequences);

        $replayed = \App\Services\RealtimeService::replay($channel, 10);

        $runner->assertEquals('replay from sequence 10 returns the last 10 events', 10, count($replayed));

        $firstReplayed = $replayed[0]['sequence'] ?? 0;
        $runner->assertEquals('replay starts at the next sequence', 11, $firstReplayed);

        $runner->assert('no event is duplicated in a replay',
            count(array_unique(array_column($replayed, 'sequence'))) === count($replayed));

        $db->execute('DELETE FROM realtime_events WHERE channel = :c', ['c' => $channel]);
        $db->execute('DELETE FROM realtime_channel_cursors WHERE channel = :c', ['c' => $channel]);
    }

    /* =====================================================================
     * 12. Only a lesson that is actually openable may be opened
     *
     * A period is "active" from its tap-in window opening to its tap-out
     * window closing, which for back-to-back lessons in one room means two of
     * them are active together for the twenty-odd minutes where one is ending
     * and the next is starting. One open session per classroom is enforced, so
     * treating "active" as permission to OPEN let whichever teacher scanned
     * first take the room — including the one whose lesson had already ended,
     * locking out the teacher whose lesson was actually running.
     * ===================================================================== */
    if ($want('openable')) {
        $runner->group('12. Only the teacher whose lesson is running may open it');

        $fixture->build(2, 5, 2);

        $device      = $fixture->device(0);
        $classroomId = (int) $device['classroom_id'];
        $deviceRowId = (int) $device['id'];
        $mine        = (int) $fixture->schedule(0)['schedule_id'];
        $previous    = (int) $fixture->schedule(1)['schedule_id'];

        // The fixture's windows are deliberately wide so the other groups never
        // trip over a clock boundary. This group is about the boundaries, so it
        // uses the school's real defaults: in from ten minutes before the bell,
        // out until fifteen minutes after.
        $move = static function (int $scheduleId, string $startOffset, string $endOffset) use ($db, $classroomId): void {
            $db->execute(
                'UPDATE schedules
                    SET classroom_id = :c, day_of_week = :d, start_time = :s, end_time = :e,
                        time_in_window_open  = 10, time_in_window_close  = 30,
                        time_out_window_open = 10, time_out_window_close = 15
                  WHERE schedule_id = :id',
                [
                    'c'  => $classroomId,
                    'd'  => Clock::now()->format('l'),
                    's'  => Clock::now()->modify($startOffset)->format('H:i:s'),
                    'e'  => Clock::now()->modify($endOffset)->format('H:i:s'),
                    'id' => $scheduleId,
                ]
            );
        };

        $openableIds = static function (int $deviceRowId): array {
            return array_map(
                static fn (array $row): int => (int) $row['schedule_id'],
                \App\Services\ScheduleService::openableForDevice($deviceRowId)
            );
        };

        // The previous period ended ten minutes ago; mine is in progress. Both
        // are inside their windows, so activeForDevice() sees two.
        $move($previous, '-70 minutes', '-10 minutes');
        $move($mine,     '-10 minutes', '+50 minutes');

        $runner->assertEquals('both lessons are still "active" for the terminal', 2,
            count(\App\Services\ScheduleService::activeForDevice($deviceRowId)));

        $runner->assertEquals('only the lesson in progress may be opened', [$mine],
            $openableIds($deviceRowId));

        // Arriving early, with the room free, must still work.
        $move($previous, '-80 minutes', '-20 minutes');
        $move($mine,     '+10 minutes', '+70 minutes');

        $runner->assertEquals('a teacher arriving inside their tap-in window may open', [$mine],
            $openableIds($deviceRowId));

        // Arriving early while the room is still being taught in must not.
        $move($previous, '-10 minutes', '+50 minutes');

        $runner->assertEquals('the next teacher cannot take a room from a lesson in progress',
            [$previous], $openableIds($deviceRowId));

        // Before the tap-in window opens at all, nothing is offered.
        $move($previous, '-80 minutes', '-20 minutes');
        $move($mine,     '+45 minutes', '+105 minutes');

        $runner->assertEquals('nothing is openable before the tap-in window opens', [],
            $openableIds($deviceRowId));

        // And a finished lesson is never openable, however wide its tap-out
        // window still is.
        $move($mine, '-70 minutes', '-10 minutes');

        $runner->assert('a finished lesson is still active for tap-out',
            \App\Services\ScheduleService::activeForDevice($deviceRowId) !== []);

        $runner->assertEquals('but a finished lesson can never be opened', [],
            $openableIds($deviceRowId));
    }

    /* =====================================================================
     * 13. Attendance carries to the next subject
     *
     * The section stays in the room across periods, so presence does too:
     * whoever was Present or Late when the bell went starts the next subject
     * Present without tapping. Whoever was NOT in the room — absent, left
     * early — carries nothing and gets a clean chance to tap in, which is the
     * half of the rule that stops one missed period following a student
     * through the whole day.
     * ===================================================================== */
    if ($want('carry')) {
        $runner->group('13. Attendance carries to the next subject');

        // Two devices so the fixture builds two schedules; both are then moved
        // into the first device's room, because a handover is two periods in
        // ONE room with one terminal.
        $fixture->build(1, 6, 2);

        $device      = $fixture->device(0);
        $classroomId = (int) $device['classroom_id'];
        $sectionId   = $fixture->ids['sections'][0];
        $cards       = $fixture->ids['cards'][$sectionId];

        // Back to back for the same section: 09:00–09:55 then 10:00–10:55,
        // against the suite's frozen 10:00 clock.
        $period = static function (Database $db, array $schedule, string $start, string $end,
                                   int $sectionId, int $classroomId): void {
            $db->update('schedules', [
                'section_id'            => $sectionId,
                'classroom_id'          => $classroomId,
                'start_time'            => $start,
                'end_time'              => $end,
                'time_in_window_open'   => 10,
                'time_in_window_close'  => 30,
                'time_out_window_open'  => 10,
                'time_out_window_close' => 15,
                'minimum_dwell_minutes' => 1,
            ], ['schedule_id' => (int) $schedule['schedule_id']]);
        };

        $period($db, $fixture->schedule(0), '09:00:00', '09:55:00', $sectionId, $classroomId);
        $period($db, $fixture->schedule(1), '10:00:00', '10:55:00', $sectionId, $classroomId);

        // --- first period ---------------------------------------------------
        Clock::freeze(Clock::now()->setTime(8, 55, 0));
        $one = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        Clock::freeze(Clock::now()->setTime(9, 2, 0));
        foreach (array_slice($cards, 0, 4) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
        }

        // One of the four leaves before the bell; two more never turn up.
        Clock::freeze(Clock::now()->setTime(9, 30, 0));
        AttendanceService::tap($device, $cards[3], null, AttendanceService::INTENT_TIME_OUT);

        // --- second period, opened while the first still holds the room -----
        Clock::freeze(Clock::now()->setTime(9, 58, 0));
        $two = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(1), 0);

        $firstStatus = (string) $db->scalar(
            'SELECT status FROM attendance_sessions WHERE session_id = :id',
            ['id' => (int) $one['session_id']]
        );

        $runner->assertEquals('the previous period was handed over, not refused', 'closed', $firstStatus);

        $stampedLate = (int) $db->scalar(
            "SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :id AND time_out > :end",
            ['id' => (int) $one['session_id'], 'end' => Clock::now()->setTime(9, 55, 0)->format('Y-m-d H:i:s')]
        );

        $runner->assertEquals('nobody was credited past the bell of the period that ended', 0, $stampedLate);

        $runner->assertEquals('the three students still in the room carried forward', 3, $two['carried_in']);

        $carriedRows = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :id AND carried_from_session_id = :from',
            ['id' => (int) $two['session_id'], 'from' => (int) $one['session_id']]
        );

        $runner->assertEquals('each carried row names the period it came from', 3, $carriedRows);

        $fabricated = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :id AND carried_from_session_id IS NOT NULL
                AND (time_in_device_id IS NOT NULL OR time_in_ip IS NOT NULL OR time_in_mac IS NOT NULL)',
            ['id' => (int) $two['session_id']]
        );

        $runner->assertEquals('no carried row claims a tap at a terminal', 0, $fabricated);

        $countedIn = (int) $db->scalar(
            'SELECT carried_in_count FROM attendance_sessions WHERE session_id = :id',
            ['id' => (int) $two['session_id']]
        );

        $runner->assertEquals('the session records how much of its register was carried', 3, $countedIn);

        // The student who left early must have no record at all in the new
        // period — that is what lets them tap in for it.
        $leftEarlyStudent = $fixture->ids['students'][$sectionId][3];

        $rowsForLeaver = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE session_id = :id AND student_id = :student',
            ['id' => (int) $two['session_id'], 'student' => $leftEarlyStudent]
        );

        $runner->assertEquals('the student who left early carried nothing', 0, $rowsForLeaver);

        Clock::freeze(Clock::now()->setTime(10, 3, 0));
        $rejoined = AttendanceService::tap($device, $cards[3], null, AttendanceService::INTENT_TIME_IN);

        $runner->assertEquals('and may tap in for the new subject', 'Present', $rejoined['status']);

        // Nor may anyone be carried twice: reopening must not duplicate a row.
        $duplicates = (int) $db->scalar(
            'SELECT COUNT(*) FROM (
                SELECT student_id FROM attendance_records WHERE session_id = :id
                 GROUP BY student_id HAVING COUNT(*) > 1) dupes',
            ['id' => (int) $two['session_id']]
        );

        $runner->assertEquals('no student appears twice in the new register', 0, $duplicates);

        // A gap too long to be a handover carries nothing: the students went
        // somewhere in between, and what they did there is not evidence.
        $fixture->build(1, 6, 2);

        $device      = $fixture->device(0);
        $classroomId = (int) $device['classroom_id'];
        $sectionId   = $fixture->ids['sections'][0];
        $cards       = $fixture->ids['cards'][$sectionId];

        $period($db, $fixture->schedule(0), '07:00:00', '07:55:00', $sectionId, $classroomId);
        $period($db, $fixture->schedule(1), '10:00:00', '10:55:00', $sectionId, $classroomId);

        Clock::freeze(Clock::now()->setTime(7, 0, 0));
        AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        Clock::freeze(Clock::now()->setTime(7, 5, 0));
        AttendanceService::tap($device, $cards[0], null, AttendanceService::INTENT_TIME_IN);

        Clock::freeze(Clock::now()->setTime(9, 58, 0));
        $later = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(1), 0);

        $runner->assertEquals('a three-hour gap is not a handover and carries nothing',
            0, $later['carried_in']);

        // Nothing carries across noon, however small the gap.
        //
        // This is the case the gap limit cannot catch and the reason the
        // barrier exists: 11:50 to 12:10 is twenty minutes, well inside the
        // limit, so without a fixed boundary the morning register would carry
        // through lunch and mark present every student who had gone home for
        // the afternoon.
        $fixture->build(1, 6, 2);

        $device      = $fixture->device(0);
        $classroomId = (int) $device['classroom_id'];
        $sectionId   = $fixture->ids['sections'][0];
        $cards       = $fixture->ids['cards'][$sectionId];

        $period($db, $fixture->schedule(0), '11:00:00', '11:50:00', $sectionId, $classroomId);
        $period($db, $fixture->schedule(1), '12:10:00', '13:00:00', $sectionId, $classroomId);

        Clock::freeze(Clock::now()->setTime(11, 0, 0));
        AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        Clock::freeze(Clock::now()->setTime(11, 5, 0));
        foreach (array_slice($cards, 0, 3) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
        }

        Clock::freeze(Clock::now()->setTime(12, 8, 0));
        $afternoon = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(1), 0);

        $runner->assertEquals('the afternoon starts from zero even after a twenty-minute break',
            0, $afternoon['carried_in']);

        $afternoonRows = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE session_id = :id',
            ['id' => (int) $afternoon['session_id']]
        );

        $runner->assertEquals('and its register is genuinely empty', 0, $afternoonRows);

        // The barrier must not swallow ordinary morning handovers, which is
        // the regression that would make it worse than the bug it fixes.
        $fixture->build(1, 6, 2);

        $device      = $fixture->device(0);
        $classroomId = (int) $device['classroom_id'];
        $sectionId   = $fixture->ids['sections'][0];
        $cards       = $fixture->ids['cards'][$sectionId];

        $period($db, $fixture->schedule(0), '10:00:00', '10:50:00', $sectionId, $classroomId);
        $period($db, $fixture->schedule(1), '11:00:00', '11:50:00', $sectionId, $classroomId);

        Clock::freeze(Clock::now()->setTime(10, 0, 0));
        AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        Clock::freeze(Clock::now()->setTime(10, 5, 0));
        foreach (array_slice($cards, 0, 3) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
        }

        Clock::freeze(Clock::now()->setTime(10, 58, 0));
        $stillMorning = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(1), 0);

        $runner->assertEquals('a morning handover still carries as it always did',
            3, $stillMorning['carried_in']);

        $runner->assertEquals('and a successful handover explains nothing, because nothing needs it',
            null, $stillMorning['carry_note']);

        // Four different conditions each open a register empty, and from the
        // outside they look identical. Reported as "carry-over does not work"
        // when the cause was a timetable the software could see and the person
        // could not — so each one has to name itself.
        $runner->assert('the afternoon reset says it was the reset',
            is_string($afternoon['carry_note'] ?? null)
                && str_contains((string) $afternoon['carry_note'], 'reset'),
            'the noon reset gave no reason: ' . var_export($afternoon['carry_note'] ?? null, true));

        $runner->assert('a long gap says how long it was, and what the limit is',
            is_string($later["carry_note"] ?? null)
                && str_contains((string) $later["carry_note"], 'minutes'),
            'the gap case gave no reason: ' . var_export($later["carry_note"] ?? null, true));

        // The first class of the day has nothing before it. Distinct from a
        // refusal, and the commonest reason of all for an empty register.
        Clock::freeze(Clock::now()->setTime(10, 0, 0));

        // Move today's sessions out of the way and leave none running, so the
        // next open is genuinely the first of its day for this section.
        $db->execute(
            "UPDATE attendance_sessions
                SET session_date = DATE_SUB(session_date, INTERVAL 1 DAY),
                    status = 'closed'
              WHERE section_id = :section",
            ['section' => (int) $fixture->ids['sections'][0]]
        );

        $firstOfDay = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $runner->assert('the first class of the day says so rather than looking broken',
            is_string($firstOfDay['carry_note'] ?? null)
                && str_contains((string) $firstOfDay['carry_note'], 'first class'),
            'the first-class case gave no reason: ' . var_export($firstOfDay['carry_note'] ?? null, true));

        // The "previous period still open" branch is deliberately not asserted
        // here. Opening the next period hands the room over and closes the one
        // before it, so the state is unreachable through the normal path and
        // any test for it would be describing a scenario the system prevents.
        // The branch stays because a session open in ANOTHER room for the same
        // section can still produce it, and an unexplained empty register is
        // the thing this whole group exists to stop.

        Clock::freeze(Clock::now()->setTime(10, 0, 0));
    }

    /* =====================================================================
     * 14. A teacher may release a student from the room
     *
     * The reader deliberately refuses a tap-out before the minimum dwell, and
     * that rule is right against a student tapping in and walking out. It is
     * wrong for a child who has been unwell for ten minutes, and there was no
     * other way to record that they had gone. The teacher records it instead,
     * with a reason, and the record becomes a real departure at the real time.
     * ===================================================================== */
    if ($want('release')) {
        $runner->group('14. A teacher may release a student from the room');

        $fixture->build(1, 6, 2);

        $device    = $fixture->device(0);
        $sectionId = $fixture->ids['sections'][0];
        $cards     = $fixture->ids['cards'][$sectionId];
        $students  = $fixture->ids['students'][$sectionId];
        $userId    = (int) $db->scalar('SELECT user_id FROM users ORDER BY user_id LIMIT 1');

        $db->update('schedules', ['minimum_dwell_minutes' => 20],
            ['schedule_id' => (int) $fixture->schedule(0)['schedule_id']]);

        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        foreach (array_slice($cards, 0, 3) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
        }

        $attendanceOf = static function (int $studentId) use ($db, $session): int {
            return (int) $db->scalar(
                'SELECT attendance_id FROM attendance_records WHERE session_id = :s AND student_id = :st',
                ['s' => (int) $session['session_id'], 'st' => $studentId]
            );
        };

        // The reader refuses this: the dwell minimum has not elapsed. That is
        // the gap the release exists to fill, so assert it rather than assume it.
        $reader = null;

        try {
            AttendanceService::tap($device, $cards[0], null, AttendanceService::INTENT_TIME_OUT);
        } catch (BusinessRuleException $e) {
            $reader = $e->errorCode();
        }

        $runner->assertEquals('the reader refuses a tap-out before the minimum dwell',
            'MINIMUM_DWELL_NOT_MET', $reader);

        $released = AttendanceService::releaseEarly(
            (int) $session['session_id'], $attendanceOf($students[0]), 'sickness', 'Sent to the clinic', $userId);

        $runner->assertEquals('the teacher may release the same student anyway',
            'Left Early', $released['final_status']);

        $row = $db->selectOne('SELECT * FROM attendance_records WHERE attendance_id = :id',
            ['id' => $attendanceOf($students[0])]);

        $runner->assertEquals('the reason is stored', 'sickness', (string) $row['early_release_reason']);
        $runner->assertEquals('so is the note', 'Sent to the clinic', (string) $row['early_release_note']);
        $runner->assertEquals('and the account that authorised it', $userId, (int) $row['early_released_by']);

        // auto_generated_time_out means "the system stamped a departure nobody
        // witnessed". A person witnessed this one.
        $runner->assertEquals('a release is not flagged as an automatic time-out',
            0, (int) $row['auto_generated_time_out']);

        $runner->assert('the departure is stamped, not left pending',
            $row['time_out'] !== null && (string) $row['departure_status'] === 'left_early');

        // Guards.
        $codes = [];

        foreach ([
            'twice'       => [$students[0], 'sickness', null],
            'bad reason'  => [$students[1], 'bored', null],
            'other, bare' => [$students[1], 'other', '   '],
        ] as $label => [$studentId, $reason, $note]) {
            try {
                AttendanceService::releaseEarly(
                    (int) $session['session_id'], $attendanceOf($studentId), $reason, $note, $userId);
                $codes[$label] = 'ALLOWED';
            } catch (BusinessRuleException $e) {
                $codes[$label] = $e->errorCode();
            }
        }

        $runner->assertEquals('releasing an already-departed student is refused',
            'ALREADY_TIMED_OUT', $codes['twice']);
        $runner->assertEquals('a reason outside the list is refused',
            'INVALID_RELEASE_REASON', $codes['bad reason']);
        $runner->assertEquals("'other' with no note is refused",
            'RELEASE_NOTE_REQUIRED', $codes['other, bare']);

        // The real authorisation test: a teacher holding one open session must
        // not reach a record in another room by passing its attendance_id.
        $other = AttendanceSessionService::open(
            $fixture->device(1), $fixture->teacher(), $fixture->schedule(1), 0);

        $crossRoom = null;

        try {
            AttendanceService::releaseEarly(
                (int) $other['session_id'], $attendanceOf($students[1]), 'sickness', null, $userId);
        } catch (BusinessRuleException $e) {
            $crossRoom = $e->errorCode();
        }

        $runner->assertEquals('a record from another room is out of reach',
            'RECORD_NOT_FOUND', $crossRoom);

        // A student never released is untouched by any of it.
        $untouched = $db->selectOne('SELECT * FROM attendance_records WHERE attendance_id = :id',
            ['id' => $attendanceOf($students[2])]);

        $runner->assert('a student who was not released still has no departure',
            $untouched['time_out'] === null && $untouched['early_release_reason'] === null);
    }

    /* =====================================================================
     * 15. An unknown card leaves a trace, and tells somebody
     *
     * Everything a rejection writes lives inside a transaction that the
     * rejection itself rolls back. Only the rfid_logs row and the rejected-tap
     * counter were being carried past it; the unknown-card tally, the security
     * event and any alert were written inline and lost. So an unrecognised
     * card left one log row, the "seen 6 times" counter never counted, and
     * nobody was ever told.
     * ===================================================================== */
    if ($want('unknown')) {
        $runner->group('15. An unknown card leaves a trace, and tells somebody');

        $fixture->build(1, 3, 1);

        $device = $fixture->device(0);
        $uid    = 'FEEDFACE01'; // hex only: normaliseUid() strips anything else

        $db->execute('DELETE FROM unknown_rfid_logs WHERE card_uid = :u', ['u' => $uid]);
        $db->execute('DELETE FROM rfid_logs WHERE card_uid = :u', ['u' => $uid]);
        $db->execute("DELETE FROM notifications WHERE category = 'security' AND message LIKE :m",
            ['m' => '%' . $uid . '%']);

        AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $tap = static function () use ($device, $uid): ?string {
            try {
                AttendanceService::tap($device, $uid);
            } catch (BusinessRuleException $e) {
                return $e->errorCode();
            }

            return null;
        };

        $alerts = static fn (): int => (int) $db->scalar(
            "SELECT COUNT(*) FROM notifications WHERE category = 'security' AND message LIKE :m",
            ['m' => '%' . $uid . '%']
        );

        $seen = static fn (): int => (int) $db->scalar(
            'SELECT COALESCE(seen_count, 0) FROM unknown_rfid_logs WHERE card_uid = :u', ['u' => $uid]
        );

        $runner->assertEquals('an unregistered card is refused', 'RFID_UNKNOWN', $tap());

        // The whole point: this survived a rollback.
        $runner->assertEquals('the sighting is tallied despite the rejection', 1, $seen());
        $runner->assertEquals('an administrator is told', 1, $alerts());

        $logged = (int) $db->scalar(
            "SELECT COUNT(*) FROM rfid_logs WHERE card_uid = :u AND result = 'unknown_card'",
            ['u' => $uid]
        );

        $runner->assertEquals('and the scan itself is still logged', 1, $logged);

        // A rejection broadcast is a realtime_events row, so it was being
        // rolled back too: every refused tap was invisible on the very screen
        // somebody watches for refused taps.
        $broadcast = (int) $db->scalar(
            "SELECT COUNT(*) FROM realtime_events
              WHERE event_type = 'attendance.rejected' AND payload LIKE :m",
            ['m' => '%' . $uid . '%']
        );

        $runner->assert('the rejection reaches the live feed', $broadcast >= 1,
            'no attendance.rejected event was published');

        // A card tapped through a lesson is one problem, not forty.
        $tap();
        $tap();
        $tap();

        $runner->assertEquals('every sighting counts', 4, $seen());
        $runner->assertEquals('but the cooldown holds the alert to one', 1, $alerts());

        // Past the cooldown and past the repeat threshold, the alert is a
        // different statement rather than a repeat of the first.
        Clock::freeze(Clock::now()->modify('+2 hours'));
        $tap();
        $tap();

        $runner->assertEquals('past the cooldown it speaks again', 2, $alerts());

        $escalated = (int) $db->scalar(
            "SELECT COUNT(*) FROM notifications
              WHERE category = 'security' AND message LIKE :m AND priority = 'high'",
            ['m' => '%' . $uid . '%']
        );

        $runner->assertEquals('and a persistent card is raised as high priority', 1, $escalated);

        // Once an administrator has answered, the system stops repeating itself.
        $db->execute("UPDATE unknown_rfid_logs SET resolution = 'blacklisted' WHERE card_uid = :u",
            ['u' => $uid]);

        // No clock move: triage is checked before the cooldown, so it stops the
        // alert on its own. (Nor could the clock move far — the session has to
        // still be open, because steps 7 and 8 reject a tap for a closed or
        // expired session before step 9 ever looks the card up.)
        $tap();

        $runner->assertEquals('a triaged card raises nothing further', 2, $alerts());
        $runner->assertEquals('though it is still counted', 7, $seen());

        Clock::freeze(Clock::now()->setTime(10, 0, 0));

        $db->execute('DELETE FROM unknown_rfid_logs WHERE card_uid = :u', ['u' => $uid]);
        $db->execute('DELETE FROM rfid_logs WHERE card_uid = :u', ['u' => $uid]);
        $db->execute("DELETE FROM notifications WHERE category = 'security' AND message LIKE :m",
            ['m' => '%' . $uid . '%']);
        $db->execute("DELETE FROM realtime_events WHERE event_type = 'attendance.rejected' AND payload LIKE :m",
            ['m' => '%' . $uid . '%']);
    }

    /* =====================================================================
     * 16. A lost card is replaced without losing anything
     *
     * The point of the chain is that attendance survives it: records reference
     * the student, never the card. The point of the reason is that "replaced"
     * covers a card in a bin and a card lying in a corridor, and a school
     * asked which of its cards are unaccounted for cannot answer from it.
     * ===================================================================== */
    if ($want('replace')) {
        $runner->group('16. A lost card is replaced without losing anything');

        $fixture->build(1, 4, 1);

        $device    = $fixture->device(0);
        $sectionId = $fixture->ids['sections'][0];
        $studentId = $fixture->ids['students'][$sectionId][0];
        $oldUid    = $fixture->ids['cards'][$sectionId][0];
        $userId    = (int) $db->scalar('SELECT user_id FROM users ORDER BY user_id LIMIT 1');

        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);
        AttendanceService::tap($device, $oldUid, null, AttendanceService::INTENT_TIME_IN);

        $before = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE student_id = :s', ['s' => $studentId]
        );

        $runner->assert('the student has attendance on the old card', $before > 0);

        $refused = null;

        try {
            RfidService::assign($studentId, 'BADC0DE1', $userId);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refused = array_key_first($e->errors());
        }

        $runner->assertEquals('replacing without a reason is refused', 'replacement_reason', $refused);

        $noNote = null;

        try {
            RfidService::assign($studentId, 'BADC0DE1', $userId, null, 'other', '   ');
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $noNote = array_key_first($e->errors());
        }

        $runner->assertEquals("'other' with no note is refused", 'replacement_note', $noNote);

        RfidService::assign($studentId, 'BADC0DE1', $userId, null, 'lost', 'Left on the bus');

        $old = $db->selectOne('SELECT * FROM rfid_cards WHERE card_uid = :u', ['u' => $oldUid]);
        $new = $db->selectOne('SELECT * FROM rfid_cards WHERE card_uid = :u', ['u' => 'BADC0DE1']);

        $runner->assertEquals('a lost card is recorded as lost, not merely replaced',
            'lost', (string) $old['status']);
        $runner->assertEquals('with the reason kept', 'lost', (string) $old['replacement_reason']);
        $runner->assertEquals('and the note', 'Left on the bus', (string) $old['replacement_note']);
        $runner->assertEquals('the new card chains to the old one',
            (int) $old['rfid_id'], (int) $new['replaced_rfid_id']);
        $runner->assertEquals('and is the one in service', 'active', (string) $new['status']);

        $after = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE student_id = :s', ['s' => $studentId]
        );

        $runner->assertEquals('every attendance record survived the replacement', $before, $after);

        // The old card is out there. Somebody may find it and present it.
        $presented = null;

        try {
            AttendanceService::tap($device, $oldUid);
        } catch (BusinessRuleException $e) {
            $presented = $e->errorCode();
        }

        $runner->assertEquals('the lost card is refused at the reader', 'RFID_DISABLED', $presented);

        // A stolen card differs from a lost one in what happens next.
        RfidService::assign($studentId, 'BADC0DE2', $userId, null, 'stolen');

        $stolen = $db->selectOne('SELECT * FROM rfid_cards WHERE card_uid = :u', ['u' => 'BADC0DE1']);

        $runner->assertEquals('a stolen card is blacklisted, not lost',
            'blacklisted', (string) $stolen['status']);

        $reissue = null;

        try {
            RfidService::assign($studentId, 'BADC0DE1', $userId, null, 'damaged');
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $reissue = array_key_first($e->errors());
        }

        $runner->assertEquals('and can never be issued to anybody again', 'card_uid', $reissue);

        AttendanceSessionService::close((int) $session['session_id'], 'teacher', null);

        $db->execute("DELETE FROM rfid_cards WHERE card_uid IN ('BADC0DE1','BADC0DE2')");
    }

    /* =====================================================================
     * 17. A report filter that is offered is a filter that is applied
     *
     * The reports form advertises a set of filters per report type. Four of
     * them were being discarded by the builder — choosing one subject still
     * returned every subject, and "chronic absence in Grade 7" returned the
     * whole school and looked like the whole school was in trouble. Nothing
     * failed; the number was simply wrong, which is the worst way for a report
     * to be wrong.
     *
     * This asserts the property rather than the numbers: narrowing by a filter
     * the form offers must change the result. A builder that silently drops one
     * again fails here.
     * ===================================================================== */
    if ($want('report-filters')) {
        $runner->group('17. A report filter that is offered is a filter that is applied');

        $fixture->build(2, 8, 2);

        $sectionId = $fixture->ids['sections'][0];
        $device    = $fixture->device(0);
        $session   = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        foreach (array_slice($fixture->ids['cards'][$sectionId], 0, 5) as $card) {
            AttendanceService::tap($device, $card, null, AttendanceService::INTENT_TIME_IN);
        }

        AttendanceSessionService::close((int) $session['session_id'], 'teacher', null);

        $schedule = $fixture->schedule(0);
        $today    = Clock::today();

        $base = [
            'date'      => $today,
            'date_from' => Clock::now()->modify('-7 days')->format('Y-m-d'),
            'date_to'   => $today,
            // A threshold nothing can pass, so chronic_absence has rows to
            // narrow. At the default it is empty and proves nothing.
            'threshold' => 100,
        ];

        $count = static fn (string $type, array $extra = []): int => count(
            \App\Services\ReportService::build($type, $base + $extra)['rows']
        );

        // Every pair below is (report type, the filter its form offers, a value
        // that must exclude everything). Narrowing to the section that has no
        // attendance, or to a grade level nothing was recorded under, has to
        // empty the report — filtering to the section that holds all of it
        // proves nothing, because the unfiltered answer is already that.
        $emptySection = $fixture->ids['sections'][1];
        $otherGrade   = $fixture->ids['grade_level_id'] + 1;

        $narrowing = [
            ['subject',         'grade_level_id', $otherGrade],
            ['section_summary', 'section_id',     $emptySection],
            ['chronic_absence', 'section_id',     $emptySection],
            ['chronic_absence', 'grade_level_id', $otherGrade],
        ];

        foreach ($narrowing as [$type, $filter, $value]) {
            $wide   = $count($type);
            $narrow = $count($type, [$filter => $value]);

            $runner->assert(
                sprintf('%s honours %s', $type, $filter),
                $wide !== $narrow,
                sprintf('%d rows either way — the filter was discarded', $wide)
            );
        }

        // Naming a subject changes the question: the league table across every
        // subject becomes that one subject, day by day.
        $byDate = \App\Services\ReportService::build('subject', $base + [
            'subject_id' => (int) $schedule['subject_id'],
        ]);

        $runner->assert('naming a subject reports it day by day',
            str_contains($byDate['title'], 'Attendance by Date'),
            'title was ' . $byDate['title']);

        $runner->assertEquals('with a row per class meeting, dated',
            'Date', $byDate['headers'][0]);

        $runner->assert('and every row belongs to that subject',
            $byDate['rows'] !== [], 'the report came back empty');

        // The exports have to survive the new shape too — a report nobody can
        // export is half a report.
        foreach (['xlsx', 'pdf'] as $format) {
            $rendered = \App\Services\ReportService::export($byDate, $format);

            $runner->assert(
                sprintf('it exports as %s', $format),
                strlen($rendered['content']) > 0 && $rendered['filename'] !== '',
                'empty export'
            );

            // A file the receiving application dispatches on. An export that is
            // the right length and the wrong shape opens as nothing.
            $runner->assert(
                sprintf('and the %s is what it claims to be', $format),
                str_starts_with($rendered['content'], $format === 'pdf' ? '%PDF' : "PK\x03\x04"),
                'wrong signature: ' . bin2hex(substr($rendered['content'], 0, 4))
            );
        }

        // CSV was withdrawn. Asserted rather than assumed, because a format
        // silently still on offer is exactly what a removal is supposed to
        // prevent, and the buttons disappearing from a page proves nothing
        // about the service behind them.
        $refused = false;

        try {
            \App\Services\ReportService::export($byDate, 'csv');
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refused = true;
        }

        $runner->assert('and csv is refused, not quietly produced',
            $refused, 'the report still exported as CSV');
    }

    /* =====================================================================
     * 18. An import never discards a cell somebody filled in
     *
     * The card UID column was normalised before it was checked, and
     * normalising strips every character that is not hexadecimal. A cell
     * reading "ZZZZ" or "N/A" came back as the empty string, which the
     * validator read as "no card given" — so the preview reported no problems
     * and the student was created with no card at all.
     *
     * On a roll of three hundred that is a handful of students silently marked
     * absent every day until somebody works out why. Nothing errors; the data
     * is simply missing.
     * ===================================================================== */
    if ($want('import')) {
        $runner->group('18. An import never discards a cell somebody filled in');

        $fixture->build(1, 2, 1);

        $sectionId = $fixture->ids['sections'][0];
        $section   = $db->selectOne(
            'SELECT s.section_code, g.grade_level_code
               FROM sections s JOIN grade_levels g ON g.grade_level_id = s.grade_level_id
              WHERE s.section_id = :id',
            ['id' => $sectionId]
        );

        $header = ['student_number', 'first_name', 'last_name', 'gender', 'birthdate',
                   'guardian_name', 'guardian_contact', 'grade_level_code', 'section_code',
                   'card_uid', 'status'];

        $row = static function (string $number, string $cardUid) use ($section): array {
            return [
                'student_number'   => $number,
                'first_name'       => 'Test',
                'last_name'        => 'Importer',
                'gender'           => 'Male',
                'birthdate'        => '2012-01-01',
                'guardian_name'    => 'Test Guardian',
                'guardian_contact' => '09170000000',
                'grade_level_code' => (string) $section['grade_level_code'],
                'section_code'     => (string) $section['section_code'],
                'card_uid'         => $cardUid,
                'status'           => 'active',
            ];
        };

        $preview = \App\Services\ImportService::previewStudents([
            $row(Fixture::PREFIX . 'I1', 'ZZZZ'),        // no hex at all
            $row(Fixture::PREFIX . 'I2', 'N/A'),         // nor this
            $row(Fixture::PREFIX . 'I3', '04-A7-C1-99'), // hex with separators — valid
            $row(Fixture::PREFIX . 'I4', ''),            // genuinely no card
        ]);

        $byNumber = [];
        foreach ($preview['valid'] as $v)   { $byNumber[$v['student_number']] = ['valid', $v]; }
        foreach ($preview['invalid'] as $i) { $byNumber[$i['student_number']] = ['invalid', $i]; }

        $runner->assertEquals('a cell of non-hex text is refused, not silently emptied',
            'invalid', $byNumber[Fixture::PREFIX . 'I1'][0] ?? 'missing');

        $runner->assertEquals('and so is "N/A"',
            'invalid', $byNumber[Fixture::PREFIX . 'I2'][0] ?? 'missing');

        $runner->assert('the refusal quotes what was actually typed',
            str_contains(implode(' ', $byNumber[Fixture::PREFIX . 'I1'][1]['errors'] ?? []), 'ZZZZ'),
            'the error did not name the offending value');

        $runner->assertEquals('a UID written with separators is still accepted',
            'valid', $byNumber[Fixture::PREFIX . 'I3'][0] ?? 'missing');

        $runner->assertEquals('and normalised to bare hex',
            '04A7C199', $byNumber[Fixture::PREFIX . 'I3'][1]['card_uid'] ?? null);

        $runner->assertEquals('an empty cell still means no card',
            'valid', $byNumber[Fixture::PREFIX . 'I4'][0] ?? 'missing');

        $runner->assert('with nothing invented for it',
            array_key_exists('card_uid', $byNumber[Fixture::PREFIX . 'I4'][1] ?? [])
            && $byNumber[Fixture::PREFIX . 'I4'][1]['card_uid'] === null,
            'a card UID appeared for a row that named none');

        // The template has to survive its own importer. A sample row the
        // importer rejects teaches the wrong lesson on the very first attempt.
        $template = \App\Services\ImportService::template('students');
        $lines    = array_values(array_filter(explode("\n", str_replace("\r", '', $template))));

        $runner->assertEquals('the template has a header and one example row', 2, count($lines));

        // The five-argument form: PHP 8.4 deprecates omitting $escape.
        $parsed = array_combine(
            str_getcsv(preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]), ',', '"', '\\'),
            str_getcsv($lines[1], ',', '"', '\\')
        );

        $templatePreview = \App\Services\ImportService::previewStudents([$parsed]);

        $runner->assertEquals("the template's own example row imports cleanly",
            0, count($templatePreview['invalid']));
    }

    /* =====================================================================
     * 19. Issuing credentials is all-or-nothing
     *
     * "Rotate key" and "Download provisioning file" both rotated the key as
     * their very first act, then did three more things that could fail. If any
     * of them did, the key had already been rotated and committed: the old key
     * was in its grace window, a new one existed, and because a key is stored
     * hashed the administrator could never be shown what had just been
     * created. The terminal was then on a countdown to the grace window
     * expiring, after which it was dead.
     *
     * The trigger in practice was the plainest one — a device row that no
     * longer exists, from a stale page — which failed on a foreign key and
     * surfaced as "An unexpected error occurred" on both buttons.
     * ===================================================================== */
    if ($want('reprovision')) {
        $runner->group('19. Issuing credentials is all-or-nothing');

        $fixture->build(1, 2, 1);

        $deviceRowId = $fixture->ids['devices'][0];
        $userId      = (int) $db->scalar('SELECT user_id FROM users ORDER BY user_id LIMIT 1');

        $activeKey = static fn (int $id): ?string => $db->scalar(
            "SELECT key_id FROM api_keys WHERE device_row_id = :d AND status = 'active'
              ORDER BY api_key_id DESC LIMIT 1",
            ['d' => $id]
        );

        $refuse = static function (int $id) use ($userId): ?string {
            try {
                \App\Services\DeviceService::reprovision($id, $userId);
            } catch (BusinessRuleException $e) {
                return $e->errorCode();
            } catch (Throwable $e) {
                return 'UNEXPECTED: ' . get_class($e);
            }

            return null;
        };

        // The reported failure: a row that is not there any more.
        $runner->assertEquals('a device that does not exist is refused cleanly',
            'DEVICE_NOT_FOUND', $refuse(2147483646));

        // The fixture inserts its devices directly, so mint the key a real
        // registration would have given them — the grace-window assertion at
        // the end is about what happens to an EXISTING key.
        \App\Services\ApiKeyService::generateForDevice($deviceRowId, $userId);

        $before = $activeKey($deviceRowId);
        $runner->assert('the fixture device holds a key to begin with', $before !== null);

        // A retired terminal must not be handed working credentials.
        $db->update('devices', ['status' => 'decommissioned'], ['id' => $deviceRowId]);

        $runner->assertEquals('a decommissioned terminal is refused',
            'DEVICE_DECOMMISSIONED', $refuse($deviceRowId));

        $runner->assertEquals('and its key is untouched by the refusal', $before, $activeKey($deviceRowId));

        $runner->assertEquals('with nothing left half-rotated behind it', 0, (int) $db->scalar(
            "SELECT COUNT(*) FROM api_keys WHERE device_row_id = :d AND status = 'rotating'",
            ['d' => $deviceRowId]
        ));

        $db->update('devices', ['status' => 'active', 'deleted_at' => null], ['id' => $deviceRowId]);
        $db->update('devices', ['deleted_at' => Clock::nowString()], ['id' => $deviceRowId]);

        $runner->assertEquals('a deleted terminal is refused', 'DEVICE_DELETED', $refuse($deviceRowId));
        $runner->assertEquals('and its key is still untouched', $before, $activeKey($deviceRowId));

        $db->update('devices', ['deleted_at' => null], ['id' => $deviceRowId]);

        // The healthy path still does the whole job in one go.
        $issued = \App\Services\DeviceService::reprovision($deviceRowId, $userId);

        $runner->assert('a healthy terminal is issued a new key',
            ($issued['credentials']['api_key'] ?? '') !== '' && $activeKey($deviceRowId) !== $before,
            'the key did not change');

        $runner->assert('with an HMAC secret the administrator can actually see',
            ($issued['credentials']['hmac_secret'] ?? '') !== '');

        $runner->assert('and a claim token, so the board can adopt it',
            ($issued['claim']['token'] ?? '') !== '');

        $runner->assertEquals('the provisioning file names the right terminal',
            (string) $db->scalar('SELECT device_id FROM devices WHERE id = :d', ['d' => $deviceRowId]),
            (string) ($issued['provisioning']['device_id'] ?? ''));

        $runner->assertEquals('and carries the same key that was just issued',
            $issued['credentials']['api_key'], $issued['provisioning']['api_key'] ?? null);

        $runner->assertEquals('the previous key is in its grace window, not revoked outright',
            'rotating', (string) $db->scalar(
                'SELECT status FROM api_keys WHERE device_row_id = :d AND key_id = :k',
                ['d' => $deviceRowId, 'k' => $before]
            ));
    }

    /* =====================================================================
     * 20. A misconfigured installation says which
     *
     * The likeliest thing to be wrong after an update, and the application
     * used to say nothing about it: the schema is a few migrations short of
     * what the code expects, so the dashboard, the attendance list and every
     * student and session page answer 500 with "An unexpected error occurred"
     * — which describes a bug and sends somebody hunting for one.
     *
     * update.bat runs the migrations, so this only reaches somebody who
     * updated another way, which is exactly the person with no reason to
     * suspect it.
     * ===================================================================== */
    if ($want('migrations')) {
        $runner->group('20. A misconfigured installation says which, not "something went wrong"');

        $pending = $db->pendingMigrations();

        $runner->assertEquals('a fully migrated database reports nothing pending', [], $pending);

        // Forget the most recent migration, exactly as an installation that
        // pulled the code but never ran migrate.
        $latest = (string) $db->scalar(
            'SELECT migration FROM schema_migrations ORDER BY migration DESC LIMIT 1'
        );

        $row = $db->selectOne(
            'SELECT * FROM schema_migrations WHERE migration = :m',
            ['m' => $latest]
        );

        $db->execute('DELETE FROM schema_migrations WHERE migration = :m', ['m' => $latest]);

        try {
            // Primed by the call above, so it has to be told to look again.
            $db->forgetPendingMigrations();
            $seen = $db->pendingMigrations();

            $runner->assertEquals('a missing migration is noticed', [$latest], $seen);

            $runner->assert('and named, so the fix is obvious',
                $seen !== [] && str_ends_with($seen[0], '.sql'),
                'the pending list did not name a file');
        } finally {
            $db->insert('schema_migrations', [
                'migration'  => $row['migration'],
                'batch'      => $row['batch'],
                'checksum'   => $row['checksum'],
                'applied_at' => $row['applied_at'],
            ]);
        }

        $db->forgetPendingMigrations();

        $runner->assertEquals('and it is clean again once restored',
            [], $db->pendingMigrations());

        // The other configuration failure that used to hide behind a generic
        // 500: no APP_KEY, so nothing can encrypt a terminal's secret, and the
        // page that needed one looked like it had a bug of its own.
        $runner->assertEquals('a configured installation reports no missing secrets',
            [], \App\Core\App::missingSecrets());

        // Env caches .env into its own array, so blanking it there is what an
        // installation with no APP_KEY actually looks like.
        $realKey = (string) \App\Core\Env::get('APP_KEY', '');
        \App\Core\Env::set('APP_KEY', '');

        try {
            $runner->assert('an absent APP_KEY is noticed and named',
                in_array('APP_KEY', \App\Core\App::missingSecrets(), true),
                'a blank APP_KEY was not reported');
        } finally {
            \App\Core\Env::set('APP_KEY', $realKey);
        }

        $runner->assertEquals('and clean again once it is back',
            [], \App\Core\App::missingSecrets());
    }

    /* =====================================================================
     * 21. A locked account lets go by itself
     * ===================================================================== */
    if ($want('lockout')) {
        $runner->group('21. Too many wrong passwords lock the account — for fifteen minutes, not forever');

        // Request::capture() reads these; under CLI they are absent.
        $_SERVER['REQUEST_METHOD']  = 'POST';
        $_SERVER['REQUEST_URI']     = '/login';
        $_SERVER['REMOTE_ADDR']     = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'concurrency-suite';

        $username = Fixture::PREFIX . 'lock';
        $password = 'CorrectHorseBattery#2026';

        $db->execute('DELETE FROM login_attempts WHERE identifier = :u', ['u' => $username]);

        $db->insert('users', [
            'username'             => $username,
            'email'                => $username . '@test.local',
            'password_hash'        => \App\Core\Hash::make($password),
            'full_name'            => 'Lockout Subject',
            'role_id'              => (int) $db->scalar(
                "SELECT role_id FROM roles WHERE role_slug = 'administrator'"
            ),
            'status'               => 'active',
            'must_change_password' => 0,
            'failed_login_count'   => 0,
            'created_at'           => Clock::nowString(),
        ]);

        $lockUserId = (int) $db->scalar(
            'SELECT user_id FROM users WHERE username = :u',
            ['u' => $username]
        );

        /** Returns the error code, or 'SIGNED_IN'. */
        $signIn = static function (string $secret) use ($db, $username): array {
            // The route-level throttle is a separate layer with its own tests;
            // clearing it keeps this group measuring the account lockout alone.
            $db->execute('DELETE FROM login_attempts WHERE identifier = :u', ['u' => $username]);

            try {
                \App\Services\AuthService::attempt($username, $secret, \App\Core\Request::capture());

                return ['code' => 'SIGNED_IN', 'message' => ''];
            } catch (\App\Core\Exceptions\AuthenticationException $e) {
                return ['code' => $e->errorCode(), 'message' => $e->getMessage()];
            }
        };

        $state = static fn (): array => (array) $db->selectOne(
            'SELECT status, failed_login_count, locked_until FROM users WHERE username = :u',
            ['u' => $username]
        );

        for ($i = 1; $i <= 4; $i++) {
            $result = $signIn('Wrong#' . $i);
        }

        $runner->assertEquals('four wrong passwords are just wrong passwords',
            'INVALID_CREDENTIALS', $result['code']);

        $before = $state();

        $runner->assertEquals('and the account is still open', 'active', $before['status']);
        $runner->assertEquals('with the failures counted', 4, (int) $before['failed_login_count']);

        // The fifth is the one that changes the account's state, and used to be
        // the one attempt that said nothing about it.
        $fifth = $signIn('Wrong#5');

        $runner->assertEquals('the fifth locks the account', 'ACCOUNT_LOCKED', $fifth['code']);

        $runner->assert('and says so, rather than repeating "invalid username or password"',
            str_contains($fifth['message'], 'locked'), $fifth['message']);

        $runner->assert('naming the wait, so nobody goes looking for an administrator',
            preg_match('/\b\d+ minutes?\b/', $fifth['message']) === 1, $fifth['message']);

        $locked = $state();

        $runner->assertEquals('the record agrees it is locked', 'locked', $locked['status']);
        $runner->assert('and carries the moment it expires',
            $locked['locked_until'] !== null, 'locked_until was null');

        // The right password during the lock is still refused — but for the
        // real reason, not as a sixth "invalid username or password".
        $during = $signIn($password);

        $runner->assertEquals('the correct password during the lock is refused for the stated reason',
            'ACCOUNT_LOCKED', $during['code']);

        // Fifteen minutes later. The suite's clock is frozen, so the lock is
        // moved into the past instead of waiting.
        $db->execute(
            'UPDATE users SET locked_until = :past WHERE user_id = :id',
            ['past' => Clock::now()->modify('-1 minute')->format('Y-m-d H:i:s'), 'id' => $lockUserId]
        );

        $after = $signIn($password);

        $runner->assertEquals('once the lock expires the right password works again',
            'SIGNED_IN', $after['code']);

        $reopened = $state();

        $runner->assertEquals('and the account is active again, not left locked forever',
            'active', $reopened['status']);
        $runner->assertEquals('with the failure count reset', 0, (int) $reopened['failed_login_count']);
        $runner->assertEquals('and nothing left to expire', null, $reopened['locked_until']);

        // The automatic lock clears itself; a lock an administrator applied is
        // a decision and has to outlast it.
        $db->update('users', ['status' => 'locked', 'locked_until' => null], ['user_id' => $lockUserId]);

        $admin = $signIn($password);

        $runner->assertEquals('a lock an administrator set is not cleared by the same code path',
            'ACCOUNT_INACTIVE', $admin['code']);

        $db->update('users', ['status' => 'active'], ['user_id' => $lockUserId]);
    }

    /* =====================================================================
     * 22. Template distribution: enough to work, no more than that
     * ===================================================================== */
    if ($want('fingerprint-sync')) {
        $runner->group('22. A terminal learns the fingerprints it is meant to hold');

        // Both settings are exercised. The default, 'all', is what a school
        // runs on — any teacher can start a class at any reader, so a
        // substitute or a room change never meets NOT RECOGNISED. 'timetable'
        // is the tighter posture for terminals in public corridors, and it has
        // to keep working too or the setting is a bluff.
        $scopeWas = \App\Core\Config::get('security.fingerprint.sync_scope', 'all');
        \App\Core\Config::set('security.fingerprint.sync_scope', 'all');

        $runner->assertEquals('the shipped default is that every terminal holds every teacher',
            'all', $scopeWas);

        $fixture->build(1, 1, 1);

        $firstDevice = (int) $fixture->ids['devices'][0];
        $teacherId   = (int) $fixture->ids['teacher_id'];

        // The teacher is enrolled on the terminal that already exists, and the
        // template was captured — the state every enrolment reaches today.
        $fingerprintId = (int) $fixture->ids['fingerprint_id'];

        $db->update('fingerprint_templates', [
            'sensor_template_id'     => 1,
            'enrolled_device_row_id' => $firstDevice,
            'template_data'          => \App\Core\Crypto::encrypt(str_repeat("\x41", 512)),
            'template_bytes'         => 512,
            'template_captured_at'   => Clock::nowString(),
        ], ['fingerprint_id' => $fingerprintId]);

        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :fp',
            ['fp' => $fingerprintId]);

        $db->insert('fingerprint_slots', [
            'fingerprint_id'     => $fingerprintId,
            'device_row_id'      => $firstDevice,
            'sensor_template_id' => 1,
            'source'             => 'enrolled',
            'status'             => 'present',
            'synced_at'          => Clock::nowString(),
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);

        // Now the second room gets a terminal — after the enrolment, which is
        // the ordinary way a school grows and the case that used to leave the
        // new sensor empty for good.
        $lateClassroom = (int) $db->insert('classrooms', [
            'room_number' => Fixture::PREFIX . 'RLATE',
            'capacity'    => 60,
            'status'      => 'active',
            'created_at'  => Clock::nowString(),
            'updated_at'  => Clock::nowString(),
        ]);

        $lateDevice = (int) $db->insert('devices', [
            'device_id'    => Fixture::PREFIX . 'DEVLATE',
            'device_name'  => 'Terminal added later',
            'mac_address'  => sprintf('AA:BB:CC:0F:%02X:%02X', random_int(0, 255), random_int(0, 255)),
            'classroom_id' => $lateClassroom,
            'device_role'  => 'both',
            'status'       => 'active',
            'claim_status' => 'claimed',
            'timezone'     => 'Asia/Manila',
            'created_at'   => Clock::nowString(),
            'updated_at'   => Clock::nowString(),
        ]);

        $queuedAtBirth = (int) $db->scalar(
            'SELECT COUNT(*) FROM fingerprint_slots WHERE device_row_id = :d',
            ['d' => $lateDevice]
        );

        $runner->assertEquals('a terminal inserted straight into the table starts with nothing',
            0, $queuedAtBirth);

        // The new room has no timetable at all yet, and under the default that
        // is beside the point: the terminal is sent the school's fingerprints
        // so a class can start there whatever the timetable does or does not
        // say. This is the case a substitute teacher walks into.
        $runner->assert('a terminal is given the fingerprints even for a room with no timetable',
            \App\Services\FingerprintSyncService::nextPendingFor($lateDevice) !== null,
            'the new terminal was told there was nothing to sync');

        // The teacher is timetabled into the new room as well. Under 'all' this
        // changes nothing; it is what part two below turns on.
        $db->insert('schedules', [
            'teacher_id'     => $teacherId,
            'subject_id'     => (int) $fixture->ids['subject_id'],
            'section_id'     => (int) $fixture->ids['sections'][0],
            'classroom_id'   => $lateClassroom,
            'school_year_id' => (int) $fixture->ids['school_year_id'],
            'day_of_week'    => Clock::now()->format('l'),
            'start_time'     => Clock::now()->modify('-10 minutes')->format('H:i:s'),
            'end_time'       => Clock::now()->modify('+110 minutes')->format('H:i:s'),
            'time_in_window_open'    => 30,
            'late_threshold_minutes' => 15,
            'time_in_window_close'   => 60,
            'time_out_window_open'   => 30,
            'time_out_window_close'  => 30,
            'minimum_dwell_minutes'  => 1,
            'status'     => 'active',
            'created_at' => Clock::nowString(),
            'updated_at' => Clock::nowString(),
        ]);

        // The terminal boots and asks for work. Being told "nothing" is the
        // bug: it is missing a template and does not know it.
        //
        // Drained rather than checked one at a time, and the assertions are
        // about this teacher rather than about totals. A real database holds
        // other enrolled teachers, and a test that only passes when the fixture
        // is the only enrolment in the world is a test that will fail on
        // somebody's real data for a reason that is not a bug.
        $offered   = \App\Services\FingerprintSyncService::nextPendingFor($lateDevice);
        $collected = [];

        $runner->assert('asking for work discovers the template it never received',
            $offered !== null, 'the new terminal was told there was nothing to sync');

        if ($offered !== null) {
            $runner->assertEquals('and is handed the full template, not a truncated one',
                512, strlen((string) base64_decode($offered['template'], true)));

            $runner->assert('addressed to a slot on its own sensor',
                $offered['slot'] >= 1, 'slot was ' . $offered['slot']);
        }

        // Confirming the write is what marks it present — not having offered it.
        $guard = 0;

        while ($offered !== null && $guard++ < 200) {
            $collected[] = (int) $offered['fingerprint_id'];
            \App\Services\FingerprintSyncService::markStored($lateDevice, (int) $offered['slot']);
            $offered = \App\Services\FingerprintSyncService::nextPendingFor($lateDevice);
        }

        $runner->assert('the teacher enrolled elsewhere is among what it collected',
            in_array($fingerprintId, $collected, true),
            'the new terminal never received the enrolled teacher');

        $runner->assertEquals('and once written, the terminal is not asked again',
            null, \App\Services\FingerprintSyncService::nextPendingFor($lateDevice));

        // Slot numbers are per-sensor: the same teacher sits in slot 1 on the
        // terminal that enrolled them, and in whatever slot this sensor had
        // free. Both are correct, and treating them as the same number is the
        // bug migration 018 gave fingerprint_slots its own table to prevent.
        $runner->assert('slots are allocated per sensor, not shared between terminals',
            (int) $db->scalar(
                'SELECT sensor_template_id FROM fingerprint_slots
                  WHERE device_row_id = :d AND fingerprint_id = :f',
                ['d' => $lateDevice, 'f' => $fingerprintId]
            ) >= 1,
            'the teacher has no slot on the new terminal');

        $coverage = [];

        foreach (\App\Services\FingerprintSyncService::terminalStatus() as $row) {
            $coverage[(string) $row['device_id']] = $row;
        }

        $late = $coverage[Fixture::PREFIX . 'DEVLATE'] ?? null;

        $runner->assert('the Fingerprints page can see the new terminal',
            $late !== null, 'the new terminal was absent from the coverage table');

        if ($late !== null) {
            $runner->assert('holding at least the enrolment it was missing',
                (int) $late['present'] >= 1, 'present was ' . $late['present']);
            $runner->assert('and reported complete', (bool) $late['complete'], 'not complete');
        }

        // --- Part two: the tighter posture, FINGERPRINT_SYNC_SCOPE=timetable --
        //
        // Now a terminal holds only the teachers scheduled into its own room,
        // so a board unscrewed from a corridor wall carries those few rather
        // than the whole staff.
        \App\Core\Config::set('security.fingerprint.sync_scope', 'timetable');

        $runner->assertEquals('the setting is what decides, not a hard-coded rule',
            'timetable', \App\Services\FingerprintSyncService::scope());

        // A room nobody teaches in is owed nothing under this setting — the
        // property the whole posture rests on.
        $emptyClassroom = (int) $db->insert('classrooms', [
            'room_number' => Fixture::PREFIX . 'REMPTY',
            'capacity'    => 30,
            'status'      => 'active',
            'created_at'  => Clock::nowString(),
            'updated_at'  => Clock::nowString(),
        ]);

        $emptyDevice = (int) $db->insert('devices', [
            'device_id'    => Fixture::PREFIX . 'DEVEMPTY',
            'device_name'  => 'Terminal in an untimetabled room',
            'mac_address'  => sprintf('AA:BB:CC:0E:%02X:%02X', random_int(0, 255), random_int(0, 255)),
            'classroom_id' => $emptyClassroom,
            'device_role'  => 'both',
            'status'       => 'active',
            'claim_status' => 'claimed',
            'timezone'     => 'Asia/Manila',
            'created_at'   => Clock::nowString(),
            'updated_at'   => Clock::nowString(),
        ]);

        $runner->assertEquals('scoped: a room nobody is timetabled into is sent nothing at all',
            null, \App\Services\FingerprintSyncService::nextPendingFor($emptyDevice));

        // A timetable edit takes the teacher back out of the new room. What has
        // not been written yet must not be sent; what has already been written
        // stays in the sensor — deleting from a sensor is a separate decision —
        // and has to be visible rather than quietly forgotten.
        $db->execute('DELETE FROM fingerprint_slots WHERE device_row_id = :d',
            ['d' => $lateDevice]);

        $db->insert('fingerprint_slots', [
            'fingerprint_id'     => $fingerprintId,
            'device_row_id'      => $lateDevice,
            'sensor_template_id' => 1,
            'source'             => 'synced',
            'status'             => 'pending',
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);

        $db->execute("UPDATE schedules SET status = 'inactive' WHERE classroom_id = :c",
            ['c' => $lateClassroom]);

        $runner->assertEquals('scoped: a template queued for a room the teacher has left is never sent',
            null, \App\Services\FingerprintSyncService::nextPendingFor($lateDevice));

        $runner->assertEquals('scoped: and the queued row is dropped rather than left pending forever',
            0, (int) $db->scalar(
                "SELECT COUNT(*) FROM fingerprint_slots
                  WHERE device_row_id = :d AND status = 'pending'",
                ['d' => $lateDevice]
            ));

        // Now the case where the sensor already holds it. The row above was
        // dropped because nothing had been written; this one stands for a
        // template that reached the flash before the timetable changed.
        $db->insert('fingerprint_slots', [
            'fingerprint_id'     => $fingerprintId,
            'device_row_id'      => $lateDevice,
            'sensor_template_id' => 1,
            'source'             => 'synced',
            'status'             => 'present',
            'synced_at'          => Clock::nowString(),
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);

        $stale = null;

        foreach (\App\Services\FingerprintSyncService::terminalStatus() as $row) {
            if ((string) $row['device_id'] === Fixture::PREFIX . 'DEVLATE') {
                $stale = $row;
            }
        }

        $runner->assert('scoped: a template already written to a sensor is reported, not forgotten',
            $stale !== null && (int) $stale['stale'] === 1,
            'stale count was ' . ($stale === null ? 'no row' : (string) $stale['stale']));

        $runner->assertEquals('scoped: and the room is owed nothing, so it is not shown as behind',
            0, $stale === null ? -1 : (int) $stale['expected']);

        // Back to the shipped setting for the rest of the group, and for every
        // group after it — a test that leaves configuration behind it makes the
        // next failure somebody else's mystery.
        \App\Core\Config::set('security.fingerprint.sync_scope', $scopeWas);

        $db->execute("UPDATE schedules SET status = 'active' WHERE classroom_id = :c",
            ['c' => $lateClassroom]);

        // The empty room proves the switch both ways: owed nothing while
        // scoped, owed the school's enrolments once the setting is back.
        $runner->assert('back on the default, an untimetabled room is owed the enrolments again',
            \App\Services\FingerprintSyncService::reconcile($emptyDevice) >= 1,
            'the untimetabled room was still sent nothing under scope "all"');

        // An enrolment made before templates were stored cannot be copied
        // anywhere, and has to be named rather than silently skipped.
        $db->update('fingerprint_templates',
            ['template_data' => null, 'template_bytes' => null],
            ['fingerprint_id' => $fingerprintId]
        );

        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f AND device_row_id = :d',
            ['f' => $fingerprintId, 'd' => $lateDevice]);

        $names = array_map(
            static fn (array $r): int => (int) $r['teacher_id'],
            \App\Services\FingerprintSyncService::awaitingRecapture()
        );

        $runner->assert('a teacher with no stored template is named, not silently skipped',
            in_array($teacherId, $names, true), 'the teacher was not listed for re-enrolment');

        \App\Services\FingerprintSyncService::reconcile($lateDevice);

        $runner->assertEquals('and nothing tries to copy a template that does not exist',
            0, (int) $db->scalar(
                'SELECT COUNT(*) FROM fingerprint_slots
                  WHERE device_row_id = :d AND fingerprint_id = :f',
                ['d' => $lateDevice, 'f' => $fingerprintId]
            ));

        // An enrolment scanner verifies nobody, so it is never given a backlog.
        $db->update('devices', ['enrollment_station' => 1, 'classroom_id' => null], ['id' => $lateDevice]);

        $runner->assertEquals('an enrolment scanner is never sent other people\'s fingerprints',
            0, \App\Services\FingerprintSyncService::reconcile($lateDevice));
    }

    /* =====================================================================
     * 23. A legacy enrolment is recovered without fetching the teacher
     * ===================================================================== */
    if ($want('fingerprint-backfill')) {
        $runner->group('23. The sensor hands back the template, so nobody is asked to enrol again');

        $fixture->build(1, 1, 2);

        $firstDevice   = (int) $fixture->ids['devices'][0];
        $secondDevice  = (int) $fixture->ids['devices'][1];
        $teacherId     = (int) $fixture->ids['teacher_id'];
        $fingerprintId = (int) $fixture->ids['fingerprint_id'];

        // The state a school that has been running a while is actually in: the
        // teacher is enrolled, the sensor holds their finger, and the server
        // has a slot number and nothing else. Re-enrolling every such teacher
        // is the remedy this group exists to avoid.
        $db->update('fingerprint_templates', [
            'sensor_template_id'     => 7,
            'enrolled_device_row_id' => $firstDevice,
            'template_data'          => null,
            'template_bytes'         => null,
            'template_captured_at'   => null,
        ], ['fingerprint_id' => $fingerprintId]);

        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f', ['f' => $fingerprintId]);

        $runner->assertEquals('nothing can be distributed while the template is only in a sensor',
            0, \App\Services\FingerprintSyncService::syncableCount());

        $runner->assertEquals('and the second terminal is offered nothing',
            null, \App\Services\FingerprintSyncService::nextPendingFor($secondDevice));

        // The terminal that did the original enrolling is asked for it.
        $wanted = \App\Services\FingerprintSyncService::nextBackfillFor($firstDevice);

        $runner->assert('the terminal holding it is asked to send it back',
            $wanted !== null, 'no backfill was requested');

        $runner->assertEquals('naming the slot it is recorded as holding',
            7, $wanted === null ? -1 : $wanted['slot']);

        $runner->assertEquals('a terminal that never enrolled anybody is asked for nothing',
            null, \App\Services\FingerprintSyncService::nextBackfillFor($secondDevice));

        // A terminal cannot attach a template to a teacher of its choosing:
        // the slot has to be one the server already records against it.
        $runner->assert('an upload for a slot nothing owns is refused',
            !\App\Services\FingerprintSyncService::acceptBackfill(
                $firstDevice, 99, base64_encode(str_repeat("\x51", 512))),
            'a template was accepted for an unowned slot');

        $runner->assert('and one from a terminal that does not hold that enrolment is refused',
            !\App\Services\FingerprintSyncService::acceptBackfill(
                $secondDevice, 7, base64_encode(str_repeat("\x51", 512))),
            'another terminal was allowed to supply the template');

        // A truncated transfer is worse than none: it writes cleanly into every
        // other sensor and then matches nobody, which reads as the teacher's
        // finger being at fault.
        $runner->assert('a truncated upload is refused rather than distributed',
            !\App\Services\FingerprintSyncService::acceptBackfill(
                $firstDevice, 7, base64_encode(str_repeat("\x51", 32))),
            'a truncated template was accepted');

        $runner->assertEquals('so nothing has been recorded yet',
            0, \App\Services\FingerprintSyncService::syncableCount());

        // The real upload — bytes the sensor read back out of slot 7, with no
        // teacher present and no finger involved.
        $runner->assert('the genuine upload is accepted',
            \App\Services\FingerprintSyncService::acceptBackfill(
                $firstDevice, 7, base64_encode(str_repeat("\x52", 512))),
            'the sensor\'s own template was refused');

        $runner->assertEquals('the enrolment is now distributable',
            1, \App\Services\FingerprintSyncService::syncableCount());

        $runner->assertEquals('and no longer listed as needing a teacher to re-enrol',
            0, count(array_filter(
                \App\Services\FingerprintSyncService::awaitingRecapture(),
                static fn (array $r): bool => (int) $r['teacher_id'] === $teacherId
            )));

        // The point of the whole exercise: the other room now works.
        $reached = \App\Services\FingerprintSyncService::nextPendingFor($secondDevice);

        $runner->assert('the second terminal is finally offered the template',
            $reached !== null, 'the second terminal still has nothing to collect');

        if ($reached !== null) {
            $runner->assertEquals('intact, all 512 bytes of it',
                512, strlen((string) base64_decode($reached['template'], true)));

            $runner->assertEquals('and it is the template the sensor handed back',
                str_repeat("\x52", 512), (string) base64_decode($reached['template'], true));
        }

        // Asked once, not forever: a recovered template must not keep the
        // terminal re-uploading the same slot on every poll.
        $runner->assertEquals('the terminal is not asked for it again',
            null, \App\Services\FingerprintSyncService::nextBackfillFor($firstDevice));
    }

    /* =====================================================================
     * 24. Wiping a sensor that has drifted, and refilling it
     * ===================================================================== */
    if ($want('sensor-wipe')) {
        $runner->group('24. A drifted sensor can be erased, and fills itself back up');

        $fixture->build(1, 1, 1);

        $device        = (int) $fixture->ids['devices'][0];
        $teacherId     = (int) $fixture->ids['teacher_id'];
        $fingerprintId = (int) $fixture->ids['fingerprint_id'];
        $adminId       = (int) $db->scalar('SELECT user_id FROM users ORDER BY user_id LIMIT 1');

        // A stored template, present on the sensor — the ordinary case.
        $db->update('fingerprint_templates', [
            'sensor_template_id'     => 1,
            'enrolled_device_row_id' => $device,
            'template_data'          => \App\Core\Crypto::encrypt(str_repeat("\x53", 512)),
            'template_bytes'         => 512,
            'template_captured_at'   => Clock::nowString(),
        ], ['fingerprint_id' => $fingerprintId]);

        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f', ['f' => $fingerprintId]);
        $db->insert('fingerprint_slots', [
            'fingerprint_id'     => $fingerprintId,
            'device_row_id'      => $device,
            'sensor_template_id' => 1,
            'source'             => 'enrolled',
            'status'             => 'present',
            'synced_at'          => Clock::nowString(),
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);

        $db->update('devices', ['sensor_template_count' => 4], ['id' => $device]);

        $runner->assertEquals('a terminal is not asked to wipe until somebody asks',
            false, \App\Services\FingerprintSyncService::wipeRequestedFor($device));

        $runner->assertEquals('nothing would be lost while every template has a server copy',
            0, \App\Services\FingerprintSyncService::templatesLostByWiping($device));

        \App\Services\FingerprintSyncService::requestSensorWipe($device, $adminId);

        $runner->assertEquals('the request is recorded for the terminal to collect',
            true, \App\Services\FingerprintSyncService::wipeRequestedFor($device));

        // Asking is not doing. The slot records describe flash that still has
        // something in it until the terminal says otherwise, and dropping them
        // early would make the page claim a sensor was empty while it was
        // still matching fingers.
        $runner->assertEquals('asking does not by itself erase the server\'s record of the sensor',
            1, (int) $db->scalar('SELECT COUNT(*) FROM fingerprint_slots WHERE device_row_id = :d',
                ['d' => $device]));

        // The terminal reports back.
        \App\Services\FingerprintSyncService::confirmSensorWipe($device);

        $runner->assertEquals('the request clears once the terminal confirms',
            false, \App\Services\FingerprintSyncService::wipeRequestedFor($device));

        $runner->assertEquals('and the sensor is recorded as holding nothing',
            0, (int) $db->scalar('SELECT sensor_template_count FROM devices WHERE id = :d',
                ['d' => $device]));

        // The whole reason a wipe is survivable: the refill is automatic.
        $refill = \App\Services\FingerprintSyncService::nextPendingFor($device);

        $runner->assert('the template is queued straight back',
            $refill !== null, 'nothing was queued after the wipe');

        if ($refill !== null) {
            $runner->assertEquals('byte for byte what the server was holding',
                str_repeat("\x53", 512), (string) base64_decode($refill['template'], true));
        }

        // Now the case that must not happen quietly: a template that exists
        // only in the sensor about to be erased.
        $db->update('fingerprint_templates', [
            'template_data'  => null,
            'template_bytes' => null,
        ], ['fingerprint_id' => $fingerprintId]);

        $runner->assertEquals('a template with no server copy is counted as a loss',
            1, \App\Services\FingerprintSyncService::templatesLostByWiping($device));

        $refused = false;

        try {
            \App\Services\FingerprintSyncService::requestSensorWipe($device, $adminId);
        } catch (BusinessRuleException $e) {
            $refused = $e->errorCode() === 'WIPE_WOULD_LOSE_TEMPLATES';
        }

        $runner->assert('and the wipe is refused rather than destroying it silently',
            $refused, 'the wipe went ahead without the loss being accepted');

        $runner->assertEquals('nothing was requested',
            false, \App\Services\FingerprintSyncService::wipeRequestedFor($device));

        // Accepted deliberately, it proceeds — the administrator may know the
        // sensor's contents are stale and worth losing.
        $lost = \App\Services\FingerprintSyncService::requestSensorWipe($device, $adminId, true);

        $runner->assertEquals('accepting the loss says how much of it there is', 1, $lost);

        $runner->assertEquals('and then it proceeds',
            true, \App\Services\FingerprintSyncService::wipeRequestedFor($device));

        // A terminal that never confirms must be asked again, not assumed done.
        $runner->assertEquals('a request the terminal never answered is still outstanding',
            true, \App\Services\FingerprintSyncService::wipeRequestedFor($device));

        \App\Services\FingerprintSyncService::confirmSensorWipe($device);

        $runner->assertEquals('after the wipe there is nothing left to refill from',
            null, \App\Services\FingerprintSyncService::nextPendingFor($device));

        $runner->assertEquals('and no slot rows survive describing flash that is now empty',
            0, (int) $db->scalar('SELECT COUNT(*) FROM fingerprint_slots WHERE device_row_id = :d',
                ['d' => $device]));
    }

    /* =====================================================================
     * 24b. A refused write can be tried again without erasing the sensor
     *
     * The sensor refusing a write is a hardware event — a brown-out on a long
     * lead, a lost serial frame — and says nothing about the template or the
     * teacher. But the slot went to 'failed' and nothing moved it back: the
     * poll reads 'pending' only, and reconcile() inserts missing rows without
     * touching existing ones. So one bad poll left that teacher permanently
     * unrecognised at that reader, and the page's only remedy was erasing the
     * whole sensor — destroying four good templates to recover one, on
     * hardware that had just shown it can refuse a write.
     * ===================================================================== */
    if ($want('sync-retry')) {
        $runner->group('24b. A refused template write can be retried, not just wiped');

        $fixture->build(1, 1, 1);

        $device        = (int) $fixture->ids['devices'][0];
        $fingerprintId = (int) $fixture->ids['fingerprint_id'];
        $adminId       = (int) $db->scalar('SELECT user_id FROM users ORDER BY user_id LIMIT 1');
        $bytes         = str_repeat("\x41", 512);

        $db->update('fingerprint_templates', [
            'sensor_template_id'     => 1,
            'enrolled_device_row_id' => null,
            'template_data'          => \App\Core\Crypto::encrypt($bytes),
            'template_bytes'         => 512,
            'template_captured_at'   => Clock::nowString(),
        ], ['fingerprint_id' => $fingerprintId]);

        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f', ['f' => $fingerprintId]);
        $db->insert('fingerprint_slots', [
            'fingerprint_id'     => $fingerprintId,
            'device_row_id'      => $device,
            'sensor_template_id' => 9,
            'source'             => 'synced',
            'status'             => 'pending',
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);

        $queued = \App\Services\FingerprintSyncService::nextPendingFor($device);

        $runner->assert('the template is offered while it is queued',
            $queued !== null && (int) $queued['slot'] === 9, 'nothing was offered');

        // The sensor refuses it.
        \App\Services\FingerprintSyncService::markFailed($device, 9, 'Sensor refused the write.');

        $runner->assertEquals('a refused write is not offered again on its own',
            null, \App\Services\FingerprintSyncService::nextPendingFor($device));

        $runner->assertEquals('and the terminal is shown as having one failure',
            1, (int) $db->scalar(
                "SELECT COUNT(*) FROM fingerprint_slots WHERE device_row_id = :d AND status = 'failed'",
                ['d' => $device]));

        // Try again.
        $requeued = \App\Services\FingerprintSyncService::retryFailed($device, $adminId);

        $runner->assertEquals('retrying requeues the refused write', 1, $requeued);

        $again = \App\Services\FingerprintSyncService::nextPendingFor($device);

        $runner->assert('and the terminal is offered it once more',
            $again !== null && (int) $again['slot'] === 9, 'nothing was offered after the retry');

        if ($again !== null) {
            // The point of retrying rather than re-enrolling: it is the same
            // template, not a fresh capture the teacher had to stand there for.
            $runner->assertEquals('byte for byte the template that was refused',
                $bytes, (string) base64_decode($again['template'], true));
        }

        $runner->assertEquals('the failure note is cleared, not left to mislead',
            null, $db->scalar(
                'SELECT last_error FROM fingerprint_slots WHERE device_row_id = :d AND sensor_template_id = 9',
                ['d' => $device]));

        // Nothing was destroyed to achieve it — the distinction from a wipe.
        $runner->assertEquals('and nothing else on the sensor was disturbed',
            1, (int) $db->scalar('SELECT COUNT(*) FROM fingerprint_slots WHERE device_row_id = :d',
                ['d' => $device]));

        // Retrying when there is nothing to retry is refused rather than
        // silently reporting success, so the page cannot claim it did
        // something it did not.
        $refused = false;

        try {
            \App\Services\FingerprintSyncService::retryFailed($device, $adminId);
        } catch (\App\Core\Exceptions\BusinessRuleException $e) {
            $refused = $e->errorCode() === 'NOTHING_TO_RETRY';
        }

        $runner->assert('retrying with nothing failed is refused, not a silent no-op',
            $refused, 'a second retry reported success');
    }

    /* =====================================================================
     * 25. The two panels on the Fingerprints page tell the same story
     * ===================================================================== */
    if ($want('sensor-agreement')) {
        $runner->group('25. A synced template is not reported as an intruder');

        $fixture->build(1, 1, 2);

        $enrolledOn    = (int) $fixture->ids['devices'][0];
        $syncedTo      = (int) $fixture->ids['devices'][1];
        $fingerprintId = (int) $fixture->ids['fingerprint_id'];

        $db->update('fingerprint_templates', [
            'sensor_template_id'     => 1,
            'enrolled_device_row_id' => $enrolledOn,
            'template_data'          => \App\Core\Crypto::encrypt(str_repeat("\x55", 512)),
            'template_bytes'         => 512,
            'template_captured_at'   => Clock::nowString(),
        ], ['fingerprint_id' => $fingerprintId]);

        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f', ['f' => $fingerprintId]);

        // Present on both: enrolled at the first, copied to the second. This is
        // the ordinary state of any school with more than one terminal.
        foreach ([$enrolledOn => 'enrolled', $syncedTo => 'synced'] as $deviceRowId => $source) {
            $db->insert('fingerprint_slots', [
                'fingerprint_id'     => $fingerprintId,
                'device_row_id'      => (int) $deviceRowId,
                'sensor_template_id' => 1,
                'source'             => $source,
                'status'             => 'present',
                'synced_at'          => Clock::nowString(),
                'created_at'         => Clock::nowString(),
                'updated_at'         => Clock::nowString(),
            ]);
        }

        // Both sensors report holding the one template they hold.
        $db->update('devices', ['sensor_template_count' => 1, 'sensor_reported_at' => Clock::nowString()],
            ['id' => $enrolledOn]);
        $db->update('devices', ['sensor_template_count' => 1, 'sensor_reported_at' => Clock::nowString()],
            ['id' => $syncedTo]);

        $flagged = array_map(
            static fn (array $r): int => (int) $r['device_row_id'],
            \App\Services\FingerprintService::sensorMismatches()
        );

        $runner->assert('the terminal that did the enrolling is not flagged',
            !in_array($enrolledOn, $flagged, true), 'the enrolling terminal was reported as mismatched');

        // The bug: a terminal holding a template it did not enrol counted as
        // holding a template belonging to nobody, and the page told the
        // administrator to wipe a sensor that was working.
        $runner->assert('nor is the terminal that merely received a copy',
            !in_array($syncedTo, $flagged, true),
            'a synced template was reported as belonging to nobody');

        // And the two panels agree, which is the property that actually
        // matters — one page saying "4 of 4 complete" and "0 are recorded
        // here" about the same sensor leaves nobody knowing which to believe.
        $coverage = [];

        foreach (\App\Services\FingerprintSyncService::terminalStatus() as $row) {
            $coverage[(int) $row['device_row_id']] = $row;
        }

        $runner->assertEquals('the coverage table calls the synced terminal complete',
            true, (bool) ($coverage[$syncedTo]['complete'] ?? false));

        $runner->assertEquals('and counts the template it holds',
            1, (int) ($coverage[$syncedTo]['present'] ?? -1));

        // A genuine divergence must still be caught, or the fix has simply
        // silenced the alarm.
        $db->update('devices', ['sensor_template_count' => 4], ['id' => $syncedTo]);

        $flagged = [];

        foreach (\App\Services\FingerprintService::sensorMismatches() as $row) {
            $flagged[(int) $row['device_row_id']] = $row;
        }

        $runner->assert('a sensor holding more than its records is still reported',
            isset($flagged[$syncedTo]), 'three orphan templates went unreported');

        $runner->assertEquals('and the shortfall is stated correctly',
            1, (int) ($flagged[$syncedTo]['expected'] ?? -1));

        // The other direction: a sensor that was wiped while the records stayed.
        $db->update('devices', ['sensor_template_count' => 0], ['id' => $syncedTo]);

        $flagged = [];

        foreach (\App\Services\FingerprintService::sensorMismatches() as $row) {
            $flagged[(int) $row['device_row_id']] = $row;
        }

        $runner->assert('an erased sensor with records left behind is reported too',
            isset($flagged[$syncedTo]), 'an emptied sensor went unreported');

        // A slot still queued is not in the sensor yet, so it must not be
        // counted as something the sensor ought to be holding.
        $db->update('devices', ['sensor_template_count' => 1], ['id' => $syncedTo]);
        $db->update('fingerprint_slots', ['status' => 'pending'],
            ['device_row_id' => $syncedTo, 'fingerprint_id' => $fingerprintId]);

        $flagged = array_map(
            static fn (array $r): int => (int) $r['device_row_id'],
            \App\Services\FingerprintService::sensorMismatches()
        );

        $runner->assert('a template still queued is not counted as already in the sensor',
            in_array($syncedTo, $flagged, true),
            'a pending slot was counted as present');
    }

    /* =====================================================================
     * 25b. Archiving is reversible, and the archive can be looked at
     *
     * Archiving a section or a department set deleted_at, and every listing
     * query filtered deleted_at IS NULL, so the row left the interface with no
     * way back and no way to see it had happened. The Sections page made it
     * worse by appearing to offer the opposite: its status filter has an
     * "Archived" option, but v_section_summary filtered the archived rows out
     * before the filter ran, so it returned nothing every time — which reads
     * as "there are none".
     * ===================================================================== */
    if ($want('archive-restore')) {
        $runner->group('25b. An archived section or department can be found and restored');

        $section = $db->selectOne('SELECT section_id, section_code FROM sections WHERE deleted_at IS NULL LIMIT 1');
        $sectionId = (int) $section['section_id'];
        $code      = (string) $section['section_code'];

        // archiveSection refuses while students are enrolled, which is its own
        // guard and not what is under test here.
        $db->execute("UPDATE students SET status = 'inactive' WHERE section_id = :i", ['i' => $sectionId]);

        $liveBefore = count(\App\Services\AcademicStructureService::sections());

        \App\Services\AcademicStructureService::archiveSection($sectionId);

        $live     = array_column(\App\Services\AcademicStructureService::sections(), 'section_code');
        $archived = array_column(
            \App\Services\AcademicStructureService::sections(['status' => 'archived']),
            'section_code'
        );

        $runner->assert('an archived section leaves the live list',
            !in_array($code, $live, true), 'it was still listed');

        $runner->assertEquals('and the live list is one shorter', $liveBefore - 1, count($live));

        // The assertion this whole group exists for.
        $runner->assert('the Archived filter returns it rather than nothing',
            in_array($code, $archived, true), 'the archived list came back empty');

        $runner->assertEquals('and returns only archived rows', 1, count($archived));

        $leftArchived = \App\Services\AcademicStructureService::restoreSection($sectionId);

        $runner->assert('restoring puts it back in the live list',
            in_array($code, array_column(\App\Services\AcademicStructureService::sections(), 'section_code'), true),
            'it did not come back');

        // Inactive, not active: undoing a deletion is not the same as saying
        // the section is running again, and it has no timetable at this point.
        $runner->assertEquals('it comes back inactive rather than active',
            'inactive', (string) $db->scalar('SELECT status FROM sections WHERE section_id = :i', ['i' => $sectionId]));

        // The schedules stay archived on purpose — those periods may since
        // have been given away — and the count is returned so the interface
        // can say so instead of leaving it to be discovered.
        $runner->assert('the schedules left archived are reported back',
            $leftArchived >= 0, 'no count was returned');

        $refused = false;

        try {
            \App\Services\AcademicStructureService::restoreSection($sectionId);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refused = true;
        }

        $runner->assert('restoring one that is not archived is refused',
            $refused, 'a second restore was accepted');

        // --- departments ---
        $department = $db->selectOne(
            'SELECT department_id, department_name FROM departments WHERE deleted_at IS NULL LIMIT 1'
        );
        $departmentId = (int) $department['department_id'];
        $departmentName = (string) $department['department_name'];

        // Same idea as the students above: clear what archiveDepartment
        // legitimately refuses over, so the archive itself is what is tested.
        // teachers.department_id is NOT NULL, so they are moved rather than
        // detached.
        $elsewhere = (int) $db->scalar(
            'SELECT department_id FROM departments WHERE department_id <> :i AND deleted_at IS NULL LIMIT 1',
            ['i' => $departmentId]
        );

        $db->execute('UPDATE subjects SET deleted_at = NOW() WHERE department_id = :i', ['i' => $departmentId]);
        $db->execute(
            'UPDATE teachers SET department_id = :to WHERE department_id = :i',
            ['to' => $elsewhere, 'i' => $departmentId]
        );

        \App\Services\AcademicStructureService::archiveDepartment($departmentId, true);

        $runner->assert('an archived department leaves the live list',
            !in_array($departmentName,
                array_column(\App\Services\AcademicStructureService::departments(), 'department_name'), true),
            'it was still listed');

        $runner->assert('and the archived list returns it',
            in_array($departmentName,
                array_column(\App\Services\AcademicStructureService::departments(false, true), 'department_name'), true),
            'the archived list came back empty');

        \App\Services\AcademicStructureService::restoreDepartment($departmentId);

        $runner->assert('restoring puts it back',
            in_array($departmentName,
                array_column(\App\Services\AcademicStructureService::departments(), 'department_name'), true),
            'it did not come back');

        $runner->assertEquals('and the archived list is empty again',
            0, count(\App\Services\AcademicStructureService::departments(false, true)));
    }

    /* =====================================================================
     * 25c. A subject code can be corrected
     *
     * It could not be. The edit form set the field readOnly and updateSubject()
     * simply never wrote the column, so a typo in a code was permanent with no
     * message saying why — the field just would not take. The rule was copied
     * from departments, where the code IS immutable because exports and
     * integrations key on it. Nothing keys on a subject code: schedules,
     * sessions and attendance records all carry subject_id, no query looks a
     * subject up by code, and no table snapshots it beside the history. The
     * subject *name* next to it was always editable.
     * ===================================================================== */
    if ($want('subject-rename')) {
        $runner->group('25c. A subject code can be corrected, and history is not disturbed');

        $fixture->build(1, 1, 1);

        $subjectId    = (int) $fixture->ids['subject_id'];
        $originalCode = (string) $db->scalar(
            'SELECT subject_code FROM subjects WHERE subject_id = :i', ['i' => $subjectId]);
        $departmentId = (int) $db->scalar(
            'SELECT department_id FROM subjects WHERE subject_id = :i', ['i' => $subjectId]);

        $historyBefore = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE subject_id = :i', ['i' => $subjectId]);
        $schedulesBefore = (int) $db->scalar(
            'SELECT COUNT(*) FROM schedules WHERE subject_id = :i', ['i' => $subjectId]);

        \App\Services\AcademicStructureService::updateSubject($subjectId, [
            'subject_code'  => 'lstest-ren',
            'subject_name'  => 'Renamed Subject',
            'department_id' => $departmentId,
        ]);

        $runner->assertEquals('the code is written, and upper-cased as it is on creation',
            'LSTEST-REN', (string) $db->scalar(
                'SELECT subject_code FROM subjects WHERE subject_id = :i', ['i' => $subjectId]));

        // The reason this is safe: nothing joined on the code in the first
        // place, so renaming cannot orphan a record.
        $runner->assertEquals('attendance history is untouched', $historyBefore,
            (int) $db->scalar('SELECT COUNT(*) FROM attendance_records WHERE subject_id = :i', ['i' => $subjectId]));

        $runner->assertEquals('schedules are untouched', $schedulesBefore,
            (int) $db->scalar('SELECT COUNT(*) FROM schedules WHERE subject_id = :i', ['i' => $subjectId]));

        // A code already in use must be refused rather than written, and the
        // unique index is what decides — not a check that could race.
        $taken = (string) $db->scalar(
            'SELECT subject_code FROM subjects WHERE subject_id <> :i AND deleted_at IS NULL LIMIT 1',
            ['i' => $subjectId]
        );

        $refused = false;

        try {
            \App\Services\AcademicStructureService::updateSubject($subjectId, [
                'subject_code'  => $taken,
                'subject_name'  => 'Renamed Subject',
                'department_id' => $departmentId,
            ]);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refused = true;
        }

        $runner->assert('a code already in use is refused', $refused, 'the duplicate was accepted');

        $runner->assertEquals('and the subject keeps its own code after the refusal',
            'LSTEST-REN', (string) $db->scalar(
                'SELECT subject_code FROM subjects WHERE subject_id = :i', ['i' => $subjectId]));

        // Every other caller of updateSubject() omits the code. It must not be
        // read as "blank it".
        \App\Services\AcademicStructureService::updateSubject($subjectId, [
            'subject_name'  => 'Renamed Again',
            'department_id' => $departmentId,
        ]);

        $runner->assertEquals('omitting the code leaves it alone',
            'LSTEST-REN', (string) $db->scalar(
                'SELECT subject_code FROM subjects WHERE subject_id = :i', ['i' => $subjectId]));

        // Renaming reference data has to be answerable for afterwards.
        $audited = $db->select(
            "SELECT old_value, new_value FROM audit_logs
              WHERE record_type = 'subject' AND record_id = :i",
            ['i' => $subjectId]
        );

        $rename = array_values(array_filter(
            $audited,
            static fn (array $r): bool => str_contains((string) $r['new_value'], 'LSTEST-REN')
        ));

        $runner->assert('the rename is in the audit log', $rename !== [], 'no audit row named the new code');

        if ($rename !== []) {
            $runner->assert('with the code it replaced',
                str_contains((string) $rename[0]['old_value'], $originalCode),
                (string) $rename[0]['old_value']);
        }
    }

    /* =====================================================================
     * 25d. A reader that cannot answer says so
     *
     * The teacher panel polled sessionStartState() and, with no attempt
     * logged, reported "Waiting for your fingerprint - place your finger on
     * the terminal now" forever. That is indistinguishable from every way this
     * actually fails, because the two likeliest failures never reach the
     * server: the firmware posts to /api/attendance/start only after its
     * sensor matches a print, so a sensor that did not initialise returns at
     * the first line of handleFingerprint(), and a sensor with no copy of the
     * teacher's template answers NOTFOUND and returns. Neither writes a
     * fingerprint_logs row. The reason was already in the database - the
     * heartbeat carries the sensor's health, and fingerprint_slots records
     * what the terminal holds - and none of it was shown to the person
     * standing at the reader.
     * ===================================================================== */
    if ($want('reader-silent')) {
        $runner->group('25d. A silent reader explains itself instead of spinning');

        $fixture->build(1, 1, 1);

        $teacherId = (int) $fixture->ids['teacher_id'];
        $deviceId  = (int) $fixture->ids['devices'][0];
        $deviceCode = (string) $db->scalar('SELECT device_id FROM devices WHERE id = :i', ['i' => $deviceId]);
        $fingerprintId = (int) $fixture->ids['fingerprint_id'];

        // Put the fixture's schedule under way now, so a scan is expected.
        $db->execute(
            'UPDATE schedules SET day_of_week = :d, start_time = :s, end_time = :e
              WHERE teacher_id = :t',
            [
                'd' => Clock::now()->format('l'),
                's' => Clock::now()->modify('-5 minutes')->format('H:i:s'),
                // chk_sched_windows wants duration - time_out_window_open >
                // time_in_window_close, and the fixture uses 30 and 60, so the
                // period has to run longer than 90 minutes to be legal.
                'e' => Clock::now()->modify('+120 minutes')->format('H:i:s'),
                't' => $teacherId,
            ]
        );

        $db->execute(
            "UPDATE devices SET fingerprint_ok = 1, last_heartbeat_at = :n, heartbeat_interval_sec = 30
              WHERE id = :i",
            ['n' => Clock::nowString(), 'i' => $deviceId]
        );

        $slot = static function (string $status) use ($db, $fingerprintId, $deviceId): void {
            $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f AND device_row_id = :d',
                ['f' => $fingerprintId, 'd' => $deviceId]);
            $db->execute(
                'INSERT INTO fingerprint_slots (fingerprint_id, device_row_id, sensor_template_id, source, status, created_at, updated_at)
                 VALUES (:f, :d, 77, \'synced\', :s, :n, :n2)',
                ['f' => $fingerprintId, 'd' => $deviceId, 's' => $status,
                 'n' => Clock::nowString(), 'n2' => Clock::nowString()]
            );
        };

        $state = static fn (): array => \App\Services\TeacherService::sessionStartState($teacherId, null);

        // Healthy: a plain wait, and no invented problem.
        $slot('present');
        $healthy = $state();

        $runner->assert('a healthy reader leaves an ordinary wait',
            !isset($healthy['blocked']), (string) ($healthy['blocked'] ?? ''));

        $runner->assertEquals('and the terminal is still named', $deviceCode, (string) ($healthy['terminal'] ?? ''));

        // The case that reads as "not recognised" at the terminal and as
        // nothing at all here, because a search that found nothing is not
        // posted.
        $db->execute('DELETE FROM fingerprint_slots WHERE fingerprint_id = :f AND device_row_id = :d',
            ['f' => $fingerprintId, 'd' => $deviceId]);
        $missing = $state();

        $runner->assertEquals('a template the terminal does not hold is named',
            'template_missing', (string) ($missing['blocked'] ?? ''));

        $runner->assert('and the message names the terminal',
            str_contains((string) $missing['blocked_message'], $deviceCode), 'the terminal was not named');

        $slot('pending');
        $runner->assert('a template still copying is distinguished from one that never will',
            str_contains((string) ($state()['blocked_message'] ?? ''), 'not finished copying'),
            (string) ($state()['blocked_message'] ?? ''));

        $slot('failed');
        $runner->assert('a refused write points at the retry an administrator can run',
            str_contains((string) ($state()['blocked_message'] ?? ''), 'refused'),
            (string) ($state()['blocked_message'] ?? ''));

        // A dead sensor outranks the template state: a sensor that never
        // started cannot be missing a template.
        $slot('present');
        $db->execute('UPDATE devices SET fingerprint_ok = 0 WHERE id = :i', ['i' => $deviceId]);
        $down = $state();

        $runner->assertEquals('a sensor reporting itself dead is named as a hardware fault',
            'sensor_down', (string) ($down['blocked'] ?? ''));

        $runner->assert('and says plainly that scanning again cannot help',
            str_contains((string) $down['blocked_message'], 'will not help'), (string) $down['blocked_message']);

        // Offline outranks both: a dead terminal cannot report a dead sensor,
        // so reporting the stale flag would describe a symptom.
        $db->execute('UPDATE devices SET last_heartbeat_at = :n WHERE id = :i',
            ['n' => Clock::now()->modify('-30 minutes')->format('Y-m-d H:i:s'), 'i' => $deviceId]);

        $runner->assertEquals('an offline terminal outranks its own stale sensor flag',
            'terminal_offline', (string) ($state()['blocked'] ?? ''));

        // The window this panel explains over must be the window the reader is
        // actually open for. It was hardcoded to "ten minutes before the start
        // until the end time", which was wrong at both ends: it ignored
        // time_in_window_open, which a school may set to anything, and it
        // stopped at end_time, so a teacher scanning during the tap-out tail
        // got no diagnosis while the terminal was still willing to be scanned
        // at. It now uses the same expression as activeForDevice().
        $db->execute(
            "UPDATE devices SET fingerprint_ok = 0, last_heartbeat_at = :n WHERE id = :i",
            ['n' => Clock::nowString(), 'i' => $deviceId]
        );

        // A lesson that ended five minutes ago, with twenty minutes of tap-out
        // window still to run.
        $db->execute(
            'UPDATE schedules SET start_time = :s, end_time = :e,
                    time_in_window_open = 10, time_in_window_close = 60,
                    time_out_window_open = 30, time_out_window_close = 20
              WHERE teacher_id = :t',
            [
                's' => Clock::now()->modify('-125 minutes')->format('H:i:s'),
                'e' => Clock::now()->modify('-5 minutes')->format('H:i:s'),
                't' => $teacherId,
            ]
        );

        $runner->assert('the tap-out tail is still explained, not silent',
            isset($state()['blocked']), 'no diagnosis during the tap-out window');

        // A pre-window wider than the ten minutes that used to be assumed.
        $db->execute(
            'UPDATE schedules SET start_time = :s, end_time = :e, time_in_window_open = 25
              WHERE teacher_id = :t',
            [
                's' => Clock::now()->modify('+20 minutes')->format('H:i:s'),
                'e' => Clock::now()->modify('+140 minutes')->format('H:i:s'),
                't' => $teacherId,
            ]
        );

        $runner->assert('a pre-window wider than ten minutes is honoured',
            isset($state()['blocked']), 'time_in_window_open was ignored');
    }

    /* =====================================================================
     * 25e. A refusal names who the finger was taken to be
     *
     * "You are not the assigned teacher for the current class in this room" is
     * true and useless. It does not say who the finger resolved to, and that
     * is the thing most likely to be wrong: a slot number identifies a person
     * only together with the sensor that allocated it, so a sensor holding a
     * template this server has no record of can match a finger to a slot that
     * resolves to somebody else. The refusal then lands on a teacher who IS
     * assigned, at the right terminal, in the right room, being told they are
     * not who they are.
     *
     * The per-teacher verification log cannot show it either - it filters on
     * the resolved teacher_id, so the attempt is filed under the wrong person
     * and the real teacher's log looks empty. This message is the only place
     * the mismatch surfaces.
     * ===================================================================== */
    if ($want('wrong-teacher-named')) {
        $runner->group('25e. A refused scan names both teachers, not just "not you"');

        $fixture->build(1, 1, 1);

        $assignedId = (int) $fixture->ids['teacher_id'];
        $deviceId   = (int) $fixture->ids['devices'][0];
        $device     = $db->selectOne('SELECT * FROM devices WHERE id = :i', ['i' => $deviceId]);

        $assigned = $db->selectOne(
            'SELECT first_name, last_name FROM teachers WHERE teacher_id = :i', ['i' => $assignedId]);
        $assignedName = trim($assigned['first_name'] . ' ' . $assigned['last_name']);

        // Put the fixture's lesson under way, so a class IS openable here and
        // the refusal is about identity rather than about timing.
        $db->execute(
            'UPDATE schedules SET day_of_week = :d, start_time = :s, end_time = :e WHERE teacher_id = :t',
            [
                'd' => Clock::now()->format('l'),
                's' => Clock::now()->modify('-10 minutes')->format('H:i:s'),
                'e' => Clock::now()->modify('+110 minutes')->format('H:i:s'),
                't' => $assignedId,
            ]
        );

        // Another teacher's template, sitting in a slot on this terminal - the
        // "sensor holds a template we have no record of" case, made explicit.
        $other = $db->selectOne(
            'SELECT teacher_id, first_name, last_name FROM teachers
              WHERE teacher_id <> :i AND deleted_at IS NULL LIMIT 1',
            ['i' => $assignedId]
        );
        $otherId   = (int) $other['teacher_id'];
        $otherName = trim($other['first_name'] . ' ' . $other['last_name']);

        $db->execute('DELETE FROM fingerprint_templates WHERE teacher_id = :t', ['t' => $otherId]);
        $strayFp = (int) $db->insert('fingerprint_templates', [
            'teacher_id'         => $otherId,
            'sensor_template_id' => 7,
            'status'             => 'active',
            'enrollment_date'    => Clock::nowString(),
            'created_at'         => Clock::nowString(),
            'updated_at'         => Clock::nowString(),
        ]);
        $db->execute(
            'INSERT INTO fingerprint_slots (fingerprint_id, device_row_id, sensor_template_id, source, status, created_at, updated_at)
             VALUES (:f, :d, 7, \'synced\', \'present\', :n, :n2)',
            ['f' => $strayFp, 'd' => $deviceId, 'n' => Clock::nowString(), 'n2' => Clock::nowString()]
        );

        $message = '';

        try {
            \App\Services\FingerprintService::verifyAndOpenSession($device, 7, 180);
        } catch (\App\Core\Exceptions\BusinessRuleException $e) {
            $message = $e->getMessage();
        }

        $runner->assert('the scan is refused', $message !== '', 'the session opened');

        $runner->assert('the message names who the finger resolved to',
            str_contains($message, $otherName), $message);

        $runner->assert('and who the class actually belongs to',
            str_contains($message, $assignedName), $message);

        $runner->assert('and points at clearing the sensor as the remedy',
            str_contains($message, 'clear this terminal'), $message);

        // The old wording, which said neither name, must not come back.
        $runner->assert('it no longer says only "you are not the assigned teacher"',
            !str_contains($message, 'You are not the assigned teacher'), $message);

        // An empty room is a different problem and keeps its own wording.
        $db->execute(
            // Still >90 minutes long, or chk_sched_windows rejects it; just
            // nowhere near now, so no class is openable here.
            'UPDATE schedules SET start_time = \'20:00:00\', end_time = \'23:59:00\' WHERE teacher_id = :t',
            ['t' => $assignedId]
        );

        $noClass = '';

        try {
            \App\Services\FingerprintService::verifyAndOpenSession($device, 7, 180);
        } catch (\App\Core\Exceptions\BusinessRuleException $e) {
            $noClass = $e->getMessage();
        }

        $runner->assert('a room with no class running still says exactly that',
            str_contains($noClass, 'No class is scheduled in this room'), $noClass);
    }

    /* =====================================================================
     * 25g. A subject can be removed, and refuses when it should
     *
     * Subjects were the only thing in Academic Setup with no way to remove
     * them at all - departments archive, sections archive, subjects had
     * nothing. A duplicate created by a typo or left behind by a change of
     * naming stayed in every dropdown for good.
     *
     * The guard that matters is the schedule one, and it is a hard stop rather
     * than a confirmable warning: ScheduleService does not check a subject
     * status when deciding what a terminal may open, so a schedule whose
     * subject has been archived goes on opening classes every day for a
     * subject the school believes it removed.
     * ===================================================================== */
    if ($want('subject-archive')) {
        $runner->group('25g. A subject archives, unless a live schedule needs it');

        $fixture->build(1, 1, 1);

        $scheduled = (int) $fixture->ids['subject_id'];

        // On a live schedule: refused outright, whatever the caller confirms.
        $refusal = '';

        try {
            \App\Services\AcademicStructureService::archiveSubject($scheduled, true);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refusal = json_encode($e->errors());
        }

        $runner->assert('a subject on a live schedule is refused even when confirmed',
            $refusal !== '', 'it archived anyway');

        $runner->assert('and the refusal explains that the schedule would keep running',
            str_contains($refusal, 'keeps running'), $refusal);

        // Named, not counted. The Schedules list has no subject filter, so a
        // bare count leaves somebody scrolling a week's timetable looking for
        // rows they cannot identify.
        $blocking = $db->selectOne(
            "SELECT sch.day_of_week, sec.section_code
               FROM schedules sch
               JOIN sections sec ON sec.section_id = sch.section_id
              WHERE sch.subject_id = :i AND sch.status = 'active' AND sch.deleted_at IS NULL
              LIMIT 1",
            ['i' => $scheduled]
        );

        $runner->assert('and names the day of a schedule that is blocking it',
            str_contains($refusal, (string) $blocking['day_of_week']), $refusal);

        $runner->assert('and the section, so it can be found on sight',
            str_contains($refusal, (string) $blocking['section_code']), $refusal);

        $runner->assertEquals('and the subject is untouched',
            null, $db->scalar('SELECT deleted_at FROM subjects WHERE subject_id = :i', ['i' => $scheduled]));

        // Take the schedule out of the way, and make sure a qualification
        // exists so the confirmation branch is actually exercised — the
        // fixture does not create one.
        $db->execute("UPDATE schedules SET status = 'archived', deleted_at = NOW() WHERE subject_id = :i",
            ['i' => $scheduled]);
        $db->execute(
            'INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, is_exception) VALUES (:t, :s, 1)',
            ['t' => (int) $fixture->ids['teacher_id'], 's' => $scheduled]
        );

        $impact = \App\Services\AcademicStructureService::subjectArchiveImpact($scheduled);

        $runner->assert('the impact lists the teachers it would unassign',
            $impact['teachers'] !== [], 'no teachers reported');

        $runner->assert('and reports the attendance it will not touch',
            array_key_exists('attendance_records', $impact), 'attendance was not counted');

        $needsConfirming = false;

        try {
            \App\Services\AcademicStructureService::archiveSubject($scheduled, false);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $needsConfirming = true;
        }

        $runner->assert('unconfirmed archiving with teachers attached is refused',
            $needsConfirming, 'it archived without confirmation');

        $attendanceBefore = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records WHERE subject_id = :i', ['i' => $scheduled]);

        \App\Services\AcademicStructureService::archiveSubject($scheduled, true);

        $runner->assert('confirmed, it archives',
            $db->scalar('SELECT deleted_at FROM subjects WHERE subject_id = :i', ['i' => $scheduled]) !== null,
            'it did not archive');

        $runner->assertEquals('the teacher qualifications go with it',
            0, (int) $db->scalar('SELECT COUNT(*) FROM teacher_subjects WHERE subject_id = :i', ['i' => $scheduled]));

        // The promise the confirmation dialog makes.
        $runner->assertEquals('and not one attendance record is altered',
            $attendanceBefore,
            (int) $db->scalar('SELECT COUNT(*) FROM attendance_records WHERE subject_id = :i', ['i' => $scheduled]));

        $runner->assert('it leaves the live list',
            !in_array($scheduled, array_column(\App\Services\AcademicStructureService::subjects(), 'subject_id'), true),
            'still listed');

        $runner->assert('and is reachable in the archived list',
            in_array($scheduled, array_column(
                \App\Services\AcademicStructureService::subjects(['archived' => true]), 'subject_id'), true),
            'not in the archive');

        \App\Services\AcademicStructureService::restoreSubject($scheduled);

        $runner->assert('restoring brings it back',
            in_array($scheduled, array_column(\App\Services\AcademicStructureService::subjects(), 'subject_id'), true),
            'it did not come back');

        $twice = false;

        try {
            \App\Services\AcademicStructureService::restoreSubject($scheduled);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $twice = true;
        }

        $runner->assert('restoring one that is not archived is refused', $twice, 'a second restore was accepted');

        // Who teaches this. The Subjects page showed a count and nothing else,
        // so "3" meant going to Teachers, filtering, and reading down a list.
        $db->execute(
            'INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, is_exception) VALUES (:t, :s, 1)',
            ['t' => (int) $fixture->ids['teacher_id'], 's' => $scheduled]
        );

        $who = \App\Services\AcademicStructureService::subjectTeachers($scheduled);

        $runner->assertEquals('the subject names its teachers, not just how many', 1, count($who));

        $runner->assertEquals('with the employee number',
            (string) $db->scalar('SELECT employee_number FROM teachers WHERE teacher_id = :i',
                ['i' => (int) $fixture->ids['teacher_id']]),
            (string) ($who[0]['employee_number'] ?? ''));

        // The interesting half: qualified through their own department is
        // unremarkable, qualified across one was somebody's decision.
        $runner->assertEquals('and whether the qualification is a cross-department exception',
            1, (int) ($who[0]['is_exception'] ?? 0));

        $runner->assert('and how many classes they actually take in it',
            array_key_exists('schedule_count', $who[0]), 'no class count returned');

        // An archived teacher is not somebody to go and ask.
        $db->execute('UPDATE teachers SET deleted_at = NOW() WHERE teacher_id = :i',
            ['i' => (int) $fixture->ids['teacher_id']]);

        $runner->assertEquals('an archived teacher drops off the list',
            0, count(\App\Services\AcademicStructureService::subjectTeachers($scheduled)));
    }

    /* =====================================================================
     * 25h. A classroom can be removed, and refuses when it cannot
     *
     * Classrooms could be created and listed and nothing else - no edit, no
     * removal - so a room added by mistake stayed in the list and in every
     * schedule dropdown for good.
     *
     * Both guards are about things that would go on working invisibly. A
     * terminal resolves its classroom from this record rather than the other
     * way round, so archiving the room out from under a live terminal leaves
     * it opening sessions in a room the school believes it removed; and
     * ScheduleService does not check a classroom status when deciding what may
     * open, the same argument as subjects.
     * ===================================================================== */
    if ($want('classroom-archive')) {
        $runner->group('25h. A classroom archives, unless a terminal or schedule needs it');

        $fixture->build(1, 1, 1);

        $roomId   = (int) $db->scalar('SELECT classroom_id FROM schedules WHERE teacher_id = :t LIMIT 1',
            ['t' => (int) $fixture->ids['teacher_id']]);
        $deviceId = (int) $fixture->ids['devices'][0];

        // A terminal is registered here, and that alone is a refusal.
        $db->execute("UPDATE devices SET classroom_id = :c, status = 'active' WHERE id = :i",
            ['c' => $roomId, 'i' => $deviceId]);

        $refusal = '';

        try {
            \App\Services\AcademicStructureService::archiveClassroom($roomId);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refusal = json_encode($e->errors());
        }

        $runner->assert('a room holding a registered terminal is refused', $refusal !== '', 'it archived anyway');

        $runner->assert('and the refusal names the terminal',
            str_contains($refusal, (string) $db->scalar('SELECT device_id FROM devices WHERE id = :i', ['i' => $deviceId])),
            $refusal);

        // Move the terminal away; the schedule is now what blocks it.
        $db->execute('UPDATE devices SET classroom_id = NULL WHERE id = :i', ['i' => $deviceId]);

        $refusal = '';

        try {
            \App\Services\AcademicStructureService::archiveClassroom($roomId);
        } catch (\App\Core\Exceptions\ValidationException $e) {
            $refusal = json_encode($e->errors());
        }

        $runner->assert('a room on a live schedule is refused', $refusal !== '', 'it archived anyway');

        $runner->assert('and the schedules are named, not just counted',
            str_contains($refusal, 'Monday') || str_contains($refusal, 'Tuesday')
            || str_contains($refusal, 'Wednesday') || str_contains($refusal, 'Thursday')
            || str_contains($refusal, 'Friday') || str_contains($refusal, 'Saturday')
            || str_contains($refusal, 'Sunday'),
            $refusal);

        // Clear the schedule and it goes.
        $db->execute("UPDATE schedules SET status = 'archived', deleted_at = NOW() WHERE classroom_id = :c",
            ['c' => $roomId]);

        $attendanceBefore = (int) $db->scalar('SELECT COUNT(*) FROM attendance_records');

        \App\Services\AcademicStructureService::archiveClassroom($roomId);

        $runner->assert('with nothing holding it, the room archives',
            $db->scalar('SELECT deleted_at FROM classrooms WHERE classroom_id = :i', ['i' => $roomId]) !== null,
            'it did not archive');

        $runner->assertEquals('and no attendance is touched',
            $attendanceBefore, (int) $db->scalar('SELECT COUNT(*) FROM attendance_records'));

        $runner->assert('it leaves the live list',
            !in_array($roomId, array_column(\App\Services\AcademicStructureService::classrooms(), 'classroom_id'), true),
            'still listed');

        $runner->assert('and is reachable in the archived list',
            in_array($roomId, array_column(
                \App\Services\AcademicStructureService::classrooms(false, true), 'classroom_id'), true),
            'not in the archive');

        \App\Services\AcademicStructureService::restoreClassroom($roomId);

        $runner->assert('restoring brings it back',
            in_array($roomId, array_column(\App\Services\AcademicStructureService::classrooms(), 'classroom_id'), true),
            'it did not come back');
    }

    /* =====================================================================
     * 26. Sustained soak (opt-in, 30 minutes)
     * ===================================================================== */
    if (($options['load'] ?? false) && $want('load')) {
        $runner->group('26. Sustained load: 100 taps/minute for 30 minutes');

        $fixture->build(1, 120, 1);
        $device  = $fixture->device(0);
        $session = AttendanceSessionService::open($device, $fixture->teacher(), $fixture->schedule(0), 0);

        $cards      = $fixture->ids['cards'][$fixture->ids['sections'][0]];
        $deadline   = time() + 1800;
        $latencies  = [];
        $errors     = 0;
        $processed  = 0;

        $runner->info('This runs for 30 minutes. Ctrl+C to abort.');

        while (time() < $deadline) {
            $minuteEnd = time() + 60;

            for ($i = 0; $i < 100 && time() < $minuteEnd; $i++) {
                $card    = $cards[array_rand($cards)];
                $started = microtime(true);

                try {
                    AttendanceService::tap($device, $card);
                    $processed++;
                } catch (BusinessRuleException) {
                    $processed++;
                } catch (Throwable $e) {
                    $errors++;
                }

                $latencies[] = microtime(true) - $started;
                usleep(600000 - (int) ((microtime(true) - $started) * 1000000));
            }

            $runner->info(sprintf('%d processed, %d errors, %d minute(s) remaining',
                $processed, $errors, (int) (($deadline - time()) / 60)));
        }

        sort($latencies);
        $p95 = $latencies[(int) floor(count($latencies) * 0.95)];

        $runner->metric('total processed', (string) $processed);
        $runner->metric('p95 latency', sprintf('%.1f ms', $p95 * 1000));

        $runner->assertEquals('zero unexpected errors under sustained load', 0, $errors);
        $runner->assert('p95 stays under 800 ms', $p95 < 0.8, sprintf('%.1f ms', $p95 * 1000));
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\n\033[31m  Suite aborted: " . $e->getMessage() . "\033[0m\n");
    fwrite(STDERR, '  at ' . $e->getFile() . ':' . $e->getLine() . "\n\n");

    if (!($options['keep'] ?? false)) {
        $fixture->teardown();
    }

    exit(1);
}

if (!($options['keep'] ?? false)) {
    fwrite(STDOUT, "\n\033[90m  Cleaning up test data…\033[0m\n");
    $fixture->teardown();
}

exit($runner->summary());
