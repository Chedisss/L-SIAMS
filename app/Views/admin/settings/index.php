<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$groupLabels = [
    'school' => 'School', 'attendance' => 'Attendance', 'device' => 'Devices',
    'security' => 'Security', 'fingerprint' => 'Fingerprint', 'academic' => 'Academic rules',
    'backup' => 'Backup', 'appearance' => 'Appearance',
];
?>
<?php $__view->include('partials.page-header', [
    'title'       => 'System Settings',
    'subtitle'    => 'Changes take effect immediately. Sensitive settings require you to re-enter your password.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Settings', null]],
]); ?>

<div class="alert alert-info">
    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
    <div class="alert__body">
        Attendance windows set here are the defaults for <em>new</em> schedules. Each schedule keeps
        its own copy, so changing a default never rewrites an existing class.
    </div>
</div>

<form id="settings-form">
    <?php foreach ($groups as $group => $settings): ?>
        <div class="card">
            <div class="card__header">
                <h2 class="card__title">
                    <?= e($groupLabels[$group] ?? ucfirst($group)) ?>
                    <?php if (array_filter($settings, static fn (array $s): bool => (int) $s['is_sensitive'] === 1)): ?>
                        <span class="badge badge-warning"><i class="fa-solid fa-lock"></i> sensitive</span>
                    <?php endif; ?>
                </h2>
            </div>
            <div class="card__body">
                <div class="form-grid">
                    <?php foreach ($settings as $setting): ?>
                        <div class="form-group <?= $setting['value_type'] === 'bool' ? 'form-group--full' : '' ?>">
                            <?php if ($setting['value_type'] === 'bool'): ?>
                                <label class="checkbox">
                                    <input type="checkbox" name="settings[<?= e($setting['setting_key']) ?>]"
                                           value="1" <?= $setting['setting_value'] === '1' ? 'checked' : '' ?>
                                           data-sensitive="<?= e($setting['is_sensitive']) ?>">
                                    <span>
                                        <strong><?= e($setting['label']) ?></strong>
                                        <?php if ($setting['description']): ?>
                                            <div class="text-xs text-muted"><?= e($setting['description']) ?></div>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php else: ?>
                                <label for="set-<?= e($setting['setting_key']) ?>"><?= e($setting['label']) ?></label>
                                <input type="<?= $setting['value_type'] === 'int' || $setting['value_type'] === 'float' ? 'number' : 'text' ?>"
                                       id="set-<?= e($setting['setting_key']) ?>"
                                       name="settings[<?= e($setting['setting_key']) ?>]"
                                       value="<?= e($setting['setting_value']) ?>"
                                       data-sensitive="<?= e($setting['is_sensitive']) ?>"
                                       <?= $setting['min_value'] !== null ? 'min="' . e($setting['min_value']) . '"' : '' ?>
                                       <?= $setting['max_value'] !== null ? 'max="' . e($setting['max_value']) . '"' : '' ?>
                                       <?= $setting['value_type'] === 'float' ? 'step="0.1"' : '' ?>>
                                <?php if ($setting['description']): ?>
                                    <span class="field-help"><?= e($setting['description']) ?></span>
                                <?php endif; ?>
                                <?php if ($setting['min_value'] !== null): ?>
                                    <span class="field-help">Permitted range: <?= e($setting['min_value']) ?>–<?= e($setting['max_value']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="flex gap-1 mb-3" style="justify-content:flex-end">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save settings</button>
    </div>
</form>

<div class="card">
    <div class="card__header"><h2 class="card__title">School year</h2></div>
    <div class="card__body">
        <p class="text-sm text-muted">
            The active school year scopes section codes, enrolments and schedules.
            Changing it is a significant operation and requires re-authentication.
        </p>
        <div class="flex gap-1 items-center flex-wrap">
            <select id="school-year" style="max-width:260px">
                <?php foreach ($schoolYears as $year): ?>
                    <option value="<?= e($year['school_year_id']) ?>" <?= (int) $year['is_current'] === 1 ? 'selected' : '' ?>>
                        <?= e($year['year_label']) ?>
                        (<?= e(format_date($year['start_date'])) ?> – <?= e(format_date($year['end_date'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-secondary" id="set-year">Set as active</button>
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
    const form = document.getElementById('settings-form');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const settings = {};
        let touchesSensitive = false;

        form.querySelectorAll('[name^="settings["]').forEach((field) => {
            const key = field.name.slice(9, -1);
            settings[key] = field.type === 'checkbox' ? (field.checked ? '1' : '0') : field.value;
            if (field.dataset.sensitive === '1') touchesSensitive = true;
        });

        let password = null;

        if (touchesSensitive) {
            const result = await LS.modal.confirm({
                title: 'Confirm sensitive settings',
                message: 'These settings govern session lifetime, lockouts and attendance enforcement. '
                       + 'Re-enter your password to save them.',
                confirmLabel: 'Save settings',
                requirePassword: true,
            });

            if (!result) return;
            password = result.password;
        }

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        try {
            const response = await LS.http.post('/admin/settings',
                { settings: settings, confirm_password: password });
            LS.toast.success(response.message);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    document.getElementById('set-year').addEventListener('click', async function () {
        const result = await LS.modal.confirm({
            title: 'Change the active school year?',
            message: 'New sections, enrolments and schedules will belong to the selected year.',
            confirmLabel: 'Change year',
            requirePassword: true,
        });

        if (!result) return;

        try {
            const response = await LS.http.post('/admin/settings/school-year', {
                school_year_id: document.getElementById('school-year').value,
                confirm_password: result.password,
            });
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            LS.toast.fromError(error);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
