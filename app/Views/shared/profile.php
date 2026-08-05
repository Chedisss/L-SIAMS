<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$isTeacher = ($profile['role_slug'] ?? '') === 'teacher';
$home      = $isTeacher ? '/teacher' : '/admin';
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'My Account',
    'subtitle'    => 'Your login details, your photo, and every device currently signed in as you.',
    'breadcrumbs' => [['Dashboard', $home], ['My Account', null]],
    'actions'     => '<a class="btn btn-secondary" href="/password/change"><i class="fa-solid fa-key"></i> Change password</a>',
]); ?>

<div class="grid" style="grid-template-columns:340px 1fr;align-items:start">
    <div>
        <div class="card">
            <div class="card__body text-center">
                <div style="position:relative;display:inline-block">
                    <?php if (!empty($profile['photo_path'])): ?>
                        <img class="avatar avatar--lg" id="photo-preview" src="/uploads/<?= e($profile['photo_path']) ?>" alt="">
                    <?php else: ?>
                        <span class="avatar avatar--lg" id="photo-preview"><?= e(initials((string) $profile['full_name'])) ?></span>
                    <?php endif; ?>
                </div>

                <h3 class="mt-2" style="margin-bottom:.15rem"><?= e($profile['full_name']) ?></h3>
                <div class="text-muted text-sm mono"><?= e($profile['username']) ?></div>
                <div class="mt-2">
                    <span class="badge badge-primary"><?= e($profile['role_name'] ?? $profile['role_slug']) ?></span>
                    <span class="badge <?= e(status_badge((string) $profile['status'])) ?>"><?= e(ucfirst((string) $profile['status'])) ?></span>
                </div>

                <form id="photo-form" class="mt-3">
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png" hidden>
                    <button type="button" class="btn btn-secondary btn-sm" id="choose-photo">
                        <i class="fa-solid fa-camera"></i> Change photo
                    </button>
                    <div class="field-help mt-1">
                        JPG or PNG. The image is re-encoded on the server and stored under a random
                        name, so an image file cannot smuggle in anything executable.
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Account</h2></div>
            <div class="card__body">
                <?php foreach ([
                    'Username'    => (string) $profile['username'],
                    'Role'        => (string) ($profile['role_name'] ?? $profile['role_slug']),
                    'Last sign-in'=> $profile['last_login_at'] ? format_datetime($profile['last_login_at']) : 'this is your first',
                    'Member since'=> format_date($profile['created_at'] ?? null),
                ] as $label => $value): ?>
                    <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted"><?= e($label) ?></span>
                        <span class="text-sm text-right"><?= e($value ?: '—') ?></span>
                    </div>
                <?php endforeach; ?>

                <p class="text-xs text-muted mt-2">
                    Your username cannot be changed — audit and attendance history reference it, and
                    rewriting it would break the trail.
                </p>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__header"><h2 class="card__title">Details</h2></div>
            <div class="card__body">
                <form id="profile-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="full_name" class="required">Full name</label>
                            <input type="text" id="full_name" name="full_name" required maxlength="150"
                                   value="<?= e($profile['full_name']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="email" class="required">Email</label>
                            <input type="email" id="email" name="email" required
                                   value="<?= e($profile['email']) ?>">
                            <span class="field-help">Used for password-reset requests only. Passwords are never emailed.</span>
                        </div>
                    </div>

                    <div class="flex gap-1 mt-2" style="justify-content:flex-end">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card__header">
                <h2 class="card__title">Active sessions</h2>
                <span class="text-xs text-muted"><?= e(count($sessions)) ?> signed in</span>
            </div>

            <div class="card__body--flush">
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Device</th><th>IP address</th><th>Signed in</th><th>Last activity</th><th>Idles out</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <?php $isCurrent = (int) $session['session_id'] === (int) $currentSessionId; ?>
                            <tr class="<?= $isCurrent ? 'is-current' : '' ?>">
                                <td class="cell-stack">
                                    <span class="cell-primary">
                                        <?= e($session['device_label'] ?? 'Unknown device') ?>
                                        <?php if ($isCurrent): ?>
                                            <span class="badge badge-success">This device</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="cell-muted"><?= e($session['browser_label'] ?? '—') ?></span>
                                </td>
                                <td class="mono text-xs"><?= e($session['ip_address']) ?></td>
                                <td class="nowrap text-sm"><?= e(format_datetime($session['created_at'])) ?></td>
                                <td class="nowrap text-sm"><?= e(time_ago($session['last_activity_at'])) ?></td>
                                <td class="nowrap text-sm"><?= e(format_time($session['idle_expires_at'])) ?></td>
                                <td class="nowrap">
                                    <?php if (!$isCurrent): ?>
                                        <button class="btn btn-ghost btn-sm text-danger"
                                                data-terminate="<?= e($session['session_id']) ?>">
                                            <i class="fa-solid fa-right-from-bracket"></i> Sign out
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">current</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card__body">
                <p class="text-xs text-muted" style="margin:0">
                    Sessions idle out after <?= e((int) config('security.session.idle_timeout_minutes', 10)) ?>
                    minutes of real activity — background refreshes, chart updates and the notification
                    badge deliberately do not count, so an open tab cannot keep a session alive forever.
                    If you see something here you do not recognise, sign it out and change your password.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card__header">
                <h2 class="card__title">Recent sign-ins</h2>
                <span class="text-xs text-muted">Last 20 attempts, successful or not</span>
            </div>
            <div class="card__body--flush">
                <?php if ($loginHistory === []): ?>
                    <?php $__view->include('partials.empty-state', ['icon' => 'fa-right-to-bracket', 'title' => 'No sign-in history yet']); ?>
                <?php else: ?>
                    <div class="table-wrap" style="max-height:420px;overflow-y:auto">
                        <table class="data">
                            <thead><tr><th>When</th><th>Result</th><th>IP address</th><th>Browser</th><th>Platform</th><th>Detail</th></tr></thead>
                            <tbody>
                            <?php foreach ($loginHistory as $entry): ?>
                                <tr>
                                    <td class="nowrap text-xs"><?= e(format_datetime($entry['created_at'])) ?></td>
                                    <td>
                                        <span class="badge <?= e(match ((string) $entry['status']) {
                                            'success'          => 'badge-success',
                                            'logout'           => 'badge-neutral',
                                            'failed', 'locked', 'blocked_ip', 'rate_limited' => 'badge-danger',
                                            default            => 'badge-warning',
                                        }) ?>"><?= e(str_replace('_', ' ', (string) $entry['status'])) ?></span>
                                    </td>
                                    <td class="mono text-xs"><?= e($entry['ip_address']) ?></td>
                                    <td class="text-sm"><?= e($entry['browser'] ?? '—') ?></td>
                                    <td class="text-sm"><?= e($entry['platform'] ?? '—') ?></td>
                                    <td class="text-xs text-muted"><?= e($entry['detail'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script>
(function () {
    const LS = window.LSIAMS;

    /* ---- details ---------------------------------------------------------- */
    const form = document.getElementById('profile-form');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        try {
            const response = await LS.http.put('/profile', LS.util.formData(form));
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- photo ------------------------------------------------------------- */
    const photo = document.getElementById('photo');

    document.getElementById('choose-photo').addEventListener('click', () => photo.click());

    photo.addEventListener('change', async function () {
        if (!photo.files[0]) return;

        const body = new FormData();
        body.append('photo', photo.files[0]);
        body.append('_csrf', LS.config.csrfToken);

        try {
            const response = await LS.http.upload('/profile/photo', body);
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            LS.toast.fromError(error);
        }
    });

    /* ---- sessions ---------------------------------------------------------- */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-terminate]');
        if (!button) return;

        const confirmed = await LS.modal.confirm({
            title:        'Sign out this device?',
            message:      'That device will be signed out immediately and will have to authenticate '
                          + 'again. If you did not recognise it, change your password afterwards.',
            confirmLabel: 'Sign out',
            danger:       true,
        });

        if (!confirmed) return;

        LS.util.setBusy(button, true, 'Signing out…');

        try {
            const response = await LS.http.post('/profile/sessions/' + button.dataset.terminate + '/terminate', {});
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            LS.toast.fromError(error);
            LS.util.setBusy(button, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
