<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>
<?php $__view->include('partials.page-header', [
    'title'       => 'Active Sessions',
    'subtitle'    => 'Sessions expire after ' . (int) $idleTimeout . ' minutes of inactivity. The server enforces this — a tampered browser timer gains nothing.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Security Center', '/admin/security'], ['Sessions', null]],
    'actions'     => '<button class="btn btn-danger" id="terminate-all"><i class="fa-solid fa-power-off"></i> Terminate all</button>',
]); ?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title"><?= e(count($sessions)) ?> active session(s)</h2>
        <span class="text-sm text-muted">Idle counters update live</span>
    </div>
    <div class="card__body--flush">
        <?php if ($sessions === []): ?>
            <?php $__view->include('partials.empty-state', ['icon' => 'fa-users-gear', 'title' => 'No active sessions']); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>User</th><th>Role</th><th>IP address</th><th>Device / browser</th>
                        <th>Signed in</th><th>Last activity</th><th>Idle for</th><th>Expires in</th><th style="width:120px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php $isCurrent = (int) $session['session_id'] === (int) $currentSessionId; ?>
                        <tr <?= $isCurrent ? 'style="background:var(--primary-light)"' : '' ?>>
                            <td>
                                <div class="cell-person">
                                    <span class="avatar avatar--sm"><?= e(initials((string) $session['full_name'])) ?></span>
                                    <span class="cell-stack">
                                        <span class="cell-primary"><?= e($session['full_name']) ?>
                                            <?= $isCurrent ? '<span class="badge badge-primary">this session</span>' : '' ?></span>
                                        <span class="cell-muted"><?= e($session['username']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><span class="badge badge-neutral"><?= e($session['role_name']) ?></span></td>
                            <td class="mono text-xs"><?= e($session['ip_address']) ?></td>
                            <td class="text-sm"><?= e($session['device_label'] ?? '—') ?></td>
                            <td class="text-sm nowrap"><?= e(format_datetime($session['created_at'], 'M j, g:i A')) ?></td>
                            <td class="text-sm nowrap"><?= e(time_ago($session['last_activity_at'])) ?></td>
                            <td class="nowrap" data-idle="<?= e($session['idle_seconds']) ?>">
                                <span class="idle-counter"></span>
                            </td>
                            <td class="nowrap" data-expires="<?= e($session['expires_in_seconds']) ?>">
                                <span class="expires-counter"></span>
                            </td>
                            <td>
                                <?php if (!$isCurrent): ?>
                                    <button class="btn btn-ghost btn-sm text-danger"
                                            data-terminate="<?= e($session['session_id']) ?>"
                                            data-name="<?= e($session['full_name']) ?>">
                                        Terminate
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="card__footer text-sm text-muted">
        A teacher's web session ending never closes an open classroom attendance session — the two
        lifecycles are independent. Attendance continues on the terminal.
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    function format(seconds) {
        if (seconds < 0) return '—';
        const minutes = Math.floor(seconds / 60);
        return minutes + 'm ' + String(seconds % 60).padStart(2, '0') + 's';
    }

    function tick() {
        document.querySelectorAll('[data-idle]').forEach((cell) => {
            const seconds = parseInt(cell.dataset.idle, 10) + 1;
            cell.dataset.idle = seconds;
            const span = cell.querySelector('.idle-counter');
            span.textContent = format(seconds);
            // Amber past 7 minutes, red past 9 — the shape of the timeout.
            span.className = 'idle-counter ' + (seconds > 540 ? 'text-danger fw-600' : (seconds > 420 ? 'text-warning' : ''));
        });

        document.querySelectorAll('[data-expires]').forEach((cell) => {
            const seconds = parseInt(cell.dataset.expires, 10) - 1;
            cell.dataset.expires = seconds;
            const span = cell.querySelector('.expires-counter');
            span.textContent = seconds <= 0 ? 'expired' : format(seconds);
            span.className = 'expires-counter ' + (seconds < 60 ? 'text-danger fw-600' : '');
        });
    }

    tick();
    setInterval(tick, 1000);

    // Refresh the list itself periodically — passively, so watching this page
    // does not itself keep sessions alive.
    setInterval(async () => {
        try {
            await LS.http.poll('/admin/security/sessions');
        } catch (error) { /* ignore */ }
    }, 60000);

    document.addEventListener('click', async (event) => {
        const terminate = event.target.closest('[data-terminate]');

        if (terminate) {
            const result = await LS.modal.confirm({
                title: 'Terminate session?',
                message: terminate.dataset.name + ' will be signed out immediately on that device. '
                       + 'Any open classroom attendance session is unaffected.',
                confirmLabel: 'Terminate',
                danger: true,
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/security/sessions/' + terminate.dataset.terminate + '/terminate', {});
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    document.getElementById('terminate-all').addEventListener('click', async function () {
        const result = await LS.modal.confirm({
            title: 'Terminate every other session?',
            message: 'Every signed-in user except you will be signed out immediately. '
                   + 'Classroom attendance sessions continue unaffected.',
            confirmLabel: 'Terminate all',
            danger: true,
            requirePassword: true,
        });

        if (!result) return;

        try {
            const response = await LS.http.post('/admin/security/sessions/terminate-all',
                { confirm_password: result.password });
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            LS.toast.fromError(error);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
