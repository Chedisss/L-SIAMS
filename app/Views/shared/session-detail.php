<?php
/**
 * Session roster, shared by the administrator and teacher portals.
 *
 * The two views are the same screen with a different URL prefix and one
 * different breadcrumb trail; keeping a single template means the roster, the
 * live counters and the close-session wording can never drift apart between
 * the two roles. Authorisation is done in the controllers — a teacher reaching
 * this template has already been checked to own the session.
 *
 * @var App\Core\View $__view
 */

use App\Core\Auth;
use App\Services\AttendanceService;
use App\Services\AttendanceStatusResolver;

$__view->extend('layouts.app');
$__view->start('content');

$isOpen   = ($session['status'] ?? '') === 'open';
$isTeacher = Auth::isTeacher();

$sessionsPath = $isTeacher ? '/teacher/sessions' : '/admin/attendance/sessions';
$crumbs       = $isTeacher
    ? [['Dashboard', '/teacher'], ['My Sessions', '/teacher/sessions'], [(string) $session['session_code'], null]]
    : [['Dashboard', '/admin'], ['Attendance', '/admin/attendance'],
       ['Sessions', '/admin/attendance/sessions'], [(string) $session['session_code'], null]];

$counts = ['in_room' => 0, 'timed_out' => 0, 'late' => 0, 'no_record' => 0];

foreach ($roster as $entry) {
    if ($entry['time_in'] === null)         { $counts['no_record']++; }
    elseif ($entry['time_out'] === null)    { $counts['in_room']++; }
    else                                    { $counts['timed_out']++; }

    if ($entry['arrival_status'] === 'late') { $counts['late']++; }
}
?>

<?php $__view->include('partials.page-header', [
    'title'       => (string) $session['session_code'],
    'subtitle'    => $session['section_code'] . ' · ' . $session['subject_name'] . ' · Room ' . $session['room_number']
        . ' · ' . $session['teacher_name'],
    'breadcrumbs' => $crumbs,
    // The roster link is teacher-only: this page is shared, and an
    // administrator following it would land on a teacher route they have no
    // access to. Admins reach the same students through Students.
    'actions'     => ($isTeacher
            ? '<a class="btn btn-secondary" href="/teacher/sections/' . (int) $session['section_id'] . '">'
              . '<i class="fa-solid fa-users-rectangle"></i> Section roster</a>'
            : '')
        . ($isOpen
            ? '<button class="btn btn-danger" id="close-session"><i class="fa-solid fa-lock"></i> Close session</button>'
            : '<span class="badge badge-neutral">Closed ' . e(format_datetime($session['closed_at'])) . '</span>'),
]); ?>

<div class="grid grid--4 mb-3">
    <div class="stat">
        <span class="stat__icon stat__icon--success"><i class="fa-solid fa-person-shelter"></i></span>
        <div>
            <div class="stat__label">In the room</div>
            <div class="stat__value" data-count="in_room"><?= e($counts['in_room']) ?></div>
            <div class="stat__meta">Timed in, no time-out yet</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--info"><i class="fa-solid fa-right-from-bracket"></i></span>
        <div>
            <div class="stat__label">Timed out</div>
            <div class="stat__value" data-count="timed_out"><?= e($counts['timed_out']) ?></div>
            <div class="stat__meta">Completed both taps</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--warning"><i class="fa-solid fa-clock"></i></span>
        <div>
            <div class="stat__label">Late arrivals</div>
            <div class="stat__value" data-count="late"><?= e($counts['late']) ?></div>
            <div class="stat__meta">After <?= e($session['late_threshold_minutes']) ?> minute threshold</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--danger"><i class="fa-solid fa-user-slash"></i></span>
        <div>
            <div class="stat__label"><?= $isOpen ? 'Not yet seen' : 'Absent' ?></div>
            <div class="stat__value" data-count="no_record"><?= e($counts['no_record']) ?></div>
            <div class="stat__meta">
                <?= $isOpen
                    ? 'Marked absent when the session closes'
                    : 'Written when the session closed' ?>
            </div>
        </div>
    </div>
</div>

<div class="grid" style="grid-template-columns:1fr 300px;align-items:start">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Roster <span class="badge badge-neutral"><?= e(count($roster)) ?></span></h2>
            <div class="flex gap-1">
                <input type="search" id="roster-search" placeholder="Filter by name or number" style="max-width:230px">
                <select id="roster-status" style="max-width:150px">
                    <option value="">Everyone</option>
                    <option value="in">In the room</option>
                    <option value="out">Timed out</option>
                    <option value="none">No record</option>
                </select>
            </div>
        </div>

        <div class="card__body--flush">
            <?php if ($roster === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-users',
                    'title' => 'This section has no active students',
                    'text'  => 'Enrol students into the section before running a session for it.',
                ]); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data" id="roster-table">
                        <thead>
                        <tr>
                            <th>Student</th><th>Card</th><th>Time in</th><th>Time out</th>
                            <th>Duration</th><th>Arrival</th><th>Departure</th><th>Final status</th>
                            <?php if ($isOpen): ?><th style="width:1%"></th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($roster as $entry): ?>
                            <tr data-student="<?= e($entry['student_id']) ?>"
                                data-state="<?= e($entry['time_in'] === null ? 'none' : ($entry['time_out'] === null ? 'in' : 'out')) ?>"
                                data-search="<?= e(strtolower($entry['student_name'] . ' ' . $entry['student_number'])) ?>">
                                <td class="cell-stack">
                                    <span class="cell-primary"><?= e($entry['student_name']) ?></span>
                                    <span class="cell-muted mono"><?= e($entry['student_number']) ?></span>
                                </td>
                                <td>
                                    <?php if ($entry['card_uid']): ?>
                                        <span class="mono text-xs"><?= e($entry['card_uid']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" title="Without a card this student cannot tap in">No card</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap text-sm" data-cell="time_in">
                                    <?= e(format_time($entry['time_in'])) ?>
                                    <?php if (!empty($entry['carried_from_session_id'])): ?>
                                        <i class="fa-solid fa-arrow-right-arrow-left text-muted"
                                           title="Carried from <?= e((string) ($entry['carried_from_subject'] ?: $entry['carried_from_code'])) ?> — this student was already in the room and did not tap again"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap text-sm" data-cell="time_out">
                                    <?= e(format_time($entry['time_out'])) ?>
                                    <?php if ((int) $entry['auto_generated_time_out'] === 1): ?>
                                        <i class="fa-solid fa-robot text-muted" title="Automatic time-out written when the session closed"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm" data-cell="duration">
                                    <?= e(human_duration($entry['duration_minutes'] === null ? null : (int) $entry['duration_minutes'])) ?>
                                </td>
                                <td class="text-xs text-muted" data-cell="arrival_status"><?= e($entry['arrival_status'] ?? '—') ?></td>
                                <td class="text-xs text-muted" data-cell="departure_status">
                                    <?= e($entry['departure_status'] ?? '—') ?>
                                    <?php if (!empty($entry['early_release_reason'])): ?>
                                        <?php /* Left Early is also what a genuine early tap-out
                                                 produces. This says a named adult sent them out,
                                                 and why — a different fact with the same status. */ ?>
                                        <i class="fa-solid fa-hand text-warning"
                                           title="Released by <?= e((string) ($entry['early_released_by_name'] ?? 'a member of staff')) ?> — <?= e(strtolower(AttendanceService::RELEASE_REASONS[$entry['early_release_reason']] ?? (string) $entry['early_release_reason'])) ?><?= $entry['early_release_note'] ? ': ' . e((string) $entry['early_release_note']) : '' ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td data-cell="final_status">
                                    <?php if ($entry['final_status']): ?>
                                        <span class="badge <?= e(AttendanceStatusResolver::badgeClass((string) $entry['final_status'])) ?>">
                                            <?= e($entry['final_status']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($isOpen): ?>
                                    <td data-cell="release">
                                        <?php /* Only for somebody actually in the room: no tap-in
                                                 means there is nothing to release them from, and a
                                                 tap-out means they have already gone. */ ?>
                                        <?php if ($entry['time_in'] !== null && $entry['time_out'] === null): ?>
                                            <button type="button" class="btn btn-sm btn-secondary"
                                                    data-release="<?= e($entry['attendance_id']) ?>"
                                                    data-name="<?= e($entry['student_name']) ?>"
                                                    title="Send this student out of the room before the end of the period">
                                                <i class="fa-solid fa-person-walking-arrow-right"></i> Release
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__header"><h2 class="card__title">Session</h2></div>
            <div class="card__body">
                <?php foreach ([
                    'Status'          => ucfirst((string) $session['status']),
                    'Device'          => (string) $session['device_id'],
                    'Building'        => (string) ($session['building'] ?? '—'),
                    'Scheduled'       => format_time($session['start_time']) . ' – ' . format_time($session['end_time']),
                    'Opened'          => format_datetime($session['opened_at']),
                    // Which proof the teacher gave. 'password' means the
                    // fingerprint could not be read and the failover was used;
                    // it belongs on the record rather than only in the audit
                    // trail, because the person asking "was this teacher
                    // actually in the room" is reading this page.
                    'Opened by'       => (string) ($session['opened_method'] ?? 'fingerprint') === 'password'
                        ? 'Password (no fingerprint)'
                        : 'Fingerprint',
                    'Auto-close'      => $session['expires_at'] ? format_datetime($session['expires_at']) : '—',
                    'Closed'          => $session['closed_at'] ? format_datetime($session['closed_at']) : '—',
                    'Closed by'       => (string) ($session['closed_by_type'] ?? '—'),
                    // How much of this register was actually scanned in front
                    // of the teacher, and how much arrived because the same
                    // students were already in the room for the last period.
                    'Carried in'      => (int) ($session['carried_in_count'] ?? 0) === 0
                        ? 'None — every record was tapped'
                        : $session['carried_in_count'] . ' from the previous period',
                    'Rejected taps'   => (string) $session['rejected_tap_count'],
                ] as $label => $value): ?>
                    <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted"><?= e($label) ?></span>
                        <span class="text-sm text-right"><?= e($value ?: '—') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Windows in force</h2></div>
            <div class="card__body">
                <p class="text-xs text-muted" style="margin-bottom:.6rem">
                    These come from the schedule and were fixed when the session opened. Changing the
                    schedule now does not reinterpret records already written.
                </p>

                <?php foreach ([
                    'Late after'        => $session['late_threshold_minutes'] . ' min past start',
                    'Time-in closes'    => format_time($session['time_in_window_close']),
                    'Time-out opens'    => format_time($session['time_out_window_open']),
                    'Minimum dwell'     => $session['minimum_dwell_minutes'] . ' min',
                ] as $label => $value): ?>
                    <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted"><?= e($label) ?></span>
                        <span class="text-sm"><?= e($value) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($isOpen): ?>
            <div class="card">
                <div class="card__header"><h2 class="card__title">Live taps</h2></div>
                <div class="card__body--flush live-feed" id="tap-feed" style="max-height:340px">
                    <div class="text-muted text-sm" style="padding:1rem" data-feed-empty>Waiting for taps…</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isOpen): ?>
<div class="modal-backdrop" id="release-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Release <span data-field="student_name">the student</span></h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form id="release-form">
            <div class="modal__body">
                <p class="text-sm text-muted" style="margin-bottom:.8rem">
                    Records a real departure at the time you confirm it, marked <strong>Left Early</strong>.
                    Nothing carries into the next subject, so the student may tap in again if they come back.
                </p>

                <div class="form-group">
                    <label for="release-reason" class="required">Why are they leaving?</label>
                    <select id="release-reason" name="reason" required>
                        <option value="">Choose a reason</option>
                        <?php foreach (AttendanceService::RELEASE_REASONS as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="release-note">Note <span class="label__hint" id="release-note-hint">optional</span></label>
                    <textarea id="release-note" name="note" rows="2" maxlength="255"
                              placeholder="Anything the reason above does not say"></textarea>
                    <span class="field-help">Kept with the record and shown to whoever reads the register.</span>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning" id="release-submit">
                    <i class="fa-solid fa-person-walking-arrow-right"></i> Release student
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS   = window.LSIAMS;
    const CODE = <?= json_js((string) $session['session_code']) ?>;
    const ID   = <?= (int) $session['session_id'] ?>;
    const RELEASE_LABELS = <?= json_js(AttendanceService::RELEASE_REASONS) ?>;
    const BASE = <?= json_js($sessionsPath) ?>;

    /* ---- roster filtering ------------------------------------------------ */
    const search = document.getElementById('roster-search');
    const status = document.getElementById('roster-status');

    function filter() {
        const query = (search.value || '').trim().toLowerCase();
        const state = status.value;

        document.querySelectorAll('#roster-table tbody tr').forEach((row) => {
            const matchesQuery = query === '' || row.dataset.search.indexOf(query) !== -1;
            const matchesState = state === '' || row.dataset.state === state;
            row.hidden = !(matchesQuery && matchesState);
        });
    }

    if (search) search.addEventListener('input', LS.util.debounce(filter, 150));
    if (status) status.addEventListener('change', filter);

    <?php if ($isOpen): ?>
    /* ---- live roster updates --------------------------------------------- */

    // Rather than patching each cell by hand from the event payload, the row
    // is re-read from the server: the payload is a notification, the server is
    // the source of truth, and a missed event cannot leave the table wrong.
    const refresh = LS.util.debounce(async function () {
        try {
            const response = await LS.http.poll(BASE + '/' + ID);
            applyRoster(response.data.roster);
        } catch (error) { /* the next event will try again */ }
    }, 400);

    function applyRoster(roster) {
        const counts = { in_room: 0, timed_out: 0, late: 0, no_record: 0 };

        roster.forEach((entry) => {
            if (entry.time_in === null)      counts.no_record++;
            else if (entry.time_out === null) counts.in_room++;
            else                              counts.timed_out++;

            if (entry.arrival_status === 'late') counts.late++;

            const row = document.querySelector('#roster-table tr[data-student="' + entry.student_id + '"]');
            if (!row) return;

            row.dataset.state = entry.time_in === null ? 'none' : (entry.time_out === null ? 'in' : 'out');

            setTimeIn(row, entry);
            set(row, 'time_out', entry.time_out ? time(entry.time_out) : '—');
            set(row, 'duration', entry.duration_minutes === null ? '—' : entry.duration_minutes + ' min');
            set(row, 'arrival_status',   entry.arrival_status   || '—');
            setDeparture(row, entry);
            setRelease(row, entry);

            const cell = row.querySelector('[data-cell="final_status"]');
            if (cell) {
                cell.innerHTML = entry.final_status
                    ? '<span class="badge ' + badge(entry.final_status) + '">'
                      + LS.util.escape(entry.final_status) + '</span>'
                    : '<span class="badge badge-neutral">Pending</span>';
            }
        });

        Object.entries(counts).forEach(([key, value]) => {
            const node = document.querySelector('[data-count="' + key + '"]');
            if (node && node.textContent.trim() !== String(value)) node.textContent = value;
        });

        filter();
    }

    // A student who has just left — by their own tap or by being released —
    // must lose the Release button without waiting for a reload, or the next
    // click posts against a record that already has a time-out.
    function setRelease(row, entry) {
        const cell = row.querySelector('[data-cell="release"]');
        if (!cell) return;

        const inTheRoom = entry.time_in !== null && entry.time_out === null;

        if (!inTheRoom) {
            if (cell.firstChild) cell.textContent = '';
            return;
        }

        if (cell.querySelector('[data-release]')) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-secondary';
        button.dataset.release = entry.attendance_id;
        button.dataset.name = entry.student_name;
        button.title = 'Send this student out of the room before the end of the period';
        button.innerHTML = '<i class="fa-solid fa-person-walking-arrow-right"></i> Release';
        cell.appendChild(button);
    }

    // Departure carries a marker for the same reason time-in does: Left Early
    // is what a genuine early tap-out produces too, and a release is a
    // different event that happens to end in the same status.
    function setDeparture(row, entry) {
        const cell = row.querySelector('[data-cell="departure_status"]');
        if (!cell) return;

        const label = LS.util.escape(entry.departure_status || '—');
        const note  = entry.early_release_note ? ': ' + entry.early_release_note : '';
        const html  = label + (entry.early_release_reason
            ? ' <i class="fa-solid fa-hand text-warning" title="'
              + LS.util.escape('Released by ' + (entry.early_released_by_name || 'a member of staff')
                + ' — ' + (RELEASE_LABELS[entry.early_release_reason] || entry.early_release_reason).toLowerCase()
                + note)
              + '"></i>'
            : '');

        if (cell.innerHTML.trim() === html) return;

        cell.innerHTML = html;
        cell.classList.remove('cell-flash');
        void cell.offsetWidth;
        cell.classList.add('cell-flash');
    }

    // Time in carries a marker the other cells do not: a record that arrived by
    // carry-over from the previous period rather than by a tap. set() writes
    // textContent, which would silently strip that icon on the first refresh
    // and leave a carried record looking exactly like a scanned one.
    function setTimeIn(row, entry) {
        const cell = row.querySelector('[data-cell="time_in"]');
        if (!cell) return;

        const label = entry.time_in ? time(entry.time_in) : '—';
        const from  = entry.carried_from_subject || entry.carried_from_code || '';
        const html  = LS.util.escape(label) + (entry.carried_from_session_id
            ? ' <i class="fa-solid fa-arrow-right-arrow-left text-muted" title="Carried from '
              + LS.util.escape(from)
              + ' — this student was already in the room and did not tap again"></i>'
            : '');

        if (cell.innerHTML.trim() === html) return;

        cell.innerHTML = html;
        cell.classList.remove('cell-flash');
        void cell.offsetWidth;
        cell.classList.add('cell-flash');
    }

    function set(row, key, value) {
        const cell = row.querySelector('[data-cell="' + key + '"]');
        if (!cell || cell.textContent.trim() === value) return;

        cell.textContent = value;
        cell.classList.remove('cell-flash');
        void cell.offsetWidth;
        cell.classList.add('cell-flash');
    }

    function time(value) {
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime())
            ? String(value)
            : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    // Status colours come from the shared helper so the table, the feed and the
    // dashboard can never disagree about what "Incomplete" looks like.
    const badge = LS.util.statusBadge;

    /* ---- tap feed --------------------------------------------------------- */
    const feed = document.getElementById('tap-feed');

    function push(direction, title, detail, rejected) {
        const empty = feed.querySelector('[data-feed-empty]');
        if (empty) empty.remove();

        const row = document.createElement('div');
        row.className = 'live-row' + (rejected ? ' live-row--rejected' : '');
        row.innerHTML = '<span class="live-row__arrow live-row__arrow--' + direction + '">'
            + '<i class="fa-solid fa-' + (rejected ? 'ban' : (direction === 'out' ? 'arrow-left' : 'arrow-right')) + '"></i></span>'
            + '<span class="live-row__body"><span class="live-row__name"></span>'
            + '<span class="live-row__meta"></span></span>';

        row.querySelector('.live-row__name').textContent = title;
        row.querySelector('.live-row__meta').textContent = detail;

        feed.prepend(row);

        while (feed.children.length > 20) feed.lastElementChild.remove();
    }

    function mine(payload) { return payload && payload.session_id === CODE; }

    LS.realtime.on('attendance.time_in', function (payload) {
        if (!mine(payload)) return;
        push('in', payload.student.name, 'Time in ' + payload.time_in + ' · ' + payload.final_status, false);
        refresh();
    });

    LS.realtime.on('attendance.time_out', function (payload) {
        if (!mine(payload)) return;
        push('out', payload.student.name,
            'Time out ' + payload.time_out + ' · ' + payload.duration_minutes + ' min · ' + payload.final_status, false);
        refresh();
    });

    LS.realtime.on('attendance.rejected', function (payload) {
        if (!mine(payload)) return;
        push('in', payload.code.replace(/_/g, ' '), payload.message, true);
    });

    LS.realtime.on('session.closed', function (payload) {
        if (!payload || payload.session_id !== CODE) return;
        LS.toast.info('This session has been closed. Reloading.');
        setTimeout(() => window.location.reload(), 1200);
    });

    /* ---- releasing a student before the bell ------------------------------ */

    // One dialog reused by every row, holding the attendance_id of whichever
    // Release was pressed. A per-row form would put forty identical forms in
    // the page for a control used perhaps twice a week.
    const releaseModal = document.getElementById('release-modal');
    const releaseForm  = document.getElementById('release-form');
    const releaseNote  = document.getElementById('release-note');
    const releaseWhy   = document.getElementById('release-reason');
    const noteHint     = document.getElementById('release-note-hint');
    let   releasing    = null;

    // Delegated, because rows lose and regain their button as students come
    // and go and a handler bound at load would be bound to the wrong buttons.
    document.getElementById('roster-table').addEventListener('click', function (event) {
        const button = event.target.closest('[data-release]');
        if (!button) return;

        releasing = button.dataset.release;
        releaseForm.reset();
        syncNoteRequirement();
        LS.modal.fill(releaseModal, { student_name: button.dataset.name });
        LS.modal.open('release-modal');
    });

    // 'Other' with no note records that something happened and nothing about
    // what, so the field earns its asterisk only for that one choice.
    function syncNoteRequirement() {
        const isOther = releaseWhy.value === 'other';
        releaseNote.required = isOther;
        noteHint.textContent = isOther ? 'required' : 'optional';
    }

    releaseWhy.addEventListener('change', syncNoteRequirement);

    releaseForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!releasing) return;

        const submit = document.getElementById('release-submit');
        LS.util.setBusy(submit, true, 'Releasing…');

        try {
            const response = await LS.http.post(BASE + '/' + ID + '/release', {
                attendance_id: releasing,
                reason: releaseWhy.value,
                note: releaseNote.value,
            });

            LS.toast.success(response.message);
            LS.modal.close('release-modal');
            releasing = null;
            refresh();
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(submit, false);
        }
    });

    /* ---- force close ------------------------------------------------------ */
    document.getElementById('close-session').addEventListener('click', async function () {
        const confirmed = await LS.modal.confirm({
            title:   'Close ' + CODE + '?',
            message: 'Students still in the room are given an automatic time-out at whichever comes '
                     + 'first, this moment or the scheduled end, and flagged as such; everyone with no '
                     + 'record is marked absent. Attendance records cannot be edited or deleted '
                     + 'afterwards.',
            confirmLabel: 'Close session',
            danger: true,
        });

        if (!confirmed) return;

        LS.util.setBusy(this, true, 'Closing…');

        try {
            const response = await LS.http.post(BASE + '/' + ID + '/close', {});
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 1000);
        } catch (error) {
            LS.toast.fromError(error);
            LS.util.setBusy(this, false);
        }
    });
    <?php endif; ?>
})();
</script>
<?php $__view->stop(); ?>
