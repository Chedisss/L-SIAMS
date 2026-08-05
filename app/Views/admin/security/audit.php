<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>
<?php $__view->include('partials.page-header', [
    'title'       => 'Audit Logs',
    'subtitle'    => 'Every administrative action, permanently. The table carries database triggers that refuse UPDATE and DELETE.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Audit Logs', null]],
    'actions'     => '<div class="dropdown"><button class="btn btn-secondary" data-dropdown="audit-export"><i class="fa-solid fa-download"></i> Export</button>'
        . '<div class="dropdown__menu" id="audit-export">'
        . '<a class="dropdown__item" href="#" data-export="csv">CSV</a>'
        . '<a class="dropdown__item" href="#" data-export="xlsx">Excel</a>'
        . '<a class="dropdown__item" href="#" data-export="pdf">PDF</a></div></div>',
]); ?>

<form class="filter-bar" onsubmit="return false">
    <div class="form-group form-group--wide">
        <label for="f-search">Search</label>
        <input type="search" id="f-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Description, record or actor">
    </div>
    <div class="form-group">
        <label for="f-action">Action</label>
        <select id="f-action" name="action" onchange="applyAuditFilters()">
            <option value="">All actions</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= e($action) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-module">Module</label>
        <select id="f-module" name="module" onchange="applyAuditFilters()">
            <option value="">All modules</option>
            <?php foreach ($modules as $module): ?>
                <option value="<?= e($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>><?= e($module) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f-from">From</label>
        <input type="date" id="f-from" name="date_from" value="<?= e($filters['date_from']) ?>" onchange="applyAuditFilters()">
    </div>
    <div class="form-group">
        <label for="f-to">To</label>
        <input type="date" id="f-to" name="date_to" value="<?= e($filters['date_to']) ?>" onchange="applyAuditFilters()">
    </div>
    <div class="filter-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.search=''">Reset</button>
    </div>
</form>

<div class="card">
    <div class="card__body--flush">
        <?php if ($logs === []): ?>
            <?php $__view->include('partials.empty-state', ['icon' => 'fa-list-check', 'title' => 'No audit entries match']); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>When</th><th>Actor</th><th>Role</th><th>Action</th><th>Module</th>
                        <th>Record</th><th>IP</th><th>Result</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="nowrap text-sm"><?= e(format_datetime($log['created_at'])) ?></td>
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($log['actor_label'] ?? 'SYSTEM') ?></span>
                                <?php if ($log['username']): ?><span class="cell-muted"><?= e($log['username']) ?></span><?php endif; ?>
                            </td>
                            <td class="text-sm"><?= e($log['role_name'] ?? '—') ?></td>
                            <td><span class="badge badge-primary mono" style="font-size:10px"><?= e($log['action']) ?></span></td>
                            <td class="text-sm"><?= e($log['module']) ?></td>
                            <td class="text-xs text-muted"><?= e(trim(($log['record_type'] ?? '') . ' ' . ($log['record_id'] ?? ''))) ?: '—' ?></td>
                            <td class="mono text-xs"><?= e($log['ip_address'] ?? '—') ?></td>
                            <td><span class="badge <?= $log['result'] === 'success' ? 'badge-success' : 'badge-danger' ?>"><?= e($log['result']) ?></span></td>
                            <td>
                                <?php if ($log['old_value'] || $log['new_value'] || $log['description']): ?>
                                    <button class="btn btn-ghost btn-sm" title="Detail"
                                            data-detail='<?= json_attr([
                                                'description' => $log['description'],
                                                'old' => $log['old_value'],
                                                'new' => $log['new_value'],
                                                'agent' => $log['user_agent'],
                                                'device' => $log['device_label'],
                                            ]) ?>'><i class="fa-solid fa-eye"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php $__view->include('partials.pagination', ['pagination' => $pagination, 'target' => 'auditTable']); ?>
</div>

<div class="modal-backdrop" id="audit-detail-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Audit entry</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body" id="audit-detail"></div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
function applyAuditFilters() {
    const params = new URLSearchParams();
    ['f-search:search', 'f-action:action', 'f-module:module', 'f-from:date_from', 'f-to:date_to']
        .forEach((pair) => {
            const [id, name] = pair.split(':');
            const value = document.getElementById(id).value;
            if (value) params.set(name, value);
        });
    window.location.search = params.toString();
}

window.auditTable = {
    load: (o) => { const p = new URLSearchParams(window.location.search); Object.entries(o).forEach(([k, v]) => p.set(k, v)); window.location.search = p.toString(); },
    page: (n) => window.auditTable.load({ page: n }),
};

(function () {
    const LS = window.LSIAMS;
    document.getElementById('f-search').addEventListener('input', LS.util.debounce(applyAuditFilters, 500));

    document.querySelectorAll('[data-export]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const params = new URLSearchParams(window.location.search);
            params.set('format', link.dataset.export);
            window.location.href = '/admin/audit/export?' + params.toString();
        });
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-detail]');
        if (!trigger) return;

        const data = JSON.parse(trigger.dataset.detail);
        const body = document.getElementById('audit-detail');

        let html = '';
        if (data.description) html += '<p>' + LS.util.escape(data.description) + '</p>';
        if (data.device) html += '<p class="text-sm text-muted">' + LS.util.escape(data.device) + '</p>';

        if (data.old || data.new) {
            html += '<div class="grid grid--2">';
            html += '<div><h4>Before</h4><pre class="mono text-xs" style="background:var(--surface-alt);padding:.7rem;border-radius:var(--radius-sm);overflow:auto">'
                + LS.util.escape(JSON.stringify(JSON.parse(data.old || '{}'), null, 2)) + '</pre></div>';
            html += '<div><h4>After</h4><pre class="mono text-xs" style="background:var(--surface-alt);padding:.7rem;border-radius:var(--radius-sm);overflow:auto">'
                + LS.util.escape(JSON.stringify(JSON.parse(data.new || '{}'), null, 2)) + '</pre></div>';
            html += '</div>';
        }

        body.innerHTML = html || '<p class="text-muted">No additional detail recorded.</p>';
        LS.modal.open('audit-detail-modal');
    });
})();
</script>
<?php $__view->stop(); ?>
