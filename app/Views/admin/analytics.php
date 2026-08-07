<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Analytics',
    'subtitle'    => 'Attendance and hardware behaviour over a chosen range. Each card loads on its own so a slow query never blocks the page.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Analytics', null]],
]); ?>

<div class="card">
    <div class="card__body">
        <form class="filter-bar" id="analytics-filters">
            <div class="filter-bar__field">
                <label for="a-from" class="text-xs text-muted">From</label>
                <input type="date" id="a-from" name="date_from" value="<?= e($dateFrom) ?>">
            </div>

            <div class="filter-bar__field">
                <label for="a-to" class="text-xs text-muted">To</label>
                <input type="date" id="a-to" name="date_to" value="<?= e($dateTo) ?>">
            </div>

            <div class="filter-bar__field">
                <label for="a-grade" class="text-xs text-muted">Grade level</label>
                <select id="a-grade" name="grade_level_id">
                    <option value="">All grade levels</option>
                    <?php foreach ($gradeLevels as $grade): ?>
                        <option value="<?= e($grade['grade_level_id']) ?>"><?= e($grade['grade_level_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-bar__field">
                <label for="a-days" class="text-xs text-muted">Hardware window</label>
                <select id="a-days" name="days">
                    <option value="7">Last 7 days</option>
                    <option value="14">Last 14 days</option>
                    <option value="30">Last 30 days</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-rotate"></i> Refresh</button>

            <div class="flex gap-1" style="margin-left:auto">
                <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $days => $label): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-range="<?= e($days) ?>"><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>

<!-- Reliability strip ------------------------------------------------------->
<div class="grid grid--4 mb-3">
    <?php foreach ([
        ['id' => 'rel-rfid',    'label' => 'RFID acceptance',      'icon' => 'fa-id-card',            'hint' => 'Taps accepted vs all taps'],
        ['id' => 'rel-fp',      'label' => 'Fingerprint success',  'icon' => 'fa-fingerprint',        'hint' => 'Verified vs all verification attempts'],
        ['id' => 'rel-sync',    'label' => 'Offline sync success', 'icon' => 'fa-arrows-rotate',      'hint' => 'Completed vs attempted queue syncs'],
        ['id' => 'rel-timeout', 'label' => 'Time-out compliance',  'icon' => 'fa-right-from-bracket', 'hint' => 'Students who tapped out themselves'],
    ] as $stat): ?>
        <div class="stat">
            <span class="stat__icon stat__icon--info"><i class="fa-solid <?= e($stat['icon']) ?>"></i></span>
            <div>
                <div class="stat__label"><?= e($stat['label']) ?></div>
                <div class="stat__value" id="<?= e($stat['id']) ?>">—</div>
                <div class="stat__meta"><?= e($stat['hint']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid--2">
    <div class="card" style="grid-column:1 / -1">
        <div class="card__header">
            <h2 class="card__title">Attendance trend</h2>
            <span class="text-xs text-muted">Daily present / late / absent counts</span>
        </div>
        <div class="card__body"><div id="chart-trend" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Attendance rate by grade level</h2>
        </div>
        <div class="card__body"><div id="chart-grade" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Attendance rate by section</h2>
            <span class="text-xs text-muted">Lowest first</span>
        </div>
        <div class="card__body"><div id="chart-section" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Attendance rate by subject</h2></div>
        <div class="card__body"><div id="chart-subject" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Attendance rate by teacher</h2></div>
        <div class="card__body"><div id="chart-teacher" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Arrival distribution</h2>
            <span class="text-xs text-muted">Minutes relative to the scheduled start</span>
        </div>
        <div class="card__body"><div id="chart-arrival" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Left-early rate</h2>
            <span class="text-xs text-muted">Share of records ending before the early-departure cutoff</span>
        </div>
        <div class="card__body"><div id="chart-left-early" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Average time in room</h2>
            <span class="text-xs text-muted">Mean dwell per section, in minutes</span>
        </div>
        <div class="card__body"><div id="chart-dwell" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Incomplete records by subject</h2>
            <span class="text-xs text-muted">Time-in with no time-out</span>
        </div>
        <div class="card__body"><div id="chart-incomplete" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Device uptime</h2>
            <span class="text-xs text-muted">Heartbeats received against heartbeats expected</span>
        </div>
        <div class="card__body"><div id="chart-uptime" style="min-height:260px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Rejected taps by cause</h2>
            <span class="text-xs text-muted">Where the rejections are actually coming from</span>
        </div>
        <div class="card__body"><div id="chart-rejections" style="min-height:260px"></div></div>
    </div>

    <div class="card" style="grid-column:1 / -1">
        <div class="card__header">
            <h2 class="card__title">Monthly trend</h2>
            <span class="text-xs text-muted">Last 12 months, attendance percentage</span>
        </div>
        <div class="card__body"><div id="chart-monthly" style="min-height:260px"></div></div>
    </div>

    <div class="card" style="grid-column:1 / -1">
        <div class="card__header">
            <h2 class="card__title">Chronic absence</h2>
            <span class="text-xs text-muted">Students below the configured attendance threshold</span>
        </div>
        <div class="card__body--flush">
            <div class="table-wrap" style="max-height:420px;overflow-y:auto">
                <table class="data" id="chronic-table">
                    <thead>
                    <tr><th>Student</th><th>Section</th><th>Adviser</th>
                        <th class="numeric">Records</th><th class="numeric">Absent</th><th class="numeric">Rate</th></tr>
                    </thead>
                    <tbody><tr><td colspan="6" class="text-center text-muted">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    const form = document.getElementById('analytics-filters');

    function params() {
        const data = LS.util.formData(form);
        return {
            date_from:      data.date_from,
            date_to:        data.date_to,
            grade_level_id: data.grade_level_id || '',
            days:           data.days || 7,
        };
    }

    /**
     * Each card is requested separately (`chart=`), so one heavy dataset does
     * not delay the rest of the page and a failure is contained to its own
     * card rather than blanking the dashboard.
     */
    async function load(chart, render) {
        const container = document.getElementById(chart.container);
        if (container) container.innerHTML = '<div class="skeleton skeleton--chart"></div>';

        try {
            const response = await LS.http.get('/api/analytics', Object.assign({ chart: chart.name }, params()));
            render(response.data.datasets[chart.name], response.data);
        } catch (error) {
            if (container) {
                container.innerHTML = '<div class="alert alert-danger" style="margin:0">'
                    + LS.util.escape(error.message || 'Could not load this dataset.') + '</div>';
            }
        }
    }

    function percentRows(rows) {
        return (rows || []).map((row) => Object.assign({}, row, { value: Number(row.percentage) || 0 }));
    }

    function renderAll() {
        load({ name: 'trend', container: 'chart-trend' }, (rows) => {
            LS.charts.line('chart-trend', rows || [], {
                xKey: 'date',
                series: [
                    { key: 'present', label: 'Present', color: '#22C55E' },
                    { key: 'late',    label: 'Late',    color: '#F59E0B' },
                    { key: 'absent',  label: 'Absent',  color: '#EF4444' },
                ],
                title: 'Attendance trend',
            });
        });

        load({ name: 'by_grade', container: 'chart-grade' }, (rows) => {
            LS.charts.bar('chart-grade', percentRows(rows), {
                valueKey: 'value', labelKey: 'label', maxValue: 100,
                title: 'Attendance rate by grade level', suffix: '%',
            });
        });

        load({ name: 'by_section', container: 'chart-section' }, (rows) => {
            const sorted = percentRows(rows).sort((a, b) => a.value - b.value);
            LS.charts.horizontalBar('chart-section', sorted, {
                valueKey: 'value', labelKey: 'label', limit: 12, suffix: '%',
                title: 'Attendance rate by section',
            });
        });

        load({ name: 'by_subject', container: 'chart-subject' }, (rows) => {
            LS.charts.horizontalBar('chart-subject', percentRows(rows), {
                valueKey: 'value', labelKey: 'label', limit: 12, suffix: '%',
                title: 'Attendance rate by subject',
            });
        });

        load({ name: 'by_teacher', container: 'chart-teacher' }, (rows) => {
            LS.charts.horizontalBar('chart-teacher', percentRows(rows), {
                valueKey: 'value', labelKey: 'label', limit: 12, suffix: '%',
                title: 'Attendance rate by teacher',
            });
        });

        load({ name: 'time_in_distribution', container: 'chart-arrival' }, (rows) => {
            LS.charts.bar('chart-arrival', rows || [], {
                valueKey: 'total', labelKey: 'label', limit: 20,
                title: 'Arrival distribution',
            });
        });

        load({ name: 'left_early', container: 'chart-left-early' }, (rows) => {
            LS.charts.line('chart-left-early', rows || [], {
                xKey: 'date',
                series: [{ key: 'percentage', label: 'Left early %', color: '#FB923C' }],
                title: 'Left-early rate',
            });
        });

        load({ name: 'dwell', container: 'chart-dwell' }, (rows) => {
            LS.charts.horizontalBar('chart-dwell', rows || [], {
                valueKey: 'average_dwell', labelKey: 'label', limit: 12, suffix: ' min',
                title: 'Average time in room',
            });
        });

        load({ name: 'incomplete', container: 'chart-incomplete' }, (rows) => {
            LS.charts.horizontalBar('chart-incomplete', rows || [], {
                valueKey: 'incomplete', labelKey: 'label', limit: 12,
                title: 'Incomplete records by subject',
                emptyMessage: 'No incomplete records in this range.',
            });
        });

        load({ name: 'device_uptime', container: 'chart-uptime' }, (rows) => {
            LS.charts.horizontalBar('chart-uptime', rows || [], {
                valueKey: 'uptime_percentage', labelKey: 'label', limit: 15, suffix: '%',
                title: 'Device uptime',
            });
        });

        load({ name: 'rejections', container: 'chart-rejections' }, (rows) => {
            LS.charts.donut('chart-rejections', (rows || []).map((row, index) => ({
                label: String(row.label || '').replace(/_/g, ' '),
                value: Number(row.total) || 0,
                color: LS.charts.PALETTE[index % LS.charts.PALETTE.length],
            })), {
                size: 200, legend: true,
                emptyMessage: 'No rejected taps in this window — good.',
            });
        });

        load({ name: 'monthly', container: 'chart-monthly' }, (rows) => {
            LS.charts.line('chart-monthly', rows || [], {
                xKey: 'label',
                series: [{ key: 'percentage', label: 'Attendance %', color: '#2563EB' }],
                maxValue: 100,
                title: 'Monthly attendance',
            });
        });

        // Reliability strip and the compliance tile share one request each.
        load({ name: 'reliability', container: null }, (data) => {
            set('rel-rfid', data.rfid_acceptance_rate, data.rfid_rejections + ' rejected');
            set('rel-fp',   data.fingerprint_success_rate, data.fingerprint_failures + ' failed');
            set('rel-sync', data.sync_success_rate, data.sync_failures + ' failed syncs');
        });

        load({ name: 'time_out_compliance', container: null }, (data) => {
            set('rel-timeout', data.compliance_percentage, data.auto_out + ' auto-closed, ' + data.missing + ' missing');
        });

        load({ name: 'chronic_absence', container: null }, (rows) => {
            const body = document.querySelector('#chronic-table tbody');

            if (!rows || rows.length === 0) {
                body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">'
                    + 'No student is below the threshold in this range.</td></tr>';
                return;
            }

            body.innerHTML = rows.map((row) =>
                '<tr><td class="cell-stack"><span class="cell-primary">' + LS.util.escape(row.student_name) + '</span>'
                + '<span class="cell-muted mono">' + LS.util.escape(row.student_number) + '</span></td>'
                + '<td><span class="badge badge-neutral">' + LS.util.escape(row.section_code || '—') + '</span></td>'
                + '<td class="text-sm">' + LS.util.escape(row.adviser_name || 'unassigned') + '</td>'
                + '<td class="numeric">' + row.total + '</td>'
                + '<td class="numeric">' + row.absent + '</td>'
                + '<td class="numeric text-danger fw-600">' + row.percentage + '%</td></tr>').join('');
        });
    }

    function set(id, value, hint) {
        const node = document.getElementById(id);
        if (!node) return;

        node.textContent = value === null || value === undefined ? 'n/a' : value + '%';
        node.className = 'stat__value ' + (value === null || value === undefined
            ? 'text-muted'
            : (value >= 95 ? 'text-success' : value >= 85 ? 'text-warning' : 'text-danger'));

        const hintNode = node.nextElementSibling;
        if (hintNode && hint) hintNode.textContent = hint;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        renderAll();
    });

    document.querySelectorAll('[data-range]').forEach((button) => {
        button.addEventListener('click', function () {
            const days = Number(this.dataset.range);
            const to   = new Date();
            const from = new Date(Date.now() - (days - 1) * 86400000);

            document.getElementById('a-to').value   = to.toISOString().slice(0, 10);
            document.getElementById('a-from').value = from.toISOString().slice(0, 10);
            renderAll();
        });
    });

    renderAll();
})();
</script>
<?php $__view->stop(); ?>
