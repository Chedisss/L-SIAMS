<?php
declare(strict_types=1);

/**
 * Reference data every installation needs: roles, permissions, grade levels,
 * departments, attendance statuses, settings and a school year.
 *
 * Idempotent — safe to re-run after an upgrade adds a new permission or
 * setting, which is exactly when you want to run it again.
 */

use App\Core\Clock;
use App\Core\Database;

return static function (Database $db): void {
    // ---------------------------------------------------------- roles ----
    $roles = [
        ['administrator', 'Administrator', 'Full access to every module, device and record.', 1],
        ['teacher',       'Teacher',       'Attendance, schedules, reports and own profile only.', 1],
        // Seeded inactive so the schema is ready for them without granting
        // access nobody has configured yet (Part 5, "future ready").
        ['registrar',     'Registrar',     'Reserved for future use.', 0],
        ['principal',     'Principal',     'Reserved for future use.', 0],
        ['it_admin',      'IT Administrator', 'Reserved for future use.', 0],
    ];

    foreach ($roles as [$slug, $name, $description, $isSystem]) {
        $db->execute(
            'INSERT INTO roles (role_slug, role_name, description, is_system, status)
                  VALUES (:slug, :name, :description, :system, :status)
             ON DUPLICATE KEY UPDATE description = VALUES(description)',
            [
                'slug'        => $slug,
                'name'        => $name,
                'description' => $description,
                'system'      => $isSystem,
                'status'      => in_array($slug, ['administrator', 'teacher'], true) ? 'active' : 'inactive',
            ]
        );
    }

    // ----------------------------------------------------- permissions ----
    $permissions = [
        'dashboard'   => ['view'],
        'students'    => ['view', 'create', 'update', 'archive', 'import', 'export', 'transfer'],
        'teachers'    => ['view', 'create', 'update', 'archive'],
        'academic'    => ['view', 'manage'],
        'schedules'   => ['view', 'create', 'update', 'archive'],
        'rfid'        => ['view', 'assign', 'replace', 'blacklist'],
        'fingerprints' => ['view', 'enroll', 'delete'],
        'devices'     => ['view', 'register', 'update', 'rotate_key', 'revoke_key', 'decommission'],
        'attendance'  => ['view', 'correct', 'close_session', 'export'],
        'reports'     => ['view', 'generate', 'export'],
        'analytics'   => ['view'],
        'security'    => ['view', 'resolve', 'block_ip', 'terminate_session'],
        'audit'       => ['view', 'export'],
        'users'       => ['view', 'create', 'update', 'archive', 'reset_password'],
        'settings'    => ['view', 'update'],
        'backup'      => ['view', 'create', 'restore', 'download'],
        'profile'     => ['view', 'update'],
    ];

    foreach ($permissions as $module => $actions) {
        foreach ($actions as $action) {
            $db->execute(
                'INSERT IGNORE INTO permissions (permission_key, module, description)
                      VALUES (:key, :module, :description)',
                [
                    'key'         => $module . '.' . $action,
                    'module'      => $module,
                    'description' => ucfirst(str_replace('_', ' ', $action)) . ' ' . str_replace('_', ' ', $module),
                ]
            );
        }
    }

    $administratorId = (int) $db->scalar("SELECT role_id FROM roles WHERE role_slug = 'administrator'");
    $teacherId       = (int) $db->scalar("SELECT role_id FROM roles WHERE role_slug = 'teacher'");

    // Administrators hold everything.
    $db->execute(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT :role, permission_id FROM permissions',
        ['role' => $administratorId]
    );

    // Teachers hold a deliberately narrow set. Note what is absent:
    // attendance.correct, devices.*, users.*, settings.* and security.* —
    // the Part 1 "They CANNOT" list, expressed as data.
    $teacherPermissions = [
        'dashboard.view',
        'attendance.view', 'attendance.close_session', 'attendance.export',
        'schedules.view',
        'reports.view', 'reports.generate', 'reports.export',
        'profile.view', 'profile.update',
        'students.view',
    ];

    foreach ($teacherPermissions as $key) {
        $db->execute(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT :role, permission_id FROM permissions WHERE permission_key = :key',
            ['role' => $teacherId, 'key' => $key]
        );
    }

    // ---------------------------------------------------- grade levels ----
    $gradeLevels = [
        ['G7',  'Grade 7',  7,  null],
        ['G8',  'Grade 8',  8,  null],
        ['G9',  'Grade 9',  9,  null],
        ['G10', 'Grade 10', 10, null],
        ['G11', 'Grade 11', 11, 'Academic'],
        ['G12', 'Grade 12', 12, 'Academic'],
    ];

    foreach ($gradeLevels as [$code, $name, $numeric, $track]) {
        $db->execute(
            'INSERT INTO grade_levels (grade_level_code, grade_level_name, numeric_level, track, status)
                  VALUES (:code, :name, :numeric, :track, \'active\')
             ON DUPLICATE KEY UPDATE grade_level_name = VALUES(grade_level_name)',
            ['code' => $code, 'name' => $name, 'numeric' => $numeric, 'track' => $track]
        );
    }

    // ----------------------------------------------------- departments ----
    // The minimum set required by Part 13.2.
    $departments = [
        ['ENG',   'English Department',              'Language, literature and communication.'],
        ['MATH',  'Mathematics Department',          'General mathematics, statistics and calculus.'],
        ['SCI',   'Science Department',              'Biology, chemistry, physics and earth science.'],
        ['FIL',   'Filipino Department',             'Wika at panitikang Filipino.'],
        ['AP',    'Araling Panlipunan Department',   'Social studies, history and civics.'],
        ['MAPEH', 'MAPEH Department',                'Music, arts, physical education and health.'],
        ['TLE',   'TLE / TVL Department',            'Technology and livelihood education, including the ICT, Home Economics, Industrial Arts and Agri-Fishery Arts strands.'],
        ['ESP',   'Values Education Department',     'Edukasyon sa Pagpapakatao.'],
        // No separate ICT department: in DepEd, ICT is a strand under the TVL
        // track, not a department of its own. Subjects like Empowerment
        // Technologies belong to TLE above.
    ];

    foreach ($departments as [$code, $name, $description]) {
        $db->execute(
            'INSERT INTO departments (department_code, department_name, description, status)
                  VALUES (:code, :name, :description, \'active\')
             ON DUPLICATE KEY UPDATE description = VALUES(description)',
            ['code' => $code, 'name' => $name, 'description' => $description]
        );
    }

    // ----------------------------------------------- attendance status ----
    $statuses = [
        ['present',           'Present',           'arrival',   'badge-success', 1, 1],
        ['late',              'Late',              'arrival',   'badge-warning', 1, 2],
        ['absent',            'Absent',            'arrival',   'badge-danger',  0, 3],
        ['excused',           'Excused',           'arrival',   'badge-info',    1, 4],
        ['official_business', 'Official Business', 'arrival',   'badge-info',    1, 5],
        ['pending',           'Pending',           'departure', 'badge-neutral', 0, 1],
        ['timed_out',         'Timed Out',         'departure', 'badge-success', 1, 2],
        ['left_early',        'Left Early',        'departure', 'badge-warning', 1, 3],
        ['no_time_out',       'No Time Out',       'departure', 'badge-warning', 0, 4],
        ['auto_closed',       'Auto-Closed',       'departure', 'badge-neutral', 1, 5],
        ['Present',           'Present',           'final',     'badge-success', 1, 1],
        ['Late',              'Late',              'final',     'badge-warning', 1, 2],
        ['Left Early',        'Left Early',        'final',     'badge-warning', 1, 3],
        ['Incomplete',        'Incomplete',        'final',     'badge-warning', 1, 4],
        ['Absent',            'Absent',            'final',     'badge-danger',  0, 5],
        ['Excused',           'Excused',           'final',     'badge-info',    1, 6],
        ['Official Business', 'Official Business', 'final',     'badge-info',    1, 7],
    ];

    foreach ($statuses as [$key, $label, $category, $badge, $countsPresent, $order]) {
        $db->execute(
            'INSERT INTO attendance_status (status_key, label, category, badge_class, counts_as_present, sort_order)
                  VALUES (:key, :label, :category, :badge, :counts, :sort)
             ON DUPLICATE KEY UPDATE label = VALUES(label), badge_class = VALUES(badge_class)',
            [
                'key' => $key, 'label' => $label, 'category' => $category,
                'badge' => $badge, 'counts' => $countsPresent, 'sort' => $order,
            ]
        );
    }

    // -------------------------------------------------------- settings ----
    // group, key, value, type, label, description, sensitive, min, max
    $settings = [
        ['school',     'school.name',                 'L-SIAMS Academy', 'string', 'School name', 'Appears on reports and the sign-in page.', 0, null, null],
        ['school',     'school.address',              '',                'string', 'School address', 'Printed on report headers.', 0, null, null],
        ['school',     'school.contact',              '',                'string', 'Contact number', null, 0, null, null],

        ['attendance', 'attendance.time_in_window_open',    '10', 'int', 'Tap-in opens (minutes before start)', 'Default for new schedules; each schedule may override it.', 0, '0', '180'],
        ['attendance', 'attendance.late_threshold_minutes', '15', 'int', 'Late threshold (minutes after start)', 'A tap after this is recorded as Late.', 0, '0', '180'],
        ['attendance', 'attendance.time_in_window_close',   '30', 'int', 'Tap-in closes (minutes after start)', 'After this, tap-in is refused entirely.', 0, '1', '240'],
        ['attendance', 'attendance.time_out_window_open',   '10', 'int', 'Tap-out opens (minutes before end)', null, 0, '0', '180'],
        ['attendance', 'attendance.time_out_window_close',  '15', 'int', 'Tap-out closes (minutes after end)', 'The session auto-closes at this point.', 0, '0', '180'],
        ['attendance', 'attendance.minimum_dwell_minutes',  '20', 'int', 'Minimum classroom stay (minutes)', 'A tap-out before this is refused; after it, the student is Left Early.', 0, '0', '480'],
        ['attendance', 'attendance.auto_timeout_on_close',  '1',  'bool', 'Stamp a time-out at session close', 'When off, a missing tap-out is recorded as Incomplete instead.', 0, null, null],
        ['attendance', 'attendance.generate_absent_on_close', '1', 'bool', 'Generate Absent records at close', 'Marks every rostered student who never tapped in.', 0, null, null],
        ['attendance', 'attendance.carry_over_enabled', '1', 'bool', 'Carry attendance to the next subject', 'A student who was Present or Late in the previous period starts the next one Present without tapping again. Absent, Left Early, Excused and Incomplete never carry — those students may still tap in.', 0, null, null],
        ['attendance', 'attendance.carry_over_max_gap_minutes', '30', 'int', 'Carry-over gap limit (minutes)', 'The longest break between one period ending and the next beginning that still counts as a handover. Beyond this, the new register starts empty.', 0, '0', '240'],
        ['attendance', 'attendance.section_mismatch_alert_threshold', '3', 'int', 'Section mismatch alert threshold', 'Notify an administrator after this many wrong-section taps by one student in a day.', 0, '1', '50'],
        ['attendance', 'attendance.chronic_absence_threshold_percent', '80', 'float', 'Chronic absence threshold (%)', 'Students below this appear on the chronic absence report.', 0, '0', '100'],

        ['device',     'device.heartbeat_interval_sec', '30',  'int', 'Heartbeat interval (seconds)', null, 0, '10', '600'],
        ['device',     'device.offline_after_sec',      '90',  'int', 'Mark offline after (seconds)', 'No heartbeat for this long means the terminal is offline.', 0, '30', '3600'],
        ['device',     'device.offline_queue_limit',    '500', 'int', 'Offline queue limit (records)', null, 0, '10', '5000'],
        ['device',     'device.queue_warning_depth',    '100', 'int', 'Queue warning depth', 'Notify an administrator when a terminal holds this many unsynced records.', 0, '1', '5000'],

        ['security',   'security.session_idle_timeout_minutes',   '10', 'int', 'Idle sign-out (minutes)', 'Users are signed out after this long without interaction.', 1, '5', '60'],
        ['security',   'security.session_absolute_timeout_hours', '8',  'int', 'Maximum session length (hours)', 'A session ends after this long regardless of activity.', 1, '1', '24'],
        ['security',   'security.max_concurrent_sessions_per_user', '3', 'int', 'Concurrent sessions per user', 'The oldest session is signed out when the limit is exceeded.', 1, '1', '10'],
        ['security',   'security.login_max_attempts',      '5',  'int', 'Failed sign-ins before lockout', null, 1, '3', '20'],
        ['security',   'security.login_lockout_minutes',   '15', 'int', 'Lockout duration (minutes)', null, 1, '1', '1440'],
        ['security',   'security.api_key_rotation_grace_hours', '24', 'int', 'API key rotation grace (hours)', 'The old key keeps working this long after rotation, then auto-revokes.', 1, '0', '168'],
        ['security',   'security.password_min_length',     '12', 'int', 'Minimum password length', null, 1, '8', '64'],

        ['fingerprint', 'fingerprint.max_failures_before_lock', '5', 'int', 'Failed scans before terminal lock', null, 1, '1', '20'],
        ['fingerprint', 'fingerprint.device_lock_minutes',      '5', 'int', 'Terminal lock duration (minutes)', null, 1, '1', '60'],

        ['academic',   'academic.allow_cross_department_exceptions', '0', 'bool', 'Allow cross-department exceptions', 'When off, a teacher can only ever be assigned subjects from their own department. Leave off for hard enforcement.', 1, null, null],

        ['backup',     'backup.schedule_enabled', '1',    'bool',   'Automatic daily backup', null, 0, null, null],
        ['backup',     'backup.schedule_time',    '01:00', 'string', 'Daily backup time', 'Run the worker with cron for this to take effect.', 0, null, null],
        ['backup',     'backup.retain_count',     '30',   'int',    'Backups to retain', null, 0, '1', '365'],

        ['appearance', 'appearance.default_theme', 'light', 'string', 'Default theme', 'Users can override this individually.', 0, null, null],
    ];

    foreach ($settings as [$group, $key, $value, $type, $label, $description, $sensitive, $min, $max]) {
        $db->execute(
            'INSERT INTO settings (setting_key, setting_value, value_type, group_name, label, description, is_sensitive, min_value, max_value)
                  VALUES (:key, :value, :type, :group, :label, :description, :sensitive, :min, :max)
             ON DUPLICATE KEY UPDATE
                  label = VALUES(label),
                  description = VALUES(description),
                  value_type = VALUES(value_type),
                  group_name = VALUES(group_name),
                  is_sensitive = VALUES(is_sensitive),
                  min_value = VALUES(min_value),
                  max_value = VALUES(max_value)',
            [
                'key' => $key, 'value' => $value, 'type' => $type, 'group' => $group,
                'label' => $label, 'description' => $description,
                'sensitive' => $sensitive, 'min' => $min, 'max' => $max,
            ]
        );
    }

    // ---------------------------------------------------- school year ----
    $year      = (int) Clock::now()->format('Y');
    $month     = (int) Clock::now()->format('n');
    // A Philippine school year runs June–March, so before June we are still in
    // the year that started last calendar year.
    $startYear = $month >= 6 ? $year : $year - 1;
    $label     = $startYear . '-' . ($startYear + 1);

    $db->execute(
        'INSERT INTO school_years (year_label, start_date, end_date, is_current, status)
              VALUES (:label, :start, :end, 1, \'active\')
         ON DUPLICATE KEY UPDATE is_current = 1, status = \'active\'',
        [
            'label' => $label,
            'start' => $startYear . '-06-01',
            'end'   => ($startYear + 1) . '-03-31',
        ]
    );
};
