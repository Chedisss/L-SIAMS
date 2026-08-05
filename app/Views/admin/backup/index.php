<?php
/** @var App\Core\View $__view */

use App\Services\BackupService;

$__view->extend('layouts.app');
$__view->start('content');
?>
<?php $__view->include('partials.page-header', [
    'title'       => 'Backup & Restore',
    'subtitle'    => 'Archives are gzipped then encrypted with AES-256-GCM — a backup is a complete copy of every attendance record and password hash in the school.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Backup & Restore', null]],
    'actions'     => '<button class="btn btn-primary" id="create-backup"><i class="fa-solid fa-database"></i> Create backup now</button>',
]); ?>

<div class="alert alert-warning">
    <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
    <div class="alert__body">
        <div class="alert__title">Restoring replaces the entire database</div>
        A restore verifies the archive first, takes an automatic safety backup, and requires both your
        password and a typed confirmation. It cannot be undone by anything except that safety backup.
    </div>
</div>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Backup history</h2>
        <span class="text-sm text-muted"><?= e(count($backups)) ?> archive(s)</span>
    </div>
    <div class="card__body--flush">
        <?php if ($backups === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-database',
                'title'  => 'No backups yet',
                'text'   => 'Create one now, and schedule the maintenance worker for daily automatic backups.',
                'action' => '<button class="btn btn-primary" id="create-backup-empty"><i class="fa-solid fa-database"></i> Create backup</button>',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Name</th><th>Created</th><th>By</th><th class="numeric">Tables</th>
                        <th class="numeric">Size</th><th>Trigger</th><th>Status</th><th style="width:210px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td class="cell-stack">
                                <span class="cell-primary mono text-sm"><?= e($backup['backup_name']) ?></span>
                                <?php if ($backup['checksum']): ?>
                                    <span class="cell-muted mono">SHA-256 <?= e(substr((string) $backup['checksum'], 0, 16)) ?>…</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm nowrap"><?= e(format_datetime($backup['created_at'])) ?></td>
                            <td class="text-sm"><?= e($backup['created_by_username'] ?? 'system') ?></td>
                            <td class="numeric"><?= e($backup['table_count']) ?></td>
                            <td class="numeric text-sm"><?= e(BackupService::humanSize((int) $backup['file_size'])) ?></td>
                            <td><span class="badge badge-neutral"><?= e($backup['trigger_type']) ?></span></td>
                            <td>
                                <span class="badge <?= e(status_badge($backup['status'])) ?>"><?= e(ucfirst((string) $backup['status'])) ?></span>
                                <?php if ($backup['error_message']): ?>
                                    <div class="text-xs text-danger mt-1"><?= e($backup['error_message']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?php if (in_array($backup['status'], ['completed', 'verified', 'restored'], true)): ?>
                                    <button class="btn btn-ghost btn-sm" data-verify="<?= e($backup['backup_id']) ?>">Verify</button>
                                    <a class="btn btn-ghost btn-sm" href="/admin/backup/<?= e($backup['backup_id']) ?>/download">Download</a>
                                    <button class="btn btn-ghost btn-sm text-danger" data-restore="<?= e($backup['backup_id']) ?>"
                                            data-name="<?= e($backup['backup_name']) ?>">Restore</button>
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

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
(function () {
    const LS = window.LSIAMS;

    async function createBackup(button) {
        LS.util.setBusy(button, true, 'Backing up…');

        try {
            const response = await LS.http.post('/admin/backup', {});
            LS.toast.success(response.message, 'Backup complete');
            setTimeout(() => window.location.reload(), 1200);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    }

    ['create-backup', 'create-backup-empty'].forEach((id) => {
        const button = document.getElementById(id);
        if (button) button.addEventListener('click', () => createBackup(button));
    });

    document.addEventListener('click', async (event) => {
        const verify = event.target.closest('[data-verify]');

        if (verify) {
            LS.util.setBusy(verify, true, 'Verifying…');

            try {
                const response = await LS.http.post('/admin/backup/' + verify.dataset.verify + '/verify', {});
                LS.toast.success(response.message, 'Verified');
            } catch (error) {
                const checks = error.payload && error.payload.data && error.payload.data.checks;
                const failed = checks ? Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name) : [];
                LS.toast.error((error.message || 'Verification failed')
                    + (failed.length ? ' Failed checks: ' + failed.join(', ') : ''), 'Verification failed');
            } finally {
                LS.util.setBusy(verify, false);
            }
        }

        const restore = event.target.closest('[data-restore]');

        if (restore) {
            const result = await LS.modal.confirm({
                title: 'Restore this backup?',
                message: 'This replaces the ENTIRE database with the contents of ' + restore.dataset.name
                       + '. Everything recorded since that backup will be lost. A safety backup is taken first.',
                confirmLabel: 'Restore database',
                danger: true,
                requirePassword: true,
                requirePhrase: 'RESTORE',
            });

            if (!result) return;

            LS.toast.info('Restoring — do not close this page.');

            try {
                const response = await LS.http.post('/admin/backup/' + restore.dataset.restore + '/restore', {
                    confirm_password: result.password,
                    confirm_phrase: result.phrase,
                });

                LS.toast.success(response.message, 'Restore complete');
                setTimeout(() => { window.location.href = '/login?reason=logout'; }, 3000);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });
})();
</script>
<?php $__view->stop(); ?>
