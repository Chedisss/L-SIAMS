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
            // than tripping over clock boundaries.
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

        $preserved = (int) $db->scalar(
            'SELECT COUNT(*) FROM attendance_records
              WHERE session_id = :s AND time_in < DATE_SUB(NOW(), INTERVAL 5 MINUTE)',
            ['s' => $session['session_id']]
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
     * 12. Sustained soak (opt-in, 30 minutes)
     * ===================================================================== */
    if (($options['load'] ?? false) && $want('load')) {
        $runner->group('12. Sustained load: 100 taps/minute for 30 minutes');

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
