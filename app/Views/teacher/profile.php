<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$fingerprintEnrolled = ($teacher['fingerprint_status'] ?? 'not_enrolled') === 'enrolled';
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'My Profile',
    'subtitle'    => 'Your contact details are yours to change. Everything else — employee number, department, assigned subjects and sections, fingerprint — is set by the administration.',
    'breadcrumbs' => [['Dashboard', '/teacher'], ['My Profile', null]],
    'actions'     => '<a class="btn btn-secondary" href="/password/change"><i class="fa-solid fa-key"></i> Change password</a>',
]); ?>

<?php if (!$fingerprintEnrolled): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-fingerprint"></i></span>
        <div class="alert__body">
            <strong>Your fingerprint is not enrolled.</strong>
            Attendance sessions can only be opened by verifying a fingerprint on the classroom
            terminal — a password will not do it. Ask the administration to enrol you before your
            next class.
        </div>
    </div>
<?php endif; ?>

<div class="grid grid--4 mb-3">
    <div class="stat">
        <span class="stat__icon stat__icon--info"><i class="fa-solid fa-door-open"></i></span>
        <div>
            <div class="stat__label">Sessions (90 days)</div>
            <div class="stat__value"><?= e($statistics['sessions']) ?></div>
            <div class="stat__meta">opened by you</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon"><i class="fa-solid fa-clipboard-user"></i></span>
        <div>
            <div class="stat__label">Records (90 days)</div>
            <div class="stat__value"><?= e(number_format($statistics['records'])) ?></div>
            <div class="stat__meta">across your classes</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--<?= $statistics['percentage'] >= 90 ? 'success' : ($statistics['percentage'] >= 80 ? 'warning' : 'danger') ?>">
            <i class="fa-solid fa-percent"></i>
        </span>
        <div>
            <div class="stat__label">Attendance rate</div>
            <div class="stat__value"><?= e($statistics['percentage']) ?>%</div>
            <div class="stat__meta">present, late, left early or incomplete</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon"><i class="fa-solid fa-hourglass-half"></i></span>
        <div>
            <div class="stat__label">Average stay</div>
            <div class="stat__value">
                <?= $statistics['average_dwell_minutes'] === null ? '—' : e($statistics['average_dwell_minutes']) . 'm' ?>
            </div>
            <div class="stat__meta">time students spend in your room</div>
        </div>
    </div>
</div>

<div class="grid" style="grid-template-columns:1fr 340px;align-items:start">
    <div>
        <div class="card">
            <div class="card__header"><h2 class="card__title">Contact details</h2></div>
            <div class="card__body">
                <form id="profile-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="p-email" class="required">Email</label>
                            <input type="email" id="p-email" name="email" required
                                   value="<?= e($teacher['email'] ?? '') ?>">
                            <span class="field-help">Also updates the email on your login account.</span>
                        </div>

                        <div class="form-group">
                            <label for="p-phone">Phone</label>
                            <input type="tel" id="p-phone" name="phone" maxlength="20"
                                   value="<?= e($teacher['phone'] ?? '') ?>">
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
                <h2 class="card__title">My classes</h2>
                <a class="btn btn-ghost btn-sm" href="/teacher/schedule">Full timetable</a>
            </div>
            <div class="card__body--flush">
                <?php if ($schedules === []): ?>
                    <?php $__view->include('partials.empty-state', [
                        'icon'  => 'fa-calendar-xmark',
                        'title' => 'No classes assigned',
                    ]); ?>
                <?php else: ?>
                    <div class="table-wrap" style="max-height:340px;overflow-y:auto">
                        <table class="data">
                            <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Room</th></tr></thead>
                            <tbody>
                            <?php foreach ($schedules as $schedule): ?>
                                <tr>
                                    <td class="text-sm"><?= e($schedule['day_of_week']) ?></td>
                                    <td class="nowrap text-sm"><?= e(format_time($schedule['start_time'])) ?> – <?= e(format_time($schedule['end_time'])) ?></td>
                                    <td class="text-sm"><?= e($schedule['subject_code']) ?></td>
                                    <td><span class="badge badge-primary"><?= e($schedule['section_code']) ?></span></td>
                                    <td class="text-sm"><?= e($schedule['room_number']) ?></td>
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
                <h2 class="card__title">Fingerprint activity</h2>
                <span class="text-xs text-muted">Verification attempts on classroom terminals</span>
            </div>
            <div class="card__body--flush">
                <?php if ($fingerprintLogs === []): ?>
                    <?php $__view->include('partials.empty-state', [
                        'icon'  => 'fa-fingerprint',
                        'title' => 'No fingerprint activity recorded',
                    ]); ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead><tr><th>When</th><th>Result</th><th>Room</th><th>Terminal</th><th>Confidence</th><th>Detail</th></tr></thead>
                            <tbody>
                            <?php foreach ($fingerprintLogs as $log): ?>
                                <tr>
                                    <td class="nowrap text-xs"><?= e(format_datetime($log['created_at'])) ?></td>
                                    <td>
                                        <span class="badge <?= e($log['result'] === 'verified' ? 'badge-success' : ($log['result'] === 'enrolled' ? 'badge-info' : 'badge-danger')) ?>">
                                            <?= e(str_replace('_', ' ', (string) $log['result'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-sm"><?= e($log['room_number'] ?? '—') ?></td>
                                    <td class="mono text-xs"><?= e($log['device_id'] ?? '—') ?></td>
                                    <td class="numeric text-sm"><?= e($log['confidence'] ?? '—') ?></td>
                                    <td class="text-xs text-muted"><?= e($log['message'] ?? '—') ?></td>
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
                <h2 class="card__title">Recent sign-ins</h2>
                <span class="text-xs text-muted">Check this for anything you do not recognise</span>
            </div>
            <div class="card__body--flush">
                <?php if ($loginHistory === []): ?>
                    <?php $__view->include('partials.empty-state', ['icon' => 'fa-right-to-bracket', 'title' => 'No sign-in history']); ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead><tr><th>When</th><th>Result</th><th>IP address</th><th>Browser</th><th>Platform</th></tr></thead>
                            <tbody>
                            <?php foreach ($loginHistory as $entry): ?>
                                <tr>
                                    <td class="nowrap text-xs"><?= e(format_datetime($entry['created_at'])) ?></td>
                                    <td>
                                        <span class="badge <?= e($entry['status'] === 'success' ? 'badge-success' : ($entry['status'] === 'logout' ? 'badge-neutral' : 'badge-warning')) ?>">
                                            <?= e(str_replace('_', ' ', (string) $entry['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="mono text-xs"><?= e($entry['ip_address']) ?></td>
                                    <td class="text-sm"><?= e($entry['browser'] ?? '—') ?></td>
                                    <td class="text-sm"><?= e($entry['platform'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__body text-center">
                <?php if (!empty($teacher['photo_path'])): ?>
                    <img class="avatar avatar--lg" src="/uploads/<?= e($teacher['photo_path']) ?>" alt="" style="margin:0 auto .75rem">
                <?php else: ?>
                    <span class="avatar avatar--lg" style="margin:0 auto .75rem">
                        <?= e(initials($teacher['first_name'] . ' ' . $teacher['last_name'])) ?>
                    </span>
                <?php endif; ?>

                <h3 style="margin-bottom:.15rem"><?= e($teacher['last_name']) ?>, <?= e($teacher['first_name']) ?></h3>
                <div class="text-muted text-sm mono"><?= e($teacher['employee_number']) ?></div>
                <div class="mt-2">
                    <span class="badge badge-primary"><?= e($teacher['department_name']) ?></span>
                    <span class="badge <?= e(status_badge($teacher['status'])) ?>"><?= e(ucfirst((string) $teacher['status'])) ?></span>
                </div>

                <div class="mt-3">
                    <a class="btn btn-secondary btn-sm w-full" href="/profile">Update photo &amp; account</a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">What I may teach</h2></div>
            <div class="card__body">
                <p class="text-xs text-muted mb-2">
                    You can only be assigned subjects from your own department, and only sections in
                    the grade levels you are qualified for. The server enforces both rules on every
                    assignment — the dropdowns an administrator sees are only a convenience.
                </p>

                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                    <span class="text-xs text-muted">Department</span>
                    <span class="text-sm"><?= e($constraints['department']['department_code']) ?></span>
                </div>

                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                    <span class="text-xs text-muted">Assignable subjects</span>
                    <span class="text-sm"><?= e($constraints['assignable_subject_count']) ?></span>
                </div>

                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                    <span class="text-xs text-muted">Assignable sections</span>
                    <span class="text-sm"><?= e($constraints['assignable_section_count']) ?></span>
                </div>

                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                    <span class="text-xs text-muted">Active schedules</span>
                    <span class="text-sm"><?= e($constraints['active_schedule_count']) ?></span>
                </div>

                <div class="mt-2">
                    <div class="text-xs text-muted mb-1">Qualified grade levels</div>
                    <?php if ($constraints['grade_levels'] === []): ?>
                        <span class="badge badge-warning">None assigned — you cannot be given a section</span>
                    <?php else: ?>
                        <?php foreach ($constraints['grade_levels'] as $grade): ?>
                            <span class="badge badge-neutral"><?= e($grade['grade_level_code']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Fingerprint</h2></div>
            <div class="card__body">
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                    <span class="text-xs text-muted">Status</span>
                    <span class="badge <?= e($fingerprintEnrolled ? 'badge-success' : 'badge-warning') ?>">
                        <?= e(str_replace('_', ' ', (string) $teacher['fingerprint_status'])) ?>
                    </span>
                </div>

                <?php if ($fingerprintEnrolled): ?>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Sensor slot</span>
                        <span class="text-sm mono"><?= e($teacher['sensor_template_id'] ?? '—') ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Enrolled</span>
                        <span class="text-sm"><?= e(format_date($teacher['enrollment_date'])) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Verifications</span>
                        <span class="text-sm"><?= e(number_format((int) ($teacher['verification_count'] ?? 0))) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0">
                        <span class="text-xs text-muted">Last verified</span>
                        <span class="text-sm"><?= e($teacher['last_verified_at'] ? time_ago($teacher['last_verified_at']) : 'never') ?></span>
                    </div>
                <?php endif; ?>

                <p class="text-xs text-muted mt-2">
                    Only a template identifier is stored here — never an image of your fingerprint, and
                    never anything from which one could be reconstructed. The template itself lives on
                    the sensor in the classroom.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const LS   = window.LSIAMS;
    const form = document.getElementById('profile-form');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        try {
            const response = await LS.http.put('/teacher/profile', LS.util.formData(form));
            LS.toast.success(response.message);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(form, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
