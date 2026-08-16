<?php
/** @var App\Core\View $__view */

use App\Services\AttendanceStatusResolver;

$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Attendance',
    'subtitle'    => 'Records from your own classes. Every row carries the section the student belonged to at the moment of the tap.',
    'breadcrumbs' => [['Dashboard', '/teacher'], ['Attendance', null]],
    'actions'     => '<a class="btn btn-secondary" href="/teacher/sessions"><i class="fa-solid fa-clock"></i> My sessions</a>'
        . '<a class="btn btn-primary" href="/teacher/reports"><i class="fa-solid fa-file-export"></i> Reports</a>',
]); ?>

<div class="alert alert-info">
    <span class="alert__icon"><i class="fa-solid fa-lock"></i></span>
    <div class="alert__body">
        Attendance records cannot be edited or deleted — not by you, not by an administrator, not by
        the database user the application connects with. If something is wrong, ask the administration
        to record a correction; the original stays and the correction is logged alongside it.
    </div>
</div>

<form class="filter-bar" id="attendance-filters" data-no-submit>
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
            <option value="">All my sections</option>
            <?php foreach ($sections as $section): ?>
                <option value="<?= e($section['section_id']) ?>"
                    <?= (int) ($filters['section_id'] ?? 0) === (int) $section['section_id'] ? 'selected' : '' ?>>
                    <?= e($section['section_code']) ?> — <?= e($section['section_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="f-subject">Subject</label>
        <select id="f-subject" name="subject_id">
            <option value="">All my subjects</option>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= e($subject['subject_id']) ?>"
                    <?= (int) ($filters['subject_id'] ?? 0) === (int) $subject['subject_id'] ? 'selected' : '' ?>>
                    <?= e($subject['subject_code']) ?>
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
               placeholder="Student name, number or session code">
    </div>

    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" id="reset-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Records</h2>
        <span class="text-sm text-muted" id="attendance-total"><?= e(number_format($pagination['total'])) ?> total</span>
    </div>

    <div class="card__body--flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Date</th><th>Student</th><th>Section</th><th>Subject</th><th>Room</th>
                    <th>Time in</th><th>Time out</th><th class="numeric">Duration</th>
                    <th>Arrival</th><th>Departure</th><th>Final</th>
                </tr>
                </thead>
                <tbody id="attendance-body">
                <?php if ($records === []): ?>
                    <tr><td colspan="11">
                        <?php $__view->include('partials.empty-state', [
                            'icon'  => 'fa-clipboard-user',
                            'title' => 'No records match these filters',
                            'text'  => 'Adjust the filters, or wait for your next class.',
                        ]); ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <?php $duration = $record['duration_minutes'] === null ? null : (int) $record['duration_minutes']; ?>
                        <tr>
                            <td class="nowrap text-sm"><?= e(format_date($record['attendance_date'])) ?></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($record['student_name']) ?></span>
                                <span class="cell-muted mono"><?= e($record['student_number']) ?></span>
                            </td>
                            <td><span class="badge badge-primary"><?= e($record['section_code']) ?></span></td>
                            <td class="text-sm"><?= e($record['subject_code']) ?></td>
                            <td class="text-sm"><?= e($record['room_number']) ?></td>
                            <td class="nowrap text-sm"><?= e(format_time($record['time_in'])) ?></td>
                            <td class="nowrap text-sm">
                                <?php if ($record['time_out'] === null && $record['time_in'] === null): ?>
                                    <?php /* Never arrived, so there is nothing to leave. Reading
                                             "still in room" off a missing tap-out alone put an
                                             absent student in the room and marked them absent in
                                             the same row. */ ?>
                                    —
                                <?php elseif ($record['time_out'] === null): ?>
                                    <span class="text-warning">still in room</span>
                                <?php else: ?>
                                    <?= e(format_time($record['time_out'])) ?>
                                    <?php if ((int) $record['auto_generated_time_out'] === 1): ?>
                                        <span class="badge badge-neutral" title="Stamped automatically when the session closed">AUTO</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="numeric"><?= $duration === null ? '—' : e($duration) . ' min' ?></td>
                            <?php /* Green or red, and "on time" rather than "present": the column
                                     answers whether they got here in time, so it should say so. */ ?>
                            <td class="text-xs fw-600 <?= e(AttendanceStatusResolver::arrivalClass((string) $record['arrival_status'])) ?>">
                                <?= e(AttendanceStatusResolver::arrivalLabel((string) $record['arrival_status'])) ?>
                            </td>
                            <td class="text-xs text-muted"><?= e(str_replace('_', ' ', (string) $record['departure_status'])) ?></td>
                            <td>
                                <span class="badge <?= e(AttendanceStatusResolver::badgeClass((string) $record['final_status'])) ?>">
                                    <?= e($record['final_status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php $__view->include('partials.pagination', ['pagination' => $pagination]); ?>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    // Filters are pushed into the URL so a filtered view can be bookmarked and
    // the back button behaves. The server re-scopes everything to this teacher
    // regardless of what the query string says.
    const apply = LS.util.debounce(function () {
        const params = new URLSearchParams();

        ['f-from:date_from', 'f-to:date_to', 'f-section:section_id',
         'f-subject:subject_id', 'f-status:final_status', 'f-search:search'].forEach((pair) => {
            const [id, name] = pair.split(':');
            const value = document.getElementById(id).value;
            if (value) params.set(name, value);
        });

        window.location.search = params.toString();
    }, 500);

    document.querySelectorAll('#attendance-filters input, #attendance-filters select')
        .forEach((field) => field.addEventListener(field.tagName === 'SELECT' ? 'change' : 'input', apply));

    document.getElementById('reset-filters').addEventListener('click', () => {
        window.location.href = '/teacher/attendance';
    });

    // A tap in one of this teacher's own classes arrives on their channel.
    //
    // Announcing it and leaving the table showing the state before the tap
    // told the teacher something had happened and then made them reload to
    // find out what. The rows are pulled fresh instead, with the filters and
    // page they are already looking at still applied.
    //
    // Debounced because a class taps in together: thirty students through the
    // reader in a minute should be a handful of refreshes, not thirty.
    const refreshRows = LS.util.debounce(() => {
        LS.util.swapFromServer(['attendance-body', 'attendance-total']);
    }, 700);

    ['attendance.time_in', 'attendance.time_out'].forEach((event) => {
        LS.realtime.on(event, refreshRows);
    });
})();
</script>
<?php $__view->stop(); ?>
