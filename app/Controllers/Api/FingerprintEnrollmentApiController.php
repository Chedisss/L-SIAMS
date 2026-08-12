<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\FingerprintEnrollmentService;

/**
 * The terminal's half of fingerprint enrolment.
 *
 * An idle terminal polls `pending`; when it is handed a request it runs the
 * R307's capture cycle, reports each step through `progress`, and finishes with
 * `complete` or `failed`. Every route here runs the full device-auth chain, so
 * a terminal can only ever speak about a request addressed to itself.
 *
 * No template crosses this boundary. The device is told which slot to write and
 * reports back which slot it wrote; the fingerprint itself never leaves the
 * sensor's flash.
 */
final class FingerprintEnrollmentApiController extends Controller
{
    /** GET /api/fingerprint/enrollment — is there anything to do? */
    public function pending(Request $request): Response
    {
        $device  = Auth::device();
        $request = FingerprintEnrollmentService::claimForDevice($device);

        if ($request === null) {
            return $this->json([
                'enrollment'   => null,
                'poll_seconds' => (int) Config::get('attendance.fingerprint.enrollment_poll_seconds', 2),
            ], 'Nothing to enrol.');
        }

        return $this->json([
            'enrollment' => [
                'request_id'         => (int) $request['request_id'],
                'sensor_template_id' => (int) $request['sensor_template_id'],
                'teacher_name'       => trim($request['first_name'] . ' ' . $request['last_name']),
                'employee_number'    => (string) $request['employee_number'],
                'stage'              => (string) $request['stage'],
            ],
            'poll_seconds' => (int) Config::get('attendance.fingerprint.enrollment_poll_seconds', 2),
            // What the terminal should put on its display while it waits for a
            // finger. Sent from here so the wording stays in one place rather
            // than being duplicated into every firmware build.
            'display_line_1' => 'ENROLL FINGER',
            'display_line_2' => mb_substr((string) $request['last_name'], 0, 20),
        ], 'Enrolment pending.');
    }

    /** POST /api/fingerprint/enrollment/progress */
    public function progress(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id' => 'required|int',
            'stage'      => 'required|string|max:40',
            'message'    => 'nullable|string|max:255',
        ]);

        $result = FingerprintEnrollmentService::progress(
            (int) $data['request_id'],
            (int) $device['id'],
            (string) $data['stage'],
            isset($data['message']) && $data['message'] !== '' ? (string) $data['message'] : null
        );

        return $this->json(['status' => $result['status'], 'stage' => $result['stage']], 'Progress recorded.');
    }

    /** POST /api/fingerprint/enrollment/complete */
    public function complete(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id'         => 'required|int',
            'sensor_template_id' => 'required|int|between:1,999',
            'quality_score'      => 'nullable|int|between:0,255',
            'sample_count'       => 'nullable|int|between:0,10',
        ], [
            'sensor_template_id' => 'Sensor slot',
        ]);

        $result = FingerprintEnrollmentService::complete(
            (int) $data['request_id'],
            (int) $device['id'],
            (int) $data['sensor_template_id'],
            isset($data['quality_score']) ? (int) $data['quality_score'] : null,
            (int) ($data['sample_count'] ?? 0)
        );

        return $this->json([
            'status'         => $result['status'],
            'display_line_1' => 'ENROLLED',
            'display_line_2' => mb_substr((string) $result['last_name'], 0, 20),
            'led'            => 'green',
            'buzzer'         => 'short',
        ], 'Fingerprint enrolled.');
    }

    /** POST /api/fingerprint/enrollment/failed */
    public function failed(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id' => 'required|int',
            'reason'     => 'required|string|max:255',
        ]);

        FingerprintEnrollmentService::fail(
            (int) $data['request_id'],
            (int) $device['id'],
            (string) $data['reason']
        );

        return $this->json([
            'display_line_1' => 'ENROLL FAILED',
            'display_line_2' => '',
            'led'            => 'red',
            'buzzer'         => 'rapid',
        ], 'Enrolment marked as failed.');
    }
}
