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
        <h2 class="card__title">Automatic backup</h2>
        <?php if ($schedule['enabled']): ?>
            <span class="badge badge-success">On · daily at <?= e($schedule['time']) ?></span>
        <?php else: ?>
            <span class="badge badge-neutral">Off</span>
        <?php endif; ?>
    </div>
    <div class="card__body">
        <p class="text-sm text-muted" style="margin-top:0">
            One backup a day, at the time you set, keeping the most recent few and pruning the rest.
            It is the background worker that writes them — <strong>the server must be running at that
            hour</strong>, so pick a time the machine is switched on. A school that powers the server down
            overnight should set this to the middle of the school day, not 1&nbsp;AM.
        </p>

        <?php if ($schedule['enabled']): ?>
            <p class="text-sm" style="margin:.4rem 0 0">
                <?php if ($lastScheduled !== null): ?>
                    <i class="fa-solid fa-circle-check text-success"></i>
                    Last automatic backup: <strong><?= e(format_datetime($lastScheduled)) ?></strong>.
                <?php else: ?>
                    <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    <strong>No automatic backup has run yet.</strong> If this stays true past the next
                    scheduled time, the worker is not running when it should be — start
                    <code>start.bat</code>, or run <code>install-worker.bat</code> once so it starts at boot.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <form id="schedule-form" class="form-grid" style="margin-top:1rem; max-width:520px">
            <div class="form-group form-group--full">
                <label class="checkbox">
                    <input type="checkbox" id="s-enabled" name="enabled" <?= $schedule['enabled'] ? 'checked' : '' ?>>
                    <span><strong>Back up automatically every day</strong></span>
                </label>
            </div>
            <div class="form-group">
                <label for="s-time">Time of day</label>
                <input type="time" id="s-time" name="time" value="<?= e($schedule['time']) ?>" required>
            </div>
            <div class="form-group">
                <label for="s-retain">Backups to keep</label>
                <input type="number" id="s-retain" name="retain" min="1" max="365"
                       value="<?= e($schedule['retain']) ?>" required>
                <span class="text-xs text-muted">Older automatic backups beyond this many are pruned. Manual backups are never pruned.</span>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" id="save-schedule">
                    <i class="fa-solid fa-floppy-disk"></i> Save schedule
                </button>
            </div>
        </form>
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
                'text'   => 'Create one now, and set a daily automatic backup in the panel above.',
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
<script nonce="<?= e(csp_nonce()) ?>">
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

    const scheduleForm = document.getElementById('schedule-form');

    if (scheduleForm) {
        scheduleForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const save = document.getElementById('save-schedule');
            LS.util.setBusy(save, true, 'Saving…');

            try {
                const response = await LS.http.post('/admin/backup/schedule', {
                    enabled: document.getElementById('s-enabled').checked,
                    time:    document.getElementById('s-time').value,
                    retain:  document.getElementById('s-retain').value,
                });
                LS.toast.success(response.message, 'Schedule saved');
                // The badge and the "last backup" line are rendered server-side,
                // so reload to show the saved state rather than patching it here.
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                if (error.errors) LS.util.showFieldErrors(scheduleForm, error.errors);
                LS.toast.fromError(error);
                LS.util.setBusy(save, false);
            }
        });
    }

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
