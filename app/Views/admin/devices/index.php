<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => 'IoT Devices',
    'subtitle'    => 'One attendance terminal per classroom. Credentials are shown once and never again.',
    'breadcrumbs' => [['Dashboard', '/admin'], ['IoT Devices', null]],
    'actions'     => '<a class="btn btn-secondary" href="/admin/devices/import"><i class="fa-solid fa-file-import"></i> Bulk register</a>'
        . '<button class="btn btn-primary" data-modal-open="device-modal"><i class="fa-solid fa-plus"></i> Register Device</button>',
]); ?>

<div class="grid grid--4 mb-3">
    <?php foreach ([
        ['Online',   $summary['online'],   'success', 'fa-circle-check'],
        ['Warning',  $summary['warning'],  'warning', 'fa-triangle-exclamation'],
        ['Offline',  $summary['offline'],  'danger',  'fa-circle-exclamation'],
        ['Pending',  $summary['pending'],  'info',    'fa-clock'],
    ] as [$label, $value, $tone, $icon]): ?>
        <div class="stat">
            <span class="stat__icon stat__icon--<?= e($tone) ?>"><i class="fa-solid <?= e($icon) ?>"></i></span>
            <div>
                <div class="stat__label"><?= e($label) ?></div>
                <div class="stat__value"><?= e($value) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($agingKeys !== []): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-key"></i></span>
        <div class="alert__body">
            <div class="alert__title"><?= e(count($agingKeys)) ?> API key(s) are more than a year old</div>
            Rotation is recommended. The previous key keeps working through the grace window,
            so a terminal can be reflashed without downtime.
        </div>
    </div>
<?php endif; ?>

<div class="device-grid" id="device-grid">
    <?php if ($devices === []): ?>
        <div class="card" style="grid-column:1/-1">
            <?php $__view->include('partials.empty-state', [
                'icon'   => 'fa-microchip',
                'title'  => 'No terminals registered',
                'text'   => 'Every classroom needs a registered ESP32 terminal before attendance can be taken there.',
                'action' => '<button class="btn btn-primary" data-modal-open="device-modal"><i class="fa-solid fa-plus"></i> Register Device</button>',
            ]); ?>
        </div>
    <?php else: ?>
        <?php foreach ($devices as $device): ?>
            <?php
            $health = (string) $device['health'];
            $tone   = ['online' => 'success', 'warning' => 'warning', 'offline' => 'danger',
                       'pending' => 'info', 'disabled' => 'neutral'][$health] ?? 'neutral';
            $keyAge = $device['key_age_days'] === null ? null : (int) $device['key_age_days'];
            ?>
            <div class="device-card" data-health="<?= e($health) ?>" data-device-id="<?= e($device['device_id']) ?>">
                <div class="device-card__header">
                    <div>
                        <div class="device-card__name"><?= e($device['device_name']) ?></div>
                        <div class="device-card__id"><?= e($device['device_id']) ?></div>
                    </div>
                    <span class="badge badge-<?= e($tone) ?> badge-dot"><?= e(ucfirst($health)) ?></span>
                </div>

                <div class="text-sm text-muted">
                    <?php if ($device['room_number']): ?>
                        <i class="fa-solid fa-door-open"></i> Room <?= e($device['room_number']) ?>
                        <?= $device['building'] ? '· ' . e($device['building']) : '' ?>
                    <?php else: ?>
                        <span class="text-warning"><i class="fa-solid fa-triangle-exclamation"></i> No classroom assigned</span>
                    <?php endif; ?>
                    · <span class="badge badge-neutral"><?= e($device['device_role']) ?></span>
                </div>

                <div class="device-card__stats">
                    <div class="device-card__stat"><span>Firmware</span><span class="mono"><?= e($device['firmware_version']) ?></span></div>
                    <div class="device-card__stat"><span>IP</span><span class="mono"><?= e($device['ip_address'] ?? '—') ?></span></div>
                    <div class="device-card__stat"><span>Signal</span><span><?= $device['wifi_signal'] === null ? '—' : e($device['wifi_signal']) . ' dBm' ?></span></div>
                    <div class="device-card__stat"><span>Queue</span>
                        <span class="<?= (int) $device['queue_depth'] > 0 ? 'text-warning fw-600' : '' ?>"><?= e($device['queue_depth']) ?></span>
                    </div>
                    <div class="device-card__stat"><span>Heartbeat</span><span><?= e(time_ago($device['last_heartbeat_at'])) ?></span></div>
                    <div class="device-card__stat"><span>Uptime</span><span><?= e(human_duration((int) $device['uptime_seconds'] > 0 ? intdiv((int) $device['uptime_seconds'], 60) : null)) ?></span></div>
                </div>

                <div class="text-xs text-muted" style="border-top:1px solid var(--border);padding-top:.55rem">
                    <div class="flex justify-between">
                        <span>Key</span>
                        <span class="mono"><?= e($device['key_id'] ?? '—') ?><?= $device['secret_last_four'] ? '.••••' . e(substr((string) $device['secret_last_four'], -2)) : '' ?></span>
                    </div>
                    <div class="flex justify-between mt-1">
                        <span>Key age</span>
                        <span class="<?= $keyAge !== null && $keyAge > 180 ? 'text-warning fw-600' : '' ?>">
                            <?= $keyAge === null ? '—' : e($keyAge) . ' days' ?>
                        </span>
                    </div>
                    <div class="flex justify-between mt-1">
                        <span>Uses</span><span><?= e(number_format((int) ($device['key_use_count'] ?? 0))) ?></span>
                    </div>
                    <?php if ($device['active_session_code']): ?>
                        <div class="flex justify-between mt-1">
                            <span>Session</span>
                            <span class="badge badge-success"><?= e($device['active_session_code']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($device['claim_status'] !== 'claimed'): ?>
                        <div class="mt-1 text-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i> Never activated — flash the provisioning file.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="device-card__actions">
                    <a class="btn btn-ghost btn-sm" href="/admin/devices/<?= e($device['device_row_id']) ?>">Details</a>
                    <button class="btn btn-ghost btn-sm" data-test="<?= e($device['device_row_id']) ?>">Test</button>
                    <button class="btn btn-ghost btn-sm" data-rotate="<?= e($device['device_row_id']) ?>"
                            data-name="<?= e($device['device_name']) ?>">Rotate key</button>
                    <button class="btn btn-ghost btn-sm text-danger" data-revoke="<?= e($device['device_row_id']) ?>"
                            data-name="<?= e($device['device_name']) ?>">Revoke</button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Register device ------------------------------------------------------- -->
<div class="modal-backdrop" id="device-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Register attendance terminal</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form id="device-form">
            <div class="modal__body">
                <div class="form-grid">
                    <div class="form-group form-group--full">
                        <label for="d-name" class="required">Device name</label>
                        <input type="text" id="d-name" name="device_name" required placeholder="Room 204 Terminal" maxlength="120">
                    </div>

                    <div class="form-group">
                        <label for="d-id">Device ID</label>
                        <input type="text" id="d-id" name="device_id" value="<?= e($suggestedId) ?>" maxlength="32">
                        <span class="field-help">Auto-generated; edit only if you have your own convention.</span>
                    </div>

                    <div class="form-group">
                        <label for="d-mac" class="required">MAC address</label>
                        <input type="text" id="d-mac" name="mac_address" required
                               placeholder="AA:BB:CC:DD:EE:FF" maxlength="17" style="font-family:var(--mono)">
                        <span class="field-help">Printed on the ESP32, or shown in its serial boot log.</span>
                    </div>

                    <div class="form-group">
                        <label for="d-classroom">Classroom</label>
                        <select id="d-classroom" name="classroom_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($classrooms as $room): ?>
                                <option value="<?= e($room['classroom_id']) ?>">
                                    Room <?= e($room['room_number']) ?><?= $room['building'] ? ' — ' . e($room['building']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="d-role">Reader role</label>
                        <select id="d-role" name="device_role">
                            <option value="both">Both (single terminal per room)</option>
                            <option value="entry">Entry only</option>
                            <option value="exit">Exit only</option>
                        </select>
                        <span class="field-help">"Both" is the standard deployment and works on its own.</span>
                    </div>

                    <div class="form-group">
                        <label for="d-serial">Serial number</label>
                        <input type="text" id="d-serial" name="serial_number" maxlength="60">
                    </div>

                    <div class="form-group">
                        <label for="d-allowlist">IP allowlist</label>
                        <input type="text" id="d-allowlist" name="ip_allowlist" placeholder="192.168.1.0/24">
                        <span class="field-help">Optional. Restricts where this key may be presented from.</span>
                    </div>

                    <div class="form-group form-group--full">
                        <label for="d-note">Physical location note</label>
                        <input type="text" id="d-note" name="location_note" maxlength="255"
                               placeholder="Mounted beside the door, inside the enclosure">
                    </div>
                </div>

                <label class="checkbox mt-2">
                    <input type="checkbox" name="confirm_mac_reuse">
                    <span class="text-sm">This MAC belonged to a retired device — reuse it (audited)</span>
                </label>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Register &amp; generate credentials</button>
            </div>
        </form>
    </div>
</div>

<!-- One-time credential display ------------------------------------------- -->
<div class="modal-backdrop" id="credentials-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title"><i class="fa-solid fa-key"></i> Device credentials</h3>
        </div>

        <div class="modal__body">
            <div class="credential-warning">
                <strong>These values are shown exactly once.</strong>
                They are stored hashed and cannot be recovered — if you lose them, rotate the key
                and flash a new provisioning file. Treat this like a physical key to the building.
            </div>

            <div class="credential-box">
                <div class="credential-box__label">API key</div>
                <button class="credential-box__copy" type="button" data-copy="#cred-api-key">Copy</button>
                <span id="cred-api-key"></span>
            </div>

            <div class="credential-box">
                <div class="credential-box__label">HMAC signing secret</div>
                <button class="credential-box__copy" type="button" data-copy="#cred-hmac">Copy</button>
                <span id="cred-hmac"></span>
            </div>

            <div class="credential-box">
                <div class="credential-box__label">Claim token (single use, expires in 24 hours)</div>
                <button class="credential-box__copy" type="button" data-copy="#cred-claim">Copy</button>
                <span id="cred-claim"></span>
            </div>

            <p class="text-muted text-sm">
                Download the provisioning file and load it during firmware flashing. The device
                exchanges its claim token on first boot to activate.
            </p>
        </div>

        <div class="modal__footer">
            <button type="button" class="btn btn-secondary" id="download-provisioning">
                <i class="fa-solid fa-download"></i> Download provisioning file
            </button>
            <button type="button" class="btn btn-primary" id="credentials-done">I have saved these</button>
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
    let provisioning = null;

    document.getElementById('device-form').addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = this.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Registering…');

        try {
            const response = await LS.http.post('/admin/devices', LS.util.formData(this));
            const data = response.data;

            document.getElementById('cred-api-key').textContent = data.api_key;
            document.getElementById('cred-hmac').textContent    = data.hmac_secret;
            document.getElementById('cred-claim').textContent   = data.claim_token;

            provisioning = data.provisioning;

            LS.modal.close('device-modal');
            LS.modal.open('credentials-modal');
            LS.toast.success(response.message);
            this.reset();
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(this, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    document.getElementById('download-provisioning').addEventListener('click', function () {
        if (!provisioning) return;

        // Built client-side from the one-time response — the server never
        // writes this file to disk.
        const blob = new Blob([JSON.stringify(provisioning, null, 2)], { type: 'application/json' });
        const url  = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = 'lsiams-provisioning-' + provisioning.device_id + '.json';
        link.click();

        URL.revokeObjectURL(url);
        LS.toast.success('Provisioning file downloaded.');
    });

    document.getElementById('credentials-done').addEventListener('click', function () {
        LS.modal.close('credentials-modal');
        // Clear the secrets out of the DOM as soon as the modal is dismissed.
        ['cred-api-key', 'cred-hmac', 'cred-claim'].forEach((id) => {
            document.getElementById(id).textContent = '';
        });
        provisioning = null;
        window.location.reload();
    });

    document.addEventListener('click', async (event) => {
        const test = event.target.closest('[data-test]');

        if (test) {
            LS.util.setBusy(test, true, 'Testing…');

            try {
                const response = await LS.http.post('/admin/devices/' + test.dataset.test + '/test', {});
                const health = response.data.health;
                LS.toast.show(response.message,
                    health === 'online' ? 'success' : (health === 'warning' ? 'warning' : 'danger'),
                    'Terminal ' + health);
            } catch (error) {
                LS.toast.fromError(error);
            } finally {
                LS.util.setBusy(test, false);
            }
        }

        const rotate = event.target.closest('[data-rotate]');

        if (rotate) {
            const result = await LS.modal.confirm({
                title: 'Rotate API key?',
                message: 'A new key is issued for ' + rotate.dataset.name + '. The current key keeps working '
                       + 'for the grace period so you can reflash without downtime, then auto-revokes.',
                confirmLabel: 'Rotate key',
                requirePassword: true,
            });

            if (!result) return;

            try {
                const response = await LS.http.post('/admin/devices/' + rotate.dataset.rotate + '/rotate-key', {
                    confirm_password: result.password,
                });

                document.getElementById('cred-api-key').textContent = response.data.api_key;
                document.getElementById('cred-hmac').textContent    = response.data.hmac_secret;
                document.getElementById('cred-claim').textContent   = response.data.claim_token;
                provisioning = response.data.provisioning;

                LS.modal.open('credentials-modal');
                LS.toast.success(response.message);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }

        const revoke = event.target.closest('[data-revoke]');

        if (revoke) {
            const result = await LS.modal.confirm({
                title: 'Revoke API key?',
                message: revoke.dataset.name + ' will be refused on its very next request and cannot record '
                       + 'attendance until a new key is issued and flashed.',
                confirmLabel: 'Revoke now',
                danger: true,
                requirePassword: true,
            });

            if (!result) return;

            const reason = prompt('Reason for revocation (recorded in the audit trail):');

            if (!reason || reason.trim().length < 5) {
                LS.toast.warning('A reason of at least 5 characters is required.');
                return;
            }

            try {
                const response = await LS.http.post('/admin/devices/' + revoke.dataset.revoke + '/revoke-key', {
                    confirm_password: result.password,
                    reason: reason,
                });

                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 900);
            } catch (error) {
                LS.toast.fromError(error);
            }
        }
    });

    /* Live fleet updates. */
    if (LS.realtime) {
        LS.realtime
            .on('device.heartbeat', (data) => {
                const card = document.querySelector('[data-device-id="' + data.device_id + '"]');
                if (card) card.dataset.health = 'online';
            })
            .on('device.offline', (data) => {
                const card = document.querySelector('[data-device-id="' + data.device_id + '"]');
                if (card) card.dataset.health = 'offline';
            });
    }
})();
</script>
<?php $__view->stop(); ?>
