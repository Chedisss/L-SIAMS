<?php
/**
 * Left navigation.
 *
 * The menu is built from a declarative map so the admin and teacher shells stay
 * in sync structurally, and a link a role may not use simply is not rendered —
 * the route middleware refuses it regardless, but showing a dead link would be
 * poor UX.
 *
 * @var bool   $isAdmin
 * @var string $role
 */

$adminMenu = [
    'Overview' => [
        ['/admin',           'fa-gauge-high',     'Dashboard'],
        ['/admin/attendance', 'fa-clipboard-user', 'Attendance'],
        ['/admin/analytics', 'fa-chart-line',     'Analytics'],
    ],
    'People' => [
        ['/admin/students', 'fa-user-graduate', 'Students'],
        ['/admin/teachers', 'fa-chalkboard-user', 'Teachers'],
        ['/admin/users',    'fa-users-gear',    'User Accounts'],
    ],
    'Academic Setup' => [
        ['/admin/departments',  'fa-building-columns', 'Departments'],
        ['/admin/grade-levels', 'fa-layer-group',      'Grade Levels'],
        ['/admin/sections',     'fa-people-group',     'Sections'],
        ['/admin/subjects',     'fa-book',             'Subjects'],
        ['/admin/classrooms',   'fa-door-open',        'Classrooms'],
        ['/admin/schedules',    'fa-calendar-days',    'Schedules'],
    ],
    'Hardware' => [
        ['/admin/rfid',         'fa-id-card',    'RFID Cards'],
        ['/admin/fingerprints', 'fa-fingerprint', 'Fingerprints'],
        ['/admin/devices',      'fa-microchip',  'IoT Devices'],
    ],
    'Governance' => [
        ['/admin/reports',  'fa-file-lines',    'Reports'],
        ['/admin/security', 'fa-shield-halved', 'Security Center'],
        ['/admin/audit',    'fa-list-check',    'Audit Logs'],
        ['/admin/settings', 'fa-gear',          'Settings'],
        ['/admin/backup',   'fa-database',      'Backup & Restore'],
    ],
];

$teacherMenu = [
    'Teaching' => [
        ['/teacher',            'fa-gauge-high',     'Dashboard'],
        ['/teacher/schedule',   'fa-calendar-days',  'My Schedule'],
        // Schedule says when, sections say who, attendance says what happened.
        ['/teacher/sections',   'fa-users-rectangle', 'My Sections'],
        ['/teacher/attendance', 'fa-clipboard-user', 'Attendance'],
        ['/teacher/sessions',   'fa-clock-rotate-left', 'My Sessions'],
    ],
    'Account' => [
        ['/teacher/reports', 'fa-file-lines', 'Reports'],
        ['/teacher/profile', 'fa-user',       'My Profile'],
    ],
];

$menu = $isAdmin ? $adminMenu : $teacherMenu;
?>
<nav class="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <span class="sidebar__logo">L</span>
        <span>
            <span class="sidebar__title">L-SIAMS</span>
            <span class="sidebar__subtitle"><?= e($isAdmin ? 'Administrator' : 'Teacher') ?></span>
        </span>
    </div>

    <div class="sidebar__nav">
        <?php foreach ($menu as $section => $links): ?>
            <div class="sidebar__section"><?= e($section) ?></div>
            <?php foreach ($links as [$href, $icon, $label]): ?>
                <a class="sidebar__link <?= e(active_class($href === '/admin' || $href === '/teacher' ? [$href] : $href)) ?>"
                   href="<?= e($href) ?>"
                   <?= active_class($href) !== '' ? 'aria-current="page"' : '' ?>>
                    <span class="sidebar__icon"><i class="fa-solid <?= e($icon) ?>"></i></span>
                    <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="sidebar__section">Session</div>
        <form method="post" action="/logout" style="margin:0">
            <?= csrf_field() ?>
            <button type="submit" class="sidebar__link" style="width:100%;background:none;border:0;cursor:pointer;font-family:inherit">
                <span class="sidebar__icon"><i class="fa-solid fa-right-from-bracket"></i></span>
                <span>Sign out</span>
            </button>
        </form>
    </div>
</nav>
