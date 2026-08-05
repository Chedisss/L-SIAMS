<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Unknown RFID Cards',
    'subtitle'    => 'Cards presented at a terminal that are not registered to any student. Every one was refused and logged.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['RFID Cards', '/admin/rfid'], ['Unknown', null]],
]); ?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title"><?= e(count($cards)) ?> card(s) awaiting triage</h2>
        <div class="btn-group">
            <?php foreach (['pending' => 'Pending', 'assigned' => 'Assigned', 'ignored' => 'Ignored', 'blacklisted' => 'Blacklisted'] as $value => $label): ?>
                <a class="btn <?= $resolution === $value ? 'btn-primary' : 'btn-secondary' ?> btn-sm"
                   href="/admin/rfid/unknown?resolution=<?= e($value) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card__body--flush">
        <?php if ($cards === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon' => 'fa-circle-check', 'title' => 'Nothing to triage',
                'text' => 'No unregistered cards have been presented.',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Card UID</th><th>First seen</th><th>Last seen</th><th class="numeric">Times</th>
                        <th>Terminal</th><th>Room</th><th style="width:220px">Resolution</th></tr></thead>
                    <tbody>
                    <?php foreach ($cards as $card): ?>
                        <tr>
                            <td class="mono"><?= e($card['card_uid']) ?></td>
                            <td class="text-sm nowrap"><?= e(format_datetime($card['first_seen_at'])) ?></td>
                            <td class="text-sm nowrap"><?= e(time_ago($card['last_seen_at'])) ?></td>
                            <td class="numeric fw-600"><?= e($card['seen_count']) ?></td>
                            <td class="mono text-xs"><?= e($card['device_id'] ?? '—') ?></td>
                            <td class="text-sm"><?= e($card['room_number'] ?? '—') ?></td>
                            <td>
                                <?php if ($resolution === 'pending'): ?>
                                    <div class="btn-group">
                                        <button class="btn btn-primary btn-sm" data-assign="<?= e($card['unknown_id']) ?>"
                                                data-uid="<?= e($card['card_uid']) ?>">Assign</button>
                                        <button class="btn btn-secondary btn-sm" data-resolve="<?= e($card['unknown_id']) ?>"
                                                data-action="ignored">Ignore</button>
                                        <button class="btn btn-danger btn-sm" data-resolve="<?= e($card['unknown_id']) ?>"
                                                data-action="blacklisted">Blacklist</button>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-neutral"><?= e(ucfirst((string) $card['resolution'])) ?></span>
                                    <div class="text-xs text-muted mt-1">
                                        by <?= e($card['resolved_by_username'] ?? 'system') ?>
                                        <?= e(time_ago($card['resolved_at'])) ?>
                                    </div>
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

<div class="modal-backdrop" id="assign-unknown-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Assign card to a student</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form id="assign-unknown-form">
            <div class="modal__body">
                <input type="hidden" id="u-id">
                <div class="form-group">
                    <label>Card UID</label>
                    <div class="credential-box" style="background:var(--surface-alt);color:var(--text)">
                        <span id="u-uid"></span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="u-student" class="required">Student</label>
                    <input type="search" id="u-search" placeholder="Type a name or student number…" autocomplete="off">
                    <select id="u-student" name="student_id" required size="6" style="margin-top:.4rem">
                        <option value="">Search for a student above</option>
                    </select>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Assign card</button>
            </div>
        </form>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
(function () {
    const LS = window.LSIAMS;

    document.addEventListener('click', async (event) => {
        const assign = event.target.closest('[data-assign]');

        if (assign) {
            document.getElementById('u-id').value = assign.dataset.assign;
            document.getElementById('u-uid').textContent = assign.dataset.uid;
            LS.modal.open('assign-unknown-modal');
        }

        const resolve = event.target.closest('[data-resolve]');

        if (resolve) {
            const action = resolve.dataset.action;

            const result = await LS.modal.confirm({
                title: action === 'blacklisted' ? 'Blacklist this card?' : 'Ignore this card?',
                message: action === 'blacklisted'
                    ? 'The card will be permanently refused at every terminal.'
                    : 'The card stays unregistered but stops appearing in this list.',
                confirmLabel: action === 'blacklisted' ? 'Blacklist' : 'Ignore',
                danger: action === 'blacklisted',
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/rfid/unknown/' + resolve.dataset.resolve + '/resolve',
                    { resolution: action });
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    document.getElementById('u-search').addEventListener('input', LS.util.debounce(async function () {
        const select = document.getElementById('u-student');
        if (this.value.trim().length < 2) return;

        try {
            const response = await LS.http.get('/admin/reports/students/search', { q: this.value.trim() });
            select.innerHTML = '';
            (response.data.rows || []).forEach((student) => {
                const option = document.createElement('option');
                option.value = student.student_id;
                option.textContent = student.name + '  ·  ' + student.student_number + '  ·  ' + student.section_code;
                select.appendChild(option);
            });
        } catch (error) { /* keep the previous list */ }
    }, 300));

    document.getElementById('assign-unknown-form').addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = this.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Assigning…');

        try {
            const response = await LS.http.post(
                '/admin/rfid/unknown/' + document.getElementById('u-id').value + '/resolve',
                { resolution: 'assigned', student_id: document.getElementById('u-student').value }
            );

            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
