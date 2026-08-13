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

<?php if ($teacherCount === 0): ?>
    <div class="card">
        <div class="card__body">
            <?php $__view->include('partials.empty-state', [
                'icon'  => 'fa-user-tie',
                'title' => 'No teachers registered yet',
                'text'  => 'A fingerprint belongs to a teacher record, so there is nothing to enrol until at '
                         . 'least one exists. A user account with the teacher role is not the same thing — the '
                         . 'teacher record is what schedules, sections and fingerprints all attach to.',
                'action' => '<a class="btn btn-primary" href="/admin/teachers">'
                          . '<i class="fa-solid fa-user-plus"></i> Register a teacher</a>',
            ]); ?>
        </div>
    </div>
<?php endif; ?>

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

        <!-- Step 1: who, and at which terminal ------------------------------->
        <form id="enroll-form">
            <div class="modal__body" id="enroll-step-setup">
                <p class="text-sm text-muted">
                    Everything is driven from here. Choose the teacher and the scanner, press Start, and
                    the sensor asks for their finger — the slot number comes back from it, so there is
                    nothing to type in and nothing to do at the device itself.
                </p>

                <div class="form-grid mt-2">
                    <div class="form-group form-group--full">
                        <label for="e-teacher" class="required">Teacher</label>
                        <?php
                        // Keyed by teacher so a record that somehow appears in
                        // both lists is offered once, and sorted the way both
                        // tables above are, so the order is never a surprise.
                        $selectable = [];

                        foreach (array_merge($pending, $enrolments) as $candidate) {
                            $selectable[(int) $candidate['teacher_id']] = $candidate;
                        }

                        uasort($selectable, static fn (array $a, array $b): int
                            => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);
                        ?>
                        <select id="e-teacher" name="teacher_id" required <?= $selectable === [] ? 'disabled' : '' ?>>
                            <option value="">Select a teacher…</option>
                            <?php foreach ($selectable as $teacher): ?>
                                <option value="<?= e($teacher['teacher_id']) ?>">
                                    <?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?> (<?= e($teacher['employee_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selectable === []): ?>
                            <span class="field-help text-danger">
                                There are no active teacher records to enrol.
                                <a href="/admin/teachers">Register a teacher</a> first.
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group form-group--full">
                        <label for="e-device" class="required">Scanner</label>
                        <select id="e-device" name="device_row_id" required <?= $devices === [] ? 'disabled' : '' ?>>
                            <option value="">Select the scanner…</option>
                            <?php foreach ($devices as $device): ?>
                                <option value="<?= e($device['id']) ?>" data-health="<?= e($device['health'] ?? '') ?>">
                                    <?= $device['enrollment_station'] ? '🖐 ' : '' ?><?= e($device['device_id']) ?><?php
                                        ?><?= $device['enrollment_station']
                                            ? ' — enrolment scanner'
                                            : ($device['room_number'] ? ' — Room ' . e($device['room_number']) : '') ?><?php
                                        ?> (<?= e(device_health_label($device['health'] ?? '')) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($devices === []): ?>
                            <span class="field-help text-danger">
                                No scanner has completed first-boot activation yet, so none can be asked to
                                read a finger. <a href="/admin/devices">Register one</a> — an enrolment
                                scanner on your desk is the usual answer.
                            </span>
                        <?php else: ?>
                            <span class="field-help" id="e-device-help">
                                An enrolment scanner sits on your desk; a classroom terminal works too, but
                                the teacher has to walk to it. Either way it must be powered on.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <details class="mt-2">
                    <summary class="text-sm text-muted" style="cursor:pointer">
                        Record a slot by hand instead
                    </summary>
                    <p class="text-xs text-muted mt-1">
                        For a sensor enrolled outside L-SIAMS — a bench setup, or a replacement terminal
                        carrying templates already in its flash. You are asserting that slot on that sensor
                        belongs to this teacher; nothing verifies it, so a wrong number binds the wrong finger.
                    </p>
                    <div class="form-grid mt-1">
                        <div class="form-group">
                            <label for="e-slot">Sensor slot</label>
                            <input type="number" id="e-slot" min="1" max="999" value="<?= e($nextSlot) ?>">
                            <span class="field-help">Next free slot suggested.</span>
                        </div>
                        <div class="form-group">
                            <label for="e-quality">Quality score</label>
                            <input type="number" id="e-quality" min="0" max="255" placeholder="reported by the sensor">
                        </div>
                        <div class="form-group form-group--full">
                            <button type="button" class="btn btn-secondary btn-sm" id="manual-enroll">
                                <i class="fa-solid fa-keyboard"></i> Record this slot without scanning
                            </button>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Step 2: the terminal is doing the work ---------------------- -->
            <div class="modal__body hidden" id="enroll-step-scanning">
                <div class="enrol-scan">
                    <div class="enrol-scan__icon" id="scan-icon"><i class="fa-solid fa-fingerprint"></i></div>
                    <div class="enrol-scan__stage" id="scan-stage">Waiting for the terminal…</div>
                    <div class="enrol-scan__detail text-sm text-muted" id="scan-detail"></div>

                    <ol class="enrol-scan__steps" id="scan-steps">
                        <li data-stage="waiting_for_device">Terminal picks up the request</li>
                        <li data-stage="place_finger">Teacher places their finger</li>
                        <li data-stage="remove_finger">Teacher lifts their finger</li>
                        <li data-stage="place_again">Teacher places the same finger again</li>
                        <li data-stage="storing">Sensor stores the template</li>
                    </ol>

                    <div class="text-xs text-muted mt-2" id="scan-target"></div>
                </div>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close id="enroll-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="enroll-start">
                    <i class="fa-solid fa-fingerprint"></i> Start scan
                </button>
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
    const LS   = window.LSIAMS;
    const form = document.getElementById('enroll-form');

    const setup    = document.getElementById('enroll-step-setup');
    const scanning = document.getElementById('enroll-step-scanning');
    const startBtn = document.getElementById('enroll-start');
    const cancelBtn = document.getElementById('enroll-cancel');

    // The order the firmware walks. Anything before the current stage is done,
    // which is what lets the checklist fill in without the server having to
    // send the whole history on every poll.
    const STAGES = ['waiting_for_device', 'ready', 'place_finger', 'remove_finger', 'place_again', 'storing', 'done'];

    let requestId = null;
    let pollTimer = null;

    function showSetup() {
        setup.classList.remove('hidden');
        scanning.classList.add('hidden');
        startBtn.classList.remove('hidden');
        cancelBtn.textContent = 'Cancel';
        LS.util.setBusy(startBtn, false);
    }

    function showScanning() {
        setup.classList.add('hidden');
        scanning.classList.remove('hidden');
        startBtn.classList.add('hidden');
        cancelBtn.textContent = 'Stop';
    }

    function paint(data) {
        document.getElementById('scan-stage').textContent =
            data.status === 'completed' ? 'Enrolled'
            : data.status === 'failed'    ? 'Enrolment failed'
            : data.status === 'expired'   ? 'Timed out'
            : data.status === 'cancelled' ? 'Cancelled'
            : (data.stage === 'waiting_for_device' ? 'Waiting for the terminal…' : 'Scanning');

        document.getElementById('scan-detail').textContent = data.message || '';

        document.getElementById('scan-target').textContent =
            data.teacher_name + ' · ' + data.device_id
            + (data.room_number ? ' · Room ' + data.room_number : '')
            + ' · sensor slot ' + data.sensor_template_id;

        const reached = STAGES.indexOf(data.stage);

        document.querySelectorAll('#scan-steps li').forEach((item) => {
            const at = STAGES.indexOf(item.dataset.stage);

            item.classList.toggle('is-done', data.status === 'completed' || (reached > -1 && at < reached));
            item.classList.toggle('is-current', data.status === 'scanning' && at === reached);
        });

        const icon = document.getElementById('scan-icon');
        icon.className = 'enrol-scan__icon'
            + (data.status === 'completed' ? ' is-success'
             : (data.finished ? ' is-error' : ' is-waiting'));
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    async function poll() {
        if (!requestId) return;

        try {
            // Passive: watching a sensor is not the administrator typing, and
            // a wizard left open must not hold the session alive by itself.
            const response = await LS.http.get('/admin/fingerprints/scan/' + requestId, null, { passive: true });
            const data = response.data;

            paint(data);

            if (!data.finished) return;

            stopPolling();
            requestId = null;

            if (data.status === 'completed') {
                LS.toast.success(data.teacher_name + ' enrolled in sensor slot ' + data.sensor_template_id + '.');
                setTimeout(() => window.location.reload(), 1200);
            } else {
                LS.toast.warning(data.message || 'The enrolment did not complete.');
                setTimeout(showSetup, 2500);
            }
        } catch (error) {
            stopPolling();
            LS.toast.fromError(error);
            showSetup();
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const teacherId = document.getElementById('e-teacher').value;
        const deviceId  = document.getElementById('e-device').value;

        if (!teacherId || !deviceId) {
            LS.toast.warning('Choose both the teacher and the terminal they are standing at.');
            return;
        }

        LS.util.setBusy(startBtn, true, 'Asking the terminal…');

        try {
            const response = await LS.http.post('/admin/fingerprints/scan', {
                teacher_id: teacherId,
                device_row_id: deviceId,
            });

            requestId = response.data.request_id;
            showScanning();
            paint(response.data);
            LS.toast.info(response.message);

            stopPolling();
            pollTimer = setInterval(poll, 1500);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
            LS.util.setBusy(startBtn, false);
        }
    });

    // Closing the wizard has to release the terminal, or it sits waiting for a
    // finger nobody is going to present and blocks the next enrolment.
    async function abandon() {
        stopPolling();

        if (!requestId) return;

        const id = requestId;
        requestId = null;

        try {
            await LS.http.post('/admin/fingerprints/scan/' + id + '/cancel', {});
        } catch (error) { /* the request expires on its own soon enough */ }
    }

    cancelBtn.addEventListener('click', () => { abandon().then(showSetup); });
    document.querySelectorAll('#enroll-modal [data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => { abandon().then(showSetup); });
    });

    /* ---- manual fallback ---------------------------------------------------- */
    document.getElementById('manual-enroll').addEventListener('click', async function () {
        const teacherId = document.getElementById('e-teacher').value;
        const slot      = document.getElementById('e-slot').value;

        if (!teacherId) {
            LS.toast.warning('Choose a teacher first.');
            return;
        }

        const confirmed = await LS.modal.confirm({
            title:   'Record slot ' + slot + ' without scanning?',
            message: 'Nothing verifies that this slot on that sensor holds this teacher\'s finger. '
                   + 'If the number is wrong, the wrong person opens their sessions.\n\n'
                   + 'Use this only for a sensor enrolled outside L-SIAMS.',
            confirmLabel: 'Record it',
            danger: true,
        });

        if (!confirmed) return;

        LS.util.setBusy(this, true, 'Saving…');

        try {
            const quality = document.getElementById('e-quality').value;
            const response = await LS.http.post('/admin/fingerprints/enroll', {
                teacher_id: teacherId,
                sensor_template_id: slot,
                device_row_id: document.getElementById('e-device').value || undefined,
                quality_score: quality || undefined,
            });
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
            LS.util.setBusy(this, false);
        }
    });

    /* ---- row actions -------------------------------------------------------- */
    document.addEventListener('click', async (event) => {
        const enroll = event.target.closest('[data-enroll]');

        if (enroll) {
            showSetup();
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
                          + '<td><span class="badge ' + (row.result === 'verified' || row.result === 'enrolled' ? 'badge-success' : 'badge-danger') + '">'
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

    window.addEventListener('beforeunload', () => {
        // No await on the way out: sendBeacon is the only request that
        // reliably survives an unload, and the request expires by itself if
        // this does not make it.
        if (requestId && navigator.sendBeacon) {
            const body = new FormData();
            body.append('_csrf', LS.config.csrfToken);
            navigator.sendBeacon('/admin/fingerprints/scan/' + requestId + '/cancel', body);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
