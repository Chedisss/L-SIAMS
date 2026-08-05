<?php
/** @var App\Core\View $__view */

use App\Services\AttendanceStatusResolver;

$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Attendance',
    'subtitle'    => 'Every record carries the section the student belonged to at the time of the tap.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Attendance', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/attendance/sessions"><i class="fa-solid fa-clock"></i> Sessions</a>'
        . '<div class="dropdown"><button class="btn btn-secondary" data-dropdown="att-export"><i class="fa-solid fa-download"></i> Export</button>'
        . '<div class="dropdown__menu" id="att-export">'
        . '<a class="dropdown__item" href="#" data-export="csv">CSV</a>'
        . '<a class="dropdown__item" href="#" data-export="xlsx">Excel</a>'
        . '<a class="dropdown__item" href="#" data-export="pdf">PDF</a></div></div>',
]); ?>

<?php if ($openSessions !== []): ?>
    <div class="alert alert-info">
        <span class="alert__icon"><i class="fa-solid fa-bolt"></i></span>
        <div class="alert__body">
            <strong><?= e(count($openSessions)) ?> session(s) open right now.</strong>
            New taps appear in this table live.
        </div>
    </div>
<?php endif; ?>

<form class="filter-bar" id="attendance-filters" onsubmit="return false">
    <div class="form-group">
        <label for="f-from">From</label>
        <input type="date" id="f-from" name="date_from" value="<?= e($filters['date_from']) ?>">
    </div>
    <div class="form-group">
        <label for="f-to">To</label>
        <input type="date" id="f-to" name="date_to" value="<?= e($filters['date_to']) ?>">
    </div>
    <div class="form-group">
        <label for="f-section">Section</label>
        <select id="f-section" name="section_id">
            <option value="">All sections</option>
            <?php foreach ($sections as $section): ?>
                <option value="<?= e($section['section_id']) ?>" <?= (int) ($filters['section_id'] ?? 0) === (int) $section['section_id'] ? 'selected' : '' ?>>
                    <?= e($section['section_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-subject">Subject</label>
        <select id="f-subject" name="subject_id">
            <option value="">All subjects</option>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= e($subject['subject_id']) ?>" <?= (int) ($filters['subject_id'] ?? 0) === (int) $subject['subject_id'] ? 'selected' : '' ?>>
                    <?= e($subject['subject_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-teacher">Teacher</label>
        <select id="f-teacher" name="teacher_id">
            <option value="">All teachers</option>
            <?php foreach ($teachers as $teacher): ?>
                <option value="<?= e($teacher['teacher_id']) ?>" <?= (int) ($filters['teacher_id'] ?? 0) === (int) $teacher['teacher_id'] ? 'selected' : '' ?>>
                    <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-status">Status</label>
        <select id="f-status" name="final_status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['final_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>"
               placeholder="Student, number, card UID, teacher or session">
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.search=''">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Attendance records</h2>
        <span class="text-sm text-muted"><?= e(number_format($pagination['total'])) ?> total</span>
    </div>

    <div class="card__body--flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Date</th><th>Student</th><th>Section</th><th>Subject</th><th>Teacher</th><th>Room</th>
                    <th>Time In</th><th>Time Out</th><th class="numeric">Duration</th>
                    <th>Arrival</th><th>Departure</th><th>Final</th><th style="width:60px"></th>
                </tr>
                </thead>
                <tbody id="attendance-body">
                <?php if ($records === []): ?>
                    <tr><td colspan="13">
                        <?php $__view->include('partials.empty-state', [
                            'icon'  => 'fa-clipboard-user',
                            'title' => 'No attendance records found',
                            'text'  => 'Adjust the filters, or wait for the next class to take attendance.',
                        ]); ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $duration = $record['duration_minutes'] === null ? null : (int) $record['duration_minutes'];
                        $short    = $duration !== null && $duration < 20;
                        ?>
                        <tr data-attendance-id="<?= e($record['attendance_id']) ?>">
                            <td class="nowrap text-sm"><?= e(format_date($record['attendance_date'])) ?></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($record['student_name']) ?></span>
                                <span class="cell-muted"><?= e($record['student_number']) ?></span>
                            </td>
                            <td><span class="badge badge-primary"><?= e($record['section_code']) ?></span></td>
                            <td class="text-sm"><?= e($record['subject_code']) ?></td>
                            <td class="text-sm"><?= e($record['teacher_name']) ?></td>
                            <td class="text-sm"><?= e($record['room_number']) ?></td>
                            <td class="nowrap"><?= e(format_time($record['time_in'])) ?></td>
                            <td class="nowrap">
                                <?php if ($record['time_out'] === null): ?>
                                    <span class="text-warning">—<span class="badge badge-warning ml-1" style="margin-left:.3rem">pending</span></span>
                                <?php else: ?>
                                    <?= e(format_time($record['time_out'])) ?>
                                    <?php if ((int) $record['auto_generated_time_out'] === 1): ?>
                                        <span class="badge badge-neutral" title="Stamped automatically at session close">AUTO</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="numeric <?= $short ? 'text-danger fw-600' : '' ?>">
                                <?= $duration === null ? '—' : e($duration) . ' min' ?>
                            </td>
                            <td><span class="badge <?= e(status_badge($record['arrival_status'])) ?>"><?= e(ucfirst(str_replace('_', ' ', (string) $record['arrival_status']))) ?></span></td>
                            <td><span class="badge <?= e(status_badge($record['departure_status'])) ?>"><?= e(ucfirst(str_replace('_', ' ', (string) $record['departure_status']))) ?></span></td>
                            <td><span class="badge <?= e(AttendanceStatusResolver::badgeClass((string) $record['final_status'])) ?>"><?= e($record['final_status']) ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" data-detail="<?= e($record['attendance_id']) ?>" title="Details">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php $__view->include('partials.pagination', ['pagination' => $pagination, 'target' => 'attendanceTable']); ?>
</div>

<!-- Attendance detail + correction ---------------------------------------- -->
<div class="modal-backdrop" id="attendance-modal">
    <div class="modal modal--lg" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Attendance record</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body" id="attendance-detail">
            <div class="skeleton skeleton--row"></div>
            <div class="skeleton skeleton--row"></div>
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

    function filters() {
        const out = {};
        ['f-from:date_from', 'f-to:date_to', 'f-section:section_id', 'f-subject:subject_id',
         'f-teacher:teacher_id', 'f-status:final_status', 'f-search:search'].forEach((pair) => {
            const [id, name] = pair.split(':');
            const value = document.getElementById(id).value;
            if (value) out[name] = value;
        });
        return out;
    }

    const apply = LS.util.debounce(() => {
        window.location.search = new URLSearchParams(filters()).toString();
    }, 450);

    ['f-from', 'f-to', 'f-section', 'f-subject', 'f-teacher', 'f-status'].forEach((id) => {
        document.getElementById(id).addEventListener('change', apply);
    });
    document.getElementById('f-search').addEventListener('input', apply);

    document.querySelectorAll('[data-export]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const params = new URLSearchParams(filters());
            params.set('format', link.dataset.export);
            window.location.href = '/admin/attendance/export?' + params.toString();
        });
    });

    window.attendanceTable = { load: (o) => { const p = new URLSearchParams(Object.assign(filters(), o)); window.location.search = p.toString(); },
                               page: (n) => window.attendanceTable.load({ page: n }) };

    /* ---- detail + correction -------------------------------------------- */

    document.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-detail]');
        if (!trigger) return;

        LS.modal.open('attendance-modal');
        const body = document.getElementById('attendance-detail');
        body.innerHTML = '<div class="skeleton skeleton--row"></div><div class="skeleton skeleton--row"></div>';

        try {
            const response = await LS.http.get('/admin/attendance/' + trigger.dataset.detail);
            body.innerHTML = render(response.data);
            wireCorrection(trigger.dataset.detail);
        } catch (error) {
            body.innerHTML = '<div class="alert alert-danger">Could not load this record.</div>';
        }
    });

    function field(label, value) {
        return '<div style="padding:.4rem 0;border-bottom:1px solid var(--border)">'
            + '<div class="text-xs text-muted">' + label + '</div>'
            + '<div class="text-sm">' + LS.util.escape(value === null || value === '' ? '—' : value) + '</div></div>';
    }

    function render(data) {
        const r = data.record;

        let html = '<div class="grid grid--3" style="gap:0 1.2rem">'
            + field('Attendance ID', r.attendance_id)
            + field('Session', r.session_code)
            + field('Student', r.student_name + ' (' + r.student_number + ')')
            + field('Section at time of tap', r.section_code)
            + field('Grade level', r.grade_level_name)
            + field('Subject', r.subject_code + ' — ' + r.subject_name)
            + field('Teacher', r.teacher_name)
            + field('Room', r.room_number)
            + field('Card UID', r.rfid_uid)
            + field('Time in', r.time_in)
            + field('Time out', r.time_out)
            + field('Duration', r.duration_minutes === null ? '—' : r.duration_minutes + ' min')
            + field('Time-in terminal', r.time_in_device_name || r.time_in_device_id)
            + field('Time-out terminal', r.time_out_device_name || r.time_out_device_id)
            + field('Time-in IP', r.time_in_ip)
            + field('Time-out IP', r.time_out_ip)
            + field('Auto-generated time out', Number(r.auto_generated_time_out) === 1 ? 'Yes' : 'No')
            + field('Synced from offline queue', Number(r.synced_offline) === 1 ? 'Yes' : 'No')
            + field('Arrival status', r.arrival_status)
            + field('Departure status', r.departure_status)
            + field('Final status', r.final_status)
            + '</div>';

        /* Full tap history for this student in this session, rejections included. */
        html += '<h4 class="mt-3">Tap history for this session</h4>';

        if ((data.taps || []).length === 0) {
            html += '<p class="text-muted text-sm">No scan log entries.</p>';
        } else {
            html += '<table class="data"><thead><tr><th>Time</th><th>Intent</th><th>Result</th><th>Terminal</th><th>Detail</th></tr></thead><tbody>';
            data.taps.forEach((tap) => {
                html += '<tr><td class="nowrap text-sm">' + LS.util.formatTime(tap.created_at) + '</td>'
                    + '<td>' + LS.util.escape(tap.intent) + '</td>'
                    + '<td><span class="badge ' + (tap.result === 'accepted' ? 'badge-success' : 'badge-danger') + '">'
                    + LS.util.escape(tap.result) + '</span></td>'
                    + '<td class="mono text-xs">' + LS.util.escape(tap.device_id || '—') + '</td>'
                    + '<td class="text-xs text-muted">' + LS.util.escape(tap.message || '') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        /* Correction history — the original values are preserved permanently. */
        html += '<h4 class="mt-3">Correction history</h4>';

        if ((data.modifications || []).length === 0) {
            html += '<p class="text-muted text-sm">This record has never been corrected.</p>';
        } else {
            html += '<table class="data"><thead><tr><th>When</th><th>Administrator</th><th>Field</th><th>From</th><th>To</th><th>Reason</th></tr></thead><tbody>';
            data.modifications.forEach((m) => {
                html += '<tr><td class="nowrap text-sm">' + LS.util.formatDate(m.created_at) + ' ' + LS.util.formatTime(m.created_at) + '</td>'
                    + '<td>' + LS.util.escape(m.full_name || m.username) + '</td>'
                    + '<td class="mono text-xs">' + LS.util.escape(m.field_changed) + '</td>'
                    + '<td class="text-xs">' + LS.util.escape(m.old_value) + '</td>'
                    + '<td class="text-xs fw-600">' + LS.util.escape(m.new_value) + '</td>'
                    + '<td class="text-xs text-muted">' + LS.util.escape(m.reason) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        /* Correction form — administrators only, reason always required. */
        html += '<h4 class="mt-3">Correct this record</h4>'
            + '<div class="alert alert-warning"><span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>'
            + '<div class="alert__body">Corrections are permanent, audited, and preserve the original values. '
            + 'The final status is recomputed automatically from arrival and departure.</div></div>'
            + '<form id="correction-form"><div class="form-grid">'
            + '<div class="form-group"><label for="c-arrival">Arrival status</label><select id="c-arrival" name="arrival_status">'
            + data.arrival_statuses.map((s) => '<option value="' + s + '"' + (s === r.arrival_status ? ' selected' : '') + '>' + s + '</option>').join('')
            + '</select></div>'
            + '<div class="form-group"><label for="c-departure">Departure status</label><select id="c-departure" name="departure_status">'
            + data.departure_statuses.map((s) => '<option value="' + s + '"' + (s === r.departure_status ? ' selected' : '') + '>' + s + '</option>').join('')
            + '</select></div>'
            + '<div class="form-group form-group--full"><label for="c-reason" class="required">Reason</label>'
            + '<textarea id="c-reason" name="reason" required minlength="5" maxlength="500" '
            + 'placeholder="e.g. Student presented a medical certificate for this date"></textarea></div>'
            + '</div><button type="submit" class="btn btn-primary">Save correction</button></form>';

        return html;
    }

    function wireCorrection(attendanceId) {
        const form = document.getElementById('correction-form');
        if (!form) return;

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const button = form.querySelector('[type=submit]');
            LS.util.setBusy(button, true, 'Saving…');

            try {
                const response = await LS.http.post('/admin/attendance/' + attendanceId + '/correct',
                    LS.util.formData(form));
                LS.toast.success(response.message);
                LS.modal.close('attendance-modal');
                setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                LS.toast.fromError(error);
            } finally {
                LS.util.setBusy(button, false);
            }
        });
    }

    /* Live inserts at the top of the table. */
    if (LS.realtime) {
        LS.realtime.on('attendance.time_in', () => {
            LS.toast.info('New attendance recorded — refresh to see it in this table.');
        });
    }
})();
</script>
<?php $__view->stop(); ?>
