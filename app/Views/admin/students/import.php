<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Import Students',
    'subtitle'    => 'Upload a CSV or Excel file, review exactly what would happen, then commit. Nothing is written until you confirm.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['Students', '/admin/students'], ['Import', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/students/import/template"><i class="fa-solid fa-download"></i> Download template</a>',
]); ?>

<div class="wizard" id="wizard">
    <div class="wizard__step is-active" data-step="1"><span class="wizard__num">1</span> Upload</div>
    <div class="wizard__step" data-step="2"><span class="wizard__num">2</span> Review</div>
    <div class="wizard__step" data-step="3"><span class="wizard__num">3</span> Done</div>
</div>

<!-- Step 1 — upload ---------------------------------------------------------->
<div class="card" data-panel="1">
    <div class="card__header"><h2 class="card__title">Choose a file</h2></div>
    <div class="card__body">
        <div class="alert alert-info">
            <span class="alert__icon"><i class="fa-solid fa-circle-info"></i></span>
            <div class="alert__body">
                <strong>Section code is what places a student.</strong> The grade level column is
                advisory — if it disagrees with the section, the row is flagged rather than guessed at.
                Card UID is optional; a student without one simply cannot tap until a card is issued.
            </div>
        </div>

        <form id="upload-form" enctype="multipart/form-data">
            <div class="dropzone" id="dropzone">
                <input type="file" id="file" name="file" accept=".csv,.xlsx" hidden>
                <div class="dropzone__icon"><i class="fa-solid fa-file-arrow-up"></i></div>
                <div class="dropzone__title">Drop a CSV or Excel file here</div>
                <div class="dropzone__text">or <button type="button" class="btn btn-secondary btn-sm" id="browse">browse</button></div>
                <div class="dropzone__meta" id="file-name"></div>
            </div>

            <p class="text-xs text-muted mt-2">
                CSV and XLSX only, up to <?= e(round((int) config('app.uploads.max_bytes', 4194304) / 1048576, 1)) ?> MB.
                The file is checked by extension, declared type and actual content before it is parsed.
            </p>

            <div class="flex gap-1 mt-2" style="justify-content:flex-end">
                <a class="btn btn-secondary" href="/admin/students">Cancel</a>
                <button type="submit" class="btn btn-primary" id="validate-btn" disabled>
                    <i class="fa-solid fa-list-check"></i> Validate file
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Step 2 — review ---------------------------------------------------------->
<div class="card hidden" data-panel="2">
    <div class="card__header">
        <h2 class="card__title">Review</h2>
        <div class="flex gap-1">
            <button class="btn btn-ghost btn-sm" id="back-to-upload"><i class="fa-solid fa-arrow-left"></i> Choose another file</button>
            <button class="btn btn-primary btn-sm" id="commit-btn"><i class="fa-solid fa-check"></i> Import valid rows</button>
        </div>
    </div>

    <div class="card__body">
        <div class="grid grid--4 mb-3" id="preview-summary"></div>

        <div id="invalid-block" hidden>
            <div class="alert alert-warning">
                <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div class="alert__body">
                    Rows with problems are listed below and will be <strong>skipped</strong>. Fix them in
                    the source file and import again — the valid rows you commit now will not be
                    duplicated, because student numbers are unique.
                </div>
            </div>

            <div class="table-wrap" style="max-height:300px;overflow-y:auto">
                <table class="data">
                    <thead><tr><th style="width:70px">Line</th><th>Student</th><th>Problems</th></tr></thead>
                    <tbody id="invalid-rows"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card__body--flush">
        <div class="table-wrap" style="max-height:460px;overflow-y:auto">
            <table class="data">
                <thead>
                <tr><th style="width:70px">Line</th><th>Student number</th><th>Name</th>
                    <th>Section</th><th>Card</th><th>Guardian</th><th>Status</th></tr>
                </thead>
                <tbody id="valid-rows"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Step 3 — result ---------------------------------------------------------->
<div class="card hidden" data-panel="3">
    <div class="card__body text-center" style="padding:3rem 1.5rem">
        <div style="font-size:44px;color:var(--success);margin-bottom:.75rem"><i class="fa-solid fa-circle-check"></i></div>
        <h2 id="result-title">Import complete</h2>
        <p class="text-muted" id="result-text"></p>
        <div class="flex gap-1 mt-3" style="justify-content:center">
            <a class="btn btn-primary" href="/admin/students">Go to students</a>
            <a class="btn btn-secondary" href="/admin/students/import">Import another file</a>
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

    const dropzone = document.getElementById('dropzone');
    const input    = document.getElementById('file');
    const validate = document.getElementById('validate-btn');

    let previewed = null;

    /* ---- step navigation -------------------------------------------------- */
    function goto(step) {
        document.querySelectorAll('[data-panel]').forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.panel !== String(step));
        });

        document.querySelectorAll('.wizard__step').forEach((node) => {
            const index = Number(node.dataset.step);
            node.classList.toggle('is-active', index === step);
            node.classList.toggle('is-done', index < step);
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* ---- file selection ---------------------------------------------------- */
    document.getElementById('browse').addEventListener('click', () => input.click());
    dropzone.addEventListener('click', (event) => { if (event.target === dropzone) input.click(); });

    ['dragenter', 'dragover'].forEach((type) => {
        dropzone.addEventListener(type, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-over');
        });
    });

    ['dragleave', 'drop'].forEach((type) => {
        dropzone.addEventListener(type, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-over');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        if (event.dataTransfer.files.length === 0) return;
        input.files = event.dataTransfer.files;
        onFileChosen();
    });

    input.addEventListener('change', onFileChosen);

    function onFileChosen() {
        const file = input.files[0];

        if (!file) {
            validate.disabled = true;
            return;
        }

        document.getElementById('file-name').textContent =
            file.name + ' — ' + (file.size / 1024).toFixed(1) + ' KB';
        validate.disabled = false;
    }

    /* ---- validate ---------------------------------------------------------- */
    document.getElementById('upload-form').addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!input.files[0]) return;

        LS.util.setBusy(validate, true, 'Validating…');

        const body = new FormData();
        body.append('file', input.files[0]);
        body.append('_csrf', LS.config.csrfToken);

        try {
            const response = await LS.http.upload('/admin/students/import/preview', body);
            previewed = response.data;
            renderPreview(response.data);
            goto(2);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(validate, false);
        }
    });

    function renderPreview(data) {
        const summary = data.summary;

        document.getElementById('preview-summary').innerHTML = [
            tile('Rows in file', summary.total, ''),
            tile('Ready to import', summary.valid, 'text-success'),
            tile('Will be skipped', summary.invalid, summary.invalid > 0 ? 'text-danger' : 'text-muted'),
            tile('With RFID cards', summary.with_cards, 'text-info'),
        ].join('');

        document.getElementById('valid-rows').innerHTML = data.valid.length === 0
            ? '<tr><td colspan="7" class="text-center text-muted">No importable rows.</td></tr>'
            : data.valid.map((row) =>
                '<tr><td class="text-xs text-muted">' + row.line + '</td>'
                + '<td class="mono text-sm">' + LS.util.escape(row.student_number) + '</td>'
                + '<td>' + LS.util.escape([row.last_name, row.first_name].join(', ')) + '</td>'
                + '<td><span class="badge badge-primary">' + LS.util.escape(row.section_code) + '</span></td>'
                + '<td class="mono text-xs">' + LS.util.escape(row.card_uid || '—') + '</td>'
                + '<td class="text-sm">' + LS.util.escape(row.guardian_name) + '</td>'
                + '<td><span class="badge badge-neutral">' + LS.util.escape(row.status) + '</span></td></tr>').join('');

        const block = document.getElementById('invalid-block');
        block.hidden = data.invalid.length === 0;

        document.getElementById('invalid-rows').innerHTML = data.invalid.map((row) =>
            '<tr><td class="text-xs text-muted">' + row.line + '</td>'
            + '<td class="text-sm">' + LS.util.escape(
                (row.student_number || '(no number)') + ' — ' + [row.last_name, row.first_name].filter(Boolean).join(', ')) + '</td>'
            + '<td><ul class="text-xs text-danger" style="margin:0;padding-left:1.1rem">'
            + row.errors.map((error) => '<li>' + LS.util.escape(error) + '</li>').join('')
            + '</ul></td></tr>').join('');

        document.getElementById('commit-btn').disabled = data.valid.length === 0;
    }

    function tile(label, value, tone) {
        return '<div class="stat"><div><div class="stat__label">' + label + '</div>'
            + '<div class="stat__value ' + tone + '">' + value + '</div></div></div>';
    }

    /* ---- commit ------------------------------------------------------------ */
    document.getElementById('back-to-upload').addEventListener('click', () => goto(1));

    document.getElementById('commit-btn').addEventListener('click', async function () {
        if (!previewed || previewed.valid.length === 0) return;

        const confirmed = await LS.modal.confirm({
            title:   'Import ' + previewed.valid.length + ' student(s)?',
            message: 'The whole import runs as one transaction: if any row fails, none of them are '
                     + 'written and nothing is left half-done. Every row is re-validated server-side '
                     + 'before anything is inserted.',
            confirmLabel: 'Import',
        });

        if (!confirmed) return;

        LS.util.setBusy(this, true, 'Importing…');

        try {
            const response = await LS.http.post('/admin/students/import/commit', { records: previewed.valid });

            document.getElementById('result-text').textContent = response.message;
            goto(3);
        } catch (error) {
            LS.toast.fromError(error);

            // A row that became invalid between preview and commit (someone
            // else registered the same student number meanwhile) comes back
            // here — show it rather than leaving the user guessing.
            if (error.payload && error.payload.data && error.payload.data.invalid) {
                previewed.invalid = error.payload.data.invalid;
                previewed.valid   = [];
                renderPreview(previewed);
            }
        } finally {
            LS.util.setBusy(this, false);
        }
    });
})();
</script>
<?php $__view->stop(); ?>
