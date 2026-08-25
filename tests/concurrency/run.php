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
            'units'         => 1,
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
     * 17. Sustained soak (opt-in, 30 minutes)
     * ===================================================================== */
    if (($options['load'] ?? false) && $want('load')) {
        $runner->group('17. Sustained load: 100 taps/minute for 30 minutes');

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
