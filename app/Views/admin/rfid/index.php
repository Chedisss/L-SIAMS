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
        ['Active', $summary['active'], 'success', null], ['Inactive', $summary['inactive'], 'neutral', null],
        ['Lost', $summary['lost'], 'warning', null], ['Blacklisted', $summary['blacklisted'], 'danger', null],
        // Kept in step with the waiting list below, which empties as cards are
        // issued without the page being reloaded.
        ['No card', $summary['unassigned_students'], 'warning', 'stat-no-card'],
        ['Unknown', $summary['unknown_pending'], 'info', null],
    ] as [$label, $value, $tone, $id]): ?>
        <div class="stat">
            <div>
                <div class="stat__label"><?= e($label) ?></div>
                <div class="stat__value <?= $value > 0 && in_array($tone, ['danger', 'warning'], true) ? 'text-' . e($tone) : '' ?>"
                     <?= $id === null ? '' : 'id="' . e($id) . '"' ?>><?= e($value) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Waiting for a card ------------------------------------------------------->
<!--
    The work queue after a roster import. Importing a section leaves every
    student in it with a complete record and no card, and the only way to find
    them used to be to already know their names. Filtering this to the section
    that is standing in front of the reader turns issuing a class's cards into
    one list that empties as you go.
-->
<div class="card mb-3" id="queue-card">
    <div class="card__header">
        <h2 class="card__title">
            Waiting for a card
            <span class="badge badge-warning" id="queue-count"><?= e($queueTotal) ?></span>
        </h2>

        <div class="flex gap-1">
            <select id="q-section" class="input-sm" aria-label="Filter waiting students by section">
                <option value="">All sections</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= e($section['section_id']) ?>"><?= e($section['section_code']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" id="q-search" class="input-sm" placeholder="Name or student number…" aria-label="Search waiting students">
        </div>
    </div>

    <div class="card__body--flush">
        <div class="table-wrap" style="max-height:420px;overflow-y:auto">
            <table class="data">
                <thead>
                <tr><th>Student</th><th>Section</th><th>Grade</th><th></th><th style="width:150px"></th></tr>
                </thead>
                <tbody id="queue-rows">
                <?php if ($queue === []): ?>
                    <tr id="queue-empty"><td colspan="5" class="text-center text-muted" style="padding:1.75rem">
                        Every active student holds a card.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($queue as $student): ?>
                        <tr data-queue-row="<?= e($student['student_id']) ?>">
                            <td>
                                <a href="/admin/students/<?= e($student['student_id']) ?>">
                                    <?= e($student['last_name']) ?>, <?= e($student['first_name']) ?>
                                </a>
                                <div class="text-xs text-muted mono"><?= e($student['student_number']) ?></div>
                            </td>
                            <td><?= $student['section_code']
                                ? '<span class="badge badge-primary">' . e($student['section_code']) . '</span>'
                                : '<span class="text-subtle">no section</span>' ?></td>
                            <td class="text-sm"><?= e($student['grade_level_code'] ?? '—') ?></td>
                            <td>
                                <?php if ((int) $student['previous_cards'] > 0): ?>
                                    <span class="badge badge-neutral" title="A card was issued before and is no longer active">replacement</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?php /* The row carries the id and nothing else. The name is looked up
                                         from the queue the script already holds, so no student-supplied
                                         text is ever written into an HTML attribute. */ ?>
                                <button class="btn btn-primary btn-sm" data-issue-to="<?= e($student['student_id']) ?>">
                                    <i class="fa-solid fa-wifi"></i> Issue card
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card__footer text-xs text-muted" id="queue-more" <?= $queueTotal > $queueSize ? '' : 'hidden' ?>>
        Showing the first <?= e($queueSize) ?> of <?= e($queueTotal) ?>. Filter by section to see the rest.
    </div>
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
                                <?php if ($card['student_id'] && (string) $card['status'] === 'active'): ?>
                                    <a class="btn btn-ghost btn-sm" href="/admin/rfid?replace=<?= e($card['student_id']) ?>"
                                       title="Replace this card"><i class="fa-solid fa-id-card"></i></a>
                                <?php endif; ?>
                                <?php /* Only where the holder has left. A card belonging to somebody
                                        still enrolled is withdrawn through the replacement flow, which
                                        makes a reason compulsory; offering release here as well would
                                        be offering a way round that. */ ?>
                                <?php if ($card['student_id'] && (string) ($card['student_status'] ?? '') !== 'active'
                                          && (string) $card['status'] !== 'blacklisted'): ?>
                                    <button class="btn btn-ghost btn-sm" data-release="<?= e($card['rfid_id']) ?>"
                                            data-uid="<?= e($card['card_uid']) ?>"
                                            title="Release this card back to stock"><i class="fa-solid fa-rotate-left"></i></button>
                                <?php endif; ?>
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
                    <input type="text" id="a-notes" name="notes" maxlength="255" placeholder="e.g. issued at enrolment">
                </div>
                <?php /* Shown only once the chosen student turns out to hold a
                         card already: this stops being an issue and becomes a
                         replacement, and the old card cannot be retired
                         without saying what happened to it. */ ?>
                <div class="form-group hidden" id="a-replace-block">
                    <div class="alert alert-warning" style="margin-bottom:.7rem">
                        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div class="alert__body">
                            This student already holds card <strong class="mono" id="a-current-uid"></strong>.
                            Issuing this one takes that card out of service.
                        </div>
                    </div>
                    <label for="a-replace-reason" class="required">What happened to the old card?</label>
                    <select id="a-replace-reason" name="replacement_reason">
                        <option value="">Choose a reason</option>
                        <?php foreach (\App\Services\RfidService::REPLACEMENT_REASONS as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="a-replace-note" style="margin-top:.6rem">
                        Note <span class="label__hint" id="a-replace-note-hint">optional</span>
                    </label>
                    <input type="text" id="a-replace-note" name="replacement_note" maxlength="255"
                           placeholder="Anything the reason above does not say">
                    <span class="field-help" id="a-replace-effect"></span>
                </div>
                <div class="alert alert-info" style="margin-bottom:0">
                    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                    <div class="alert__body">
                        A replacement keeps every attendance record. Attendance references the student,
                        never the card.
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

            <!--
                Only shown when the modal was opened from the waiting list. The
                student is already chosen and the next one is named, so the
                operator can see their place in the queue without closing the
                dialog to look.
            -->
            <div class="alert alert-success hidden" id="r-queue-note">
                <span class="alert__icon"><i class="fa-solid fa-list-check"></i></span>
                <div class="alert__body" id="r-queue-note-text"></div>
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
                <input type="text" id="r-notes" maxlength="255" placeholder="e.g. issued at enrolment">
            </div>

            <div class="form-group hidden" id="r-replace-block">
                <div class="alert alert-warning" style="margin-bottom:.7rem">
                    <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div class="alert__body">
                        This student already holds card <strong class="mono" id="r-current-uid"></strong>.
                        Reading a new one takes that card out of service.
                    </div>
                </div>
                <label for="r-replace-reason" class="required">What happened to the old card?</label>
                <select id="r-replace-reason">
                    <option value="">Choose a reason</option>
                    <?php foreach (\App\Services\RfidService::REPLACEMENT_REASONS as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="r-replace-note" style="margin-top:.6rem">
                    Note <span class="label__hint" id="r-replace-note-hint">optional</span>
                </label>
                <input type="text" id="r-replace-note" maxlength="255"
                       placeholder="Anything the reason above does not say">
                <span class="field-help" id="r-replace-effect"></span>
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
                option.textContent = student.name + '  ·  ' + student.student_number + '  ·  ' + student.section_code
                    + (student.card_uid ? '  ·  holds ' + student.card_uid : '');
                // Carried on the option so choosing a student can decide,
                // without another round trip, whether this is an issue or a
                // replacement.
                if (student.card_uid) option.dataset.cardUid = student.card_uid;
                select.appendChild(option);
            });

            if (select.options.length === 0) {
                select.innerHTML = '<option value="">No matching students</option>';
            }
        } catch (error) { /* leave the previous list */ }
    }

    document.getElementById('student-search').addEventListener('input', LS.util.debounce(async function () {
        await searchStudents(this.value.trim(), document.getElementById('a-student'));
        syncAssignReplacement();
    }, 300));

    /* ---- issuing versus replacing ----------------------------------------- */

    // A card is only ever taken out of service for a reason, and the reason
    // decides what the old card becomes — so the form asks the moment the
    // chosen student turns out to be holding one, rather than letting the
    // server refuse after the fact.
    const REPLACEMENT_EFFECT = {
        lost:         'The old card will be marked Lost, and refused if anybody presents it.',
        damaged:      'The old card will be marked Replaced.',
        stolen:       'The old card will be blacklisted and can never be issued to anybody again.',
        not_returned: 'The old card will be marked Lost, and refused if anybody presents it.',
        other:        'The old card will be marked Replaced.',
    };

    function bindReplacement(prefix, select) {
        const block  = document.getElementById(prefix + '-replace-block');
        const reason = document.getElementById(prefix + '-replace-reason');
        const note   = document.getElementById(prefix + '-replace-note');
        const hint   = document.getElementById(prefix + '-replace-note-hint');
        const effect = document.getElementById(prefix + '-replace-effect');
        const uid    = document.getElementById(prefix + '-current-uid');

        function sync() {
            const chosen  = select.selectedOptions[0];
            const holding = chosen ? chosen.dataset.cardUid : null;

            block.classList.toggle('hidden', !holding);
            reason.required = !!holding;

            if (!holding) {
                reason.value = '';
                note.value   = '';
                note.required = false;
                return;
            }

            uid.textContent = holding;
            effect.textContent = REPLACEMENT_EFFECT[reason.value] || '';

            // 'Other' with no note records that something happened and nothing
            // about what, so the field earns its asterisk only there.
            const isOther = reason.value === 'other';
            note.required     = isOther;
            hint.textContent  = isOther ? 'required' : 'optional';
        }

        select.addEventListener('change', sync);
        reason.addEventListener('change', sync);

        return sync;
    }

    const syncAssignReplacement = bindReplacement('a', document.getElementById('a-student'));

    /* ---- the waiting list -------------------------------------------------- */

    // Held in memory so "who is next" can be answered between two students
    // without another round trip, and so the row just issued can leave the list
    // the moment the card is written rather than at the next page load.
    let queue = <?= json_encode(array_map(static fn (array $s): array => [
        'student_id'     => (int) $s['student_id'],
        'student_number' => (string) $s['student_number'],
        'name'           => $s['last_name'] . ', ' . $s['first_name'],
        'section_code'   => $s['section_code'],
        'grade_level_code' => $s['grade_level_code'],
        'previous_cards' => (int) $s['previous_cards'],
    ], $queue), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    let queueTotal = <?= (int) $queueTotal ?>;
    const QUEUE_SIZE = <?= (int) $queueSize ?>;

    const queueRows  = document.getElementById('queue-rows');
    const queueCount = document.getElementById('queue-count');
    const queueMore  = document.getElementById('queue-more');

    function renderQueue() {
        queueCount.textContent = queueTotal;

        const noCard = document.getElementById('stat-no-card');
        if (noCard) {
            noCard.textContent = queueTotal;
            noCard.classList.toggle('text-warning', queueTotal > 0);
        }

        queueMore.hidden = queueTotal <= QUEUE_SIZE;
        queueMore.textContent = 'Showing the first ' + QUEUE_SIZE + ' of ' + queueTotal
            + '. Filter by section to see the rest.';

        if (queue.length === 0) {
            queueRows.innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding:1.75rem">'
                + (queueTotal === 0
                    ? 'Every active student holds a card.'
                    : 'No waiting student matches this filter.')
                + '</td></tr>';
            return;
        }

        // Only the numeric id goes into an attribute. LS.util.escape is built on
        // textContent, which leaves a double quote alone — correct for element
        // text, wrong for an attribute value, and a student's name is data an
        // administrator (or an imported spreadsheet) supplies. The click handler
        // reads the name out of this array by id instead.
        queueRows.innerHTML = queue.map((student) =>
            '<tr data-queue-row="' + Number(student.student_id) + '">'
            + '<td><a href="/admin/students/' + Number(student.student_id) + '">' + LS.util.escape(student.name) + '</a>'
            + '<div class="text-xs text-muted mono">' + LS.util.escape(student.student_number) + '</div></td>'
            + '<td>' + (student.section_code
                ? '<span class="badge badge-primary">' + LS.util.escape(student.section_code) + '</span>'
                : '<span class="text-subtle">no section</span>') + '</td>'
            + '<td class="text-sm">' + LS.util.escape(student.grade_level_code || '—') + '</td>'
            + '<td>' + (student.previous_cards > 0
                ? '<span class="badge badge-neutral" title="A card was issued before and is no longer active">replacement</span>'
                : '') + '</td>'
            + '<td class="nowrap"><button class="btn btn-primary btn-sm" data-issue-to="' + Number(student.student_id) + '">'
            + '<i class="fa-solid fa-wifi"></i> Issue card</button></td></tr>').join('');
    }

    async function loadQueue() {
        try {
            const response = await LS.http.get('/admin/rfid/without-card', {
                section_id: document.getElementById('q-section').value,
                search:     document.getElementById('q-search').value.trim(),
            });

            // Normalised to a number here so every later identity check is a
            // plain === against the same type, whatever the driver hands back.
            queue = (response.data.rows || []).map((row) => ({
                student_id:       Number(row.student_id),
                student_number:   row.student_number,
                name:             row.last_name + ', ' + row.first_name,
                section_code:     row.section_code,
                grade_level_code: row.grade_level_code,
                previous_cards:   Number(row.previous_cards),
            }));

            queueTotal = response.data.total;
            renderQueue();
        } catch (error) {
            LS.toast.fromError(error);
        }
    }

    document.getElementById('q-section').addEventListener('change', loadQueue);
    document.getElementById('q-search').addEventListener('input', LS.util.debounce(loadQueue, 400));

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

    // Queue mode is the same dialog driven from the waiting list: the student
    // is already known, so the only thing left is the card itself. The manual
    // path through the search box is untouched and still the way to issue a
    // card to somebody who is not on the list — a replacement, or a student
    // whose card was blacklisted an hour ago.
    let queueMode      = false;
    let armedStudentId = null;

    /** Put one student into the picker and select them. */
    function armStudent(student) {
        const select = document.getElementById('r-student');
        const option = document.createElement('option');

        option.value       = student.student_id;
        option.textContent = student.name + '  ·  ' + student.student_number
            + (student.section_code ? '  ·  ' + student.section_code : '')
            + (student.card_uid ? '  ·  holds ' + student.card_uid : '');
        option.selected    = true;

        if (student.card_uid) option.dataset.cardUid = student.card_uid;

        select.innerHTML = '';
        select.appendChild(option);

        document.getElementById('r-student-search').value = '';
        armedStudentId = Number(student.student_id);

        // The waiting list is students with no card, but this picker also takes
        // anybody searched for by hand — including somebody replacing one.
        syncReadReplacement();
    }

    function paintQueueNote(armedName, next) {
        const note = document.getElementById('r-queue-note');

        if (!queueMode) {
            note.classList.add('hidden');
            return;
        }

        const remaining = queue.length - (armedStudentId === null ? 0 : 1);

        document.getElementById('r-queue-note-text').innerHTML =
            '<strong>' + LS.util.escape(armedName) + '</strong> is next to receive a card.'
            + (next
                ? ' After this one, ' + remaining + ' still waiting — ' + LS.util.escape(next.name) + ' follows.'
                : (remaining > 0
                    ? ' ' + remaining + ' still waiting after this one.'
                    : ' This is the last student waiting.'));

        note.classList.remove('hidden');
    }

    /** The student below the armed one, wrapping to the top at the end. */
    function nextInQueue() {
        if (armedStudentId === null) return queue[0] || null;

        const at = queue.findIndex((student) => student.student_id === armedStudentId);

        return at === -1 ? (queue[0] || null) : (queue[at + 1] || null);
    }

    /**
     * Drop the student whose card was just written and hand back whoever is
     * now in their place, so the operator carries straight on down the list.
     */
    function advanceQueue(issuedId) {
        const at = queue.findIndex((student) => student.student_id === Number(issuedId));

        if (at === -1) {
            renderQueue();
            return queue[0] || null;
        }

        queue.splice(at, 1);
        queueTotal = Math.max(0, queueTotal - 1);
        renderQueue();

        // queue[at] is the row that moved up into the gap; at the bottom of the
        // list there is nothing below, so start again from the top rather than
        // claiming the work is finished while names are still on screen.
        return queue[at] || queue[0] || null;
    }

    document.addEventListener('click', (event) => {
        const issue = event.target.closest('[data-issue-to]');

        if (issue === null) return;

        const student = queue.find((row) => row.student_id === Number(issue.dataset.issueTo));

        if (!student) return;

        queueMode = true;
        armStudent(student);

        showReadStep('device');
        paintQueueNote(student.name, nextInQueue());
        LS.modal.open('read-modal');

        // The reader is remembered between students, so once it has been picked
        // the only thing left to do is press the button.
        const device = document.getElementById('r-device');
        (device.value === '' ? device : readButtons.start).focus();
    });

    // Opening the dialog from the page header is the manual path: no student is
    // implied, and any queue position from a previous run is dropped.
    document.querySelectorAll('[data-modal-open="read-modal"]').forEach((button) => {
        button.addEventListener('click', () => {
            queueMode      = false;
            armedStudentId = null;
            document.getElementById('r-queue-note').classList.add('hidden');
        });
    });

    const syncReadReplacement = bindReplacement('r', document.getElementById('r-student'));

    /* ---- arriving from a student's own page ------------------------------- */

    // "Replace this card" on a student names the student in the URL. Opening
    // the reader dialog with them already armed is the whole point of the link:
    // whoever followed it had already found the student once.
    const REPLACE_FOR = <?= json_js($replaceFor) ?>;

    if (REPLACE_FOR) {
        armStudent(REPLACE_FOR);
        LS.modal.open('read-modal');
        document.getElementById('r-replace-reason').focus();
    }

    document.getElementById('r-student-search').addEventListener('input', LS.util.debounce(async function () {
        await searchStudents(this.value.trim(), document.getElementById('r-student'));
        // The option list was just rebuilt, so the block has to re-decide from
        // whichever student is now selected.
        syncReadReplacement();
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

        /* Online and useless: the reader did not answer when the terminal
           started, so nothing here will ever be picked up — but the board
           heartbeats normally and so passes every liveness test below. */
        if (stillWaiting && data.terminal_reader_ok === false) {
            document.getElementById('read-terminal-warning-text').textContent =
                data.device_id + ' is online, but its card reader did not respond when the '
                + 'terminal started up, so it cannot pick this request up. The MFRC522 is a '
                + '3.3 V part — 5 V destroys it — and MISO/MOSI are the pair usually swapped. '
                + 'Check the wiring, then reset the board.';
            warning.classList.remove('hidden');
        } else if (stillWaiting && data.terminal_health !== 'online') {
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

        // Asked before the reader is driven rather than after the card has been
        // presented: the server refuses a replacement with no reason, and
        // discovering that at the end means a card read for nothing.
        const replaceReason = document.getElementById('r-replace-reason');
        const replaceNote   = document.getElementById('r-replace-note');

        if (replaceReason.required && !replaceReason.value) {
            LS.toast.warning('Say what happened to the card this student already holds.');
            replaceReason.focus();
            return;
        }

        if (replaceReason.value === 'other' && !replaceNote.value.trim()) {
            LS.toast.warning('Recording this as Other needs a short note saying what happened.');
            replaceNote.focus();
            return;
        }

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

        const issuedId = armedStudentId;

        try {
            // No student in the payload: the request already names one, and the
            // server issues to that one. Sending it again would only create a
            // way for the two to disagree.
            const response = await LS.http.post('/admin/rfid/read/' + readRequestId + '/assign', {
                notes: document.getElementById('r-notes').value,
                replacement_reason: document.getElementById('r-replace-reason').value,
                replacement_note: document.getElementById('r-replace-note').value,
            });

            LS.toast.success(response.message);
            document.body.dataset.rfidIssued = '1';

            readRequestId = null;
            document.getElementById('r-notes').value = '';
            document.getElementById('r-replace-reason').value = '';
            document.getElementById('r-replace-note').value = '';
            document.getElementById('r-replace-block').classList.add('hidden');
            showReadStep('device');

            if (queueMode) {
                // Straight on to whoever is next: the card is written, the row
                // leaves the list, and the reader stays selected. One press and
                // one tap per student, without going back to a search box.
                const next = advanceQueue(issuedId);

                if (next === null) {
                    queueMode      = false;
                    armedStudentId = null;
                    document.getElementById('r-queue-note').classList.add('hidden');
                    document.getElementById('r-student').innerHTML =
                        '<option value="">Search for a student above</option>';
                    LS.toast.success('Every student on the list now holds a card.');
                    return;
                }

                armStudent(next);
                paintQueueNote(next.name, nextInQueue());
                readButtons.start.focus();
                return;
            }

            // Manual path, unchanged: the next card belongs to somebody whose
            // name has not been given yet, so ask for it.
            armedStudentId = null;
            document.getElementById('r-student').innerHTML = '<option value="">Search for a student above</option>';
            document.getElementById('r-student-search').value = '';
            document.getElementById('r-student-search').focus();

            // The student who was just given a card may well be sitting in the
            // list behind this dialog; take them out of it.
            loadQueue();
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

        readRequestId  = null;
        queueMode      = false;
        armedStudentId = null;
        document.getElementById('r-queue-note').classList.add('hidden');
        showReadStep('device');

        // The waiting list has kept itself current throughout, but the card
        // table behind it has not, so one reload at the end of the run.
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

        const release = event.target.closest('[data-release]');

        if (release) {
            const confirmed = await LS.modal.confirm({
                title: 'Release card ' + release.dataset.uid + '?',
                message: 'The card stops belonging to this student and returns to stock, so it can be '
                       + 'issued to somebody else. Their attendance history is not affected — every tap '
                       + 'they made keeps their name and this UID.',
                confirmLabel: 'Release to stock',
            });

            if (!confirmed) return;

            try {
                const response = await LS.http.post('/admin/rfid/' + release.dataset.release + '/release', {});
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
