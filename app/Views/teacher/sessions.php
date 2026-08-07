<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$open = array_values(array_filter($sessions, static fn (array $s): bool => $s['status'] === 'open'));
$past = array_values(array_filter($sessions, static fn (array $s): bool => $s['status'] !== 'open'));
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'My Sessions',
    'subtitle'    => 'Attendance sessions you have opened, newest first. Sessions are opened by verifying your fingerprint on the classroom terminal.',
    'breadcrumbs' => [['Dashboard', '/teacher'], ['My Sessions', null]],
]); ?>

<?php if ($open !== []): ?>
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Open now <span class="badge badge-success"><?= e(count($open)) ?></span></h2>
            <span class="text-xs text-muted">Counters update live</span>
        </div>

        <div class="card__body--flush">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Session</th><th>Subject</th><th>Section</th><th>Room</th>
                        <th class="numeric">Present</th><th class="numeric">Late</th><th class="numeric">Roster</th>
                        <th>Opened</th><th>Auto-closes</th><th style="width:170px"></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($open as $session): ?>
                        <tr data-session-code="<?= e($session['session_code']) ?>">
                            <td><a class="mono text-sm" href="/teacher/sessions/<?= e($session['session_id']) ?>"><?= e($session['session_code']) ?></a></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($session['subject_name']) ?></span>
                                <span class="cell-muted mono"><?= e($session['subject_code']) ?></span>
                            </td>
                            <td><span class="badge badge-primary"><?= e($session['section_code']) ?></span></td>
                            <td class="text-sm"><?= e($session['room_number']) ?></td>
                            <td class="numeric text-success fw-600" data-count="present_count"><?= e($session['present_count']) ?></td>
                            <td class="numeric text-warning" data-count="late_count"><?= e($session['late_count']) ?></td>
                            <td class="numeric" data-count="roster_count"><?= e($session['roster_count']) ?></td>
                            <td class="nowrap text-sm"><?= e(format_time($session['opened_at'])) ?></td>
                            <td class="nowrap text-sm"><?= e($session['expires_at'] ? format_time($session['expires_at']) : '—') ?></td>
                            <td class="nowrap">
                                <a class="btn btn-ghost btn-sm" href="/teacher/sessions/<?= e($session['session_id']) ?>">
                                    <i class="fa-solid fa-eye"></i> Roster
                                </a>
                                <button class="btn btn-ghost btn-sm text-danger" data-close="<?= e($session['session_id']) ?>"
                                        data-code="<?= e($session['session_code']) ?>">
                                    <i class="fa-solid fa-lock"></i> Close
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">History</h2>
        <span class="text-xs text-muted">Last 100 sessions</span>
    </div>

    <div class="card__body--flush">
        <?php if ($past === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-clock-rotate-left',
                'title' => 'No closed sessions yet',
                'text'  => 'Open a session on the classroom terminal by verifying your fingerprint, and it will appear here once it closes.',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Date</th><th>Session</th><th>Subject</th><th>Section</th><th>Room</th>
                        <th class="numeric">Present</th><th class="numeric">Late</th>
                        <th class="numeric">Absent</th><th class="numeric">Roster</th>
                        <th class="numeric">Rate</th><th>Closed by</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($past as $session): ?>
                        <?php
                        $roster = (int) $session['roster_count'];
                        $rate   = $roster === 0
                            ? null
                            : round(((int) $session['present_count'] + (int) $session['late_count']) / $roster * 100, 1);
                        ?>
                        <tr>
                            <td class="nowrap text-sm"><?= e(format_date($session['session_date'])) ?></td>
                            <td><a class="mono text-xs" href="/teacher/sessions/<?= e($session['session_id']) ?>"><?= e($session['session_code']) ?></a></td>
                            <td class="text-sm"><?= e($session['subject_code']) ?></td>
                            <td><span class="badge badge-neutral"><?= e($session['section_code']) ?></span></td>
                            <td class="text-sm"><?= e($session['room_number']) ?></td>
                            <td class="numeric"><?= e($session['present_count']) ?></td>
                            <td class="numeric"><?= e($session['late_count']) ?></td>
                            <td class="numeric"><?= e($session['absent_count']) ?></td>
                            <td class="numeric"><?= e($roster) ?></td>
                            <td class="numeric <?= $rate !== null && $rate < 80 ? 'text-danger fw-600' : '' ?>">
                                <?= $rate === null ? '—' : e($rate) . '%' ?>
                            </td>
                            <td class="text-xs text-muted"><?= e($session['closed_by_type'] ?? '—') ?></td>
                            <td class="nowrap">
                                <a class="btn btn-ghost btn-sm" href="/teacher/sessions/<?= e($session['session_id']) ?>">
                                    <i class="fa-solid fa-eye"></i>
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

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    /* ---- live counters on the open sessions ------------------------------ */
    function applyCounters(payload) {
        const row = document.querySelector('[data-session-code="' + CSS.escape(String(payload.session_id)) + '"]');
        if (!row || !payload.counters) return;

        const c = payload.counters;

        set(row, 'present_count', c.timed_in - c.late);
        set(row, 'late_count',    c.late);
        set(row, 'roster_count',  c.total);
    }

    function set(row, key, value) {
        const cell = row.querySelector('[data-count="' + key + '"]');
        if (!cell || cell.textContent.trim() === String(value)) return;

        cell.textContent = value;
        cell.classList.remove('cell-flash');
        void cell.offsetWidth;
        cell.classList.add('cell-flash');
    }

    LS.realtime.on('attendance.time_in',  applyCounters);
    LS.realtime.on('attendance.time_out', applyCounters);

    const reload = LS.util.debounce(() => window.location.reload(), 1200);
    LS.realtime.on('session.opened', reload);
    LS.realtime.on('session.closed', reload);

    /* ---- close a session from the browser -------------------------------- */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-close]');
        if (!button) return;

        const confirmed = await LS.modal.confirm({
            title:   'Close ' + button.dataset.code + '?',
            message: 'Students still in the room get an automatic time-out at the scheduled end time, '
                     + 'and anyone on the roster who never tapped in is marked absent. This is final — '
                     + 'attendance records cannot be edited afterwards. The session would auto-close on '
                     + 'its own at the end of the period if you prefer to wait.',
            confirmLabel: 'Close session',
            danger: true,
        });

        if (!confirmed) return;

        LS.util.setBusy(button, true, 'Closing…');

        try {
            const response = await LS.http.post('/teacher/sessions/' + button.dataset.close + '/close', {});
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 1000);
        } catch (error) {
            LS.toast.fromError(error);
            LS.util.setBusy(button, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
