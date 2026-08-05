<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>
<?php $__view->include('partials.page-header', [
    'title'       => 'Security Logs',
    'subtitle'    => 'Permanent and immutable. Events cannot be edited or deleted through any interface.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Security Center', '/admin/security'], ['Logs', null]],
]); ?>

<form class="filter-bar" onsubmit="return false">
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Event, description or source IP">
    </div>
    <div class="form-group">
        <label for="f-severity">Severity</label>
        <select id="f-severity" name="severity" onchange="applySecurityFilters()">
            <option value="">All</option>
            <?php foreach (['critical', 'high', 'medium', 'low'] as $severity): ?>
                <option value="<?= e($severity) ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>><?= e(ucfirst($severity)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-resolution">Status</label>
        <select id="f-resolution" name="resolution_status" onchange="applySecurityFilters()">
            <option value="">All</option>
            <?php foreach (['open', 'investigating', 'resolved', 'false_positive'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['resolution_status'] === $status ? 'selected' : '' ?>>
                    <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-from">From</label>
        <input type="date" id="f-from" name="date_from" value="<?= e($filters['date_from']) ?>" onchange="applySecurityFilters()">
    </div>
    <div class="form-group">
        <label for="f-to">To</label>
        <input type="date" id="f-to" name="date_to" value="<?= e($filters['date_to']) ?>" onchange="applySecurityFilters()">
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.search=''">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($logs === []): ?>
            <?php $__view->include('partials.empty-state', ['icon' => 'fa-shield-halved', 'title' => 'No security events match']); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>When</th><th>Severity</th><th>Event</th><th>Description</th>
                        <th>Source IP</th><th>Device</th><th>User</th><th>Status</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="nowrap text-sm"><?= e(format_datetime($log['created_at'])) ?></td>
                            <td>
                                <span class="badge badge-<?= e(['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'][$log['severity']] ?? 'neutral') ?>">
                                    <?= e(strtoupper((string) $log['severity'])) ?>
                                </span>
                            </td>
                            <td class="mono text-xs"><?= e($log['event']) ?></td>
                            <td class="text-sm"><?= e($log['description']) ?></td>
                            <td class="mono text-xs"><?= e($log['source_ip'] ?? '—') ?></td>
                            <td class="mono text-xs"><?= e($log['device_public_id'] ?? '—') ?></td>
                            <td class="text-sm"><?= e($log['username'] ?? '—') ?></td>
                            <td><span class="badge <?= $log['resolution_status'] === 'open' ? 'badge-warning' : 'badge-neutral' ?>">
                                <?= e(ucfirst(str_replace('_', ' ', (string) $log['resolution_status']))) ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" data-resolve="<?= e($log['security_id']) ?>"
                                        data-event="<?= e($log['event']) ?>" data-status="<?= e($log['resolution_status']) ?>"
                                        data-notes="<?= e($log['admin_notes'] ?? '') ?>" title="Update"><i class="fa-solid fa-pen"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php $__view->include('partials.pagination', ['pagination' => $pagination, 'target' => 'securityTable']); ?>
</div>

<div class="modal-backdrop" id="resolve-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Update security event</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form id="resolve-form">
            <div class="modal__body">
                <input type="hidden" id="r-id">
                <p class="text-sm text-muted">Event: <strong id="r-event"></strong></p>
                <div class="form-group">
                    <label for="r-status" class="required">Resolution</label>
                    <select id="r-status" name="resolution_status" required>
                        <option value="open">Open</option>
                        <option value="investigating">Investigating</option>
                        <option value="resolved">Resolved</option>
                        <option value="false_positive">False positive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="r-notes">Administrator notes</label>
                    <textarea id="r-notes" name="admin_notes" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
function applySecurityFilters() {
    const params = new URLSearchParams();
    ['f-search:search', 'f-severity:severity', 'f-resolution:resolution_status', 'f-from:date_from', 'f-to:date_to']
        .forEach((pair) => {
            const [id, name] = pair.split(':');
            const value = document.getElementById(id).value;
            if (value) params.set(name, value);
        });
    window.location.search = params.toString();
}

window.securityTable = {
    load: (o) => { const p = new URLSearchParams(window.location.search); Object.entries(o).forEach(([k, v]) => p.set(k, v)); window.location.search = p.toString(); },
    page: (n) => window.securityTable.load({ page: n }),
};

(function () {
    const LS = window.LSIAMS;
    document.getElementById('f-search').addEventListener('input', LS.util.debounce(applySecurityFilters, 500));

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-resolve]');
        if (!trigger) return;

        document.getElementById('r-id').value = trigger.dataset.resolve;
        document.getElementById('r-event').textContent = trigger.dataset.event;
        document.getElementById('r-status').value = trigger.dataset.status;
        document.getElementById('r-notes').value = trigger.dataset.notes;
        LS.modal.open('resolve-modal');
    });

    document.getElementById('resolve-form').addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = this.querySelector('[type=submit]');
        LS.util.setBusy(button, true);

        try {
            const response = await LS.http.post('/admin/security/logs/' + document.getElementById('r-id').value + '/resolve', {
                resolution_status: document.getElementById('r-status').value,
                admin_notes: document.getElementById('r-notes').value,
            });
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
