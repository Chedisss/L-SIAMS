<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'Bulk Register Devices',
    'subtitle'    => 'Register a batch of terminals from a CSV or Excel file. Each one gets its own key pair and claim token, bundled into a single ZIP you download once.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['IoT Devices', '/admin/devices'], ['Bulk register', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/devices/import/template"><i class="fa-solid fa-download"></i> Download template</a>',
]); ?>

<div class="wizard">
    <div class="wizard__step is-active" data-step="1"><span class="wizard__num">1</span> Upload</div>
    <div class="wizard__step" data-step="2"><span class="wizard__num">2</span> Review</div>
    <div class="wizard__step" data-step="3"><span class="wizard__num">3</span> Credentials</div>
</div>

<!-- Step 1 — upload ---------------------------------------------------------->
<div class="card" data-panel="1">
    <div class="card__header"><h2 class="card__title">Choose a file</h2></div>
    <div class="card__body">
        <div class="alert alert-warning">
            <span class="alert__icon"><i class="fa-solid fa-key"></i></span>
            <div class="alert__body">
                <strong>The credential bundle is downloadable exactly once.</strong>
                Each terminal's API key, HMAC secret and claim token are generated at commit time and
                shown on the last step. They are stored hashed and cannot be retrieved afterwards —
                a lost bundle means rotating every key in it.
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
                Columns: <code>device_name</code>, <code>device_id</code>, <code>mac_address</code>,
                <code>classroom_code</code>, <code>device_role</code>, <code>serial_number</code>.
                Leave <code>device_id</code> blank and one is allocated in sequence.
            </p>

            <div class="flex gap-1 mt-2" style="justify-content:flex-end">
                <a class="btn btn-secondary" href="/admin/devices">Cancel</a>
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
            <button class="btn btn-primary btn-sm" id="commit-btn"><i class="fa-solid fa-check"></i> Register terminals</button>
        </div>
    </div>

    <div class="card__body">
        <div class="grid grid--3 mb-3" id="preview-summary"></div>

        <div id="invalid-block" hidden>
            <div class="alert alert-warning">
                <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div class="alert__body">
                    These rows will be skipped. A duplicate MAC address is the usual cause — a terminal
                    already registered under another name, or the same address typed twice in the file.
                </div>
            </div>

            <div class="table-wrap" style="max-height:280px;overflow-y:auto">
                <table class="data">
                    <thead><tr><th style="width:70px">Line</th><th>Device</th><th>Problems</th></tr></thead>
                    <tbody id="invalid-rows"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card__body--flush">
        <div class="table-wrap" style="max-height:440px;overflow-y:auto">
            <table class="data">
                <thead>
                <tr><th style="width:70px">Line</th><th>Device ID</th><th>Name</th>
                    <th>MAC address</th><th>Classroom</th><th>Role</th></tr>
                </thead>
                <tbody id="valid-rows"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Step 3 — credentials ----------------------------------------------------->
<div class="card hidden" data-panel="3">
    <div class="card__header"><h2 class="card__title">Provisioning bundle</h2></div>
    <div class="card__body">
        <div class="alert alert-danger">
            <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <div class="alert__body">
                <strong id="result-text"></strong>
                <div class="mt-1">
                    Download the bundle now. Leaving this page discards the only copy of these secrets;
                    the server keeps hashes, not the keys themselves.
                </div>
            </div>
        </div>

        <div class="text-center" style="padding:2rem 0">
            <button class="btn btn-primary btn-lg" id="download-bundle">
                <i class="fa-solid fa-file-zipper"></i> Download provisioning bundle
            </button>
            <div class="text-xs text-muted mt-2" id="download-note">
                One JSON file per terminal, ready to flash.
            </div>
        </div>

        <div class="flex gap-1" style="justify-content:center">
            <a class="btn btn-secondary" href="/admin/devices">Go to devices</a>
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
    let bundle    = null;
    let downloaded = false;

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
        dropzone.addEventListener(type, (event) => { event.preventDefault(); dropzone.classList.add('is-over'); });
    });

    ['dragleave', 'drop'].forEach((type) => {
        dropzone.addEventListener(type, (event) => { event.preventDefault(); dropzone.classList.remove('is-over'); });
    });

    dropzone.addEventListener('drop', (event) => {
        if (event.dataTransfer.files.length === 0) return;
        input.files = event.dataTransfer.files;
        onFileChosen();
    });

    input.addEventListener('change', onFileChosen);

    function onFileChosen() {
        const file = input.files[0];

        if (!file) { validate.disabled = true; return; }

        document.getElementById('file-name').textContent =
            file.name + ' — ' + (file.size / 1024).toFixed(1) + ' KB';
        validate.disabled = false;
    }

    /* ---- validate ----------------------------------------------------------- */
    document.getElementById('upload-form').addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!input.files[0]) return;

        LS.util.setBusy(validate, true, 'Validating…');

        const body = new FormData();
        body.append('file', input.files[0]);
        body.append('_csrf', LS.config.csrfToken);

        try {
            const response = await LS.http.upload('/admin/devices/import/preview', body);
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
        document.getElementById('preview-summary').innerHTML = [
            tile('Rows in file', data.summary.total, ''),
            tile('Ready to register', data.summary.valid, 'text-success'),
            tile('Will be skipped', data.summary.invalid, data.summary.invalid > 0 ? 'text-danger' : 'text-muted'),
        ].join('');

        document.getElementById('valid-rows').innerHTML = data.valid.length === 0
            ? '<tr><td colspan="6" class="text-center text-muted">No registrable rows.</td></tr>'
            : data.valid.map((row) =>
                '<tr><td class="text-xs text-muted">' + row.line + '</td>'
                + '<td class="mono text-sm">' + LS.util.escape(row.device_id || '(auto)') + '</td>'
                + '<td>' + LS.util.escape(row.device_name) + '</td>'
                + '<td class="mono text-xs">' + LS.util.escape(row.mac_address) + '</td>'
                + '<td>' + LS.util.escape(row.classroom_code || '—') + '</td>'
                + '<td><span class="badge badge-neutral">' + LS.util.escape(row.device_role || 'both') + '</span></td></tr>').join('');

        document.getElementById('invalid-block').hidden = data.invalid.length === 0;

        document.getElementById('invalid-rows').innerHTML = data.invalid.map((row) =>
            '<tr><td class="text-xs text-muted">' + row.line + '</td>'
            + '<td class="text-sm">' + LS.util.escape(row.device_name || '(unnamed)') + '</td>'
            + '<td><ul class="text-xs text-danger" style="margin:0;padding-left:1.1rem">'
            + row.errors.map((error) => '<li>' + LS.util.escape(error) + '</li>').join('')
            + '</ul></td></tr>').join('');

        document.getElementById('commit-btn').disabled = data.valid.length === 0;
    }

    function tile(label, value, tone) {
        return '<div class="stat"><div><div class="stat__label">' + label + '</div>'
            + '<div class="stat__value ' + tone + '">' + value + '</div></div></div>';
    }

    /* ---- commit ------------------------------------------------------------- */
    document.getElementById('back-to-upload').addEventListener('click', () => goto(1));

    document.getElementById('commit-btn').addEventListener('click', async function () {
        if (!previewed || previewed.valid.length === 0) return;

        const confirmed = await LS.modal.confirm({
            title:   'Register ' + previewed.valid.length + ' terminal(s)?',
            message: 'Each terminal gets a fresh 256-bit key, HMAC secret and single-use claim token. '
                     + 'The bundle is shown once on the next step and cannot be recovered afterwards. '
                     + 'The whole batch is one transaction — if any row fails, none are registered.',
            confirmLabel: 'Register',
        });

        if (!confirmed) return;

        LS.util.setBusy(this, true, 'Registering…');

        try {
            const response = await LS.http.post('/admin/devices/import/commit', { records: previewed.valid });

            bundle = response.data;
            document.getElementById('result-text').textContent = response.message;
            goto(3);
        } catch (error) {
            LS.toast.fromError(error);

            if (error.payload && error.payload.data && error.payload.data.invalid) {
                previewed.invalid = error.payload.data.invalid;
                previewed.valid   = [];
                renderPreview(previewed);
            }
        } finally {
            LS.util.setBusy(this, false);
        }
    });

    /* ---- bundle download ----------------------------------------------------- */
    document.getElementById('download-bundle').addEventListener('click', function () {
        if (!bundle) return;

        // The ZIP arrives base64-encoded in the JSON response rather than as a
        // second request, so the secrets are never re-sent over a URL that
        // could end up in a proxy or access log.
        const binary = atob(bundle.zip_base64);
        const bytes  = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([bytes], { type: 'application/zip' }));
        link.download = bundle.zip_filename;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 3000);

        downloaded = true;
        document.getElementById('download-note').textContent =
            'Downloaded. Store it somewhere the school controls, then delete it once every terminal is flashed.';
    });

    window.addEventListener('beforeunload', function (event) {
        if (!bundle || downloaded) return;

        event.preventDefault();
        event.returnValue = '';
    });
})();
</script>
<?php $__view->stop(); ?>
