<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');

$health = (string) $device['health'];

$healthBadge = match ($health) {
    'online'   => 'badge-success',
    'warning'  => 'badge-warning',
    'pending'  => 'badge-info',
    'disabled' => 'badge-neutral',
    default    => 'badge-danger',
};

$keyAge = $device['key_age_days'] === null ? null : (int) $device['key_age_days'];

// security.api_key.rotation_days does not exist, so this read always fell
// through to its own default and the page announced a 90-day rotation policy
// the system does not have. The keys the audit actually acts on are these two.
$warnDays   = (int) config('security.api_key.age_warning_days', 180);
$rotateDays = (int) config('security.api_key.age_rotate_days', 365);
?>

<?php $__view->include('partials.page-header', [
    'title'       => (string) $device['device_id'],
    'subtitle'    => $device['device_name'] . ($device['room_number'] ? ' · Room ' . $device['room_number'] : ' · unassigned'),
    'breadcrumbs' => [['Dashboard', '/admin'], ['IoT Devices', '/admin/devices'], [(string) $device['device_id'], null]],
    'actions'     => '<button class="btn btn-secondary" id="test-connection"><i class="fa-solid fa-satellite-dish"></i> Test connection</button>'
        . '<button class="btn btn-primary" data-modal-open="device-edit-modal"><i class="fa-solid fa-pen"></i> Edit</button>',
]); ?>

<div class="grid grid--4 mb-3">
    <div class="stat">
        <span class="stat__icon stat__icon--<?= $health === 'online' ? 'success' : ($health === 'warning' ? 'warning' : 'danger') ?>">
            <i class="fa-solid fa-heart-pulse"></i>
        </span>
        <div>
            <div class="stat__label">Health</div>
            <div class="stat__value" style="font-size:19px">
                <span class="badge <?= e($healthBadge) ?>" id="health-badge"
                      title="<?= e(device_health_hint($health)) ?>"><?= e(device_health_label($health)) ?></span>
            </div>
            <div class="stat__meta" id="health-meta">
                <?= $device['last_heartbeat_at']
                    ? e(time_ago($device['last_heartbeat_at']))
                    : 'never sent a heartbeat' ?>
            </div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon stat__icon--info"><i class="fa-solid fa-wifi"></i></span>
        <div>
            <div class="stat__label">Wi-Fi signal</div>
            <div class="stat__value"><?= e($device['wifi_signal'] === null ? '—' : $device['wifi_signal'] . ' dBm') ?></div>
            <div class="stat__meta">
                <?php if ($device['wifi_signal'] !== null): ?>
                    <?= (int) $device['wifi_signal'] >= -60 ? 'Good' : ((int) $device['wifi_signal'] >= -75 ? 'Usable' : 'Weak — expect queueing') ?>
                <?php else: ?>unknown<?php endif; ?>
            </div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon <?= (int) $device['queue_depth'] > 0 ? 'stat__icon--warning' : '' ?>">
            <i class="fa-solid fa-layer-group"></i>
        </span>
        <div>
            <div class="stat__label">Offline queue</div>
            <div class="stat__value"><?= e((int) $device['queue_depth']) ?></div>
            <div class="stat__meta">taps waiting to sync</div>
        </div>
    </div>

    <div class="stat">
        <span class="stat__icon <?= $keyAge === null ? '' : ($keyAge >= $rotateDays ? 'stat__icon--danger' : ($keyAge >= $warnDays ? 'stat__icon--warning' : '')) ?>">
            <i class="fa-solid fa-key"></i>
        </span>
        <div>
            <div class="stat__label">API key age</div>
            <div class="stat__value"><?= e($keyAge === null ? '—' : $keyAge . 'd') ?></div>
            <div class="stat__meta">
                <?= $keyAge === null
                    ? 'no active key'
                    : ($keyAge >= $rotateDays
                        ? 'past the ' . $rotateDays . '-day rotation point'
                        : ($keyAge >= $warnDays
                            ? 'ageing — rotate before ' . $rotateDays . ' days'
                            : 'rotate at ' . $rotateDays . ' days')) ?>
            </div>
        </div>
    </div>
</div>

<?php if ($device['claim_status'] !== 'claimed'): ?>
    <div class="alert alert-warning">
        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div class="alert__body">
            <strong>This terminal has not completed first-boot activation, so it shows as Pending.</strong>
            Its credentials exist but are inert until the board itself presents its single-use claim
            token. There is no button here that changes that — a terminal activates itself, which is
            the point: it proves the board holding the key is the board you registered.
            <ol class="mt-1" style="padding-left:1.1rem;line-height:1.8">
                <li>Download the provisioning file below.</li>
                <li>Copy the four values from it into the sketch and flash the board.</li>
                <li>Power it on within Wi-Fi range. It claims its key on first boot and turns
                    <span class="badge badge-success badge-dot">Online</span> here by itself.</li>
            </ol>
            <button class="btn btn-secondary btn-sm mt-1" id="provisioning-file-top">
                <i class="fa-solid fa-download"></i> Download provisioning file
            </button>
        </div>
    </div>

    <?php if ($claimAttempts !== []): ?>
        <?php $latest = $claimAttempts[0]; ?>
        <div class="card">
            <div class="card__header">
                <h2 class="card__title"><i class="fa-solid fa-triangle-exclamation text-danger"></i> The board tried and was refused</h2>
                <span class="badge badge-danger"><?= e(count($claimAttempts)) ?></span>
            </div>
            <div class="card__body">
                <p class="text-sm text-muted">
                    So the board is reaching the server, and the network is fine. It was turned away
                    because what it presented does not match this registration.
                </p>

                <?php if ($latest['reason'] === 'identity_mismatch'): ?>
                    <div class="table-wrap mt-2">
                        <table class="data">
                            <thead><tr><th></th><th>Registered here</th><th>Sent by the board</th><th></th></tr></thead>
                            <tbody>
                                <tr>
                                    <td class="cell-primary">Device ID</td>
                                    <td class="mono text-sm"><?= e($device['device_id']) ?></td>
                                    <td class="mono text-sm"><?= e($latest['presented_device_id'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($latest['device_id_matches']): ?>
                                            <span class="badge badge-success">matches</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">differs</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="cell-primary">MAC address</td>
                                    <td class="mono text-sm"><?= e($device['mac_address']) ?></td>
                                    <td class="mono text-sm"><?= e($latest['presented_mac'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($latest['mac_matches']): ?>
                                            <span class="badge badge-success">matches</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">differs</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$latest['mac_matches'] && $latest['presented_mac'] !== null): ?>
                        <div class="alert alert-warning mt-2">
                            <span class="alert__icon"><i class="fa-solid fa-circle-question"></i></span>
                            <div class="alert__body">
                                <strong>Is <span class="mono"><?= e($latest['presented_mac']) ?></span> the board you mean to use?</strong>
                                If it is, the MAC typed in at registration was wrong and correcting it
                                here is all that is needed. If it is <em>not</em>, some other board is
                                holding this terminal's provisioning file — revoke the key instead.
                                <div class="flex gap-1 mt-2">
                                    <button class="btn btn-primary btn-sm" id="adopt-mac"
                                            data-mac="<?= e($latest['presented_mac']) ?>">
                                        <i class="fa-solid fa-check"></i>
                                        Use <?= e($latest['presented_mac']) ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php elseif (!$latest['device_id_matches']): ?>
                        <p class="text-sm mt-2">
                            The Device ID is fixed once registered. Change <span class="mono">DEVICE_ID</span>
                            in the sketch to <span class="mono"><?= e($device['device_id']) ?></span> and flash again.
                        </p>
                    <?php endif; ?>

                <?php elseif ($latest['reason'] === 'token_expired'): ?>
                    <div class="alert alert-warning mt-2">
                        <span class="alert__icon"><i class="fa-solid fa-clock"></i></span>
                        <div class="alert__body">
                            The claim token had expired — they last 24 hours. Download a fresh
                            provisioning file above and flash the new values.
                        </div>
                    </div>

                <?php else: ?>
                    <div class="alert alert-warning mt-2">
                        <span class="alert__icon"><i class="fa-solid fa-key"></i></span>
                        <div class="alert__body">
                            The claim token was not recognised. Each download supersedes the last, so
                            this happens when the sketch carries a token from an older file. Download
                            once, and copy all four values out of that same file.
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                /* Everything above reports only the LAST attempt, and that
                   loses something the page already knows.

                   A board with two problems — a wrong MAC on the registration
                   AND a stale claim token — is refused for the token first,
                   because the token is checked before the identity. The MAC
                   comparison is then never rendered, even though an earlier
                   attempt captured the address the board presented. The token
                   gets fixed, the file re-flashed, and the MAC failure appears
                   for the first time on the next boot: a second trip to the
                   classroom that this page had the data to prevent.

                   So: if ANY recent attempt presented an address that is not
                   the registered one, say so regardless of what failed most
                   recently. */
                $seenMac = null;

                foreach ($claimAttempts as $attempt) {
                    $candidate = $attempt['presented_mac'] ?? null;

                    if ($candidate !== null && $candidate !== (string) $device['mac_address']) {
                        $seenMac = (string) $candidate;
                        break;
                    }
                }
                ?>

                <?php /* The guard is what keeps the id unique: the panel above
                         renders its own #adopt-mac only when the reason IS
                         identity_mismatch, and this one only when it is not,
                         so exactly one is ever in the document. Keep the two
                         conditions opposites if either is edited. */ ?>
                <?php if ($seenMac !== null && $latest['reason'] !== 'identity_mismatch'): ?>
                    <div class="alert alert-warning mt-2">
                        <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div class="alert__body">
                            <strong>There is a second problem waiting behind that one.</strong>
                            A board claiming this terminal reported its address as
                            <span class="mono"><?= e($seenMac) ?></span>, but this registration says
                            <span class="mono"><?= e($device['mac_address']) ?></span>. Fixing the token
                            alone will not be enough — the next attempt will be refused on the address.

                            <div class="flex gap-1 mt-2">
                                <button class="btn btn-primary btn-sm" id="adopt-mac"
                                        data-mac="<?= e($seenMac) ?>">
                                    <i class="fa-solid fa-check"></i>
                                    Use <?= e($seenMac) ?>
                                </button>
                            </div>

                            <div class="text-xs text-muted mt-1">
                                Only if that is the board you mean to use. If it is not, another board
                                is holding this terminal's provisioning file — revoke the key instead.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <details class="mt-2">
                    <summary class="text-sm text-muted" style="cursor:pointer">
                        All <?= e(count($claimAttempts)) ?> attempt(s)
                    </summary>
                    <div class="table-wrap mt-1">
                        <table class="data">
                            <thead><tr><th>When</th><th>From</th><th>Device ID sent</th><th>MAC sent</th><th>Refused because</th></tr></thead>
                            <tbody>
                            <?php foreach ($claimAttempts as $attempt): ?>
                                <tr>
                                    <td class="nowrap text-xs"><?= e(format_datetime($attempt['created_at'], 'M j, H:i:s')) ?></td>
                                    <td class="mono text-xs"><?= e($attempt['source_ip'] ?? '—') ?></td>
                                    <td class="mono text-xs"><?= e($attempt['presented_device_id'] ?? '—') ?></td>
                                    <td class="mono text-xs"><?= e($attempt['presented_mac'] ?? '—') ?></td>
                                    <td class="text-xs"><?= e(match ($attempt['reason']) {
                                        'identity_mismatch' => 'Device ID or MAC did not match',
                                        'token_expired'     => 'Claim token had expired',
                                        'unknown_token'     => 'Claim token not recognised',
                                        default             => 'Refused',
                                    }) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($device['active_session_code']): ?>
    <div class="alert alert-info">
        <span class="alert__icon"><i class="fa-solid fa-circle-play"></i></span>
        <div class="alert__body">
            A session is open on this terminal:
            <a href="/admin/attendance/sessions/<?= e($device['active_session_id']) ?>" class="mono"><?= e($device['active_session_code']) ?></a>.
            Disabling the device or revoking its key will stop taps immediately; the session itself
            stays open until it is closed or auto-closes.
        </div>
    </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:1fr 340px;align-items:start">
    <div>
        <div class="card">
            <div class="card__header">
                <h2 class="card__title">Heartbeat history</h2>
                <span class="text-xs text-muted">Last 24 hours</span>
            </div>
            <div class="card__body">
                <div id="chart-signal" style="min-height:220px"></div>
            </div>
        </div>

        <div class="card">
            <div class="card__header">
                <h2 class="card__title">Device log</h2>
                <select id="log-severity" style="max-width:150px">
                    <option value="">All severities</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                    <option value="critical">Critical</option>
                </select>
            </div>
            <div class="card__body--flush">
                <?php if ($logs === []): ?>
                    <?php $__view->include('partials.empty-state', [
                        'icon'  => 'fa-list',
                        'title' => 'Nothing logged yet',
                        'text'  => 'The terminal writes here on boot, network changes, sync and authentication failures.',
                    ]); ?>
                <?php else: ?>
                    <div class="table-wrap" style="max-height:460px;overflow-y:auto">
                        <table class="data" id="log-table">
                            <thead><tr><th>When</th><th>Event</th><th>Severity</th><th>Message</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr data-severity="<?= e($log['severity']) ?>">
                                    <td class="nowrap text-xs"><?= e(format_datetime($log['created_at'], 'M j, H:i:s')) ?></td>
                                    <td><span class="badge badge-neutral"><?= e(str_replace('_', ' ', (string) $log['event'])) ?></span></td>
                                    <td>
                                        <span class="badge <?= e(match ((string) $log['severity']) {
                                            'critical', 'error' => 'badge-danger',
                                            'warning'           => 'badge-warning',
                                            default             => 'badge-neutral',
                                        }) ?>"><?= e($log['severity']) ?></span>
                                    </td>
                                    <td class="text-sm"><?= e($log['message'] ?? '—') ?></td>
                                    <td class="mono text-xs text-muted"><?= e($log['ip_address'] ?? '—') ?></td>
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
                <h2 class="card__title">API key lifecycle</h2>
                <span class="text-xs text-muted">Key values themselves are never recorded — only what happened to them</span>
            </div>
            <div class="card__body--flush">
                <?php if ($keyHistory === []): ?>
                    <?php $__view->include('partials.empty-state', ['icon' => 'fa-key', 'title' => 'No key events recorded']); ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead><tr><th>When</th><th>Event</th><th>Key</th><th>Reason</th><th>By</th><th>From</th></tr></thead>
                            <tbody>
                            <?php foreach ($keyHistory as $entry): ?>
                                <tr>
                                    <td class="nowrap text-xs"><?= e(format_datetime($entry['created_at'])) ?></td>
                                    <td>
                                        <span class="badge <?= e(match ((string) $entry['event']) {
                                            'generated'                => 'badge-success',
                                            'rotated'                  => 'badge-info',
                                            'revoked', 'auto_revoked'  => 'badge-danger',
                                            'expired', 'suspended'     => 'badge-warning',
                                            default                    => 'badge-neutral',
                                        }) ?>"><?= e(str_replace('_', ' ', (string) $entry['event'])) ?></span>
                                    </td>
                                    <td class="mono text-xs">
                                        <?= e($entry['key_id']) ?><span class="text-muted">…<?= e($entry['secret_last_four']) ?></span>
                                    </td>
                                    <td class="text-sm"><?= e($entry['reason'] ?? '—') ?></td>
                                    <td class="text-xs"><?= e($entry['performed_by_username'] ?? 'system') ?></td>
                                    <td class="mono text-xs text-muted"><?= e($entry['ip_address'] ?? '—') ?></td>
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
            <div class="card__header"><h2 class="card__title">Identity</h2></div>
            <div class="card__body">
                <?php foreach ([
                    'Device ID'   => (string) $device['device_id'],
                    'Name'        => (string) $device['device_name'],
                    'MAC address' => (string) $device['mac_address'],
                    'IP address'  => (string) ($device['ip_address'] ?? '—'),
                    'Firmware'    => (string) ($device['firmware_version'] ?? '—'),
                    'Role'        => ucfirst((string) $device['device_role']),
                    'Classroom'   => $device['room_number'] ? $device['room_number'] . ' (' . $device['building'] . ')' : 'unassigned',
                    'Status'      => ucfirst((string) $device['configured_status']),
                    'Claim'       => ucfirst((string) $device['claim_status']),
                    'Uptime'      => $device['uptime_seconds'] === null ? '—' : human_duration((int) round((int) $device['uptime_seconds'] / 60)),
                ] as $label => $value): ?>
                    <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted"><?= e($label) ?></span>
                        <span class="text-sm text-right mono"><?= e($value) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Credentials</h2></div>
            <div class="card__body">
                <?php if ($device['key_id']): ?>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Key ID</span>
                        <span class="text-sm mono"><?= e($device['key_id']) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Secret</span>
                        <span class="text-sm mono">••••••••<?= e($device['secret_last_four']) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Status</span>
                        <span class="badge <?= e(status_badge((string) $device['key_status'])) ?>"><?= e($device['key_status']) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border)">
                        <span class="text-xs text-muted">Last used</span>
                        <span class="text-sm"><?= e($device['key_last_used_at'] ? time_ago($device['key_last_used_at']) : 'never') ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:.35rem 0">
                        <span class="text-xs text-muted">Requests signed</span>
                        <span class="text-sm"><?= e(number_format((int) $device['key_use_count'])) ?></span>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-muted">This device has no active key. It cannot authenticate until one is issued.</p>
                <?php endif; ?>

                <p class="text-xs text-muted mt-2">
                    The secret itself is not stored — only a hash of it — so it cannot be shown again,
                    to anyone. Losing it means issuing a new one.
                </p>

                <div class="flex flex-col gap-1 mt-2">
                    <button class="btn btn-secondary btn-sm" id="rotate-key">
                        <i class="fa-solid fa-rotate"></i> Rotate key
                    </button>
                    <button class="btn btn-secondary btn-sm" id="provisioning-file">
                        <i class="fa-solid fa-download"></i> Download provisioning file
                    </button>
                    <button class="btn btn-danger btn-sm" id="revoke-key">
                        <i class="fa-solid fa-ban"></i> Revoke key
                    </button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Lifecycle</h2></div>
            <div class="card__body">
                <p class="text-xs text-muted mb-2">
                    A disabled terminal is rejected at authentication — before any attendance logic runs.
                    Devices are never deleted; their logs and the attendance they carried remain.
                </p>

                <div class="flex flex-col gap-1">
                    <?php foreach ([
                        'active'         => ['Set active', 'btn-secondary'],
                        'suspended'      => ['Suspend', 'btn-secondary'],
                        'disabled'       => ['Disable', 'btn-danger'],
                        'decommissioned' => ['Decommission', 'btn-danger'],
                    ] as $status => [$label, $class]): ?>
                        <?php
                        if ($status === $device['configured_status']) {
                            continue;
                        }

                        // "Set active" on an unclaimed terminal writes a column
                        // the badge does not read, so it looks like a button
                        // that does nothing. The board activates itself.
                        if ($status === 'active' && $device['claim_status'] !== 'claimed') {
                            continue;
                        }
                        ?>
                        <button class="btn <?= e($class) ?> btn-sm" data-status="<?= e($status) ?>"><?= e($label) ?></button>
                    <?php endforeach; ?>

                    <?php if ($device['claim_status'] !== 'claimed'): ?>
                        <p class="text-xs text-muted">
                            There is no “set active” here while the terminal is Pending — it becomes
                            active by claiming its key on first boot, not by an action on this page.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit modal -------------------------------------------------------------->
<div class="modal-backdrop" id="device-edit-modal">
    <div class="modal modal--sm" role="dialog" aria-modal="true">
        <div class="modal__header">
            <h3 class="modal__title">Edit terminal</h3>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>

        <form id="device-edit-form">
            <div class="modal__body">
                <div class="form-group">
                    <label for="de-name" class="required">Device name</label>
                    <input type="text" id="de-name" name="device_name" required maxlength="120"
                           value="<?= e($device['device_name']) ?>">
                </div>

                <div class="form-group">
                    <label for="de-classroom">Classroom</label>
                    <select id="de-classroom" name="classroom_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($classrooms as $room): ?>
                            <option value="<?= e($room['classroom_id']) ?>"
                                <?= (int) ($device['classroom_id'] ?? 0) === (int) $room['classroom_id'] ? 'selected' : '' ?>>
                                Room <?= e($room['room_number']) ?><?= $room['building'] ? ' — ' . e($room['building']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-help">
                        <?php if ($device['classroom_id'] === null): ?>
                            <span class="text-warning">
                                This terminal is not assigned to a room, so no schedule can be created
                                for it and the room it sits in still reads "None registered". Assigning
                                one here is all that is needed.
                            </span>
                        <?php else: ?>
                            Which room this terminal records attendance for. Enrolment and heartbeats
                            work without one; taps do not, because an attendance record is attributed
                            to a room.
                        <?php endif; ?>
                    </span>
                </div>

                <div class="form-group">
                    <label for="de-role">Role</label>
                    <select id="de-role" name="device_role">
                        <?php foreach (['both' => 'Entry and exit', 'entry' => 'Entry only', 'exit' => 'Exit only'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $device['device_role'] === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-help">
                        An entry-only or exit-only terminal overrides the automatic in/out decision:
                        the server records what the role says, not what the student's state implies.
                    </span>
                </div>

                <div class="form-group">
                    <label for="de-mac">MAC address</label>
                    <input type="text" id="de-mac" name="mac_address" maxlength="17"
                           style="font-family:var(--mono)"
                           value="<?= e($device['mac_address']) ?>"
                           <?= $device['claim_status'] === 'claimed' ? 'readonly' : '' ?>>
                    <span class="field-help">
                        <?php if ($device['claim_status'] === 'claimed'): ?>
                            Fixed: this terminal has already claimed its key with this address. Changing
                            it now would let a leaked provisioning file be pointed at other hardware,
                            which is exactly what the check prevents. Register the new board instead.
                        <?php else: ?>
                            The claim is refused unless this matches the board exactly, so a typo here
                            is what <span class="mono">CLAIM_IDENTITY_MISMATCH</span> means. The sketch
                            prints the board's real address at boot, right under its IP. Editable only
                            until the terminal claims for the first time.
                        <?php endif; ?>
                    </span>
                </div>

                <div class="form-group">
                    <label for="de-allowlist">IP allowlist</label>
                    <input type="text" id="de-allowlist" name="ip_allowlist" maxlength="255"
                           value="<?= e($device['ip_allowlist'] ?? '') ?>" style="font-family:var(--mono)"
                           placeholder="192.168.10.0/24">
                    <span class="field-help">
                        Comma-separated addresses or CIDR ranges. A signed request from outside the list
                        is refused and logged as an anomaly. Leave blank to accept from anywhere on the LAN.
                    </span>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="de-heartbeat">Heartbeat (s)</label>
                        <input type="number" id="de-heartbeat" name="heartbeat_interval_sec" min="10" max="600"
                               value="<?= e($device['heartbeat_interval_sec'] ?? 30) ?>">
                    </div>

                    <div class="form-group">
                        <label for="de-sync">Sync interval (s)</label>
                        <input type="number" id="de-sync" name="sync_interval_sec" min="10" max="3600"
                               value="<?= e($device['sync_interval_sec'] ?? 60) ?>">
                    </div>

                    <div class="form-group">
                        <label for="de-queue">Offline queue limit</label>
                        <input type="number" id="de-queue" name="offline_queue_limit" min="10" max="5000"
                               value="<?= e($device['offline_queue_limit'] ?? 500) ?>">
                    </div>

                    <div class="form-group">
                        <label for="de-note">Location note</label>
                        <input type="text" id="de-note" name="location_note" maxlength="255"
                               value="<?= e($device['location_note'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="modal__footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Credentials, shown once ------------------------------------------------->
<div class="modal-backdrop" id="credentials-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal__header"><h3 class="modal__title">New credentials — shown once</h3></div>
        <div class="modal__body">
            <div class="alert alert-danger">
                <span class="alert__icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div class="alert__body">
                    These values are displayed now and never again — not in this page, not in a log,
                    not to another administrator. Flash them to the terminal before closing this dialog.
                </div>
            </div>
            <pre class="credential-slip" id="credentials-body"></pre>
        </div>
        <div class="modal__footer">
            <button class="btn btn-secondary" type="button" id="credentials-copy"><i class="fa-solid fa-copy"></i> Copy</button>
            <button class="btn btn-secondary" type="button" id="credentials-save"><i class="fa-solid fa-download"></i> Save as file</button>
            <button class="btn btn-primary" type="button" id="credentials-done">I have flashed the terminal</button>
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
    const ID = <?= (int) $device['device_row_id'] ?>;
    const DEVICE_ID = <?= json_js((string) $device['device_id']) ?>;

    /* ---- heartbeat chart -------------------------------------------------- */
    const heartbeats = <?= json_js(array_reverse(array_map(static fn (array $h): array => [
        'at'     => (string) $h['created_at'],
        'signal' => $h['wifi_signal'] === null ? null : (int) $h['wifi_signal'],
        'queue'  => (int) $h['queue_depth'],
    ], $heartbeats))) ?>;

    LS.charts.line('chart-signal', heartbeats.map((row) => ({
        date:   row.at.slice(11, 16),
        // dBm is negative; plotting the magnitude keeps the axis readable and
        // the legend says which way is better.
        signal: row.signal === null ? 0 : Math.abs(row.signal),
        queue:  row.queue,
    })), {
        xKey: 'date',
        series: [
            { key: 'signal', label: 'Signal strength (|dBm|, lower is better)', color: '#2563EB' },
            { key: 'queue',  label: 'Queue depth', color: '#F59E0B' },
        ],
        title: 'Heartbeat history',
        emptyMessage: 'No heartbeats in the last 24 hours.',
    });

    /* ---- log filter ------------------------------------------------------- */
    const severity = document.getElementById('log-severity');

    if (severity) {
        severity.addEventListener('change', function () {
            document.querySelectorAll('#log-table tbody tr').forEach((row) => {
                row.hidden = this.value !== '' && row.dataset.severity !== this.value;
            });
        });
    }

    /* ---- connection test --------------------------------------------------- */
    document.getElementById('test-connection').addEventListener('click', async function () {
        LS.util.setBusy(this, true, 'Checking…');

        try {
            const response = await LS.http.post('/admin/devices/' + ID + '/test', {});
            const data = response.data;

            const badge = document.getElementById('health-badge');

            // Same wording as the server-rendered badge — a test that renamed
            // "Awaiting setup" back to "Pending" would undo the explanation
            // exactly when the administrator is looking for one.
            badge.textContent = ({
                online: 'Online', warning: 'Slow', offline: 'Offline',
                pending: 'Awaiting setup', disabled: 'Disabled',
            }[data.health]) || (data.health.charAt(0).toUpperCase() + data.health.slice(1));

            badge.className = 'badge ' + ({
                online: 'badge-success', warning: 'badge-warning',
                pending: 'badge-info', disabled: 'badge-neutral',
            }[data.health] || 'badge-danger');

            document.getElementById('health-meta').textContent =
                data.seconds_ago === null ? 'never sent a heartbeat' : data.seconds_ago + 's ago';

            /* Called on LS.toast, not lifted off it. The toast helpers are
             * written as `this.show(...)`, so selecting one with a ternary and
             * invoking the result detaches it from its object — `this` is then
             * undefined under strict mode and the call dies with "Cannot read
             * properties of undefined (reading 'show')", which surfaces as an
             * error toast for a test that actually succeeded. */
            if (data.health === 'online') {
                LS.toast.success(response.message);
            } else {
                LS.toast.warning(response.message);
            }
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(this, false);
        }
    });

    /* ---- edit --------------------------------------------------------------- */
    const editForm = document.getElementById('device-edit-form');

    editForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const button = editForm.querySelector('[type=submit]');
        LS.util.setBusy(button, true, 'Saving…');

        try {
            const response = await LS.http.put('/admin/devices/' + ID, LS.util.formData(editForm));
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            if (error.errors) LS.util.showFieldErrors(editForm, error.errors);
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(button, false);
        }
    });

    /* ---- key lifecycle ------------------------------------------------------ */
    document.getElementById('rotate-key').addEventListener('click', async function () {
        const confirmed = await LS.modal.confirm({
            title:   'Rotate the API key?',
            message: 'A new key and HMAC secret are issued immediately and shown once. The current key '
                     + 'keeps working for the grace window so you can reflash without downtime, then '
                     + 'auto-revokes. A new single-use claim token is issued at the same time.',
            confirmLabel: 'Rotate key',
            requirePassword: true,
        });

        if (!confirmed) return;

        LS.util.setBusy(this, true, 'Rotating…');

        try {
            const response = await LS.http.post('/admin/devices/' + ID + '/rotate-key', {
                confirm_password: confirmed.password,
            });
            LS.toast.success(response.message);
            showCredentials(response.data);
        } catch (error) {
            LS.toast.fromError(error);
        } finally {
            LS.util.setBusy(this, false);
        }
    });

    document.getElementById('revoke-key').addEventListener('click', async function () {
        const confirmed = await LS.modal.confirm({
            title:   'Revoke this terminal’s key?',
            message: 'The terminal stops being able to authenticate the moment this is applied. Any '
                     + 'taps still queued on it will fail to sync until a new key is flashed. Use this '
                     + 'if the device is lost, stolen, or you believe the secret has leaked.',
            confirmLabel:    'Revoke key',
            danger:          true,
            requirePassword: true,
            requirePhrase:   'REVOKE',
        });

        if (!confirmed) return;

        const reason = window.prompt('Reason for revocation (recorded in the key history):', '');

        if (!reason || reason.trim().length < 5) {
            LS.toast.warning('A reason of at least 5 characters is required.');
            return;
        }

        LS.util.setBusy(this, true, 'Revoking…');

        try {
            const response = await LS.http.post('/admin/devices/' + ID + '/revoke-key', {
                confirm_password: confirmed.password,
                reason: reason.trim(),
            });
            LS.toast.success(response.message);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            LS.toast.fromError(error);
            LS.util.setBusy(this, false);
        }
    });

    // Two buttons, one behaviour: the banner at the top is where an
    // administrator looking at a Pending terminal actually is, and sending
    // them hunting for the one further down is how it gets missed.
    async function downloadProvisioning(button) {
        const confirmed = await LS.modal.confirm({
            title:   'Download a provisioning file?',
            message: 'This issues a fresh key pair and claim token — the previous key enters its grace '
                     + 'window and then auto-revokes. Download it only when you are about to flash the '
                     + 'terminal. The download is recorded in the audit log.',
            confirmLabel:    'Generate and download',
            requirePassword: true,
        });

        if (!confirmed) return;

        // Posted as a real form so the browser handles the file download; the
        // JSON never passes through the page and cannot end up in a console log.
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/admin/devices/' + ID + '/provisioning-file';
        form.style.display = 'none';

        [['_csrf', LS.config.csrfToken], ['confirm_password', confirmed.password]].forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        setTimeout(() => { form.remove(); window.location.reload(); }, 2500);
    }

    /* Adopting the MAC the board actually presented. Editable only while the
       terminal has never claimed, which is exactly the state this panel
       appears in — so this cannot re-point an already-activated terminal. */
    const adopt = document.getElementById('adopt-mac');

    if (adopt) {
        adopt.addEventListener('click', async function () {
            const mac = this.dataset.mac;

            const confirmed = await LS.modal.confirm({
                title:   'Register this terminal as ' + mac + '?',
                message: 'The board presenting this address becomes the one allowed to activate '
                       + 'this terminal.\n\nOnly do this if it is the board you mean to install '
                       + 'here. If it is not, some other board is holding this terminal\'s '
                       + 'provisioning file, and the key should be revoked instead.',
                confirmLabel: 'Yes, that is my board',
            });

            if (!confirmed) return;

            LS.util.setBusy(this, true, 'Saving…');

            try {
                const response = await LS.http.put('/admin/devices/' + ID, {
                    device_name: <?= json_js($device['device_name']) ?>,
                    mac_address: mac,
                });
                LS.toast.success(response.message + ' Power-cycle the board — it will claim itself.');
                setTimeout(() => window.location.reload(), 1200);
            } catch (error) {
                LS.toast.fromError(error);
                LS.util.setBusy(this, false);
            }
        });
    }

    ['provisioning-file', 'provisioning-file-top'].forEach((id) => {
        const button = document.getElementById(id);

        // The banner copy only exists while the terminal is unclaimed.
        if (button) button.addEventListener('click', () => downloadProvisioning(button));
    });

    /* ---- status ------------------------------------------------------------- */
    document.querySelectorAll('[data-status]').forEach((button) => {
        button.addEventListener('click', async function () {
            const status = this.dataset.status;
            const severe = status === 'disabled' || status === 'decommissioned';

            const confirmed = await LS.modal.confirm({
                title:   'Set this terminal to ' + status + '?',
                message: severe
                    ? 'Authentication is refused before any attendance logic runs, so every tap on this '
                      + 'terminal stops immediately. Existing records are untouched.'
                    : 'The terminal keeps its credentials and history; only its operational status changes.',
                confirmLabel:    'Set ' + status,
                danger:          severe,
                requirePassword: severe,
            });

            if (!confirmed) return;

            LS.util.setBusy(this, true, 'Applying…');

            try {
                const response = await LS.http.post('/admin/devices/' + ID + '/status', {
                    status: status,
                    reason: 'Changed from the device detail page.',
                    confirm_password: confirmed.password || undefined,
                });
                LS.toast.success(response.message);
                setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                LS.toast.fromError(error);
                LS.util.setBusy(this, false);
            }
        });
    });

    /* ---- credential display -------------------------------------------------- */
    function showCredentials(data) {
        const text = [
            'L-SIAMS TERMINAL CREDENTIALS',
            '============================',
            '',
            'Device ID   : ' + DEVICE_ID,
            'Key ID      : ' + data.key_id,
            'API key     : ' + data.api_key,
            'HMAC secret : ' + data.hmac_secret,
            'Claim token : ' + data.claim_token,
            '',
            'Flash these into the terminal, then power it on to claim itself.',
            'The claim token is single-use. None of these values can be retrieved again.',
        ].join('\n');

        document.getElementById('credentials-body').textContent = text;
        LS.modal.open('credentials-modal');

        document.getElementById('credentials-save').onclick = function () {
            const blob = new Blob([JSON.stringify(data.provisioning, null, 2)], { type: 'application/json' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'lsiams-provisioning-' + DEVICE_ID + '.json';
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 2000);
        };
    }

    document.getElementById('credentials-copy').addEventListener('click', function () {
        LS.util.copy(document.getElementById('credentials-body').textContent,
            'Credentials copied. Clear your clipboard when you are done.');
    });

    document.getElementById('credentials-done').addEventListener('click', function () {
        // Wiping the node before reloading keeps the secret out of the DOM for
        // the moments between the click and the navigation.
        document.getElementById('credentials-body').textContent = '';
        window.location.reload();
    });
})();
</script>
<?php $__view->stop(); ?>
