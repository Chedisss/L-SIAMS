<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\DeviceService;
use App\Services\ImportService;

/**
 * IoT device management (Parts 2, 4 and 19.3/19.6).
 *
 * Key material appears in exactly two responses in this controller — the one
 * that registers a device and the one that rotates its key — and nowhere else,
 * ever. Everything destructive requires password re-authentication and a reason.
 */
final class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $fleet = DeviceService::fleet();

        if ($request->wantsJson()) {
            return $this->json(['rows' => $fleet, 'summary' => DeviceService::fleetSummary()]);
        }

        return $this->view('admin.devices.index', [
            'pageTitle'  => 'IoT Devices',
            'devices'    => $fleet,
            'summary'    => DeviceService::fleetSummary(),
            'classrooms' => AcademicStructureService::classrooms(true),
            'agingKeys'  => ApiKeyService::agingKeys(),
            'suggestedId' => DeviceService::nextDeviceId(),
        ]);
    }

    public function show(Request $request): Response
    {
        $deviceRowId = $request->routeInt('id');

        $device = Database::instance()->selectOne(
            'SELECT * FROM v_device_status WHERE device_row_id = :id',
            ['id' => $deviceRowId]
        );

        if ($device === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Device not found.');
        }

        $payload = [
            'device'     => $device,
            'logs'       => DeviceService::logs($deviceRowId, 50),
            'heartbeats' => DeviceService::heartbeatHistory($deviceRowId, 24),
            'keyHistory' => ApiKeyService::historyForDevice($deviceRowId),
        ];

        if ($request->wantsJson()) {
            return $this->json($payload);
        }

        return $this->view('admin.devices.show', $payload + [
            'pageTitle' => (string) $device['device_id'],
        ]);
    }

    /**
     * Register a terminal. The response carries the only copy of the API key,
     * the HMAC secret and the claim token that will ever exist.
     */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'device_name'   => 'required|string|max:120|no_html',
            'device_id'     => 'nullable|string|max:32|code',
            'mac_address'   => 'required|mac',
            'serial_number' => 'nullable|string|max:60|slug',
            'classroom_id'  => 'nullable|int',
            'device_role'   => 'nullable|in:entry,exit,both',
            'firmware_version' => 'nullable|string|max:20',
            'ip_allowlist'  => 'nullable|string|max:255',
            'timezone'      => 'nullable|string|max:64',
            'heartbeat_interval_sec' => 'nullable|int|between:10,600',
            'sync_interval_sec'      => 'nullable|int|between:10,3600',
            'offline_queue_limit'    => 'nullable|int|between:10,5000',
            'location_note' => 'nullable|string|max:255|no_html',
            'confirm_mac_reuse' => 'nullable|bool',
        ], [
            'device_name' => 'Device name',
            'mac_address' => 'MAC address',
        ]);

        $registered = DeviceService::register($data, $this->requireUserId());

        $provisioning = DeviceService::buildProvisioningFile(
            (int) $registered['device_row_id'],
            $registered['credentials'],
            $registered['claim']
        );

        return $this->json([
            'device_row_id' => $registered['device_row_id'],
            'device_id'     => $registered['device_id'],
            'api_key'       => $registered['credentials']['api_key'],
            'hmac_secret'   => $registered['credentials']['hmac_secret'],
            'key_id'        => $registered['credentials']['key_id'],
            'claim_token'   => $registered['claim']['token'],
            'claim_expires_at' => $registered['claim']['expires_at'],
            'provisioning'  => $provisioning,
        ], 'Device registered. Copy or download these credentials now — they are shown only once.', 201);
    }

    public function update(Request $request): Response
    {
        $deviceRowId = $request->routeInt('id');

        $data = $this->validate($request, [
            'device_name'   => 'required|string|max:120|no_html',
            'classroom_id'  => 'nullable|int',
            'device_role'   => 'nullable|in:entry,exit,both',
            'ip_allowlist'  => 'nullable|string|max:255',
            'heartbeat_interval_sec' => 'nullable|int|between:10,600',
            'sync_interval_sec'      => 'nullable|int|between:10,3600',
            'offline_queue_limit'    => 'nullable|int|between:10,5000',
            'location_note' => 'nullable|string|max:255|no_html',
        ]);

        $db       = Database::instance();
        $existing = $db->selectOne('SELECT * FROM devices WHERE id = :id AND deleted_at IS NULL', ['id' => $deviceRowId]);

        if ($existing === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Device not found.');
        }

        $update = [
            'device_name'   => (string) $data['device_name'],
            'classroom_id'  => empty($data['classroom_id']) ? null : (int) $data['classroom_id'],
            'device_role'   => (string) ($data['device_role'] ?? $existing['device_role']),
            'ip_allowlist'  => $data['ip_allowlist'] ?? null,
            'heartbeat_interval_sec' => (int) ($data['heartbeat_interval_sec'] ?? $existing['heartbeat_interval_sec']),
            'sync_interval_sec'      => (int) ($data['sync_interval_sec'] ?? $existing['sync_interval_sec']),
            'offline_queue_limit'    => (int) ($data['offline_queue_limit'] ?? $existing['offline_queue_limit']),
            'location_note' => $data['location_note'] ?? null,
            'updated_at'    => \App\Core\Clock::nowString(),
        ];

        $db->update('devices', $update, ['id' => $deviceRowId]);

        AuditService::logChange(
            AuditService::DEVICE_UPDATED,
            'devices',
            'device',
            $deviceRowId,
            $existing,
            $update
        );

        return $this->json(['device_row_id' => $deviceRowId], 'Device updated successfully.');
    }

    /**
     * Rotate the API key. The old key stays valid for the grace window so the
     * terminal can be reflashed without downtime (Part 19.4).
     */
    public function rotateKey(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $deviceRowId = $request->routeInt('id');
        $credentials = ApiKeyService::rotate($deviceRowId, $this->requireUserId());
        $claim       = DeviceService::regenerateClaim($deviceRowId, $this->requireUserId());

        $graceHours = (int) \App\Core\Config::get('security.api_key.rotation_grace_hours', 24);

        return $this->json([
            'api_key'      => $credentials['api_key'],
            'hmac_secret'  => $credentials['hmac_secret'],
            'key_id'       => $credentials['key_id'],
            'claim_token'  => $claim['token'],
            'provisioning' => DeviceService::buildProvisioningFile($deviceRowId, $credentials, $claim),
        ], sprintf(
            'New key issued. The previous key keeps working for %d hour(s), then auto-revokes.',
            $graceHours
        ));
    }

    public function revokeKey(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $data = $this->validate($request, [
            'reason' => 'required|string|min:5|max:255|no_html',
        ], ['reason' => 'Revocation reason']);

        $deviceRowId = $request->routeInt('id');

        $keys = Database::instance()->select(
            "SELECT api_key_id FROM api_keys WHERE device_row_id = :id AND status IN ('active','rotating')",
            ['id' => $deviceRowId]
        );

        foreach ($keys as $key) {
            ApiKeyService::revoke((int) $key['api_key_id'], (string) $data['reason'], $this->requireUserId());
        }

        return $this->json(
            ['revoked' => count($keys)],
            'API key revoked. The device will be refused on its very next request.'
        );
    }

    public function keyHistory(Request $request): Response
    {
        return $this->json(['rows' => ApiKeyService::historyForDevice($request->routeInt('id'))]);
    }

    /**
     * Re-issue a provisioning file. This mints a *new* key pair, because the
     * original secret is unrecoverable by design — there is nothing to re-emit.
     */
    public function provisioningFile(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $deviceRowId = $request->routeInt('id');
        $credentials = ApiKeyService::rotate($deviceRowId, $this->requireUserId());
        $claim       = DeviceService::regenerateClaim($deviceRowId, $this->requireUserId());
        $payload     = DeviceService::buildProvisioningFile($deviceRowId, $credentials, $claim);

        AuditService::log(
            AuditService::DEVICE_PROVISIONING_DOWNLOADED,
            'devices',
            'device',
            $deviceRowId,
            null,
            ['device_id' => $payload['device_id']],
            'Provisioning file regenerated and downloaded. A new key pair was issued.'
        );

        return Response::attachment(
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            sprintf('lsiams-provisioning-%s.json', $payload['device_id']),
            'application/json'
        );
    }

    public function setStatus(Request $request): Response
    {
        $status = $request->string('status', '');

        if (!in_array($status, ['active', 'disabled', 'suspended', 'decommissioned'], true)) {
            return $this->fail('INVALID_STATUS', 'Choose active, disabled, suspended or decommissioned.', 422);
        }

        if (in_array($status, ['disabled', 'decommissioned'], true)) {
            $this->requirePasswordConfirmation($request);
        }

        $reason = $request->string('reason', 'Status changed by administrator.');

        DeviceService::setStatus($request->routeInt('id'), $status, $reason, $this->requireUserId());

        return $this->json([], sprintf('Device set to %s.', $status));
    }

    /**
     * Connectivity test. There is no inbound channel to an ESP32 in this
     * architecture — it always initiates — so "test connection" reports on the
     * heartbeat record rather than pretending to ping the device.
     */
    public function testConnection(Request $request): Response
    {
        $device = Database::instance()->selectOne(
            'SELECT * FROM v_device_status WHERE device_row_id = :id',
            ['id' => $request->routeInt('id')]
        );

        if ($device === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Device not found.');
        }

        $seconds = $device['seconds_since_heartbeat'] === null
            ? null
            : (int) $device['seconds_since_heartbeat'];

        return $this->json([
            'health'          => $device['health'],
            'last_heartbeat'  => $device['last_heartbeat_at'],
            'seconds_ago'     => $seconds,
            'ip_address'      => $device['ip_address'],
            'firmware'        => $device['firmware_version'],
            'wifi_signal'     => $device['wifi_signal'],
            'queue_depth'     => $device['queue_depth'],
            'active_session'  => $device['active_session_code'],
        ], match ((string) $device['health']) {
            'online'  => sprintf('Terminal is online (last heartbeat %ds ago).', $seconds ?? 0),
            'warning' => sprintf('Heartbeat is late — %ds since the last one.', $seconds ?? 0),
            'pending' => 'Terminal has never completed first-boot activation.',
            'disabled' => 'Terminal is administratively disabled.',
            default   => 'Terminal is offline. Check power and network in that room.',
        });
    }

    public function importForm(Request $request): Response
    {
        return $this->view('admin.devices.import', ['pageTitle' => 'Bulk Register Devices']);
    }

    public function importTemplate(Request $request): Response
    {
        return Response::attachment(
            ImportService::template('devices'),
            'lsiams-device-import-template.csv',
            'text/csv; charset=UTF-8'
        );
    }

    public function importPreview(Request $request): Response
    {
        $file = $request->file('file');

        if ($file === null) {
            return $this->fail('FILE_REQUIRED', 'Choose a CSV or Excel file.', 422);
        }

        $parsed  = ImportService::parseUpload($file, 'devices');
        $preview = ImportService::previewDevices($parsed['rows']);

        return $this->json($preview, sprintf(
            '%d device(s) ready to register, %d with problems.',
            $preview['summary']['valid'],
            $preview['summary']['invalid']
        ));
    }

    /**
     * Commit a bulk registration and return a ZIP of provisioning files —
     * offered exactly once (Part 19.7).
     */
    public function importCommit(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        /** @var list<array<string,mixed>> $records */
        $records = $request->input('records', []);

        if (!is_array($records) || $records === []) {
            return $this->fail('NO_RECORDS', 'There are no valid rows to register.', 422);
        }

        $revalidated = ImportService::previewDevices(array_map(
            static fn (array $r): array => array_map('strval', $r),
            $records
        ));

        if ($revalidated['invalid'] !== []) {
            return Response::json([
                'success' => false,
                'code'    => 'IMPORT_VALIDATION_FAILED',
                'message' => 'Some rows became invalid. Re-upload the file and review the preview.',
                'data'    => ['invalid' => $revalidated['invalid']],
            ], 422);
        }

        $result = ImportService::commitDevices($revalidated['valid'], $this->requireUserId());
        $zip    = $this->buildProvisioningZip($result['provisioning']);

        return $this->json([
            'registered'   => $result['registered'],
            'zip_base64'   => base64_encode($zip),
            'zip_filename' => sprintf('lsiams-provisioning-%s.zip', date('Ymd-His')),
        ], sprintf(
            '%d terminal(s) registered. Download the provisioning bundle now — the keys cannot be shown again.',
            $result['registered']
        ));
    }

    /** @param list<array<string,mixed>> $provisioning */
    private function buildProvisioningZip(array $provisioning): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lsiams_prov_');

        if ($tmp === false) {
            throw new HttpException(500, 'ZIP_FAILED', 'Could not build the provisioning bundle.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new HttpException(500, 'ZIP_FAILED', 'Could not build the provisioning bundle.');
        }

        $manifest = [];

        foreach ($provisioning as $file) {
            $zip->addFromString(
                sprintf('%s.json', $file['device_id']),
                (string) json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $manifest[] = [
                'device_id'   => $file['device_id'],
                'device_name' => $file['device_name'],
                'classroom'   => $file['classroom_name'],
                'role'        => $file['device_role'],
                // The manifest deliberately omits the key material.
                'claim_expires_at' => $file['claim_expires_at'],
            ];
        }

        $zip->addFromString('MANIFEST.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->addFromString('README.txt', implode("\n", [
            'L-SIAMS device provisioning bundle',
            '',
            'Each .json file contains the credentials for one attendance terminal.',
            'Flash it into the matching ESP32 using the firmware provisioning step.',
            '',
            'These credentials cannot be recovered. If a file is lost, rotate the',
            "device's key from Admin → IoT Devices and regenerate the file.",
            '',
            'Treat this bundle as you would a set of physical keys to the building.',
        ]));

        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }
}
