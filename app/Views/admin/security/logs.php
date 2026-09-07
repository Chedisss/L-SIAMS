<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>
<?php $__view->include('partials.page-header', [
    'title'       => 'Security Logs',
    'subtitle'    => 'Permanent: an event cannot be deleted and its content cannot be changed. Only its resolution status is editable.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Security Center', '/admin/security'], ['Logs', null]],
]); ?>

<style nonce="<?= e(csp_nonce()) ?>">
    .bulk-bar {
        display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
        padding: .6rem .9rem; border-bottom: 1px solid var(--border, #d5dde4);
        background: var(--surface-alt, #eef1f4);
    }
    .bulk-bar__count { font-size: .85rem; color: var(--text-muted, #47576a); white-space: nowrap; }
    .bulk-bar select, .bulk-bar input[type="text"] { margin: 0; max-width: 320px; }
</style>

<form class="filter-bar" data-no-submit>
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Event, description or source IP">
    </div>
    <div class="form-group">
        <label for="f-severity">Severity</label>
        <select id="f-severity" name="severity" data-filter-input>
            <option value="">All</option>
            <?php foreach (['critical', 'high', 'medium', 'low'] as $severity): ?>
                <option value="<?= e($severity) ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>><?= e(ucfirst($severity)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-resolution">Status</label>
        <select id="f-resolution" name="resolution_status" data-filter-input>
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
        <input type="date" id="f-from" name="date_from" value="<?= e($filters['date_from']) ?>" data-filter-input>
    </div>
    <div class="form-group">
        <label for="f-to">To</label>
        <input type="date" id="f-to" name="date_to" value="<?= e($filters['date_to']) ?>" data-filter-input>
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-filters">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($logs === []): ?>
            <?php $__view->include('partials.empty-state', ['icon' => 'fa-shield-halved', 'title' => 'No security events match']); ?>
        <?php else: ?>
            <?php /* Bulk triage. A page of routine TIMESTAMP_EXPIRED entries all
                     say the same thing; ticking them and resolving in one go beats
                     one dialog per row. The bar stays hidden until something is
                     ticked, so it never gets in the way of reading the table. */ ?>
            <div class="bulk-bar" id="bulk-bar" hidden>
                <span class="bulk-bar__count"><strong id="bulk-count">0</strong> selected</span>
                <select id="bulk-status">
                    <option value="resolved">Mark resolved</option>
                    <option value="false_positive">Mark false positive</option>
                    <option value="investigating">Mark investigating</option>
                    <option value="open">Reopen</option>
                </select>
                <input type="text" id="bulk-notes" maxlength="1000"
                       placeholder="Note (optional)" style="flex:1;min-width:160px">
                <button type="button" class="btn btn-primary btn-sm" id="bulk-apply">Apply</button>
                <button type="button" class="btn btn-ghost btn-sm" id="bulk-clear">Clear</button>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr>
                        <th style="width:34px"><input type="checkbox" id="check-all" title="Select all on this page"></th>
                        <th>When</th><th>Severity</th><th>Event</th><th>Description</th>
                        <th>Source IP</th><th>Device</th><th>User</th><th>Status</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><input type="checkbox" class="row-check" value="<?= e($log['security_id']) ?>"></td>
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
<script nonce="<?= e(csp_nonce()) ?>">
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

    /* ---- bulk triage --------------------------------------------------- */
    const bar     = document.getElementById('bulk-bar');
    const checkAll = document.getElementById('check-all');

    // The page may be empty (no rows, so no bar); guard every handler on that.
    if (bar && checkAll) {
        const rowChecks = () => Array.from(document.querySelectorAll('.row-check'));
        const selected  = () => rowChecks().filter((c) => c.checked);

        function refresh() {
            const picked = selected().length;
            document.getElementById('bulk-count').textContent = String(picked);
            bar.hidden = picked === 0;

            const all = rowChecks();
            checkAll.checked = picked > 0 && picked === all.length;
            checkAll.indeterminate = picked > 0 && picked < all.length;
        }

        checkAll.addEventListener('change', () => {
            rowChecks().forEach((c) => { c.checked = checkAll.checked; });
            refresh();
        });

        // Delegated, so it covers every row without a listener each.
        document.addEventListener('change', (event) => {
            if (event.target.classList.contains('row-check')) refresh();
        });

        document.getElementById('bulk-clear').addEventListener('click', () => {
            rowChecks().forEach((c) => { c.checked = false; });
            checkAll.checked = false;
            refresh();
        });

        document.getElementById('bulk-apply').addEventListener('click', async function () {
            const ids = selected().map((c) => c.value);
            if (ids.length === 0) return;

            const status = document.getElementById('bulk-status').value;
            const label  = document.getElementById('bulk-status').selectedOptions[0].textContent.toLowerCase();

            const confirmed = await LS.modal.confirm({
                title:   ids.length + ' event(s): ' + label + '?',
                message: 'This updates the resolution status of the selected security events. '
                       + 'The events themselves are not changed and nothing is deleted.',
                confirmLabel: 'Apply to ' + ids.length,
            });
            if (!confirmed) return;

            LS.util.setBusy(this, true, 'Applying…');

            try {
                const response = await LS.http.post('/admin/security/logs/resolve-bulk', {
                    ids: ids,
                    resolution_status: status,
                    admin_notes: document.getElementById('bulk-notes').value,
                });
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
                LS.util.setBusy(this, false);
            }
        });
    }
})();

// The filter controls announce changes rather than calling this directly:
// an inline onchange= attribute cannot be authorised by a CSP nonce.
document.addEventListener('ls:filter-change', applySecurityFilters);
</script>
<?php $__view->stop(); ?>
