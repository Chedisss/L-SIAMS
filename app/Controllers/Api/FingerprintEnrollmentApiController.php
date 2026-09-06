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
 * The device is told which slot to write and reports back which slot it wrote.
 *
 * It now also reports back the template itself, which it deliberately did not
 * before. A template that lives only in one sensor's flash makes its teacher a
 * stranger to every other classroom, and there was no fix for that short of
 * walking them round the building. The upload is what lets FingerprintSyncService
 * put the same finger on every terminal.
 *
 * That is a real change in exposure and it is documented as one — see migration
 * 018 and docs/SECURITY.md. The field is optional, so a terminal on older
 * firmware still enrols successfully; its teacher simply stays local to that
 * sensor.
 */
final class FingerprintEnrollmentApiController extends Controller
{
    /** GET /api/fingerprint/enrollment — is there anything to do? */
    public function pending(Request $httpRequest): Response
    {
        $device  = Auth::device();
        $request = FingerprintEnrollmentService::claimForDevice($device);

        // Slots holding a template that nothing owns — a registration that was
        // captured and then abandoned. The terminal deletes them and confirms,
        // which is the only way the slot becomes genuinely free again: the
        // server cannot reach into the sensor's flash by itself.
        $discard = FingerprintEnrollmentService::discardSlotsFor((int) $device['id']);

        if ($request === null) {
            return $this->json([
                'enrollment'    => null,
                'discard_slots' => $discard,
                'poll_seconds'  => (int) Config::get('attendance.fingerprint.enrollment_poll_seconds', 2),
            ], 'Nothing to enrol.');
        }

        $name = (string) ($request['display_name'] ?? 'teacher');

        return $this->json([
            'enrollment' => [
                'request_id'         => (int) $request['request_id'],
                'sensor_template_id' => (int) $request['sensor_template_id'],
                'teacher_name'       => $name,
                'employee_number'    => (string) ($request['employee_number'] ?? ''),
                'stage'              => (string) $request['stage'],
            ],
            'discard_slots' => $discard,
            'poll_seconds'  => (int) Config::get('attendance.fingerprint.enrollment_poll_seconds', 2),
            // What the terminal should put on its display while it waits for a
            // finger. Sent from here so the wording stays in one place rather
            // than being duplicated into every firmware build.
            'display_line_1' => 'ENROLL FINGER',
            'display_line_2' => mb_substr($name, 0, 20),
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
            // The template the sensor handed back, base64. Optional: a
            // terminal on older firmware sends nothing and the enrolment still
            // stands, it just cannot be copied to the other rooms.
            'template'           => 'nullable|string|max:4096',
            // Whether the terminal searched its own sensor before writing.
            // Nullable here and enforced in the service, so the refusal can
            // explain itself rather than coming back as a field error.
            'duplicate_checked'  => 'nullable|bool',
        ], [
            'sensor_template_id' => 'Sensor slot',
            'template'           => 'Template',
        ]);

        $result = FingerprintEnrollmentService::complete(
            (int) $data['request_id'],
            (int) $device['id'],
            (int) $data['sensor_template_id'],
            isset($data['quality_score']) ? (int) $data['quality_score'] : null,
            (int) ($data['sample_count'] ?? 0),
            (string) ($data['template'] ?? ''),
            (bool) ($data['duplicate_checked'] ?? false)
        );

        return $this->json([
            'status'         => $result['status'],
            'display_line_1' => 'ENROLLED',
            'display_line_2' => mb_substr((string) ($result['display_name'] ?? ''), 0, 20),
            'led'            => 'green',
            'buzzer'         => 'short',
        ], 'Fingerprint enrolled.');
    }

    /**
     * POST /api/fingerprint/enrollment/discarded
     *
     * The terminal confirming it has deleted a template it was told to drop.
     * Only then is the slot handed out again — trusting the instruction rather
     * than the confirmation would allocate a slot that still holds somebody's
     * print, and the next enrolment would silently overwrite it.
     */
    public function discarded(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'sensor_template_id' => 'required|int|between:1,999',
        ], [
            'sensor_template_id' => 'Sensor slot',
        ]);

        FingerprintEnrollmentService::confirmDiscarded(
            (int) $device['id'],
            (int) $data['sensor_template_id']
        );

        return $this->json([], 'Slot released.');
    }

    /** POST /api/fingerprint/enrollment/failed */
    /**
     * POST /api/fingerprint/enrollment/duplicate
     *
     * The terminal searched its own sensor before enrolling and found the
     * finger already there. It reports the slot; only the server can say whose
     * that is, because a slot number is meaningless without knowing the sensor.
     *
     * Answers `proceed` rather than an error status: a match against the
     * teacher being enrolled is somebody re-enrolling, which is allowed, and
     * the firmware needs to be told to carry on rather than to stop.
     */
    public function duplicate(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id'   => 'required|int',
            'matched_slot' => 'required|int|between:1,999',
        ], [
            'matched_slot' => 'Matched slot',
        ]);

        $result = FingerprintEnrollmentService::duplicateCheck(
            (int) $data['request_id'],
            (int) $device['id'],
            (int) $data['matched_slot']
        );

        return $this->json([
            'duplicate'      => $result['duplicate'],
            'proceed'        => !$result['duplicate'],
            'teacher_name'   => $result['teacher_name'],
            'display_line_1' => $result['duplicate'] ? 'ALREADY ENROLLED' : 'CONTINUE',
            'display_line_2' => mb_substr($result['teacher_name'], 0, 20),
            'led'            => $result['duplicate'] ? 'red' : 'blue',
            'buzzer'         => $result['duplicate'] ? 'rapid' : 'none',
        ], $result['duplicate'] ? $result['message'] : 'Not a duplicate; continue.');
    }

    public function failed(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id' => 'required|int',
            'reason'     => 'required|string|max:255',
            // Optional so firmware that does not send it still reports its
            // failures — the wizard then shows the reason without the extra
            // emphasis, which is the right degradation. Only the codes the
            // wizard knows change how it renders; anything else is stored and
            // displayed as an ordinary failure.
            'code'       => 'nullable|string|max:40',
        ]);

        FingerprintEnrollmentService::fail(
            (int) $data['request_id'],
            (int) $device['id'],
            (string) $data['reason'],
            isset($data['code']) && $data['code'] !== '' ? (string) $data['code'] : null
        );

        return $this->json([
            'display_line_1' => 'ENROLL FAILED',
            'display_line_2' => '',
            'led'            => 'red',
            'buzzer'         => 'rapid',
        ], 'Enrolment marked as failed.');
    }
}
