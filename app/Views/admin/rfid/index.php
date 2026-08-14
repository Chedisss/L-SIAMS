<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'RFID Cards',
    'subtitle'    => 'One active card per student. Replacing a card keeps every attendance record — attendance references the student, not the card.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['RFID Cards', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/rfid/unknown"><i class="fa-solid fa-circle-question"></i> Unknown cards'
        . ($summary['unknown_pending'] > 0 ? ' <span class="badge badge-danger">' . (int) $summary['unknown_pending'] . '</span>' : '')
        . '</a><button class="btn btn-secondary" data-modal-open="assign-modal"><i class="fa-solid fa-keyboard"></i> Type a UID</button>'
        . '<button class="btn btn-primary" data-modal-open="read-modal"><i class="fa-solid fa-wifi"></i> Issue by tapping</button>',
]); ?>

<div class="grid grid--6 mb-3">
    <?php foreach ([
        ['Active', $summary['active'], 'success'], ['Inactive', $summary['inactive'], 'neutral'],
        ['Lost', $summary['lost'], 'warning'], ['Blacklisted', $summary['blacklisted'], 'danger'],
        ['No card', $summary['unassigned_students'], 'warning'], ['Unknown', $summary['unknown_pending'], 'info'],
    ] as [$label, $value, $tone]): ?>
        <div class="stat">
            <div>
                <div class="stat__label"><?= e($label) ?></div>
                <div class="stat__value <?= $value > 0 && in_array($tone, ['danger', 'warning'], true) ? 'text-' . e($tone) : '' ?>"><?= e($value) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<form class="filter-bar" data-no-submit>
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Card UID, student number or name">
    </div>
    <div class="form-group">
        <label for="f-status">Status</label>
        <select id="f-status" name="status" data-filter-input>
            <option value="">All</option>
            <?php foreach (['active', 'inactive', 'lost', 'blacklisted', 'replaced'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-section">Section</label>
        <select id="f-section" name="section_id" data-filter-input>
            <option value="">All sections</option>
            <?php foreach ($sections as $section): ?>
                <option value="<?= e($section['section_id']) ?>" <?= (int) ($filters['section_id'] ?? 0) === (int) $section['section_id'] ? 'selected' : '' ?>>
                    <?= e($section['section_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($cards === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon' => 'fa-id-card', 'title' => 'No cards found',
                'text' => 'Issue a card to a student so they can be marked present.',
                'action' => '<button class="btn btn-primary" data-modal-open="assign-modal"><i class="fa-solid fa-plus"></i> Issue Card</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Card UID</th><th>Student</th><th>Section</th><th>Issued</th>
                        <th>Replaces</th><th class="numeric">Taps</th><th>Last tap</th><th>Status</th><th style="width:130px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($cards as $card): ?>
                        <tr>
                            <td class="mono text-sm"><?= e($card['card_uid']) ?></td>
                            <td>
                                <?php if ($card['student_id']): ?>
                                    <a href="/admin/students/<?= e($card['student_id']) ?>">
                                        <?= e($card['last_name']) ?>, <?= e($card['first_name']) ?>
                                    </a>
                                    <div class="text-xs text-muted"><?= e($card['student_number']) ?></div>
                                <?php else: ?>
                                    <span class="text-subtle">unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $card['section_code'] ? '<span class="badge badge-primary">' . e($card['section_code']) . '</span>' : '—' ?></td>
                            <td class="text-sm nowrap"><?= e(format_date($card['issue_date'])) ?></td>
                            <td class="mono text-xs"><?= e($card['previous_uid'] ?? '—') ?></td>
                            <td class="numeric"><?= e($card['tap_count']) ?></td>
                            <td class="text-sm nowrap"><?= e(time_ago($card['last_tap_at'])) ?></td>
                            <td><span class="badge <?= e(status_badge($card['status'])) ?>"><?= e(ucfirst((string) $card['status'])) ?></span></td>
                            <td class="nowrap">
                                <button class="btn btn-ghost btn-sm" data-history="<?= e($card['card_uid']) ?>" title="Tap history"><i class="fa-solid fa-clock-rotate-left"></i></button>
                                <button class="btn btn-ghost btn-sm" data-status="<?= e($card['rfid_id']) ?>"
                                        data-uid="<?= e($card['card_uid']) ?>" data-current="<?= e($card['status']) ?>" title="Change status"><i class="fa-solid fa-toggle-on"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php $__view->include('partials.pagination', ['pagination' => $pagination, 'target' => 'rfidTable']); ?>
</div>

<div class="modal-backdrop" id="assign-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Issue an RFID card</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form method="post" data-ajax action="/admin/rfid/assign" data-reload="true">
            <div class="modal__body">
                <div class="form-group">
                    <label for="a-student" class="required">Student</label>
                    <input type="search" id="student-search" placeholder="Type a name or student number…" autocomplete="off">
                    <select id="a-student" name="student_id" required size="6" style="margin-top:.4rem">
                        <option value="">Search for a student above</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="a-uid" class="required">Card UID</label>
                    <input type="text" id="a-uid" name="card_uid" required maxlength="32"
                           placeholder="Tap the card on the enrolment reader" data-uppercase style="font-family:var(--mono);text-transform:uppercase">
                    <span class="field-help">8–32 hexadecimal characters, as read by an MFRC522.</span>
                </div>
                <div class="form-group">
                    <label for="a-notes">Notes</label>
                    <input type="text" id="a-notes" name="notes" maxlength="255" placeholder="e.g. replacement for a lost card">
                </div>
                <div class="alert alert-info" style="margin-bottom:0">
                    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                    <div class="alert__body">
                        If the student already has a card, it is retired automatically and this becomes
                        their active one. All previous attendance is retained.
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Issue card</button>
            </div>
        </form>
    </div>
</div>

<!-- Issue by tapping ------------------------------------------------------- -->
<div class="modal-backdrop" id="read-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title" id="read-modal-title">Issue cards by tapping</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <!-- Step 1: who is the card for, and which reader ------------------- -->
        <div class="modal__body" id="read-step-device">
            <div class="alert alert-info">
                <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                <div class="alert__body">
                    Name the student first — their name goes on the terminal's display, so whoever is
                    holding the card can see who it is about to belong to. The terminal then takes its
                    reader off attendance duty and holds it open, so a card presented at any moment is
                    read. No class session is needed, and nothing is recorded as attendance.
                </div>
            </div>

            <div class="form-group">
                <label for="r-student" class="required">Student</label>
                <input type="search" id="r-student-search" placeholder="Type a name or student number…" autocomplete="off">
                <select id="r-student" required size="6" style="margin-top:.4rem">
                    <option value="">Search for a student above</option>
                </select>
                <span class="field-help">
                    A student must be in a section and active to hold a card — a card issued otherwise
                    could never be used, because every tap resolves against the session's section.
                </span>
            </div>

            <div class="form-group">
                <label for="r-notes">Notes</label>
                <input type="text" id="r-notes" maxlength="255" placeholder="e.g. replacement for a lost card">
            </div>

            <div class="form-group">
                <label for="r-device" class="required">Reader</label>
                <select id="r-device" required>
                    <option value="">Select the terminal…</option>
                    <?php foreach ($readers as $reader): ?>
                        <option value="<?= e($reader['id']) ?>">
                            <?= e($reader['device_id']) ?><?= $reader['room_number'] ? ' — Room ' . e($reader['room_number']) : '' ?>
                            (<?= e(ucfirst((string) $reader['health'])) ?>)<?= (int) $reader['enrollment_station'] === 1 ? ' · enrolment station' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-help" id="r-device-help">
                    <?= $readers === []
                        ? 'No terminal has completed activation yet, so none can be asked to read a card.'
                        : 'Enrolment stations are listed first.' ?>
                </span>
            </div>
        </div>

        <!-- Step 2: the terminal is holding its reader ---------------------- -->
        <div class="modal__body hidden" id="read-step-waiting">
            <div class="enrol-scan">
                <div class="enrol-scan__icon" id="read-icon"><i class="fa-solid fa-wifi"></i></div>
                <div class="enrol-scan__stage" id="read-stage">Waiting for the terminal…</div>
                <div class="enrol-scan__detail text-sm text-muted" id="read-detail"></div>

                <ol class="enrol-scan__steps" id="read-steps">
                    <li data-stage="waiting_for_device">Terminal picks up the request</li>
                    <li data-stage="ready">Reader held open for you</li>
                    <li data-stage="present_card">Hold the card against the reader</li>
                    <li data-stage="reading">Reading the card</li>
                </ol>

                <div class="alert alert-warning mt-2 hidden" id="read-terminal-warning">
                    <span class="alert__icon"><i class="fa-solid fa-plug-circle-xmark"></i></span>
                    <div class="alert__body" id="read-terminal-warning-text"></div>
                </div>

                <div class="text-xs text-muted mt-2" id="read-target"></div>
            </div>
        </div>

        <!-- Step 3: confirm the card against the student -------------------- -->
        <div class="modal__body hidden" id="read-step-assign">
            <div class="credential-box mb-2">
                <div class="credential-box__label">Card read</div>
                <span id="read-uid" class="mono" style="font-size:18px"></span>
            </div>

            <div class="credential-box mb-2">
                <div class="credential-box__label">Will be issued to</div>
                <span id="read-student" style="font-size:16px"></span>
            </div>

            <div class="alert mb-2" id="read-holder"></div>
        </div>

        <div class="modal__footer">
            <button type="button" class="btn btn-secondary" data-modal-close id="read-close">Cancel</button>
            <button type="button" class="btn btn-primary" id="read-start"
                    <?= $readers === [] ? 'disabled' : '' ?>>
                <i class="fa-solid fa-wifi"></i> Wait for a card
            </button>
            <button type="button" class="btn btn-primary hidden" id="read-assign">
                <i class="fa-solid fa-id-card"></i> Issue this card
            </button>
            <button type="button" class="btn btn-secondary hidden" id="read-again">
                <i class="fa-solid fa-rotate-right"></i> Different card
            </button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="history-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Tap history</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body" id="history-body"></div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
function applyRfidFilters() {
    const params = new URLSearchParams();
    ['f-search:search', 'f-status:status', 'f-section:section_id'].forEach((pair) => {
        const [id, name] = pair.split(':');
        const value = document.getElementById(id).value;
        if (value) params.set(name, value);
    });
    window.location.search = params.toString();
}

window.rfidTable = {
    load: (o) => { const p = new URLSearchParams(window.location.search); Object.entries(o).forEach(([k, v]) => p.set(k, v)); window.location.search = p.toString(); },
    page: (n) => window.rfidTable.load({ page: n }),
};

(function () {
    const LS = window.LSIAMS;

    document.getElementById('f-search').addEventListener('input', LS.util.debounce(applyRfidFilters, 500));

    // Student picker searches server-side so it works with thousands of students.
    async function searchStudents(query, select) {
        if (query.length < 2) return;

        try {
            const response = await LS.http.get('/admin/reports/students/search', { q: query });
            select.innerHTML = '';

            (response.data.rows || []).forEach((student) => {
                const option = document.createElement('option');
                option.value = student.student_id;
                option.textContent = student.name + '  ·  ' + student.student_number + '  ·  ' + student.section_code;
                select.appendChild(option);
            });

            if (select.options.length === 0) {
                select.innerHTML = '<option value="">No matching students</option>';
            }
        } catch (error) { /* leave the previous list */ }
    }

    document.getElementById('student-search').addEventListener('input', LS.util.debounce(function () {
        searchStudents(this.value.trim(), document.getElementById('a-student'));
    }, 300));

    /* ---- issue by tapping ------------------------------------------------- */

    const readSteps = {
        device:  document.getElementById('read-step-device'),
        waiting: document.getElementById('read-step-waiting'),
        assign:  document.getElementById('read-step-assign'),
    };

    const readButtons = {
        start:  document.getElementById('read-start'),
        assign: document.getElementById('read-assign'),
        again:  document.getElementById('read-again'),
        close:  document.getElementById('read-close'),
    };

    const READ_STAGES = ['waiting_for_device', 'ready', 'present_card', 'reading'];

    let readRequestId = null;
    let readPollTimer = null;
    let readDeviceId  = null;

    document.getElementById('r-student-search').addEventListener('input', LS.util.debounce(function () {
        searchStudents(this.value.trim(), document.getElementById('r-student'));
    }, 300));

    function showReadStep(name) {
        Object.entries(readSteps).forEach(([key, node]) => node.classList.toggle('hidden', key !== name));

        readButtons.start.classList.toggle('hidden', name !== 'device');
        readButtons.assign.classList.toggle('hidden', name !== 'assign');
        readButtons.again.classList.toggle('hidden', name !== 'assign');
        readButtons.close.textContent = name === 'waiting' ? 'Stop' : 'Close';
    }

    function stopReadPolling() {
        if (readPollTimer) { clearInterval(readPollTimer); readPollTimer = null; }
    }

    function paintRead(data) {
        document.getElementById('read-stage').textContent =
            data.status === 'captured'  ? 'Card read'
            : data.status === 'assigned'  ? 'Card issued'
            : data.status === 'failed'    ? 'No card presented'
            : data.status === 'expired'   ? 'Timed out'
            : data.status === 'cancelled' ? 'Cancelled'
            : (data.stage === 'waiting_for_device' ? 'Waiting for the terminal…' : 'Reader is open — tap now');

        document.getElementById('read-detail').textContent = data.message || '';

        document.getElementById('read-target').textContent =
            data.device_id + (data.room_number ? ' · Room ' + data.room_number : '');

        const reached = READ_STAGES.indexOf(data.stage);

        document.querySelectorAll('#read-steps li').forEach((item) => {
            const at = READ_STAGES.indexOf(item.dataset.stage);
            item.classList.toggle('is-done', data.status === 'captured' || (reached > -1 && at < reached));
            item.classList.toggle('is-current', !data.finished && at === reached);
        });

        // Same reasoning as the fingerprint wizard: "waiting" reads identically
        // whether the reader is about to answer or the board is unplugged.
        const warning = document.getElementById('read-terminal-warning');
        const stillWaiting = !data.finished;
        const silent = data.terminal_silent_for;

        if (stillWaiting && data.terminal_health !== 'online') {
            document.getElementById('read-terminal-warning-text').textContent =
                silent === null || silent === undefined
                    ? data.device_id + ' has never reported in, so nothing is listening for this request.'
                    : data.device_id + ' last reported ' + LS.util.humanDuration(silent)
                      + ' ago, so it may not pick this up. Note that a terminal goes quiet while it is '
                      + 'holding its reader open, which is normal for up to a minute.';
            warning.classList.remove('hidden');
        } else {
            warning.classList.add('hidden');
        }

        const icon = document.getElementById('read-icon');
        icon.className = 'enrol-scan__icon'
            + (data.status === 'captured' || data.status === 'assigned' ? ' is-success'
             : (data.finished ? ' is-error' : ' is-waiting'));

        if (data.status === 'captured') {
            stopReadPolling();
            document.getElementById('read-uid').textContent = data.card_uid;

            document.getElementById('read-student').textContent =
                (data.student_name || 'this student')
                + (data.student_number ? '  ·  ' + data.student_number : '');

            const holder = document.getElementById('read-holder');
            const blacklisted = (data.message || '').indexOf('blacklisted') > -1;
            const known = blacklisted || (data.message || '').indexOf('already active') > -1;

            holder.className = 'alert mb-2 ' + (known ? 'alert-warning' : 'alert-info');
            holder.textContent = data.message || '';

            // A blacklisted card is refused by assign() anyway; offering the
            // button would only produce an error the moment it is pressed.
            readButtons.assign.disabled = blacklisted;

            showReadStep('assign');
        } else if (data.finished) {
            stopReadPolling();
        }
    }

    async function pollRead() {
        if (!readRequestId) return;

        try {
            const response = await LS.http.poll('/admin/rfid/read/' + readRequestId);
            paintRead(response.data);
        } catch (error) {
            stopReadPolling();
            LS.toast.fromError(error);
        }
    }

    async function startRead() {
        readDeviceId = document.getElementById('r-device').value;

        const studentId = document.getElementById('r-student').value;

        // Named before the reader is asked, never after: a UID captured against
        // nobody is a UID whose card cannot be told apart from the rest of the
        // stack once it has been put down.
        if (!studentId)    { LS.toast.warning('Choose the student this card is for.'); return; }
        if (!readDeviceId) { LS.toast.warning('Choose which terminal should read the card.'); return; }

        LS.util.setBusy(readButtons.start, true, 'Asking the terminal…');

        try {
            const response = await LS.http.post('/admin/rfid/read', {
                device_row_id: readDeviceId,
                student_id: studentId,
            });

            readRequestId = response.data.request_id;
            showReadStep('waiting');
            paintRead(response.data);

            stopReadPolling();
            readPollTimer = setInterval(pollRead, 1200);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(readButtons.start, false);
        }
    }

    readButtons.start.addEventListener('click', startRead);

    /* Wrong card off the stack — same student, ask for another read. */
    readButtons.again.addEventListener('click', function () {
        startRead();
    });

    readButtons.assign.addEventListener('click', async function () {
        LS.util.setBusy(this, true, 'Issuing…');

        try {
            // No student in the payload: the request already names one, and the
            // server issues to that one. Sending it again would only create a
            // way for the two to disagree.
            const response = await LS.http.post('/admin/rfid/read/' + readRequestId + '/assign', {
                notes: document.getElementById('r-notes').value,
            });

            LS.toast.success(response.message);
            document.body.dataset.rfidIssued = '1';

            // Back to the top: the next card belongs to the next student, so
            // the next thing needed is their name.
            readRequestId = null;
            document.getElementById('r-student').innerHTML = '<option value="">Search for a student above</option>';
            document.getElementById('r-student-search').value = '';
            document.getElementById('r-notes').value = '';
            document.getElementById('r-student-search').focus();
            showReadStep('device');
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(this, false);
        }
    });

    document.getElementById('read-modal').addEventListener('modal:close', async () => {
        stopReadPolling();

        // Release the reader rather than leaving the terminal holding it for
        // somebody who has walked away.
        if (readRequestId) {
            try { await LS.http.post('/admin/rfid/read/' + readRequestId + '/cancel', {}); } catch (e) { /* it expires anyway */ }
        }

        readRequestId = null;
        showReadStep('device');

        // Counts on the page are stale once a card has been issued.
        if (document.body.dataset.rfidIssued === '1') window.location.reload();
    });

    document.addEventListener('click', async (event) => {
        const history = event.target.closest('[data-history]');

        if (history) {
            LS.modal.open('history-modal');
            const body = document.getElementById('history-body');
            body.innerHTML = '<div class="skeleton skeleton--row"></div>';

            try {
                const response = await LS.http.get('/admin/rfid/history', { uid: history.dataset.history });
                const rows = response.data.rows || [];

                body.innerHTML = rows.length === 0
                    ? '<p class="text-muted">This card has never been tapped.</p>'
                    : '<table class="data"><thead><tr><th>When</th><th>Result</th><th>Room</th><th>Session</th><th>Detail</th></tr></thead><tbody>'
                      + rows.map((row) =>
                          '<tr><td class="nowrap text-sm">' + LS.util.formatDate(row.created_at) + ' ' + LS.util.formatTime(row.created_at) + '</td>'
                          + '<td><span class="badge ' + (row.result === 'accepted' ? 'badge-success' : 'badge-danger') + '">'
                          + LS.util.escape(row.result) + '</span></td>'
                          + '<td class="text-sm">' + LS.util.escape(row.room_number || '—') + '</td>'
                          + '<td class="mono text-xs">' + LS.util.escape(row.session_code || '—') + '</td>'
                          + '<td class="text-xs text-muted">' + LS.util.escape(row.message || '') + '</td></tr>').join('')
                      + '</tbody></table>';
            } catch (error) {
                body.innerHTML = '<div class="alert alert-danger">Could not load the tap history.</div>';
            }
        }

        const status = event.target.closest('[data-status]');

        if (status) {
            const next = prompt('New status for card ' + status.dataset.uid
                + '\n\nactive, inactive, lost or blacklisted', status.dataset.current);

            if (!next) return;

            let reason = null;

            if (next === 'blacklisted') {
                reason = prompt('Reason for blacklisting (recorded in the audit trail):');
                if (!reason) { LS.toast.warning('A reason is required to blacklist a card.'); return; }
            }

            try {
                const response = await LS.http.post('/admin/rfid/' + status.dataset.status + '/status',
                    { status: next, reason: reason });
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });
})();

// The filter controls announce changes rather than calling this directly:
// an inline onchange= attribute cannot be authorised by a CSP nonce.
document.addEventListener('ls:filter-change', applyRfidFilters);
</script>
<?php $__view->stop(); ?>
