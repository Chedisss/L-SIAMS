<?php
/** @var App\Core\View $__view */

use App\Services\AttendanceStatusResolver;

$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => trim($student['first_name'] . ' ' . $student['last_name']),
    'subtitle'    => $student['student_number'] . ' · ' . $student['section_code'] . ' · ' . $student['grade_level_name'],
    'breadcrumbs' => [['Dashboard', '/admin'], ['Students', '/admin/students'], [$student['student_number'], null]],
    'actions'     => '<button class="btn btn-secondary" data-modal-open="transfer-modal"><i class="fa-solid fa-arrow-right"></i> Transfer</button>'
        . '<a class="btn btn-primary" href="/admin/students/' . (int) $student['student_id'] . '/edit"><i class="fa-solid fa-pen"></i> Edit</a>',
]); ?>

<div class="grid grid--3">
    <div class="card">
        <div class="card__body text-center">
            <?php if (!empty($student['photo_path'])): ?>
                <img class="avatar avatar--lg" src="/uploads/<?= e($student['photo_path']) ?>" alt="" style="margin:0 auto .75rem">
            <?php else: ?>
                <span class="avatar avatar--lg" style="margin:0 auto .75rem"><?= e(initials($student['first_name'] . ' ' . $student['last_name'])) ?></span>
            <?php endif; ?>

            <h3 style="margin-bottom:.15rem"><?= e($student['last_name']) ?>, <?= e($student['first_name']) ?></h3>
            <div class="text-muted text-sm mono"><?= e($student['student_number']) ?></div>
            <div class="mt-2">
                <span class="badge <?= e(status_badge($student['status'])) ?>"><?= e(ucfirst($student['status'])) ?></span>
                <span class="badge badge-primary"><?= e($student['section_code']) ?></span>
            </div>

            <div class="mt-3 text-left">
                <?php foreach ([
                    'Grade level' => $student['grade_level_name'],
                    'Strand'      => $student['strand'] ?? '—',
                    'Adviser'     => $student['adviser_name'] ?? 'unassigned',
                    'Gender'      => $student['gender'],
                    'Birthdate'   => format_date($student['birthdate']),
                    'Guardian'    => $student['guardian_name'],
                    'Contact'     => $student['guardian_contact'],
                    'Enrolled'    => format_date($student['enrolled_at']),
                ] as $label => $value): ?>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted"><?= e($label) ?></span>
                        <span class="text-sm"><?= e($value ?: '—') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">RFID card</h2></div>
        <div class="card__body">
            <?php if (empty($student['card_uid'])): ?>
                <div class="alert alert-warning" style="margin:0">
                    <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div class="alert__body">
                        No active card. This student cannot be marked present until one is issued.
                        <div class="mt-1"><a class="btn btn-primary btn-sm" href="/admin/rfid">Issue a card</a></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="credential-box" style="background:var(--surface-alt);color:var(--text)">
                    <div class="credential-box__label" style="color:var(--text-muted)">Card UID</div>
                    <?= e($student['card_uid']) ?>
                </div>
                <div class="text-sm text-muted">
                    Issued <?= e(format_date($student['rfid_issue_date'])) ?> ·
                    <span class="badge <?= e(status_badge($student['rfid_status'])) ?>"><?= e(ucfirst((string) $student['rfid_status'])) ?></span>
                </div>
                <p class="text-xs text-muted mt-2">
                    Replacing a card keeps every attendance record: attendance references the student,
                    not the card.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Attendance · last 30 days</h2></div>
        <div class="card__body">
            <div class="text-center mb-2">
                <div style="font-size:34px;font-weight:700;line-height:1"
                     class="<?= $summary['percentage'] < 80 ? 'text-danger' : ($summary['percentage'] < 90 ? 'text-warning' : 'text-success') ?>">
                    <?= e($summary['percentage']) ?>%
                </div>
                <div class="text-xs text-muted"><?= e($summary['total']) ?> records</div>
            </div>

            <div id="chart-student" style="min-height:150px"></div>
        </div>
    </div>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Recent attendance</h2></div>
        <div class="card__body--flush">
            <?php if ($attendance === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-clipboard-user', 'title' => 'No attendance yet']); ?>
            <?php else: ?>
                <div class="table-wrap" style="max-height:420px;overflow-y:auto">
                    <table class="data">
                        <thead><tr><th>Date</th><th>Subject</th><th>Section</th><th>In</th><th>Out</th><th>Duration</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($attendance as $record): ?>
                            <tr>
                                <td class="nowrap text-sm"><?= e(format_date($record['attendance_date'])) ?></td>
                                <td class="text-sm"><?= e($record['subject_code']) ?></td>
                                <td><span class="badge badge-neutral"><?= e($record['section_code']) ?></span></td>
                                <td class="nowrap text-sm"><?= e(format_time($record['time_in'])) ?></td>
                                <td class="nowrap text-sm"><?= e(format_time($record['time_out'])) ?></td>
                                <td class="text-sm"><?= e(human_duration($record['duration_minutes'] === null ? null : (int) $record['duration_minutes'])) ?></td>
                                <td><span class="badge <?= e(AttendanceStatusResolver::badgeClass((string) $record['final_status'])) ?>"><?= e($record['final_status']) ?></span></td>
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
            <h2 class="card__title">Section history</h2>
            <span class="text-xs text-muted">Past attendance keeps its original section</span>
        </div>
        <div class="card__body--flush">
            <?php if ($transfers === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-arrow-right', 'title' => 'No transfers recorded']); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Effective</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($transfers as $transfer): ?>
                            <tr>
                                <td class="nowrap text-sm"><?= e(format_date($transfer['effective_date'])) ?></td>
                                <td><?= e($transfer['from_code'] ?? '—') ?></td>
                                <td><span class="badge badge-primary"><?= e($transfer['to_code']) ?></span></td>
                                <td class="text-xs text-muted"><?= e($transfer['reason']) ?></td>
                                <td class="text-xs"><?= e($transfer['username'] ?? 'system') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Transfer modal -------------------------------------------------------- -->
<div class="modal-backdrop" id="transfer-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Transfer student</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form method="post" action="/admin/students/<?= e($student['student_id']) ?>/transfer" data-ajax data-reload="true">
            <?= csrf_field() ?>
            <div class="modal__body">
                <div class="alert alert-info">
                    <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
                    <div class="alert__body">
                        Attendance already recorded stays attributed to
                        <strong><?= e($student['section_code']) ?></strong>. Reports of past terms will
                        continue to show where this student actually sat.
                    </div>
                </div>

                <div class="form-group">
                    <label for="t-section" class="required">Destination section</label>
                    <select id="t-section" name="section_id" required>
                        <option value="">Select a section…</option>
                    </select>
                    <span class="field-help">Loading sections…</span>
                </div>

                <div class="form-group">
                    <label for="t-date" class="required">Effective date</label>
                    <input type="date" id="t-date" name="effective_date" required value="<?= e(date('Y-m-d')) ?>">
                </div>

                <div class="form-group">
                    <label for="t-reason" class="required">Reason</label>
                    <textarea id="t-reason" name="reason" required minlength="5" maxlength="500"
                              placeholder="e.g. Parent request, class rebalancing"></textarea>
                </div>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Transfer</button>
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

    LS.charts.donut('chart-student', [
        { label: 'Present',    value: <?= e($summary['present']) ?>,    color: '#22C55E' },
        { label: 'Late',       value: <?= e($summary['late']) ?>,       color: '#F59E0B' },
        { label: 'Left early', value: <?= e($summary['left_early']) ?>, color: '#FB923C' },
        { label: 'Incomplete', value: <?= e($summary['incomplete']) ?>, color: '#A78BFA' },
        { label: 'Excused',    value: <?= e($summary['excused']) ?>,    color: '#0EA5E9' },
        { label: 'Absent',     value: <?= e($summary['absent']) ?>,     color: '#EF4444' },
    ], { size: 150, centerValue: '<?= e($summary['total']) ?>', centerLabel: 'records', legend: true });

    // The destination list is loaded from the server so it reflects live
    // capacity, not a snapshot rendered with the page.
    document.getElementById('transfer-modal').addEventListener('modal:open', async function () {
        const select = document.getElementById('t-section');
        const help   = select.nextElementSibling;

        try {
            const response = await LS.http.get('/admin/sections', { status: 'active' });
            const rows = response.data.rows || [];

            select.innerHTML = '<option value="">Select a section…</option>';

            rows.forEach((section) => {
                if (Number(section.section_id) === <?= e($student['section_id'] ?? 0) ?>) return;

                const free = Number(section.capacity) - Number(section.enrolled_count);
                const option = document.createElement('option');
                option.value = section.section_id;
                option.textContent = section.section_code + ' — ' + section.section_name
                    + ' (' + free + ' free)';
                if (free <= 0) { option.disabled = true; option.textContent += ' — full'; }
                select.appendChild(option);
            });

            help.textContent = 'Full sections cannot receive a transfer.';
        } catch (error) {
            help.textContent = 'Could not load sections.';
        }
    });
})();
</script>
<?php $__view->stop(); ?>
