<?php
/** @var App\Core\View $__view */

use App\Services\AttendanceStatusResolver;

$__view->extend('layouts.app');
$__view->start('content');

$totals        = $overview['totals'];
$today         = $overview['today'];
$deviceSummary = $overview['devices'];
$security      = $overview['security'];

$riskColour = [
    'normal'   => 'success',
    'guarded'  => 'info',
    'elevated' => 'warning',
    'high'     => 'danger',
    'critical' => 'danger',
][$security['risk_level']] ?? 'neutral';
?>

<?php $__view->include('partials.page-header', [
    'title'    => 'Dashboard',
    'subtitle' => 'Live overview of attendance, terminals and security across the school.',
    'breadcrumbs' => [['Dashboard', null]],
    'actions'  => '<a class="btn btn-secondary" href="/admin/analytics"><i class="fa-solid fa-chart-line"></i> Analytics</a>'
        . '<a class="btn btn-primary" href="/admin/attendance"><i class="fa-solid fa-clipboard-user"></i> Attendance</a>',
]); ?>

<?php if ($actionItems !== []): ?>
    <div class="card">
        <div class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-triangle-exclamation text-warning"></i> Needs attention</h2>
            <span class="badge badge-warning"><?= e(count($actionItems)) ?></span>
        </div>
        <div class="card__body--flush">
            <?php foreach ($actionItems as $item): ?>
                <a href="<?= e($item['link']) ?>" class="live-row" style="color:inherit">
                    <span class="live-row__arrow text-<?= e($item['severity'] === 'danger' ? 'danger' : ($item['severity'] === 'warning' ? 'warning' : 'muted')) ?>">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </span>
                    <span class="live-row__body">
                        <span class="live-row__name"><?= e($item['title']) ?></span>
                        <span class="live-row__meta"><?= e($item['detail']) ?></span>
                    </span>
                    <span class="live-row__time"><i class="fa-solid fa-chevron-right"></i></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Summary cards ------------------------------------------------------- -->
<div class="grid grid--4 mb-3">
    <div class="stat">
        <span class="stat__icon"><i class="fa-solid fa-user-graduate"></i></span>
        <div>
            <div class="stat__label">Students</div>
            <div class="stat__value" data-metric="students"><?= e(number_format($totals['students'])) ?></div>
            <div class="stat__meta"><?= e($totals['sections']) ?> sections</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--info"><i class="fa-solid fa-chalkboard-user"></i></span>
        <div>
            <div class="stat__label">Teachers</div>
            <div class="stat__value"><?= e(number_format($totals['teachers'])) ?></div>
            <div class="stat__meta"><?= e($totals['subjects']) ?> subjects</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--success"><i class="fa-solid fa-user-check"></i></span>
        <div>
            <div class="stat__label">Present today</div>
            <div class="stat__value" data-metric="present"><?= e(number_format($today['present'])) ?></div>
            <div class="stat__meta">
                <span class="text-warning" data-metric="late"><?= e($today['late']) ?></span> late ·
                <span class="text-danger" data-metric="absent"><?= e($today['absent']) ?></span> absent
            </div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--<?= $today['percentage'] >= 90 ? 'success' : ($today['percentage'] >= 75 ? 'warning' : 'danger') ?>">
            <i class="fa-solid fa-percent"></i>
        </span>
        <div>
            <div class="stat__label">Attendance rate</div>
            <div class="stat__value" data-metric="percentage"><?= e($today['percentage']) ?>%</div>
            <div class="stat__meta"><?= e(number_format($today['records'])) ?> records today</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--<?= $deviceSummary['offline'] > 0 ? 'danger' : 'success' ?>">
            <i class="fa-solid fa-microchip"></i>
        </span>
        <div>
            <div class="stat__label">Terminals online</div>
            <div class="stat__value">
                <span data-metric="devices-online"><?= e($deviceSummary['online']) ?></span>
                <span class="text-muted" style="font-size:16px">/ <?= e($deviceSummary['total']) ?></span>
            </div>
            <div class="stat__meta">
                <?php if ($deviceSummary['offline'] > 0): ?>
                    <span class="text-danger"><?= e($deviceSummary['offline']) ?> offline</span>
                <?php else: ?>
                    All terminals reporting
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--info"><i class="fa-solid fa-clock"></i></span>
        <div>
            <div class="stat__label">Active sessions</div>
            <div class="stat__value" data-metric="sessions"><?= e($totals['active_sessions']) ?></div>
            <div class="stat__meta">Classes taking attendance now</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--warning"><i class="fa-solid fa-hourglass-half"></i></span>
        <div>
            <div class="stat__label">Average stay</div>
            <div class="stat__value"><?= $today['average_dwell_minutes'] === null ? '—' : e($today['average_dwell_minutes']) . '<span style="font-size:15px"> min</span>' ?></div>
            <div class="stat__meta"><?= e($today['left_early']) ?> left early today</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--<?= e($riskColour) ?>"><i class="fa-solid fa-shield-halved"></i></span>
        <div>
            <div class="stat__label">Security risk</div>
            <div class="stat__value" style="font-size:20px;text-transform:capitalize"><?= e($security['risk_level']) ?></div>
            <div class="stat__meta"><?= e($security['open_events']) ?> open event(s)</div>
        </div>
    </div>
</div>

<!-- Charts + live feed --------------------------------------------------- -->
<div class="grid grid--2">
    <div class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Attendance trend</h2>
                <p class="card__subtitle">Last 14 school days</p>
            </div>
        </div>
        <div class="card__body">
            <div id="chart-trend" style="min-height:260px"></div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Today's breakdown</h2>
                <p class="card__subtitle">All sessions, every status</p>
            </div>
        </div>
        <div class="card__body">
            <div id="chart-today" style="min-height:260px"></div>
        </div>
    </div>
</div>

<div class="grid grid--2">
    <!-- Live attendance feed ------------------------------------------- -->
    <div class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">
                    <i class="fa-solid fa-bolt text-warning"></i> Live attendance
                </h2>
                <p class="card__subtitle">Taps appear here the moment they are recorded</p>
            </div>
            <a class="btn btn-ghost btn-sm" href="/admin/attendance">View all</a>
        </div>

        <div class="card__body--flush live-feed" id="live-feed">
            <?php if ($liveFeed === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-clipboard-user',
                    'title' => 'No attendance recorded yet',
                    'text'  => 'Records appear here as soon as students tap in at a classroom terminal.',
                ]); ?>
            <?php else: ?>
                <?php foreach ($liveFeed as $record): ?>
                    <div class="live-row" data-attendance-id="<?= e($record['attendance_id']) ?>">
                        <span class="live-row__arrow live-row__arrow--<?= $record['time_out'] ? 'out' : 'in' ?>">
                            <i class="fa-solid fa-arrow-<?= $record['time_out'] ? 'left' : 'right' ?>"></i>
                        </span>

                        <?php if (!empty($record['student_photo'])): ?>
                            <img class="avatar avatar--sm" src="/uploads/<?= e($record['student_photo']) ?>" alt="">
                        <?php else: ?>
                            <span class="avatar avatar--sm"><?= e(initials((string) $record['student_name'])) ?></span>
                        <?php endif; ?>

                        <span class="live-row__body">
                            <span class="live-row__name"><?= e($record['student_name']) ?></span>
                            <span class="live-row__meta">
                                <?= e($record['student_number']) ?> ·
                                <?= e($record['section_code']) ?> ·
                                <?= e($record['subject_code']) ?> ·
                                Room <?= e($record['room_number']) ?>
                            </span>
                        </span>

                        <span class="live-row__time">
                            <span class="badge <?= e(AttendanceStatusResolver::badgeClass((string) $record['final_status'])) ?>">
                                <?= e($record['final_status']) ?>
                            </span>
                            <div class="text-xs text-muted mt-1">
                                <?= e(format_time($record['time_out'] ?: $record['time_in'])) ?>
                            </div>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rejected taps --------------------------------------------------- -->
    <div class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title"><i class="fa-solid fa-ban text-danger"></i> Rejected taps</h2>
                <p class="card__subtitle">Wrong section, unknown card, outside the window</p>
            </div>
        </div>

        <div class="card__body--flush live-feed" id="rejection-feed" style="max-height:460px">
            <?php if ($rejections === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-circle-check',
                    'title' => 'No rejected taps',
                    'text'  => 'Every card presented today was accepted.',
                ]); ?>
            <?php else: ?>
                <?php foreach ($rejections as $rejection): ?>
                    <div class="live-row live-row--rejected">
                        <span class="live-row__arrow text-danger"><i class="fa-solid fa-xmark"></i></span>
                        <span class="live-row__body">
                            <span class="live-row__name">
                                <?= e($rejection['student_name'] ?? 'Unknown card ' . $rejection['card_uid']) ?>
                            </span>
                            <span class="live-row__meta">
                                <?= e(strtoupper(str_replace('_', ' ', (string) $rejection['result']))) ?>
                                <?php if ($rejection['result'] === 'section_mismatch'): ?>
                                    · student is <?= e($rejection['student_section']) ?>,
                                    session is <?= e($rejection['session_section']) ?>
                                <?php endif; ?>
                                <?php if (!empty($rejection['room_number'])): ?>
                                    · Room <?= e($rejection['room_number']) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="live-row__time"><?= e(time_ago($rejection['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Open sessions -------------------------------------------------------- -->
<div class="card">
    <div class="card__header">
        <div>
            <h2 class="card__title">Active attendance sessions</h2>
            <p class="card__subtitle">Classes currently taking attendance</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/attendance/sessions">All sessions</a>
    </div>

    <div class="card__body--flush">
        <?php if ($openSessions === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-door-closed',
                'title' => 'No sessions open',
                'text'  => 'A session opens when an assigned teacher verifies their fingerprint at a classroom terminal.',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Session</th>
                        <th>Teacher</th>
                        <th>Subject</th>
                        <th>Section</th>
                        <th>Room</th>
                        <th>Terminal</th>
                        <th class="numeric">Present</th>
                        <th class="numeric">Late</th>
                        <th class="numeric">Roster</th>
                        <th class="numeric">Rejected</th>
                        <th>Opened</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="open-sessions-body">
                    <?php foreach ($openSessions as $session): ?>
                        <tr>
                            <td class="mono text-sm"><?= e($session['session_code']) ?></td>
                            <td class="cell-primary"><?= e($session['teacher_name']) ?></td>
                            <td><?= e($session['subject_code']) ?></td>
                            <td><span class="badge badge-primary"><?= e($session['section_code']) ?></span></td>
                            <td><?= e($session['room_number']) ?></td>
                            <td class="mono text-xs"><?= e($session['device_id']) ?></td>
                            <td class="numeric fw-600 text-success"><?= e($session['present_count']) ?></td>
                            <td class="numeric text-warning"><?= e($session['late_count']) ?></td>
                            <td class="numeric text-muted"><?= e($session['roster_count']) ?></td>
                            <td class="numeric <?= (int) $session['rejected_tap_count'] > 0 ? 'text-danger fw-600' : 'text-muted' ?>">
                                <?= e($session['rejected_tap_count']) ?>
                            </td>
                            <td class="nowrap text-sm"><?= e(format_time($session['opened_at'])) ?></td>
                            <td>
                                <a class="btn btn-ghost btn-sm" href="/admin/attendance/sessions/<?= e($session['session_id']) ?>">
                                    Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Devices, security, audit --------------------------------------------- -->
<div class="grid grid--3">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Terminal status</h2>
            <a class="btn btn-ghost btn-sm" href="/admin/devices">Manage</a>
        </div>
        <div class="card__body--flush" style="max-height:360px;overflow-y:auto">
            <?php if ($devices === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-microchip',
                    'title' => 'No terminals registered',
                    'text'  => 'Register an ESP32 terminal for each classroom to begin recording attendance.',
                ]); ?>
            <?php else: ?>
                <?php foreach ($devices as $device): ?>
                    <?php
                    $health = (string) $device['health'];
                    $tone   = match ($health) {
                        'online'  => 'success',
                        'warning' => 'warning',
                        'pending' => 'info',
                        'offline' => 'danger',
                        default   => 'neutral',
                    };
                    ?>
                    <a href="/admin/devices/<?= e($device['device_row_id']) ?>" class="live-row" style="color:inherit">
                        <span class="live-row__arrow text-<?= e($tone === 'neutral' ? 'muted' : $tone) ?>">
                            <i class="fa-solid fa-circle" style="font-size:8px"></i>
                        </span>
                        <span class="live-row__body">
                            <span class="live-row__name"><?= e($device['device_name']) ?></span>
                            <span class="live-row__meta">
                                <?= e($device['device_id']) ?>
                                <?php if (!empty($device['room_number'])): ?>
                                    · Room <?= e($device['room_number']) ?>
                                <?php endif; ?>
                                <?php if ($device['wifi_signal'] !== null): ?>
                                    · <?= e($device['wifi_signal']) ?> dBm
                                <?php endif; ?>
                                <?php if ((int) $device['queue_depth'] > 0): ?>
                                    · <span class="text-warning"><?= e($device['queue_depth']) ?> queued</span>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="live-row__time">
                            <span class="badge badge-<?= e($tone) ?>"><?= e(ucfirst($health)) ?></span>
                            <div class="text-xs text-muted mt-1"><?= e(time_ago($device['last_heartbeat_at'])) ?></div>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Security summary</h2>
            <a class="btn btn-ghost btn-sm" href="/admin/security">Security Center</a>
        </div>
        <div class="card__body">
            <div class="grid" style="grid-template-columns:repeat(2,1fr);gap:.6rem">
                <?php
                $securityTiles = [
                    ['Failed sign-ins', $security['failed_logins'], 'danger'],
                    ['Unknown cards', $security['unknown_rfid'], 'warning'],
                    ['Invalid API keys', $security['invalid_api_keys'], 'danger'],
                    ['Replay attempts', $security['replay_attempts'], 'danger'],
                    ['Bad signatures', $security['invalid_signatures'], 'danger'],
                    ['Rate limited', $security['rate_limited'], 'warning'],
                    ['Blocked devices', $security['blocked_devices'], 'neutral'],
                    ['Blocked IPs', $security['blocked_ips'], 'neutral'],
                ];
                ?>
                <?php foreach ($securityTiles as [$label, $value, $tone]): ?>
                    <div style="padding:.55rem .7rem;background:var(--surface-alt);border-radius:var(--radius-sm)">
                        <div class="text-xs text-muted"><?= e($label) ?></div>
                        <div class="fw-600" style="font-size:18px;<?= $value > 0 && $tone !== 'neutral' ? 'color:var(--' . $tone . ')' : '' ?>">
                            <?= e($value) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-2 text-xs text-muted">Counts cover the last 24 hours.</div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Recent activity</h2>
            <a class="btn btn-ghost btn-sm" href="/admin/audit">Audit log</a>
        </div>
        <div class="card__body--flush" style="max-height:360px;overflow-y:auto">
            <?php if ($auditTrail === []): ?>
                <div class="text-muted text-sm" style="padding:1rem">No activity recorded yet.</div>
            <?php else: ?>
                <?php foreach ($auditTrail as $entry): ?>
                    <div class="live-row">
                        <span class="live-row__body">
                            <span class="live-row__name" style="font-size:12.5px">
                                <?= e(str_replace('_', ' ', (string) $entry['action'])) ?>
                            </span>
                            <span class="live-row__meta">
                                <?= e($entry['actor_label'] ?? 'SYSTEM') ?> · <?= e($entry['module']) ?>
                            </span>
                        </span>
                        <span class="live-row__time"><?= e(time_ago($entry['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
(function () {
    const LS = window.LSIAMS;

    const trendData = <?= json_encode($trend, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const today     = <?= json_encode($today, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function drawCharts() {
        LS.charts.line('chart-trend', trendData, {
            xKey: 'date',
            title: 'Attendance trend',
            series: [
                { key: 'present', label: 'Present', color: '#22C55E' },
                { key: 'late',    label: 'Late',    color: '#F59E0B' },
                { key: 'absent',  label: 'Absent',  color: '#EF4444' },
            ],
            emptyMessage: 'No attendance has been recorded in the last 14 days.',
        });

        LS.charts.donut('chart-today', [
            { label: 'Present',    value: today.present,    color: '#22C55E' },
            { label: 'Late',       value: today.late,       color: '#F59E0B' },
            { label: 'Left early', value: today.left_early, color: '#FB923C' },
            { label: 'Incomplete', value: today.incomplete, color: '#A78BFA' },
            { label: 'Excused',    value: today.excused,    color: '#0EA5E9' },
            { label: 'Absent',     value: today.absent,     color: '#EF4444' },
        ], {
            title: "Today's attendance",
            centerValue: today.percentage + '%',
            centerLabel: 'attendance',
            emptyMessage: 'No attendance recorded today yet.',
        });
    }

    drawCharts();
    document.addEventListener('charts:redraw', drawCharts);

    /* ---- live updates ------------------------------------------------- */

    const feed = document.getElementById('live-feed');

    function prependTap(data, direction) {
        if (!feed) return;

        const emptyState = feed.querySelector('.empty-state');
        if (emptyState) emptyState.remove();

        const row = document.createElement('div');
        row.className = 'live-row';
        row.dataset.attendanceId = data.attendance_id;

        const student = data.student || {};
        const isOut   = direction === 'out';

        row.innerHTML =
            '<span class="live-row__arrow live-row__arrow--' + (isOut ? 'out' : 'in') + '">'
            + '<i class="fa-solid fa-arrow-' + (isOut ? 'left' : 'right') + '"></i></span>'
            + '<span class="avatar avatar--sm"></span>'
            + '<span class="live-row__body">'
            + '<span class="live-row__name"></span>'
            + '<span class="live-row__meta"></span>'
            + '</span>'
            + '<span class="live-row__time"><span class="badge"></span></span>';

        row.querySelector('.avatar').textContent = LS.util.initials(student.name);
        row.querySelector('.live-row__name').textContent = student.name || '—';
        row.querySelector('.live-row__meta').textContent =
            [student.number, (data.section || {}).code, (data.subject || {}).code,
             'Room ' + ((data.classroom || {}).room || '?')].filter(Boolean).join(' · ');

        const badge = row.querySelector('.badge');
        badge.textContent = data.final_status || '';
        badge.className = 'badge ' + LS.util.statusBadge(data.final_status);

        feed.prepend(row);

        // Keep the DOM bounded: the full history lives on the Attendance page.
        while (feed.children.length > 60) feed.lastElementChild.remove();

        if (data.counters) updateCounters(data.counters);
    }

    function updateCounters(counters) {
        const present = document.querySelector('[data-metric="present"]');
        if (present && counters.timed_in !== undefined) {
            // The card shows the school-wide figure, so nudge rather than replace.
            present.textContent = (parseInt(present.textContent.replace(/,/g, ''), 10) || 0) + 0;
        }
    }

    if (LS.realtime) {
        LS.realtime
            .on('attendance.time_in',  (data) => prependTap(data, 'in'))
            .on('attendance.time_out', (data) => prependTap(data, 'out'))
            .on('attendance.rejected', (data) => {
                const strip = document.getElementById('rejection-feed');
                if (!strip) return;

                const empty = strip.querySelector('.empty-state');
                if (empty) empty.remove();

                const row = document.createElement('div');
                row.className = 'live-row live-row--rejected';
                row.innerHTML =
                    '<span class="live-row__arrow text-danger"><i class="fa-solid fa-xmark"></i></span>'
                    + '<span class="live-row__body"><span class="live-row__name"></span>'
                    + '<span class="live-row__meta"></span></span>'
                    + '<span class="live-row__time">just now</span>';

                row.querySelector('.live-row__name').textContent =
                    data.student_name || ('Unknown card ' + (data.card_uid || ''));
                row.querySelector('.live-row__meta').textContent =
                    (data.code || '') + (data.student_section ? ' · student is ' + data.student_section : '');

                strip.prepend(row);
                while (strip.children.length > 30) strip.lastElementChild.remove();
            })
            .on('session.opened', () => refreshOverview())
            .on('session.closed', () => refreshOverview())
            .on('device.offline', (data) => LS.toast.warning('Terminal ' + data.device_id + ' went offline.', 'Device offline'))
            .on('device.online',  (data) => LS.toast.success('Terminal ' + data.device_id + ' reconnected.', 'Device online'))
            .on('security.alert', (data) => LS.toast.error(data.description, 'Security: ' + data.event));
    }

    /* Periodic full refresh as a safety net. Passive, so it never keeps the
       session alive on an unattended screen. */
    const refreshOverview = LS.util.debounce(async function () {
        try {
            const response = await LS.http.poll('/api/dashboard/admin', { since: 0 });
            const data = response.data;

            const set = (metric, value) => {
                const node = document.querySelector('[data-metric="' + metric + '"]');
                if (node) node.textContent = value;
            };

            set('present', data.overview.today.present.toLocaleString());
            set('late', data.overview.today.late);
            set('absent', data.overview.today.absent);
            set('percentage', data.overview.today.percentage + '%');
            set('sessions', data.overview.totals.active_sessions);
            set('devices-online', data.overview.devices.online);
        } catch (error) { /* the next tick will retry */ }
    }, 1500);

    setInterval(refreshOverview, 60000);
})();
</script>
<?php $__view->stop(); ?>
