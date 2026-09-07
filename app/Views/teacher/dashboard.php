<?php
/** @var App\Core\View $__view */

use App\Services\AttendanceStatusResolver;

$__view->extend('layouts.app');
$__view->start('content');

$today   = $overview['today'];
$current = $overview['current_session'];
$counters = $overview['current_counters'];
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Dashboard',
    'subtitle'    => 'Your classes, your sessions, your students.',
    'breadcrumbs' => [['Dashboard', null]],
    'actions'     => '<a class="btn btn-secondary" href="/teacher/schedule"><i class="fa-solid fa-calendar-days"></i> My schedule</a>',
]); ?>

<?php if ($overview['fingerprint_status'] !== 'enrolled'): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-fingerprint"></i></span>
        <div class="alert__body">
            <div class="alert__title">Your fingerprint is not enrolled</div>
            Ask an administrator to enrol your fingerprint on a classroom terminal. Until then you
            can still open a session from this dashboard using your account password, during the
            class's scan window — but the reader is the normal way in, and every session opened
            without it is recorded as such.
        </div>
    </div>
<?php endif; ?>

<div class="grid grid--4 mb-3">
    <div class="stat">
        <span class="stat__icon"><i class="fa-solid fa-calendar-days"></i></span>
        <div>
            <div class="stat__label">Classes today</div>
            <div class="stat__value"><?= e($today['classes']) ?></div>
            <div class="stat__meta"><?= e($overview['assigned_classrooms']) ?> classroom(s)</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--success"><i class="fa-solid fa-user-check"></i></span>
        <div>
            <div class="stat__label">Present today</div>
            <div class="stat__value"><?= e($today['present']) ?></div>
            <div class="stat__meta"><span class="text-warning"><?= e($today['late']) ?></span> late</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--<?= $today['percentage'] >= 90 ? 'success' : 'warning' ?>">
            <i class="fa-solid fa-percent"></i>
        </span>
        <div>
            <div class="stat__label">Attendance rate</div>
            <div class="stat__value"><?= e($today['percentage']) ?>%</div>
            <div class="stat__meta"><?= e($today['records']) ?> records today</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--info"><i class="fa-solid fa-clock-rotate-left"></i></span>
        <div>
            <div class="stat__label">Sessions this week</div>
            <div class="stat__value"><?= e($overview['week_sessions']) ?></div>
            <div class="stat__meta">Last 7 days</div>
        </div>
    </div>
</div>

<?php
// The class whose scan window is open right now, if any. DashboardService has
// already decided that against the application clock, so this only picks the
// first one rather than working out the times again.
$liveClass = null;

foreach ($overview['todays_schedule'] as $slot) {
    if ($slot['scan_state'] === 'now') { $liveClass = $slot; break; }
}
?>

<!-- Current class -------------------------------------------------------- -->
<?php /*
    One card, three states, in the order a teacher meets them: a session is
    open, or one can be opened right now, or neither.

    The middle state used to live in a separate card further down the page.
    That put "Go to your classroom and place your finger on the sensor" — with
    no way to do anything else — directly under the heading a teacher reads
    first, and the controls that could actually open the class below the fold.
    A teacher whose finger will not read was told to go and scan it.
*/ ?>
<div class="card"<?= $current === null && $liveClass !== null
    ? ' id="start-session" data-schedule="' . e($liveClass['schedule_id']) . '"'
    : '' ?>>
    <div class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-bolt text-warning"></i> Current class</h2>
        <?php if ($current !== null): ?>
            <span class="badge badge-success badge-dot">Session open</span>
        <?php elseif ($liveClass !== null): ?>
            <span class="badge badge-success">window open</span>
        <?php endif; ?>
    </div>

    <div class="card__body">
        <?php if ($current === null && $liveClass !== null): ?>
            <?php $__view->include('teacher.partials.session-start', ['liveClass' => $liveClass]); ?>
        <?php elseif ($current === null): ?>
            <div class="empty-state" style="padding:2rem">
                <div class="empty-state__icon"><i class="fa-solid fa-door-closed"></i></div>
                <div class="empty-state__title">No attendance session open</div>
                <p class="empty-state__text">
                    <?php /* Why there is nothing to press: not "scan your finger", which is
                             advice a teacher cannot act on when no class is running. */ ?>
                    None of your classes is inside its attendance window at the moment. The
                    controls for opening a session appear here from ten minutes before a class
                    starts.
                </p>
            </div>
        <?php else: ?>
            <div class="grid grid--3">
                <div>
                    <div class="text-xs text-muted">Subject</div>
                    <div style="font-size:19px;font-weight:600"><?= e($current['subject_code']) ?></div>
                    <div class="text-sm text-muted"><?= e($current['subject_name']) ?></div>

                    <div class="text-xs text-muted mt-2">Section</div>
                    <div><span class="badge badge-primary"><?= e($current['section_code']) ?></span>
                        <span class="text-sm text-muted"><?= e($current['section_name']) ?></span></div>

                    <div class="text-xs text-muted mt-2">Room · terminal</div>
                    <div class="text-sm">Room <?= e($current['room_number']) ?> · <span class="mono text-xs"><?= e($current['device_id']) ?></span></div>
                </div>

                <div>
                    <div class="text-xs text-muted">Scheduled</div>
                    <div class="mono"><?= e(substr((string) $current['start_time'], 0, 5)) ?>–<?= e(substr((string) $current['end_time'], 0, 5)) ?></div>

                    <div class="text-xs text-muted mt-2">Session</div>
                    <div class="mono text-sm"><?= e($current['session_code']) ?></div>

                    <div class="text-xs text-muted mt-2">Closes automatically</div>
                    <div class="text-sm"><?= e(format_time($current['expires_at'])) ?></div>
                </div>

                <div>
                    <div class="grid" style="grid-template-columns:repeat(2,1fr);gap:.5rem">
                        <?php foreach ([
                            ['Timed in', $counters['timed_in'] ?? 0, 'success'],
                            ['Timed out', $counters['timed_out'] ?? 0, 'info'],
                            ['Still in room', $counters['in_room'] ?? 0, 'primary'],
                            ['Roster', $counters['roster'] ?? 0, 'neutral'],
                        ] as [$label, $value, $tone]): ?>
                            <div style="padding:.5rem .65rem;background:var(--surface-alt);border-radius:var(--radius-sm)">
                                <div class="text-xs text-muted"><?= e($label) ?></div>
                                <div class="fw-600" style="font-size:20px" data-counter="<?= e(strtolower(str_replace(' ', '_', $label))) ?>">
                                    <?= e($value) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex gap-1 mt-2">
                        <a class="btn btn-secondary btn-sm flex-1" href="/teacher/sessions/<?= e($current['session_id']) ?>">
                            <i class="fa-solid fa-eye"></i> Roster
                        </a>
                        <button class="btn btn-danger btn-sm flex-1" id="close-session"
                                data-session="<?= e($current['session_id']) ?>">
                            <i class="fa-solid fa-stop"></i> Close attendance
                        </button>
                    </div>
                </div>
            </div>

            <div class="alert alert-info mt-3" style="margin-bottom:0">
                <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                <div class="alert__body">
                    Attendance continues on the classroom terminal even when you are signed out of this
                    web page. The two are independent — signing out here does not close the session.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>


<div class="grid grid--2">
    <!-- Today's schedule --------------------------------------------------- -->
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Today's classes</h2>
            <a class="btn btn-ghost btn-sm" href="/teacher/schedule">Full schedule</a>
        </div>
        <div class="card__body--flush">
            <?php if ($overview['todays_schedule'] === []): ?>
                <?php /* "Enjoy the quiet" was true and unhelpful — a teacher looking
                         at this wants to know when they are next expected, which is
                         a question the schedule can answer. */ ?>
                <?php $next = $overview['next_class'] ?? null; ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-mug-hot',
                    'title' => 'No classes scheduled today',
                    'text'  => $next === null
                        ? 'You have no active classes on any day. If you were expecting one, ask the administration to check your schedule.'
                        : sprintf(
                            'Next: %s with %s in Room %s on %s at %s. You can scan from %s.',
                            $next['subject_code'],
                            $next['section_code'],
                            $next['room_number'],
                            format_date(substr((string) $next['occurs_at'], 0, 10)),
                            substr((string) $next['start_time'], 0, 5),
                            substr((string) $next['scan_opens'], 0, 5)
                        ),
                ]); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Time</th><th>Subject</th><th>Section</th><th>Room</th><th>Terminal</th><th>Scan</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($overview['todays_schedule'] as $slot): ?>
                            <?php
                            // scan_state is decided in DashboardService against
                            // the application clock; asking date() here would
                            // ignore a clock shift and contradict the schedule
                            // printed beside it.
                            $opens    = (string) $slot['scan_opens'];
                            $closes   = (string) $slot['scan_closes'];
                            $scanNow  = $slot['scan_state'] === 'now';
                            $scanLate = $slot['scan_state'] === 'closed';
                            ?>
                            <tr>
                                <td class="nowrap mono text-sm">
                                    <?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?>
                                </td>
                                <td class="cell-stack">
                                    <span class="cell-primary"><?= e($slot['subject_code']) ?></span>
                                    <span class="cell-muted"><?= e($slot['subject_name']) ?></span>
                                </td>
                                <td><span class="badge badge-primary"><?= e($slot['section_code']) ?></span></td>
                                <td><?= e($slot['room_number']) ?></td>
                                <td>
                                    <?php if ($slot['device_id'] === null): ?>
                                        <span class="badge badge-danger">None</span>
                                    <?php else: ?>
                                        <span class="badge <?= e(status_badge($slot['device_status'])) ?>" title="<?= e($slot['device_id']) ?>">
                                            <?= e(ucfirst((string) $slot['device_status'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?php /* "Not started" said nothing about whether it *could* be
                                             started. This column answers that, and it is the one a
                                             teacher standing at the reader actually needs. */ ?>
                                    <?php if ($slot['session_status'] === 'closed'): ?>
                                        <span class="text-xs text-muted">—</span>
                                    <?php elseif ($scanNow): ?>
                                        <span class="badge badge-success" title="Scan your fingerprint at the terminal now">
                                            <i class="fa-solid fa-fingerprint"></i> Scan now
                                        </span>
                                    <?php elseif ($scanLate): ?>
                                        <span class="badge badge-danger" title="The window for this class has passed">
                                            closed <?= e(substr($closes, 0, 5)) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral" title="You can scan from this time">
                                            from <?= e(substr($opens, 0, 5)) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($slot['session_status'] === 'open'): ?>
                                        <span class="badge badge-success">Open</span>
                                    <?php elseif ($slot['session_status'] === 'closed'): ?>
                                        <span class="badge badge-neutral">Done</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Not started</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent attendance --------------------------------------------------- -->
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Recent attendance</h2>
            <a class="btn btn-ghost btn-sm" href="/teacher/attendance">View all</a>
        </div>
        <div class="card__body--flush live-feed" id="live-feed">
            <?php if ($recent === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-clipboard-user',
                    'title' => 'Nothing recorded yet',
                ]); ?>
            <?php else: ?>
                <?php foreach ($recent as $record): ?>
                    <div class="live-row">
                        <span class="live-row__arrow live-row__arrow--<?= $record['time_out'] ? 'out' : 'in' ?>">
                            <i class="fa-solid fa-arrow-<?= $record['time_out'] ? 'left' : 'right' ?>"></i>
                        </span>
                        <span class="live-row__body">
                            <span class="live-row__name"><?= e($record['student_name']) ?></span>
                            <span class="live-row__meta"><?= e($record['section_code']) ?> · <?= e($record['subject_code']) ?></span>
                        </span>
                        <span class="live-row__time">
                            <span class="badge <?= e(AttendanceStatusResolver::badgeClass((string) $record['final_status'])) ?>">
                                <?= e($record['final_status']) ?>
                            </span>
                            <div class="text-xs text-muted mt-1"><?= e(format_time($record['time_out'] ?: $record['time_in'])) ?></div>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($rejections !== []): ?>
    <div class="card">
        <div class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-ban text-danger"></i> Rejected taps in your classes</h2>
        </div>
        <div class="card__body--flush">
            <?php foreach ($rejections as $rejection): ?>
                <div class="live-row live-row--rejected">
                    <span class="live-row__arrow text-danger"><i class="fa-solid fa-xmark"></i></span>
                    <span class="live-row__body">
                        <span class="live-row__name"><?= e($rejection['student_name'] ?? 'Unknown card ' . $rejection['card_uid']) ?></span>
                        <span class="live-row__meta">
                            <?= e(strtoupper(str_replace('_', ' ', (string) $rejection['result']))) ?>
                            <?php if ($rejection['result'] === 'section_mismatch'): ?>
                                — this student belongs to <?= e($rejection['student_section']) ?>, not <?= e($rejection['session_section']) ?>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="live-row__time"><?= e(time_ago($rejection['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    const closeButton = document.getElementById('close-session');

    if (closeButton) {
        closeButton.addEventListener('click', async function () {
            const result = await LS.modal.confirm({
                title: 'Close attendance?',
                message: 'Students who have not tapped out will be stamped automatically, and everyone '
                       + 'on the roster who never tapped in will be marked absent. This cannot be undone.',
                confirmLabel: 'Close attendance',
                danger: true,
            });

            if (!result) return;

            LS.util.setBusy(closeButton, true, 'Closing…');

            try {
                const response = await LS.http.post('/teacher/sessions/' + this.dataset.session + '/close', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 1200);
            } catch (error) {
                LS.toast.fromError(error);
            } finally {
                LS.util.setBusy(closeButton, false);
            }
        });
    }

    if (LS.realtime) {
        LS.realtime
            .on('attendance.time_in', (data) => {
                updateCounters(data.counters);
                LS.toast.success(data.student.name + ' — ' + data.final_status, 'Tapped in');
            })
            .on('attendance.time_out', (data) => {
                updateCounters(data.counters);
            })
            .on('attendance.rejected', (data) => {
                if (data.code === 'SECTION_MISMATCH') {
                    LS.toast.warning(
                        data.student_name + ' belongs to ' + data.student_section
                        + ' and was not recorded in this class.',
                        'Wrong section'
                    );
                }
            })
            .on('session.closed', () => setTimeout(() => window.location.reload(), 1500))
            .on('session.expiring', (data) => {
                LS.toast.warning('This attendance session closes automatically at '
                    + LS.util.formatTime(data.expires_at) + '.', 'Session ending soon');
            });
    }

    function updateCounters(counters) {
        if (!counters) return;

        const map = {
            timed_in: counters.timed_in,
            timed_out: counters.timed_out,
            still_in_room: counters.in_room,
            roster: counters.total,
        };

        Object.entries(map).forEach(([key, value]) => {
            const node = document.querySelector('[data-counter="' + key + '"]');
            if (node && value !== undefined) node.textContent = value;
        });
    }
})();
</script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS   = window.LSIAMS;
    const card = document.getElementById('start-session');

    if (card === null) return;

    const icon    = document.getElementById('ss-icon');
    const stage   = document.getElementById('ss-stage');
    const detail  = document.getElementById('ss-detail');
    const warning = document.getElementById('ss-warning');
    const warnText = document.getElementById('ss-warning-text');
    const watch   = document.getElementById('ss-watch');
    const goto    = document.getElementById('ss-goto');

    // Only attempts from when this panel appeared count. Without this, the one
    // quiet poll on load reports the most recent fingerprint attempt of the
    // whole day, so a refusal from an earlier test replays as "That scan didn't
    // go through" for a scan the teacher never made this session — often
    // carrying a reason (no class scheduled) that no longer applies now that a
    // class is live. An already-open session is still detected on load: the
    // server checks for one separately and ignores this cut-off.
    const nowStamp = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

    let timer = null;
    let since = nowStamp();

    function step(name, state) {
        document.querySelectorAll('#ss-steps li').forEach((li) => {
            const order = ['window', 'scan', 'open'];
            const at    = order.indexOf(li.dataset.stage);
            const now   = order.indexOf(name);

            li.classList.toggle('is-done', at < now || state === 'done');
            li.classList.toggle('is-current', at === now && state !== 'done');
        });
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    async function poll() {
        try {
            // poll() is get() with the passive header: a panel watching itself
            // is not activity, and must not hold the idle timeout open while
            // nobody is at the desk.
            const response = await LS.http.poll('/teacher/session-state',
                since ? { since: since } : {});

            const data = response.data || {};

            if (data.state === 'open') {
                stop();
                icon.className = 'enrol-scan__icon is-success';
                stage.textContent = 'Session open';
                detail.textContent = data.message || '';
                warning.classList.add('hidden');
                watch.classList.add('hidden');
                goto.classList.remove('hidden');
                goto.href = '/teacher/sessions';
                step('open', 'done');

                // The scan won. Take the failover away rather than leaving a
                // password form open on a screen at the front of a classroom.
                fallback.classList.add('hidden');
                fbPass.value    = '';
                fbConfirm.value = '';

                LS.toast.success('Attendance session opened. Students may tap now.');
                return;
            }

            if (data.state === 'refused') {
                // Amber, not red: a scan that did not take is a "try again", not
                // a fault. The server's own reason is shown below — this is the
                // whole point of the panel, since the refusal used to exist only
                // in the serial log — but the tone stays calm.
                icon.className = 'enrol-scan__icon is-warning';
                stage.textContent = 'That scan didn’t go through — try again';
                detail.textContent = '';
                warnText.textContent = data.message || 'The terminal did not accept the scan. Place your finger again.';
                warning.classList.remove('hidden');
                step('scan');
                return;
            }

            if (data.state === 'verified') {
                icon.className = 'enrol-scan__icon is-waiting';
                stage.textContent = 'Fingerprint accepted — opening…';
                warning.classList.add('hidden');
                step('open');
                return;
            }

            /* Still waiting — but the server may already know that waiting is
               pointless. A sensor that never started, and a sensor with no
               copy of this teacher's template, both fail without the terminal
               posting anything, so this panel would otherwise ask somebody to
               keep placing a finger on a reader that can never answer. */
            if (data.blocked) {
                // Also amber: the reader being quiet, or not holding this
                // teacher's print yet, is something to act on — not a failure
                // of the attendance system itself. The message below says what
                // to do; the password failover sits right under it.
                icon.className = 'enrol-scan__icon is-warning';
                stage.textContent = data.blocked === 'template_missing'
                    ? 'This reader cannot recognise you'
                    : 'The reader is not answering';
                detail.textContent = '';
                warnText.textContent = data.blocked_message;
                warning.classList.remove('hidden');
                step('scan');
                return;
            }

            stage.textContent = 'Waiting for your fingerprint…';
            detail.textContent = 'Place your finger on the terminal now.';
            warning.classList.add('hidden');
            step('scan');
        } catch (error) {
            /* A failed poll is not worth a toast; the next one is a second away. */
        }
    }

    watch.addEventListener('click', function () {
        // Attempts are counted from this moment, so a failure from earlier in
        // the day does not open the panel already looking like a fresh one.
        since = nowStamp();

        icon.className = 'enrol-scan__icon is-waiting';
        stage.textContent = 'Waiting for your fingerprint…';
        detail.textContent = 'Place your finger on the terminal now.';
        warning.classList.add('hidden');
        step('scan');

        stop();
        poll();
        timer = setInterval(poll, 2000);
    });

    /* ------------------------------------------------------- the failover -- */

    const fallback  = document.getElementById('ss-fallback');
    const fbToggle  = document.getElementById('ss-fallback-toggle');
    const fbForm    = document.getElementById('ss-fallback-form');
    const fbSubmit  = document.getElementById('ss-fallback-submit');
    const fbError   = document.getElementById('ss-fallback-error');
    const fbErrText = document.getElementById('ss-fallback-error-text');
    const fbPass    = document.getElementById('ss-password');
    const fbConfirm = document.getElementById('ss-password-confirm');

    function fbFail(message) {
        fbErrText.textContent = message;
        fbError.classList.remove('hidden');
    }

    fbToggle.addEventListener('click', function () {
        const open = fbForm.classList.toggle('hidden') === false;

        fbToggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) fbPass.focus();
    });

    fbForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        fbError.classList.add('hidden');

        // Checked here as well as on the server. The server's answer is the
        // one that counts — this only spares a teacher a round trip to be
        // told they mistyped the second box.
        if (fbPass.value === '' || fbConfirm.value === '') {
            fbFail('Enter your password in both boxes.');
            return;
        }

        if (fbPass.value !== fbConfirm.value) {
            fbFail('The two passwords do not match.');
            fbConfirm.value = '';
            fbConfirm.focus();
            return;
        }

        fbSubmit.disabled  = true;
        // fa-arrows-rotate, not fa-spinner: icons.css carries a hand-drawn
        // subset of Font Awesome and has no spinner glyph, so that one renders
        // as a blank box for the whole time the button is busy.
        fbSubmit.innerHTML = '<i class="fa-solid fa-arrows-rotate fa-spin"></i> Opening…';

        try {
            const response = await LS.http.post('/teacher/start-session', {
                password: fbPass.value,
                password_confirmation: fbConfirm.value,
                schedule_id: card.dataset.schedule,
            });

            // Clear the fields before anything else. The session is open; the
            // password has no reason to stay in the DOM of a machine that is
            // about to be left on a classroom desk.
            fbPass.value    = '';
            fbConfirm.value = '';

            stop();

            const session = (response && response.data) || {};

            icon.className     = 'enrol-scan__icon is-success';
            stage.textContent  = 'Session open';
            detail.textContent = response.message || '';
            warning.classList.add('hidden');
            watch.classList.add('hidden');
            goto.classList.remove('hidden');
            goto.href = session.session_id ? '/teacher/sessions/' + session.session_id : '/teacher/sessions';
            step('open', 'done');

            // The whole block goes, not just the form. Leaving the toggle's
            // divider behind draws a rule under a panel with nothing below it.
            fallback.classList.add('hidden');

            // Step two says a finger was scanned. It was not, and a green tick
            // beside that sentence is the panel telling the teacher something
            // untrue about the record they just created.
            const scanStep = document.querySelector('#ss-steps li[data-stage="scan"]');
            if (scanStep) scanStep.textContent = 'Opened with your password — no fingerprint';

            LS.toast.success('Session opened without a scan. Students may tap now.');
        } catch (error) {
            fbPass.value    = '';
            fbConfirm.value = '';
            fbFail((error && error.message) || 'The session could not be opened.');
            fbPass.focus();
        } finally {
            fbSubmit.disabled  = false;
            fbSubmit.innerHTML = '<i class="fa-solid fa-door-open"></i> Start the session without scanning';
        }
    });

    // A session opened from the terminal without anybody pressing the button
    // still belongs on this card, so one quiet check runs on load.
    poll();
})();
</script>
<?php $__view->stop(); ?>
