<?php
declare(strict_types=1);

/**
 * Demonstration dataset — `php bin/console seed --demo`.
 *
 * Builds a small but complete school so every screen in the system has real
 * data behind it: departments already exist from the reference seeder, and this
 * adds classrooms, subjects, teachers with accounts and fingerprints, sections
 * with advisers, students with RFID cards, terminals, schedules, and 30 school
 * days of closed attendance sessions with plausible records.
 *
 * Three rules govern what this file is allowed to do.
 *
 * 1. It refuses to run against a database that already holds real data. A demo
 *    dataset landing in a live school's records would be worse than useless, so
 *    the guard is a hard stop rather than a warning.
 *
 * 2. Every row it writes is tagged so it can be recognised later: student
 *    numbers, employee numbers and device IDs all carry a DEMO marker, and the
 *    accounts it creates are flagged `must_change_password`.
 *
 * 3. Attendance is written the same way the application writes it — through
 *    real sessions, with the same status vocabulary and the same denormalised
 *    columns — so reports, analytics and the concurrency tests all see data
 *    shaped exactly like production data. Nothing here bypasses the schema's
 *    constraints; if a rule would reject a row in production, it rejects it
 *    here too.
 *
 * Randomness comes from `random_int()` rather than `mt_rand()`. Nothing here is
 * security-sensitive, but the project bans the weak generators outright so the
 * key-path audit (`console security:audit-keys`) can grep for them without
 * having to reason about which call sites are exempt.
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\AttendanceStatusResolver;

return static function (Database $db): void {
    $now = Clock::nowString();

    // -------------------------------------------------------------- guard --
    // The demo data must never mix with a school's real records.
    $existingStudents = (int) $db->scalar(
        "SELECT COUNT(*) FROM students WHERE student_number NOT LIKE 'DEMO-%'"
    );

    $existingAttendance = (int) $db->scalar('SELECT COUNT(*) FROM attendance_records');

    if ($existingStudents > 0 || $existingAttendance > 0) {
        throw new RuntimeException(
            'Refusing to seed demo data: this database already holds real students or attendance. '
            . 'Run the demo seeder only against an empty installation.'
        );
    }

    echo "  Building demo school…\n";

    /**
     * bcrypt at the configured cost, once. Every demo account shares this
     * password — it is printed at the end, and each account is forced to change
     * it at first sign-in, so a demo installation that is later kept cannot
     * quietly inherit a known credential.
     */
    $demoPassword = 'L-SIAMS-Demo-2026!';
    $passwordHash = password_hash($demoPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    $adminRoleId   = (int) $db->scalar("SELECT role_id FROM roles WHERE role_slug = 'administrator'");
    $teacherRoleId = (int) $db->scalar("SELECT role_id FROM roles WHERE role_slug = 'teacher'");
    $schoolYearId  = (int) $db->scalar('SELECT school_year_id FROM school_years WHERE is_current = 1');

    if ($adminRoleId === 0 || $teacherRoleId === 0 || $schoolYearId === 0) {
        throw new RuntimeException('Run the reference seeder first — roles and the school year are missing.');
    }

    /** @return array<string,int> code => id */
    $lookup = static function (Database $db, string $table, string $codeColumn, string $idColumn): array {
        $map = [];

        foreach ($db->select("SELECT {$codeColumn} AS code, {$idColumn} AS id FROM {$table}") as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }

        return $map;
    };

    $departments = $lookup($db, 'departments', 'department_code', 'department_id');
    $gradeLevels = $lookup($db, 'grade_levels', 'grade_level_code', 'grade_level_id');

    // ---------------------------------------------------------- classrooms --
    echo "    classrooms…\n";

    $classroomIds = [];

    foreach ([
        ['101', 'Main Building', '1st'],
        ['102', 'Main Building', '1st'],
        ['201', 'Main Building', '2nd'],
        ['202', 'Main Building', '2nd'],
        ['203', 'Main Building', '2nd'],
        ['301', 'Annex',         '3rd'],
        ['302', 'Annex',         '3rd'],
        ['LAB-1', 'Science Wing', '1st'],
    ] as [$room, $building, $floor]) {
        $classroomIds[$room] = (int) $db->insert('classrooms', [
            'room_number' => $room,
            'building'    => $building,
            'floor'       => $floor,
            'capacity'    => 45,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    // ------------------------------------------------------------ subjects --
    echo "    subjects…\n";

    $subjectSpecs = [
        ['ENG-7',   'English 7',                'ENG',  ['G7']],
        ['ENG-10',  'English 10',               'ENG',  ['G10']],
        ['MATH-7',  'Mathematics 7',            'MATH', ['G7']],
        ['MATH-10', 'Mathematics 10',           'MATH', ['G10']],
        ['SCI-7',   'Science 7',                'SCI',  ['G7']],
        ['SCI-10',  'Science 10',               'SCI',  ['G10']],
        ['FIL-7',   'Filipino 7',               'FIL',  ['G7']],
        ['AP-10',   'Araling Panlipunan 10',    'AP',   ['G10']],
        ['PE-7',    'MAPEH 7',                  'MAPEH', ['G7']],
        ['STEM-CS', 'Empowerment Technologies', 'TLE',  ['G11', 'G12']],
        ['STEM-PHY', 'General Physics 1',       'SCI',  ['G12']],
    ];

    $subjectIds = [];

    foreach ($subjectSpecs as [$code, $name, $departmentCode, $grades]) {
        if (!isset($departments[$departmentCode])) {
            continue; // a reference seeder that named departments differently
        }

        $subjectId = (int) $db->insert('subjects', [
            'subject_code'  => $code,
            'subject_name'  => $name,
            'department_id' => $departments[$departmentCode],
            'units'         => 1.00,
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $subjectIds[$code] = $subjectId;

        foreach ($grades as $gradeCode) {
            if (!isset($gradeLevels[$gradeCode])) {
                continue;
            }

            $db->insert('subject_grade_levels', [
                'subject_id'     => $subjectId,
                'grade_level_id' => $gradeLevels[$gradeCode],
                'created_at'     => $now,
            ]);
        }
    }

    // ------------------------------------------------------------ teachers --
    echo "    teachers and accounts…\n";

    $teacherSpecs = [
        // employee, first, last, department, grade levels, subjects
        ['DEMO-T-001', 'Maria',   'Reyes',    'ENG',  ['G7', 'G10'],  ['ENG-7', 'ENG-10']],
        ['DEMO-T-002', 'Antonio', 'Cruz',     'MATH', ['G7', 'G10'],  ['MATH-7', 'MATH-10']],
        ['DEMO-T-003', 'Liza',    'Bautista', 'SCI',  ['G7', 'G10', 'G12'], ['SCI-7', 'SCI-10', 'STEM-PHY']],
        ['DEMO-T-004', 'Rogelio', 'Santos',   'FIL',  ['G7'],         ['FIL-7']],
        ['DEMO-T-005', 'Chona',   'Dizon',    'AP',   ['G10'],        ['AP-10']],
        ['DEMO-T-006', 'Ferdie',  'Lim',      'MAPEH', ['G7'],        ['PE-7']],
        ['DEMO-T-007', 'Grace',   'Villanueva', 'TLE', ['G11', 'G12'], ['STEM-CS']],
    ];

    $teacherIds = [];
    $slot       = 1;

    foreach ($teacherSpecs as [$employee, $first, $last, $departmentCode, $grades, $subjects]) {
        if (!isset($departments[$departmentCode])) {
            continue;
        }

        $username = strtolower($first[0] . $last);
        $email    = $username . '@demo.lsiams.local';

        $userId = (int) $db->insert('users', [
            'role_id'       => $teacherRoleId,
            'username'      => $username,
            'email'         => $email,
            'password_hash' => $passwordHash,
            'full_name'     => $first . ' ' . $last,
            'status'        => 'active',
            // Demo accounts are still forced through a password change: the
            // shared demo password must not survive into anything real.
            'must_change_password' => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $teacherId = (int) $db->insert('teachers', [
            'employee_number' => $employee,
            'user_id'         => $userId,
            'department_id'   => $departments[$departmentCode],
            'first_name'      => $first,
            'last_name'       => $last,
            'email'           => $email,
            'phone'           => '0917' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
            'position'        => 'Teacher I',
            // Sessions can only be opened by a verified fingerprint, so every
            // demo teacher is enrolled — otherwise no session could ever open.
            'fingerprint_status' => 'enrolled',
            'status'          => 'active',
            'hired_at'        => Clock::now()->modify('-3 years')->format('Y-m-d'),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $teacherIds[$employee] = $teacherId;

        $db->insert('fingerprint_templates', [
            'teacher_id'         => $teacherId,
            'sensor_template_id' => $slot++,
            'enrollment_date'    => Clock::now()->modify('-2 years')->format('Y-m-d'),
            'verification_count' => random_int(40, 400),
            'status'             => 'active',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $db->insert('teacher_departments', [
            'teacher_id'    => $teacherId,
            'department_id' => $departments[$departmentCode],
            'is_primary'    => 1,
            'created_at'    => $now,
        ]);

        foreach ($grades as $gradeCode) {
            if (isset($gradeLevels[$gradeCode])) {
                $db->insert('teacher_grade_levels', [
                    'teacher_id'     => $teacherId,
                    'grade_level_id' => $gradeLevels[$gradeCode],
                    'created_at'     => $now,
                ]);
            }
        }

        foreach ($subjects as $subjectCode) {
            if (isset($subjectIds[$subjectCode])) {
                $db->insert('teacher_subjects', [
                    'teacher_id' => $teacherId,
                    'subject_id' => $subjectIds[$subjectCode],
                    'created_at' => $now,
                ]);
            }
        }
    }

    // ------------------------------------------------------------ sections --
    echo "    sections…\n";

    $sectionSpecs = [
        // code, name, grade, strand, adviser, default classroom
        ['G7-RIZAL',    'Rizal',     'G7',  null,   'DEMO-T-001', '101'],
        ['G7-MABINI',   'Mabini',    'G7',  null,   'DEMO-T-004', '102'],
        ['G10-NEWTON',  'Newton',    'G10', null,   'DEMO-T-002', '201'],
        ['G10-CURIE',   'Curie',     'G10', null,   'DEMO-T-005', '202'],
        ['G12-STEM-A',  'STEM A',    'G12', 'STEM', 'DEMO-T-003', '301'],
    ];

    $sectionIds   = [];
    $sectionGrade = [];

    foreach ($sectionSpecs as [$code, $name, $gradeCode, $strand, $adviser, $room]) {
        if (!isset($gradeLevels[$gradeCode])) {
            continue;
        }

        $sectionId = (int) $db->insert('sections', [
            'section_code'   => $code,
            'section_name'   => $name,
            'grade_level_id' => $gradeLevels[$gradeCode],
            'adviser_id'     => $teacherIds[$adviser] ?? null,
            'strand'         => $strand,
            'capacity'       => 45,
            'school_year_id' => $schoolYearId,
            'default_classroom_id' => $classroomIds[$room] ?? null,
            'status'         => 'active',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $sectionIds[$code]   = $sectionId;
        $sectionGrade[$code] = $gradeLevels[$gradeCode];

        if (isset($teacherIds[$adviser])) {
            $db->insert('teacher_sections', [
                'teacher_id' => $teacherIds[$adviser],
                'section_id' => $sectionId,
                'created_at' => $now,
            ]);
        }
    }

    // ------------------------------------------------------------ students --
    echo "    students and RFID cards…\n";

    $firstNames = [
        'Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Miguel', 'Carmen', 'Luis', 'Elena',
        'Carlo', 'Bea', 'Rafael', 'Trisha', 'Dominic', 'Kaye', 'Nathaniel', 'Angeline',
        'Paulo', 'Jasmine', 'Enrico', 'Camille', 'Adrian', 'Sofia', 'Marco', 'Danica',
        'Gabriel', 'Patricia', 'Ivan', 'Lorraine',
    ];

    $lastNames = [
        'Dela Cruz', 'Garcia', 'Ramos', 'Mendoza', 'Torres', 'Flores', 'Gonzales', 'Aquino',
        'Castillo', 'Navarro', 'Domingo', 'Salazar', 'Fernandez', 'Rivera', 'Aguilar',
        'Pascual', 'Valdez', 'Bernardo', 'Ocampo', 'Manalo',
    ];

    $adminUserId = (int) ($db->scalar(
        'SELECT user_id FROM users WHERE role_id = :role ORDER BY user_id LIMIT 1',
        ['role' => $adminRoleId]
    ) ?? 0);

    if ($adminUserId === 0) {
        // The demo needs an administrator to attribute enrolment history to.
        $adminUserId = (int) $db->insert('users', [
            'role_id'       => $adminRoleId,
            'username'      => 'demoadmin',
            'email'         => 'demoadmin@demo.lsiams.local',
            'password_hash' => $passwordHash,
            'full_name'     => 'Demo Administrator',
            'status'        => 'active',
            'must_change_password' => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    $studentCounter = 0;
    $studentsBySection = [];

    foreach ($sectionIds as $sectionCode => $sectionId) {
        $count = $sectionCode === 'G12-STEM-A' ? 22 : random_int(26, 32);

        for ($i = 0; $i < $count; $i++) {
            $studentCounter++;

            $first = $firstNames[array_rand($firstNames)];
            $last  = $lastNames[array_rand($lastNames)];

            $studentNumber = sprintf('DEMO-%s-%04d', date('Y'), $studentCounter);

            $studentId = (int) $db->insert('students', [
                'student_number' => $studentNumber,
                'section_id'     => $sectionId,
                'first_name'     => $first,
                'last_name'      => $last,
                'gender'         => random_int(0, 1) === 1 ? 'Male' : 'Female',
                'birthdate'      => Clock::now()->modify('-' . random_int(12, 18) . ' years')->format('Y-m-d'),
                'address'        => random_int(1, 300) . ' Demo Street, Barangay Uno',
                'guardian_name'  => $firstNames[array_rand($firstNames)] . ' ' . $last,
                'guardian_contact' => '0918' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                'status'         => 'active',
                'enrolled_at'    => Clock::now()->modify('-6 months')->format('Y-m-d'),
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $studentsBySection[$sectionCode][] = $studentId;

            // Initial enrolment is recorded in the history the same way a
            // transfer would be, so the student detail page is never blank.
            $db->insert('student_section_history', [
                'student_id'      => $studentId,
                'from_section_id' => null,
                'to_section_id'   => $sectionId,
                'reason'          => 'Initial enrolment (demo data).',
                'effective_date'  => Clock::now()->modify('-6 months')->format('Y-m-d'),
                'performed_by'    => $adminUserId,
                'created_at'      => $now,
            ]);

            // Roughly one student in twenty has no card yet — a realistic
            // situation the RFID page and the roster view both need to handle.
            if (random_int(1, 20) === 1) {
                continue;
            }

            $db->insert('rfid_cards', [
                'student_id' => $studentId,
                'card_uid'   => strtoupper(bin2hex(random_bytes(4))),
                'issue_date' => Clock::now()->modify('-6 months')->format('Y-m-d'),
                'status'     => 'active',
                'issued_by'  => $adminUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // -------------------------------------------------------------- devices --
    echo "    terminals…\n";

    $deviceRowIds = [];
    $deviceIndex  = 0;

    foreach (['101', '102', '201', '202', '301'] as $room) {
        $deviceIndex++;

        $deviceRowIds[$room] = (int) $db->insert('devices', [
            'device_id'    => sprintf('DEMO-DEV-%04d', $deviceIndex),
            'device_name'  => 'Room ' . $room . ' Terminal',
            'mac_address'  => sprintf('AA:BB:CC:DD:EE:%02X', $deviceIndex),
            'serial_number' => sprintf('DEMO-SN-%04d', $deviceIndex),
            'classroom_id' => $classroomIds[$room],
            'device_role'  => 'both',
            'firmware_version' => '1.0.0',
            'status'       => 'active',
            // Claimed, because a pending device could not have produced the
            // attendance history seeded below.
            'claim_status' => 'claimed',
            'heartbeat_interval_sec' => 30,
            'sync_interval_sec'      => 60,
            'offline_queue_limit'    => 500,
            'last_heartbeat_at'      => $now,
            'wifi_signal'  => random_int(-72, -45),
            'queue_depth'  => 0,
            'uptime_seconds' => random_int(3600, 604800),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    // No API keys are seeded. A key can only be shown once, at creation, and a
    // seeder cannot show anything to anybody — a key written here would be a
    // credential nobody holds and nobody can use. Register the terminals from
    // the Devices page to get real, displayable credentials.

    // ------------------------------------------------------------ schedules --
    echo "    schedules…\n";

    $scheduleSpecs = [
        // section, subject, teacher, classroom, day, start, end
        ['G7-RIZAL',   'ENG-7',   'DEMO-T-001', '101', ['Monday', 'Wednesday', 'Friday'], '08:00', '09:00'],
        ['G7-RIZAL',   'MATH-7',  'DEMO-T-002', '101', ['Monday', 'Wednesday', 'Friday'], '09:10', '10:10'],
        ['G7-RIZAL',   'SCI-7',   'DEMO-T-003', '101', ['Tuesday', 'Thursday'],           '08:00', '09:30'],
        ['G7-MABINI',  'FIL-7',   'DEMO-T-004', '102', ['Monday', 'Wednesday'],           '08:00', '09:00'],
        ['G7-MABINI',  'PE-7',    'DEMO-T-006', '102', ['Tuesday', 'Thursday'],           '10:20', '11:20'],
        ['G10-NEWTON', 'MATH-10', 'DEMO-T-002', '201', ['Monday', 'Wednesday', 'Friday'], '10:20', '11:20'],
        ['G10-NEWTON', 'ENG-10',  'DEMO-T-001', '201', ['Tuesday', 'Thursday'],           '09:40', '11:10'],
        ['G10-CURIE',  'SCI-10',  'DEMO-T-003', '202', ['Monday', 'Wednesday'],           '13:00', '14:00'],
        ['G10-CURIE',  'AP-10',   'DEMO-T-005', '202', ['Tuesday', 'Thursday'],           '13:00', '14:30'],
        ['G12-STEM-A', 'STEM-PHY', 'DEMO-T-003', '301', ['Monday', 'Wednesday'],          '14:10', '15:40'],
        ['G12-STEM-A', 'STEM-CS', 'DEMO-T-007', '301', ['Tuesday', 'Thursday'],           '14:10', '15:40'],
    ];

    /** @var list<array<string,mixed>> $schedules */
    $schedules = [];

    foreach ($scheduleSpecs as [$sectionCode, $subjectCode, $employee, $room, $days, $start, $end]) {
        if (!isset($sectionIds[$sectionCode], $subjectIds[$subjectCode], $teacherIds[$employee])) {
            continue;
        }

        foreach ($days as $day) {
            $scheduleId = (int) $db->insert('schedules', [
                'teacher_id'     => $teacherIds[$employee],
                'subject_id'     => $subjectIds[$subjectCode],
                'section_id'     => $sectionIds[$sectionCode],
                'classroom_id'   => $classroomIds[$room],
                'school_year_id' => $schoolYearId,
                'day_of_week'    => $day,
                'start_time'     => $start . ':00',
                'end_time'       => $end . ':00',
                'time_in_window_open'    => 10,
                'late_threshold_minutes' => 15,
                'time_in_window_close'   => 30,
                'time_out_window_open'   => 10,
                'time_out_window_close'  => 15,
                'minimum_dwell_minutes'  => 20,
                'status'     => 'active',
                'created_by' => $adminUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $schedules[] = [
                'schedule_id'    => $scheduleId,
                'teacher_id'     => $teacherIds[$employee],
                'subject_id'     => $subjectIds[$subjectCode],
                'section_id'     => $sectionIds[$sectionCode],
                'section_code'   => $sectionCode,
                'grade_level_id' => $sectionGrade[$sectionCode],
                'classroom_id'   => $classroomIds[$room],
                'device_row_id'  => $deviceRowIds[$room],
                'day_of_week'    => $day,
                'start_time'     => $start,
                'end_time'       => $end,
                'late_threshold_minutes' => 15,
                'minimum_dwell_minutes'  => 20,
                'time_out_window_close'  => 15,
            ];
        }
    }

    // ----------------------------------------------------------- attendance --
    echo "    30 school days of attendance…\n";

    // Card UIDs are looked up once rather than per record: 30 days × 11
    // schedules × ~28 students is roughly 9,000 records, and a query per row
    // would make the seeder take minutes instead of seconds.
    $cardsByStudent = [];

    foreach ($db->select("SELECT student_id, card_uid FROM rfid_cards WHERE status = 'active'") as $card) {
        $cardsByStudent[(int) $card['student_id']] = (string) $card['card_uid'];
    }

    $sessionCounter = 0;
    $recordCounter  = 0;

    for ($dayOffset = 30; $dayOffset >= 1; $dayOffset--) {
        $date = Clock::now()->modify("-{$dayOffset} days");
        $day  = $date->format('l');

        if (in_array($day, ['Saturday', 'Sunday'], true)) {
            continue;
        }

        foreach ($schedules as $schedule) {
            if ($schedule['day_of_week'] !== $day) {
                continue;
            }

            $roster = $studentsBySection[$schedule['section_code']] ?? [];

            if ($roster === []) {
                continue;
            }

            $dateString = $date->format('Y-m-d');
            $start      = new DateTimeImmutable($dateString . ' ' . $schedule['start_time'] . ':00');
            $end        = new DateTimeImmutable($dateString . ' ' . $schedule['end_time'] . ':00');

            $sessionCounter++;

            $sessionId = (int) $db->insert('attendance_sessions', [
                'session_code'    => sprintf('ATT-%s-%06d', $date->format('Y'), $sessionCounter),
                'schedule_id'     => $schedule['schedule_id'],
                'teacher_id'      => $schedule['teacher_id'],
                'device_row_id'   => $schedule['device_row_id'],
                'classroom_id'    => $schedule['classroom_id'],
                'section_id'      => $schedule['section_id'],
                'subject_id'      => $schedule['subject_id'],
                'session_date'    => $dateString,
                'scheduled_start' => $start->format('Y-m-d H:i:s'),
                'scheduled_end'   => $end->format('Y-m-d H:i:s'),
                'opened_at'       => $start->modify('-5 minutes')->format('Y-m-d H:i:s'),
                'closed_at'       => $end->modify('+5 minutes')->format('Y-m-d H:i:s'),
                'expires_at'      => $end->modify('+' . $schedule['time_out_window_close'] . ' minutes')->format('Y-m-d H:i:s'),
                'status'          => 'closed',
                'closed_by_type'  => 'teacher',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            $counts = [
                'present' => 0, 'late' => 0, 'absent' => 0,
                'incomplete' => 0, 'left_early' => 0, 'timed_out' => 0,
            ];

            $dwellTotal   = 0;
            $dwellSamples = 0;

            foreach ($roster as $studentId) {
                $roll = random_int(1, 100);

                // A distribution that gives every screen something to show:
                // mostly present, a realistic tail of late, early departures,
                // missing time-outs and absences.
                if ($roll <= 6) {
                    // Absent — no taps at all.
                    $db->insert('attendance_records', [
                        'session_id'     => $sessionId,
                        'student_id'     => $studentId,
                        'section_id'     => $schedule['section_id'],
                        'grade_level_id' => $schedule['grade_level_id'],
                        'subject_id'     => $schedule['subject_id'],
                        'teacher_id'     => $schedule['teacher_id'],
                        'classroom_id'   => $schedule['classroom_id'],
                        'arrival_status'   => AttendanceStatusResolver::ARRIVAL_ABSENT,
                        'departure_status' => AttendanceStatusResolver::DEPARTURE_NO_TIME_OUT,
                        'final_status'     => AttendanceStatusResolver::FINAL_ABSENT,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);

                    $counts['absent']++;
                    $recordCounter++;
                    continue;
                }

                $isLate  = $roll <= 18;
                $timeIn  = $start->modify(($isLate ? '+' . random_int(16, 28) : ($roll % 2 === 0 ? '-' : '+') . random_int(0, 8)) . ' minutes');

                $arrival = $isLate
                    ? AttendanceStatusResolver::ARRIVAL_LATE
                    : AttendanceStatusResolver::ARRIVAL_PRESENT;

                // 4% never tap out — the Incomplete case.
                $noTimeOut = $roll > 96;

                if ($noTimeOut) {
                    $db->insert('attendance_records', [
                        'session_id'     => $sessionId,
                        'student_id'     => $studentId,
                        'section_id'     => $schedule['section_id'],
                        'grade_level_id' => $schedule['grade_level_id'],
                        'subject_id'     => $schedule['subject_id'],
                        'teacher_id'     => $schedule['teacher_id'],
                        'classroom_id'   => $schedule['classroom_id'],
                        'rfid_uid'       => $cardsByStudent[$studentId] ?? null,
                        'time_in'        => $timeIn->format('Y-m-d H:i:s'),
                        'time_in_device_id' => sprintf('DEMO-DEV-%04d', 1),
                        'arrival_status'   => $arrival,
                        'departure_status' => AttendanceStatusResolver::DEPARTURE_NO_TIME_OUT,
                        'final_status'     => AttendanceStatusResolver::FINAL_INCOMPLETE,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);

                    $counts['incomplete']++;
                    $recordCounter++;
                    continue;
                }

                // 8% leave before the minimum dwell has elapsed.
                $leavesEarly = $roll > 88;

                $timeOut = $leavesEarly
                    ? $timeIn->modify('+' . random_int(6, $schedule['minimum_dwell_minutes'] - 2) . ' minutes')
                    : $end->modify('-' . random_int(0, 6) . ' minutes');

                if ($timeOut <= $timeIn) {
                    $timeOut = $timeIn->modify('+5 minutes');
                }

                $dwell = (int) round(($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 60);

                $departure = $leavesEarly
                    ? AttendanceStatusResolver::DEPARTURE_LEFT_EARLY
                    : AttendanceStatusResolver::DEPARTURE_TIMED_OUT;

                // The one resolver the whole system uses — the demo data must
                // not invent its own idea of what a final status is.
                $final = AttendanceStatusResolver::resolve($arrival, $departure);

                $db->insert('attendance_records', [
                    'session_id'     => $sessionId,
                    'student_id'     => $studentId,
                    'section_id'     => $schedule['section_id'],
                    'grade_level_id' => $schedule['grade_level_id'],
                    'subject_id'     => $schedule['subject_id'],
                    'teacher_id'     => $schedule['teacher_id'],
                    'classroom_id'   => $schedule['classroom_id'],
                    'rfid_uid'       => $cardsByStudent[$studentId] ?? null,
                    'time_in'        => $timeIn->format('Y-m-d H:i:s'),
                    'time_out'       => $timeOut->format('Y-m-d H:i:s'),
                    'duration_minutes' => $dwell,
                    'arrival_status'   => $arrival,
                    'departure_status' => $departure,
                    'final_status'     => $final,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);

                $recordCounter++;
                $counts['timed_out']++;
                $dwellTotal   += $dwell;
                $dwellSamples++;

                if ($isLate) {
                    $counts['late']++;
                } else {
                    $counts['present']++;
                }

                if ($leavesEarly) {
                    $counts['left_early']++;
                }
            }

            $roster_count = count($roster);
            $attended     = $counts['present'] + $counts['late'] + $counts['incomplete'];

            $db->update('attendance_sessions', [
                'roster_count'     => $roster_count,
                'present_count'    => $counts['present'],
                'late_count'       => $counts['late'],
                'absent_count'     => $counts['absent'],
                'incomplete_count' => $counts['incomplete'],
                'left_early_count' => $counts['left_early'],
                'timed_out_count'  => $counts['timed_out'],
                'average_dwell_minutes' => $dwellSamples === 0 ? null : (int) round($dwellTotal / $dwellSamples),
                'attendance_percentage' => $roster_count === 0
                    ? null
                    : round($attended / $roster_count * 100, 2),
            ], ['session_id' => $sessionId]);
        }
    }

    // ------------------------------------------------------------- summary --
    echo "\n";
    echo "  Demo data created:\n";
    echo '    ' . count($classroomIds) . " classrooms\n";
    echo '    ' . count($subjectIds) . " subjects\n";
    echo '    ' . count($teacherIds) . " teachers (all with fingerprints enrolled)\n";
    echo '    ' . count($sectionIds) . " sections\n";
    echo '    ' . $studentCounter . " students\n";
    echo '    ' . count($deviceRowIds) . " terminals (no API keys — register them from the Devices page)\n";
    echo '    ' . count($schedules) . " schedules\n";
    echo '    ' . $sessionCounter . " closed sessions, {$recordCounter} attendance records\n";
    echo "\n";
    echo "  Every demo account uses the password:  {$demoPassword}\n";
    echo "  All of them are flagged must_change_password, so the shared password\n";
    echo "  cannot survive first sign-in. Teacher usernames follow first-initial +\n";
    echo "  surname, e.g. mreyes, acruz, lbautista.\n";
    echo "\n";
};
