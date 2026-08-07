<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Fingerprints',
    'subtitle'    => 'Attendance can never begin until the assigned teacher verifies their fingerprint. Student attendance never uses the sensor.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Fingerprints', null]],
    'actions'     => '<button class="btn btn-primary" data-modal-open="enroll-modal"><i class="fa-solid fa-fingerprint"></i> Enrol Fingerprint</button>',
]); ?>

<div class="alert alert-info">
    <span class="alert__icon"><i class="fa-solid fa-shield-halved"></i></span>
    <div class="alert__body">
        No biometric template is ever sent to or stored on this server. The R307 keeps the template
        in its own flash and returns a slot number; L-SIAMS records only that number and the teacher
        it belongs to. A full database compromise therefore leaks no biometric data.
    </div>
</div>

<?php if ($pending !== []): ?>
    <div class="card">
        <div class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-triangle-exclamation text-warning"></i> Awaiting enrolment</h2>
            <span class="badge badge-warning"><?= e(count($pending)) ?></span>
        </div>
        <div class="card__body--flush">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Employee No.</th><th>Teacher</th><th>Department</th><th style="width:120px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($pending as $teacher): ?>
                        <tr>
                            <td class="mono text-sm"><?= e($teacher['employee_number']) ?></td>
                            <td class="cell-primary"><?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?></td>
                            <td class="text-sm"><?= e($teacher['department_name']) ?></td>
                            <td>
                                <button class="btn btn-primary btn-sm" data-enroll="<?= e($teacher['teacher_id']) ?>"
                                        data-name="<?= e($teacher['first_name'] . ' ' . $teacher['last_name']) ?>">
                                    Enrol
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__header"><h2 class="card__title">Enrolled fingerprints</h2></div>
    <div class="card__body--flush">
        <?php if ($enrolments === []): ?>
            <?php $__view->include('partials.empty-state', [
                'icon' => 'fa-fingerprint', 'title' => 'No fingerprints enrolled',
                'text' => 'No teacher can open an attendance session until at least one is enrolled.',
            ]); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Teacher</th><th>Department</th><th class="numeric">Sensor slot</th><th>Enrolled on</th>
                        <th>Enrolled</th><th class="numeric">Verifications</th><th>Last verified</th><th>Status</th><th style="width:130px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($enrolments as $enrolment): ?>
                        <tr>
                            <td>
                                <div class="cell-person">
                                    <span class="avatar avatar--sm"><?= e(initials($enrolment['first_name'] . ' ' . $enrolment['last_name'])) ?></span>
                                    <span class="cell-stack">
                                        <span class="cell-primary"><?= e($enrolment['last_name']) ?>, <?= e($enrolment['first_name']) ?></span>
                                        <span class="cell-muted"><?= e($enrolment['employee_number']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td class="text-sm"><?= e($enrolment['department_name']) ?></td>
                            <td class="numeric mono"><?= e($enrolment['sensor_template_id']) ?></td>
                            <td class="text-sm"><?= e($enrolment['enrolled_room'] ? 'Room ' . $enrolment['enrolled_room'] : ($enrolment['enrolled_device'] ?? '—')) ?></td>
                            <td class="text-sm nowrap"><?= e(format_date($enrolment['enrollment_date'])) ?></td>
                            <td class="numeric"><?= e($enrolment['verification_count']) ?></td>
                            <td class="text-sm nowrap"><?= e(time_ago($enrolment['last_verified_at'])) ?></td>
                            <td><span class="badge <?= e(status_badge($enrolment['status'])) ?>"><?= e(ucfirst((string) $enrolment['status'])) ?></span></td>
                            <td class="nowrap">
                                <button class="btn btn-ghost btn-sm" data-logs="<?= e($enrolment['teacher_id']) ?>" title="Verification log"><i class="fa-solid fa-clock-rotate-left"></i></button>
                                <button class="btn btn-ghost btn-sm" data-enroll="<?= e($enrolment['teacher_id']) ?>"
                                        data-name="<?= e($enrolment['first_name'] . ' ' . $enrolment['last_name']) ?>" title="Re-enrol"><i class="fa-solid fa-rotate"></i></button>
                                <button class="btn btn-ghost btn-sm text-danger" data-delete="<?= e($enrolment['teacher_id']) ?>"
                                        data-name="<?= e($enrolment['first_name'] . ' ' . $enrolment['last_name']) ?>" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-backdrop" id="enroll-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Fingerprint enrolment</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <form id="enroll-form">
            <div class="modal__body">
                <ol class="text-sm text-muted" style="padding-left:1.2rem;line-height:1.9">
                    <li>Choose the teacher and the terminal you are standing at.</li>
                    <li>On the terminal, put the sensor into enrolment mode.</li>
                    <li>Have the teacher place the same finger several times until the sensor confirms.</li>
                    <li>Enter the slot number the sensor reports below and save.</li>
                </ol>

                <div class="form-grid mt-2">
                    <div class="form-group form-group--full">
                        <label for="e-teacher" class="required">Teacher</label>
                        <select id="e-teacher" name="teacher_id" required>
                            <option value="">Select a teacher…</option>
                            <?php foreach (array_merge($pending, $enrolments) as $teacher): ?>
                                <option value="<?= e($teacher['teacher_id']) ?>">
                                    <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?>
                                    (<?= e($teacher['employee_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="e-device">Terminal</label>
                        <select id="e-device" name="device_row_id">
                            <option value="">Not specified</option>
                            <?php foreach ($devices as $device): ?>
                                <option value="<?= e($device['id']) ?>">
                                    <?= e($device['device_id']) ?><?= $device['room_number'] ? ' — Room ' . e($device['room_number']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="e-slot" class="required">Sensor slot</label>
                        <input type="number" id="e-slot" name="sensor_template_id" required min="1" max="999"
                               value="<?= e($nextSlot) ?>">
                        <span class="field-help">Next free slot suggested. Must be unique per terminal.</span>
                    </div>
                    <div class="form-group">
                        <label for="e-quality">Quality score</label>
                        <input type="number" id="e-quality" name="quality_score" min="0" max="255" placeholder="reported by the sensor">
                    </div>
                    <div class="form-group">
                        <label for="e-samples">Samples captured</label>
                        <input type="number" id="e-samples" name="sample_count" min="0" max="10" value="2">
                    </div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Record enrolment</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="logs-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Verification history</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body" id="logs-body"></div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS = window.LSIAMS;
    const form = document.getElementById('enroll-form');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        try {
            const response = await LS.http.post('/admin/fingerprints/enroll', LS.util.formData(form));
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    // Suggest the next free slot for the chosen terminal.
    document.getElementById('e-device').addEventListener('change', async function () {
        try {
            const response = await LS.http.get('/admin/fingerprints/next-slot',
                { device_row_id: this.value || '' });
            document.getElementById('e-slot').value = response.data.sensor_template_id;
        } catch (error) { /* keep the current suggestion */ }
    });

    document.addEventListener('click', async (event) => {
        const enroll = event.target.closest('[data-enroll]');

        if (enroll) {
            document.getElementById('e-teacher').value = enroll.dataset.enroll;
            LS.modal.open('enroll-modal');
        }

        const logs = event.target.closest('[data-logs]');

        if (logs) {
            LS.modal.open('logs-modal');
            const body = document.getElementById('logs-body');
            body.innerHTML = '<div class="skeleton skeleton--row"></div>';

            try {
                const response = await LS.http.get('/admin/fingerprints/' + logs.dataset.logs + '/logs');
                const rows = response.data.rows || [];

                body.innerHTML = rows.length === 0
                    ? '<p class="text-muted">No verification attempts recorded.</p>'
                    : '<table class="data"><thead><tr><th>When</th><th>Result</th><th>Terminal</th><th>Room</th><th>Detail</th></tr></thead><tbody>'
                      + rows.map((row) =>
                          '<tr><td class="nowrap text-sm">' + LS.util.formatDate(row.created_at) + ' ' + LS.util.formatTime(row.created_at) + '</td>'
                          + '<td><span class="badge ' + (row.result === 'verified' ? 'badge-success' : 'badge-danger') + '">'
                          + LS.util.escape(row.result) + '</span></td>'
                          + '<td class="mono text-xs">' + LS.util.escape(row.device_id || '—') + '</td>'
                          + '<td class="text-sm">' + LS.util.escape(row.room_number || '—') + '</td>'
                          + '<td class="text-xs text-muted">' + LS.util.escape(row.message || '') + '</td></tr>').join('')
                      + '</tbody></table>';
            } catch (error) {
                body.innerHTML = '<div class="alert alert-danger">Could not load the history.</div>';
            }
        }

        const remove = event.target.closest('[data-delete]');

        if (remove) {
            const result = await LS.modal.confirm({
                title: 'Delete fingerprint enrolment?',
                message: remove.dataset.name + ' will not be able to open any attendance session until '
                       + 'they are re-enrolled. Also delete the template from the sensor itself.',
                confirmLabel: 'Delete enrolment',
                danger: true,
                requirePassword: true,
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/fingerprints/' + remove.dataset.delete + '/delete',
                    { confirm_password: result.password });
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });
})();
</script>
<?php $__view->stop(); ?>
