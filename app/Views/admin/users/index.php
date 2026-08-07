<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'User Accounts',
    'subtitle'    => 'Administrator and teacher logins. Teacher accounts are created from the Teachers page so their academic assignments are collected at the same time.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['User Accounts', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="user-modal"><i class="fa-solid fa-user-plus"></i> Add Administrator</button>',
]); ?>

<div class="card">
    <div class="card__header">
        <form class="filter-bar" method="get" action="/admin/users">
            <div class="filter-bar__field">
                <input type="search" name="search" placeholder="Username, name or email"
                       value="<?= e($filters['search']) ?>">
            </div>

            <div class="filter-bar__field">
                <select name="role">
                    <option value="">All roles</option>
                    <?php foreach (['administrator' => 'Administrator', 'teacher' => 'Teacher'] as $slug => $label): ?>
                        <option value="<?= e($slug) ?>" <?= $filters['role'] === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-bar__field">
                <select name="status">
                    <option value="">Any status</option>
                    <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked', 'archived' => 'Archived'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="checkbox">
                <input type="checkbox" name="include_archived" value="1" <?= $filters['include_archived'] ? 'checked' : '' ?>>
                <span>Include archived</span>
            </label>

            <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="btn btn-ghost btn-sm" href="/admin/users">Reset</a>
        </form>
    </div>

    <div class="card__body--flush">
        <?php if ($users === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-users-gear',
                'title' => 'No accounts match these filters',
                'text'  => 'Adjust the filters, or add an administrator account.',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>User</th><th>Role</th><th>Email</th><th>Status</th>
                        <th class="numeric">Sessions</th><th>Last sign-in</th><th style="width:190px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr class="<?= $user['status'] === 'archived' ? 'is-muted' : '' ?>">
                            <td class="cell-stack">
                                <span class="cell-primary"><?= e($user['full_name']) ?></span>
                                <span class="cell-muted mono"><?= e($user['username']) ?><?php
                                    if (!empty($user['employee_number'])): ?> · <?= e($user['employee_number']) ?><?php endif; ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $user['role_slug'] === 'administrator' ? 'badge-primary' : 'badge-neutral' ?>">
                                    <?= e($user['role_name']) ?>
                                </span>
                                <?php if (!empty($user['department_name'])): ?>
                                    <div class="text-xs text-muted mt-1"><?= e($user['department_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm"><?= e($user['email']) ?></td>
                            <td>
                                <span class="badge <?= e(status_badge($user['status'])) ?>"><?= e(ucfirst((string) $user['status'])) ?></span>
                                <?php if ((int) $user['must_change_password'] === 1): ?>
                                    <span class="badge badge-warning" title="Must set a new password at next sign-in">Password reset pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric"><?= e($user['active_sessions']) ?></td>
                            <td class="nowrap text-sm"><?= e($user['last_login_at'] ? format_datetime($user['last_login_at']) : 'never') ?></td>
                            <td class="nowrap">
                                <button class="btn btn-ghost btn-sm" title="Edit"
                                        data-edit='<?= json_attr([
                                            'user_id'   => (int) $user['user_id'],
                                            'full_name' => $user['full_name'],
                                            'email'     => $user['email'],
                                            'status'    => $user['status'],
                                            'username'  => $user['username'],
                                        ]) ?>'><i class="fa-solid fa-pen"></i></button>

                                <button class="btn btn-ghost btn-sm" title="Reset password"
                                        data-reset="<?= e($user['user_id']) ?>" data-name="<?= e($user['full_name']) ?>">
                                    <i class="fa-solid fa-key"></i>
                                </button>

                                <?php if ($user['status'] !== 'archived'): ?>
                                    <button class="btn btn-ghost btn-sm text-danger" title="Archive account"
                                            data-archive="<?= e($user['user_id']) ?>" data-name="<?= e($user['full_name']) ?>">
                                        <i class="fa-solid fa-box-archive"></i>
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

    <?php $__view->include('partials.pagination', ['pagination' => $pagination]); ?>
</div>

<!-- Create administrator ---------------------------------------------------->
<div class="modal-backdrop" id="user-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Add Administrator</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form id="user-form">
            <div class="modal__body">
                <div class="alert alert-info">
                    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                    <div class="alert__body">
                        Administrators have unrestricted access to every module, including security logs
                        and backups. Create teacher accounts from the Teachers page instead.
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="u-first" class="required">First name</label>
                        <input type="text" id="u-first" name="first_name" required maxlength="60">
                    </div>

                    <div class="form-group">
                        <label for="u-last" class="required">Last name</label>
                        <input type="text" id="u-last" name="last_name" required maxlength="60">
                    </div>
                </div>

                <div class="form-group">
                    <label for="u-username">Username</label>
                    <input type="text" id="u-username" name="username" maxlength="32" style="font-family:var(--mono)">
                    <span class="field-help" id="u-username-help">Leave blank to generate one from the name.</span>
                </div>

                <div class="form-group">
                    <label for="u-email" class="required">Email</label>
                    <input type="email" id="u-email" name="email" required>
                    <span class="field-help">Used for password-reset requests. The password itself is never emailed.</span>
                </div>

                <div class="form-group">
                    <label for="u-password">Initial password</label>
                    <div class="input-group">
                        <input type="password" id="u-password" name="password" autocomplete="new-password"
                               minlength="12" maxlength="200">
                        <button class="btn btn-secondary btn-sm" type="button" id="u-generate">Generate</button>
                    </div>
                    <div class="strength" id="u-strength" hidden>
                        <div class="strength__bar"><span></span></div>
                        <div class="strength__label text-xs text-muted"></div>
                    </div>
                    <span class="field-help">Leave blank and a strong password is generated for you.</span>
                </div>

                <div class="form-group">
                    <label for="u-password2">Confirm password</label>
                    <input type="password" id="u-password2" name="password_confirmation" autocomplete="new-password">
                </div>

                <label class="checkbox">
                    <input type="checkbox" name="force_password_change" value="1" checked>
                    <span>Require a new password at first sign-in</span>
                </label>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Create account</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit account ------------------------------------------------------------>
<div class="modal-backdrop" id="user-edit-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Edit account</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form id="user-edit-form">
            <div class="modal__body">
                <input type="hidden" name="user_id" id="ue-id">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="ue-username" readonly style="font-family:var(--mono)">
                    <span class="field-help">Usernames are permanent — audit trails reference them.</span>
                </div>

                <div class="form-group">
                    <label for="ue-name" class="required">Full name</label>
                    <input type="text" id="ue-name" name="full_name" required maxlength="150">
                </div>

                <div class="form-group">
                    <label for="ue-email" class="required">Email</label>
                    <input type="email" id="ue-email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="ue-status">Status</label>
                    <select id="ue-status" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="locked">Locked</option>
                    </select>
                    <span class="field-help">Setting an account to anything other than active signs out its sessions.</span>
                </div>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Credential slip --------------------------------------------------------->
<div class="modal-backdrop" id="credential-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Credentials — shown once</h3>
        </div>
        <div class="modal__body">
            <div class="alert alert-warning">
                <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div class="alert__body">
                    This password is displayed only now. It is stored as a bcrypt hash and cannot be
                    recovered — if it is lost, issue a new one.
                </div>
            </div>
            <pre class="credential-slip" id="credential-slip"></pre>
        </div>
        <div class="modal__footer">
            <button class="btn btn-secondary" type="button" id="credential-copy"><i class="fa-solid fa-copy"></i> Copy</button>
            <button class="btn btn-secondary" type="button" id="credential-print"><i class="fa-solid fa-print"></i> Print</button>
            <button class="btn btn-primary" type="button" id="credential-done">I have saved it</button>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;

    const createForm = document.getElementById('user-form');
    const editForm   = document.getElementById('user-edit-form');

    /* ---- username suggestion + availability ---------------------------- */
    const username = document.getElementById('u-username');
    const help     = document.getElementById('u-username-help');

    async function suggest() {
        if (username.value.trim() !== '') return;

        const first = document.getElementById('u-first').value.trim();
        const last  = document.getElementById('u-last').value.trim();
        if (first === '' || last === '') return;

        try {
            const response = await LS.http.get('/admin/users/suggest-username', { first_name: first, last_name: last });
            username.placeholder = response.data.username;
        } catch (error) { /* suggestion is a convenience only */ }
    }

    document.getElementById('u-first').addEventListener('blur', suggest);
    document.getElementById('u-last').addEventListener('blur', suggest);

    username.addEventListener('input', LS.util.debounce(async function () {
        const value = username.value.trim();

        if (value.length < 4) {
            help.textContent = 'Leave blank to generate one from the name.';
            help.className = 'field-help';
            return;
        }

        try {
            const response = await LS.http.get('/admin/users/check-username', { username: value });
            help.textContent = response.data.available ? 'Available.' : 'Already taken.';
            help.className = 'field-help ' + (response.data.available ? 'text-success' : 'text-danger');
        } catch (error) { /* ignore */ }
    }, 350));

    /* ---- password generator + strength meter --------------------------- */
    document.getElementById('u-generate').addEventListener('click', async function () {
        try {
            const response = await LS.http.get('/admin/users/generate-password');
            document.getElementById('u-password').value  = response.data.password;
            document.getElementById('u-password2').value = response.data.password;
            document.getElementById('u-password').type   = 'text';
            score(response.data.password);
        } catch (error) {
            LS.toast.fromError(error);
        }
    });

    const strength = document.getElementById('u-strength');

    const score = LS.util.debounce(async function (value) {
        if (!value) { strength.hidden = true; return; }

        try {
            const response = await LS.http.post('/admin/users/check-password', {
                password:   value,
                username:   username.value,
                email:      document.getElementById('u-email').value,
                first_name: document.getElementById('u-first').value,
                last_name:  document.getElementById('u-last').value,
            });

            const data  = response.data;
            const bar   = strength.querySelector('.strength__bar span');
            const label = strength.querySelector('.strength__label');
            const names = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
            const index = Math.max(0, Math.min(4, data.score));

            strength.hidden = false;
            bar.style.width = ((index + 1) * 20) + '%';
            bar.dataset.level = String(index);
            label.textContent = data.issues.length > 0 ? data.issues[0] : names[index];
            label.className = 'strength__label text-xs ' + (data.issues.length > 0 ? 'text-danger' : 'text-success');
        } catch (error) { /* advisory only */ }
    }, 300);

    document.getElementById('u-password').addEventListener('input', (event) => score(event.target.value));

    /* ---- create --------------------------------------------------------- */
    createForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = createForm.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Creating…');

        try {
            const response = await LS.http.post('/admin/users', LS.util.formData(createForm));
            LS.modal.close('user-modal');
            showCredentials(response.data.credential_slip);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(createForm, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- edit ----------------------------------------------------------- */
    editForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const data = LS.util.formData(editForm);
        const id   = data.user_id;
        delete data.user_id;

        const button = editForm.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        try {
            const response = await LS.http.put('/admin/users/' + id, data);
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(editForm, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- row actions ---------------------------------------------------- */
    document.addEventListener('click', async function (event) {
        const edit = event.target.closest('[data-edit]');

        if (edit) {
            const data = JSON.parse(edit.dataset.edit);
            document.getElementById('ue-id').value       = data.user_id;
            document.getElementById('ue-username').value = data.username;
            document.getElementById('ue-name').value     = data.full_name;
            document.getElementById('ue-email').value    = data.email;
            document.getElementById('ue-status').value   = data.status === 'archived' ? 'inactive' : data.status;
            LS.modal.open('user-edit-modal');
            return;
        }

        const reset = event.target.closest('[data-reset]');

        if (reset) {
            // Resetting a password terminates every session that account holds,
            // so the confirmation says so before it happens.
            const confirmed = await LS.modal.confirm({
                title:        'Reset password?',
                message:      'A new password will be generated for ' + reset.dataset.name
                              + ' and all of their active sessions will be signed out immediately. '
                              + 'The new password is shown once and cannot be recovered afterwards.',
                confirmLabel: 'Reset password',
                danger:        true,
                requirePassword: true,
            });

            if (!confirmed) return;

            try {
                const response = await LS.http.post('/admin/users/' + reset.dataset.reset + '/reset-password', {
                    confirm_password: confirmed.password,
                });
                showCredentials(response.data.credential_slip);
            } catch (error) {
                LS.toast.fromError(error);
            }
            return;
        }

        const archive = event.target.closest('[data-archive]');

        if (archive) {
            const confirmed = await LS.modal.confirm({
                title:        'Archive account?',
                message:      archive.dataset.name + ' will no longer be able to sign in and every '
                              + 'active session will be terminated. Their history is kept — accounts '
                              + 'are never deleted, because audit and attendance records reference them.',
                confirmLabel: 'Archive',
                danger:        true,
                requirePassword: true,
            });

            if (!confirmed) return;

            try {
                const response = await LS.http.post('/admin/users/' + archive.dataset.archive + '/archive', {
                    confirm_password: confirmed.password,
                });
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    /* ---- credential slip ------------------------------------------------ */
    function showCredentials(slip) {
        document.getElementById('credential-slip').textContent = slip;
        LS.modal.open('credential-modal');
    }

    document.getElementById('credential-copy').addEventListener('click', function () {
        LS.util.copy(document.getElementById('credential-slip').textContent, 'Credentials copied to the clipboard.');
    });

    document.getElementById('credential-print').addEventListener('click', function () {
        const frame = document.createElement('iframe');
        frame.style.position = 'fixed';
        frame.style.right = '-10000px';
        document.body.appendChild(frame);

        const doc = frame.contentWindow.document;
        doc.open();
        doc.write('<pre style="font:13px/1.5 monospace">'
            + LS.util.escape(document.getElementById('credential-slip').textContent) + '</pre>');
        doc.close();

        frame.contentWindow.focus();
        frame.contentWindow.print();
        setTimeout(() => frame.remove(), 1000);
    });

    document.getElementById('credential-done').addEventListener('click', function () {
        LS.modal.close('credential-modal');
        window.location.reload();
    });
})();
</script>
<?php $__view->stop(); ?>
