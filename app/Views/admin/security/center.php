<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$risk = (string) $summary['risk_level'];
$tone = ['normal' => 'success', 'guarded' => 'info', 'elevated' => 'warning',
         'high' => 'danger', 'critical' => 'danger'][$risk] ?? 'neutral';
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Security Center',
    'subtitle'    => 'Every counter below covers the last 24 hours. Security events are permanent and cannot be deleted.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Security Center', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/security/sessions"><i class="fa-solid fa-users-gear"></i> Active sessions</a>'
        . '<a class="btn btn-secondary" href="/admin/security/logs"><i class="fa-solid fa-list-check"></i> Security log</a>'
        . '<a class="btn btn-primary" href="/admin/audit"><i class="fa-solid fa-file-lines"></i> Audit log</a>',
]); ?>

<div class="card" style="border-left:4px solid var(--<?= e($tone === 'neutral' ? 'border' : $tone) ?>)">
    <div class="card__body flex items-center justify-between flex-wrap gap-2">
        <div>
            <div class="text-xs text-muted">Current risk level</div>
            <div style="font-size:27px;font-weight:700;text-transform:capitalize" class="text-<?= e($tone) ?>">
                <?= e($risk) ?>
            </div>
            <div class="text-sm text-muted">
                Weighted score <?= e($summary['risk_score']) ?> ·
                <?= e($summary['open_events']) ?> unresolved event(s)
            </div>
        </div>
        <div class="flex gap-2">
            <?php foreach (['critical', 'high', 'medium', 'low'] as $severity): ?>
                <div class="text-center" style="min-width:74px">
                    <div class="text-xs text-muted"><?= e(ucfirst($severity)) ?></div>
                    <div style="font-size:21px;font-weight:700"
                         class="<?= $summary['by_severity'][$severity] > 0 && in_array($severity, ['critical', 'high'], true) ? 'text-danger' : '' ?>">
                        <?= e($summary['by_severity'][$severity]) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid grid--4 mb-3">
    <?php foreach ([
        ['Failed sign-ins', $summary['failed_logins'], 'fa-right-to-bracket', 'danger'],
        ['Account lockouts', $summary['account_lockouts'], 'fa-user-lock', 'danger'],
        ['Unknown cards', $summary['unknown_rfid'], 'fa-id-card', 'warning'],
        ['Fingerprint failures', $summary['fingerprint_failures'], 'fa-fingerprint', 'warning'],
        ['Invalid API keys', $summary['invalid_api_keys'], 'fa-key', 'danger'],
        ['Bad signatures', $summary['invalid_signatures'], 'fa-shield-halved', 'danger'],
        ['Replay attempts', $summary['replay_attempts'], 'fa-rotate', 'danger'],
        ['Unknown devices', $summary['unknown_devices'], 'fa-microchip', 'danger'],
        ['Permission violations', $summary['permission_violations'], 'fa-lock', 'warning'],
        ['CSRF failures', $summary['csrf_failures'], 'fa-ban', 'warning'],
        ['Rate limited', $summary['rate_limited'], 'fa-gauge-high', 'warning'],
        ['Cross-department blocked', $summary['cross_department_blocked'], 'fa-building-columns', 'info'],
        ['Grade level blocked', $summary['grade_level_blocked'], 'fa-layer-group', 'info'],
        ['Blocked IPs', $summary['blocked_ips'], 'fa-ban', 'neutral'],
        ['Blocked devices', $summary['blocked_devices'], 'fa-microchip', 'neutral'],
    ] as [$label, $value, $icon, $itemTone]): ?>
        <div class="stat">
            <span class="stat__icon <?= $itemTone !== 'neutral' ? 'stat__icon--' . e($itemTone) : '' ?>">
                <i class="fa-solid <?= e($icon) ?>"></i>
            </span>
            <div>
                <div class="stat__label"><?= e($label) ?></div>
                <div class="stat__value <?= $value > 0 && in_array($itemTone, ['danger'], true) ? 'text-danger' : '' ?>"><?= e($value) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Event trend · 24 hours</h2></div>
        <div class="card__body"><div id="chart-security" style="min-height:240px"></div></div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Recent events</h2>
            <a class="btn btn-ghost btn-sm" href="/admin/security/logs">View all</a>
        </div>
        <div class="card__body--flush" style="max-height:380px;overflow-y:auto">
            <?php if ($recent === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-shield-halved', 'title' => 'No security events']); ?>
            <?php else: ?>
                <?php foreach ($recent as $event): ?>
                    <div class="live-row">
                        <span class="live-row__arrow text-<?= e(['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'muted'][$event['severity']] ?? 'muted') ?>">
                            <i class="fa-solid fa-circle" style="font-size:8px"></i>
                        </span>
                        <span class="live-row__body">
                            <span class="live-row__name"><?= e($event['event']) ?></span>
                            <span class="live-row__meta"><?= e($event['description']) ?></span>
                        </span>
                        <span class="live-row__time">
                            <span class="badge badge-<?= e(['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'][$event['severity']] ?? 'neutral') ?>">
                                <?= e($event['severity']) ?>
                            </span>
                            <div class="text-xs text-muted mt-1"><?= e(time_ago($event['created_at'])) ?></div>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Blocked IP addresses</h2>
            <button class="btn btn-secondary btn-sm" data-modal-open="block-ip-modal">Block an address</button>
        </div>
        <div class="card__body--flush">
            <?php if ($blockedIps === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-ban', 'title' => 'No blocked addresses']); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>IP</th><th>Reason</th><th>Blocked</th><th>Expires</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($blockedIps as $block): ?>
                            <tr>
                                <td class="mono text-sm"><?= e($block['ip_address']) ?></td>
                                <td class="text-xs text-muted"><?= e($block['reason']) ?></td>
                                <td class="text-sm nowrap"><?= e(time_ago($block['created_at'])) ?></td>
                                <td class="text-sm"><?= $block['expires_at'] ? e(format_datetime($block['expires_at'])) : 'permanent' ?></td>
                                <td><span class="badge <?= $block['status'] === 'active' ? 'badge-danger' : 'badge-neutral' ?>"><?= e($block['status']) ?></span></td>
                                <td>
                                    <?php if ($block['status'] === 'active'): ?>
                                        <button class="btn btn-ghost btn-sm" data-unblock="<?= e($block['ip_address']) ?>">Lift</button>
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

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Active sessions</h2>
            <a class="btn btn-ghost btn-sm" href="/admin/security/sessions">Manage</a>
        </div>
        <div class="card__body--flush" style="max-height:340px;overflow-y:auto">
            <?php foreach (array_slice($sessions, 0, 10) as $session): ?>
                <div class="live-row">
                    <span class="avatar avatar--sm"><?= e(initials((string) $session['full_name'])) ?></span>
                    <span class="live-row__body">
                        <span class="live-row__name"><?= e($session['full_name']) ?></span>
                        <span class="live-row__meta">
                            <?= e($session['role_name']) ?> · <?= e($session['ip_address']) ?> · <?= e($session['browser_label']) ?>
                        </span>
                    </span>
                    <span class="live-row__time">
                        idle <?= e(intdiv((int) $session['idle_seconds'], 60)) ?>m
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if ($sessions === []): ?>
                <div class="text-muted text-sm" style="padding:1rem">No active sessions.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="block-ip-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Block an IP address</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form method="post" data-ajax action="/admin/security/block-ip" data-reload="true">
            <div class="modal__body">
                <div class="form-group">
                    <label for="b-ip" class="required">IP address</label>
                    <input type="text" id="b-ip" name="ip_address" required placeholder="192.168.1.55" style="font-family:var(--mono)">
                </div>
                <div class="form-group">
                    <label for="b-reason" class="required">Reason</label>
                    <textarea id="b-reason" name="reason" required minlength="5" maxlength="255"></textarea>
                </div>
                <div class="form-group">
                    <label for="b-minutes">Duration (minutes)</label>
                    <input type="number" id="b-minutes" name="minutes" min="1" placeholder="Leave blank for permanent">
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger">Block address</button>
            </div>
        </form>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;
    const trend = <?= json_js($trend) ?>;

    // Roll the per-severity rows into one series per bucket for the chart.
    const buckets = {};
    trend.forEach((row) => {
        buckets[row.bucket] = buckets[row.bucket] || { bucket: row.bucket, low: 0, medium: 0, high: 0, critical: 0 };
        buckets[row.bucket][row.severity] = Number(row.total);
    });

    LS.charts.line('chart-security', Object.values(buckets), {
        xKey: 'bucket',
        title: 'Security events by severity',
        formatX: (v) => String(v).slice(11, 16),
        series: [
            { key: 'critical', label: 'Critical', color: '#EF4444' },
            { key: 'high',     label: 'High',     color: '#F59E0B' },
            { key: 'medium',   label: 'Medium',   color: '#0EA5E9' },
            { key: 'low',      label: 'Low',      color: '#94A3B8' },
        ],
        emptyMessage: 'No security events in the last 24 hours.',
    });

    document.addEventListener('click', async (event) => {
        const unblock = event.target.closest('[data-unblock]');
        if (!unblock) return;

        try {
            const response = await LS.http.post('/admin/security/unblock-ip', { ip_address: unblock.dataset.unblock });
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            LS.toast.fromError(error);
        }
    });

    if (LS.realtime) {
        LS.realtime.on('security.alert', (data) => {
            LS.toast.error(data.description, data.event);
        });
    }
})();
</script>
<?php $__view->stop(); ?>
