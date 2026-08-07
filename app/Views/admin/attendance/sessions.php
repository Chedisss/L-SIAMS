<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Open Attendance Sessions',
    'subtitle'    => 'Sessions currently accepting taps. Only one session can be open per classroom and per device — the database enforces it, not the interface.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Attendance', '/admin/attendance'], ['Sessions', null]],
]); ?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Live sessions <span class="badge badge-neutral" id="open-count"><?= e(count($sessions)) ?></span></h2>
        <span class="text-xs text-muted">Counters update as taps arrive</span>
    </div>

    <div class="card__body--flush">
        <div id="sessions-body">
            <?php if ($sessions === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-door-open',
                    'title' => 'No sessions are open',
                    'text'  => 'A teacher opens a session by verifying their fingerprint on the classroom terminal. Nothing else opens one.',
                ]); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Session</th><th>Section</th><th>Subject</th><th>Teacher</th>
                            <th>Room / device</th><th class="numeric">Present</th><th class="numeric">Late</th>
                            <th class="numeric">Roster</th><th class="numeric">Rejected</th>
                            <th>Opened</th><th>Auto-close</th><th style="width:170px"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <tr data-session="<?= e($session['session_id']) ?>"
                                data-session-code="<?= e($session['session_code']) ?>">
                                <td><a class="mono text-sm" href="/admin/attendance/sessions/<?= e($session['session_id']) ?>"><?= e($session['session_code']) ?></a></td>
                                <td><span class="badge badge-primary"><?= e($session['section_code']) ?></span></td>
                                <td class="text-sm"><?= e($session['subject_code']) ?></td>
                                <td class="text-sm"><?= e($session['teacher_name']) ?></td>
                                <td class="cell-stack">
                                    <span class="cell-primary"><?= e($session['room_number']) ?></span>
                                    <span class="cell-muted mono"><?= e($session['device_id']) ?></span>
                                </td>
                                <td class="numeric text-success fw-600" data-count="present_count"><?= e($session['present_count']) ?></td>
                                <td class="numeric text-warning" data-count="late_count"><?= e($session['late_count']) ?></td>
                                <td class="numeric" data-count="roster_count"><?= e($session['roster_count']) ?></td>
                                <td class="numeric <?= (int) $session['rejected_tap_count'] > 0 ? 'text-danger' : 'text-muted' ?>"
                                    data-count="rejected_tap_count"><?= e($session['rejected_tap_count']) ?></td>
                                <td class="nowrap text-sm"><?= e(format_time($session['opened_at'])) ?></td>
                                <td class="nowrap text-sm" data-expires="<?= e($session['expires_at']) ?>">
                                    <?= e($session['expires_at'] ? format_time($session['expires_at']) : '—') ?>
                                </td>
                                <td class="nowrap">
                                    <a class="btn btn-ghost btn-sm" href="/admin/attendance/sessions/<?= e($session['session_id']) ?>">
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
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">What closing a session does</h2>
    </div>
    <div class="card__body">
        <p class="text-sm text-muted" style="max-width:70ch">
            Closing runs as a single transaction. Every student still inside is given an automatic
            time-out stamped at the session's scheduled end and flagged as auto-generated; every
            student on the roster with no record at all is written as <strong>Absent</strong>; the
            session counters are recomputed from the records rather than trusted; and the session is
            marked closed. If any part fails, none of it is applied. A session is never partially
            closed, and closing it a second time is rejected rather than double-counted.
        </p>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    /* ---- live counters --------------------------------------------------- */

    // Realtime payloads carry the session *code*, not the numeric id, so rows
    // are matched on the code they were rendered with.
    function rowFor(code) {
        return document.querySelector('[data-session-code="' + CSS.escape(String(code)) + '"]');
    }

    function applyCounters(payload) {
        const row = rowFor(payload.session_id);
        if (!row || !payload.counters) return;

        const c = payload.counters;

        // present_count on the row is "timed in, not late" — the same
        // derivation the server persists, kept in one place here.
        setCell(row, 'present_count', c.timed_in - c.late);
        setCell(row, 'late_count',    c.late);
        setCell(row, 'roster_count',  c.total);
    }

    function setCell(row, key, value) {
        const cell = row.querySelector('[data-count="' + key + '"]');
        if (!cell || cell.textContent.trim() === String(value)) return;

        cell.textContent = value;
        cell.classList.remove('cell-flash');
        void cell.offsetWidth;          // restart the animation
        cell.classList.add('cell-flash');
    }

    LS.realtime.on('attendance.time_in',  applyCounters);
    LS.realtime.on('attendance.time_out', applyCounters);

    LS.realtime.on('attendance.rejected', function (payload) {
        const row = rowFor(payload.session_id);
        if (!row) return;

        const cell = row.querySelector('[data-count="rejected_tap_count"]');
        if (!cell) return;

        setCell(row, 'rejected_tap_count', (parseInt(cell.textContent, 10) || 0) + 1);
        cell.classList.remove('text-muted');
        cell.classList.add('text-danger');
    });

    // A session opening or closing changes the whole list, so the page is
    // refetched rather than patched — correctness beats a clever diff here.
    const reload = LS.util.debounce(() => window.location.reload(), 1200);

    LS.realtime.on('session.opened', reload);
    LS.realtime.on('session.closed', reload);

    LS.realtime.on('session.expiring', function (payload) {
        LS.toast.warning(payload.section + ' · ' + payload.subject
            + ' in ' + payload.room + ' auto-closes shortly.');
    });

    /* ---- countdown to auto-close ---------------------------------------- */
    function tickCountdowns() {
        const now = Date.now();

        document.querySelectorAll('[data-expires]').forEach((cell) => {
            const value = cell.dataset.expires;
            if (!value) return;

            const expires = new Date(value.replace(' ', 'T')).getTime();
            if (Number.isNaN(expires)) return;

            const minutes = Math.round((expires - now) / 60000);

            cell.classList.toggle('text-danger',  minutes <= 5);
            cell.classList.toggle('text-warning', minutes > 5 && minutes <= 15);
            cell.title = minutes <= 0
                ? 'Past its auto-close time — the worker will close it on its next pass.'
                : minutes + ' minute(s) remaining';
        });
    }

    tickCountdowns();
    setInterval(tickCountdowns, 30000);

    /* ---- force close ----------------------------------------------------- */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-close]');
        if (!button) return;

        const confirmed = await LS.modal.confirm({
            title:   'Close ' + button.dataset.code + '?',
            message: 'Students still inside will be given an automatic time-out at the scheduled '
                     + 'end time, and everyone on the roster with no record will be marked absent. '
                     + 'This cannot be undone — attendance records are immutable once written.',
            confirmLabel: 'Close session',
            danger: true,
        });

        if (!confirmed) return;

        LS.util.setBusy(button, true, 'Closing…');

        try {
            const response = await LS.http.post('/admin/attendance/sessions/' + button.dataset.close + '/close', {});
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            LS.toast.fromError(error);
            LS.util.setBusy(button, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
