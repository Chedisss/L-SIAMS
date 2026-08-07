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
        . '</a><button class="btn btn-primary" data-modal-open="assign-modal"><i class="fa-solid fa-plus"></i> Issue Card</button>',
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
    document.getElementById('student-search').addEventListener('input', LS.util.debounce(async function () {
        const select = document.getElementById('a-student');
        const query = this.value.trim();

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
    }, 300));

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
