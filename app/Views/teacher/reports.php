<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

/**
 * Which controls each report type consumes. The server ignores anything
 * irrelevant and always re-scopes to the signed-in teacher, so this is a
 * clarity measure rather than a security one.
 */
$fieldsByType = [
    'daily'           => ['date', 'section_id', 'subject_id'],
    'weekly'          => ['range', 'section_id', 'subject_id'],
    'monthly'         => ['range', 'section_id', 'subject_id'],
    'teacher'         => ['range'],
    'section_daily'   => ['date', 'section_id'],
    'section_summary' => ['range', 'section_id'],
];
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Reports',
    'subtitle'    => 'Preview on screen, then export as PDF, Excel or CSV. Every report is scoped to your own classes.',
    'breadcrumbs' => [['Dashboard', '/teacher'], ['Reports', null]],
]); ?>

<div class="grid" style="grid-template-columns:320px 1fr;align-items:start">
    <div class="card">
        <div class="card__header"><h2 class="card__title">Build a report</h2></div>

        <div class="card__body">
            <form id="report-form">
                <div class="form-group">
                    <label for="r-type" class="required">Report type</label>
                    <select id="r-type" name="type" required>
                        <?php foreach ($types as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="date">
                    <label for="r-date">Date</label>
                    <input type="date" id="r-date" name="date" value="<?= e($defaultTo) ?>">
                </div>

                <div class="form-grid" data-field="range">
                    <div class="form-group">
                        <label for="r-from">From</label>
                        <input type="date" id="r-from" name="date_from" value="<?= e($defaultFrom) ?>">
                    </div>
                    <div class="form-group">
                        <label for="r-to">To</label>
                        <input type="date" id="r-to" name="date_to" value="<?= e($defaultTo) ?>">
                    </div>
                </div>

                <div class="form-group" data-field="section_id">
                    <label for="r-section">Section</label>
                    <select id="r-section" name="section_id">
                        <option value="">All my sections</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= e($section['section_id']) ?>">
                                <?= e($section['section_code']) ?> — <?= e($section['section_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-field="subject_id">
                    <label for="r-subject">Subject</label>
                    <select id="r-subject" name="subject_id">
                        <option value="">All my subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= e($subject['subject_id']) ?>">
                                <?= e($subject['subject_code']) ?> — <?= e($subject['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fa-solid fa-magnifying-glass"></i> Preview
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title" id="preview-title">Preview</h2>
                <div class="text-xs text-muted" id="preview-subtitle">Choose a report type and press Preview.</div>
            </div>
            <div class="flex gap-1" id="export-buttons" hidden>
                <button class="btn btn-secondary btn-sm" data-export="pdf"><i class="fa-solid fa-file-pdf"></i> PDF</button>
                <button class="btn btn-secondary btn-sm" data-export="xlsx"><i class="fa-solid fa-file-excel"></i> Excel</button>
                <button class="btn btn-secondary btn-sm" data-export="csv"><i class="fa-solid fa-file-csv"></i> CSV</button>
            </div>
        </div>

        <div class="card__body" id="preview-stats" hidden></div>

        <div class="card__body--flush">
            <div id="preview-body">
                <?php $__view->include('partials.empty-state', [
                    'icon'  => 'fa-file-lines',
                    'title' => 'Nothing previewed yet',
                    'text'  => 'Reports are built from live data each time — nothing here is cached.',
                ]); ?>
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
    const LS     = window.LSIAMS;
    const FIELDS = <?= json_js($fieldsByType) ?>;

    const form = document.getElementById('report-form');
    const type = document.getElementById('r-type');

    function syncFields() {
        const wanted = FIELDS[type.value] || [];

        form.querySelectorAll('[data-field]').forEach((node) => {
            const show = wanted.indexOf(node.dataset.field) !== -1;
            node.hidden = !show;

            if (!show) {
                node.querySelectorAll('select').forEach((select) => { select.value = ''; });
            }
        });
    }

    type.addEventListener('change', syncFields);
    syncFields();

    let lastFilters = null;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = form.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Building…');

        const body = document.getElementById('preview-body');
        body.innerHTML = '<div style="padding:1rem"><div class="skeleton skeleton--row"></div>'
            + '<div class="skeleton skeleton--row"></div><div class="skeleton skeleton--row"></div></div>';

        lastFilters = LS.util.formData(form);

        try {
            const response = await LS.http.post('/teacher/reports/preview', lastFilters);
            render(response.data);
        } catch (error) {
            body.innerHTML = '<div class="alert alert-danger" style="margin:1rem">'
                + LS.util.escape(error.message || 'Could not build this report.') + '</div>';
            document.getElementById('export-buttons').hidden = true;
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    function render(data) {
        document.getElementById('preview-title').textContent    = data.title;
        document.getElementById('preview-subtitle').textContent = data.subtitle;
        document.getElementById('export-buttons').hidden = false;

        const stats   = document.getElementById('preview-stats');
        const entries = Object.entries(data.statistics || {});

        if (entries.length === 0) {
            stats.hidden = true;
        } else {
            stats.hidden = false;
            stats.innerHTML = '<div class="grid grid--6">' + entries.map(([key, value]) =>
                '<div style="padding:.55rem .7rem;background:var(--surface-alt);border-radius:var(--radius-sm)">'
                + '<div class="text-xs text-muted">' + LS.util.escape(key.replace(/_/g, ' ')) + '</div>'
                + '<div class="fw-600" style="font-size:18px">' + LS.util.escape(String(value)) + '</div></div>').join('')
                + '</div>';
        }

        const body = document.getElementById('preview-body');

        if (!data.rows || data.rows.length === 0) {
            body.innerHTML = '<div class="empty-state">'
                + '<div class="empty-state__icon"><i class="fa-solid fa-inbox"></i></div>'
                + '<div class="empty-state__title">No rows matched</div>'
                + '<div class="empty-state__text">Try a wider date range or a different section.</div></div>';
            return;
        }

        let html = '<div class="table-wrap" style="max-height:560px;overflow:auto"><table class="data"><thead><tr>'
            + data.headers.map((header) => '<th>' + LS.util.escape(header) + '</th>').join('')
            + '</tr></thead><tbody>'
            + data.rows.map((row) => '<tr>'
                + row.map((cell) => '<td>' + LS.util.escape(cell === null ? '—' : String(cell)) + '</td>').join('')
                + '</tr>').join('')
            + '</tbody></table></div>';

        if (data.truncated) {
            html += '<div class="alert alert-info" style="margin:1rem">'
                + '<span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>'
                + '<div class="alert__body">Showing the first ' + data.rows.length + ' of '
                + data.total_rows.toLocaleString() + ' rows. The export contains all of them.</div></div>';
        }

        body.innerHTML = html;
    }

    document.getElementById('export-buttons').addEventListener('click', function (event) {
        const button = event.target.closest('[data-export]');
        if (!button || !lastFilters) return;

        // A real form POST so the browser handles the download itself.
        const post = document.createElement('form');
        post.method = 'post';
        post.action = '/teacher/reports/generate';
        post.style.display = 'none';

        const fields = Object.assign({}, lastFilters, {
            format: button.dataset.export,
            _csrf:  LS.config.csrfToken,
        });

        Object.entries(fields).forEach(([key, value]) => {
            if (value === '' || value === null || value === undefined) return;

            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = key;
            input.value = value;
            post.appendChild(input);
        });

        document.body.appendChild(post);
        post.submit();
        setTimeout(() => post.remove(), 1000);
    });
})();
</script>
<?php $__view->stop(); ?>
