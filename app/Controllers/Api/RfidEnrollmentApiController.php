<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\RfidEnrollmentService;

/**
 * The terminal's half of card issuance.
 *
 * An idle terminal polls `pending`; when it is handed a request it takes the
 * reader for itself, waits for a card, reports each step through `progress`,
 * and finishes with `captured` or `failed`. Every route here runs the full
 * device-auth chain, so a terminal can only speak about a request addressed to
 * itself.
 *
 * Deliberately not an attendance route: reading a card for issuance records no
 * attendance, needs no open session, and needs no classroom. A terminal on the
 * registrar's desk is exactly where this should run.
 */
final class RfidEnrollmentApiController extends Controller
{
    /** GET /api/rfid/enrollment — is there a card to read? */
    public function pending(Request $httpRequest): Response
    {
        $device  = Auth::device();
        $request = RfidEnrollmentService::claimForDevice($device);

        if ($request === null) {
            return $this->json([
                'enrollment'   => null,
                'poll_seconds' => (int) Config::get('attendance.rfid.enrollment_poll_seconds', 2),
            ], 'Nothing to read.');
        }

        $name = (string) ($request['display_name'] ?? 'the next card');

        return $this->json([
            'enrollment' => [
                'request_id'  => (int) $request['request_id'],
                'label'       => $name,
                'student_number' => (string) ($request['student_number'] ?? ''),
                'stage'       => (string) $request['stage'],
                // How long the terminal should hold the reader before giving
                // up. Sent from here so the two ends cannot disagree about it.
                'wait_seconds' => (int) Config::get('attendance.rfid.enrollment_wait_seconds', 45),
            ],
            'poll_seconds' => (int) Config::get('attendance.rfid.enrollment_poll_seconds', 2),
            'display_line_1' => 'PRESENT CARD',
            'display_line_2' => mb_substr($name, 0, 20),
        ], 'Card read pending.');
    }

    /** POST /api/rfid/enrollment/progress */
    public function progress(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id' => 'required|int',
            'stage'      => 'required|string|max:40',
            'message'    => 'nullable|string|max:255',
        ]);

        $result = RfidEnrollmentService::progress(
            (int) $data['request_id'],
            (int) $device['id'],
            (string) $data['stage'],
            isset($data['message']) && $data['message'] !== '' ? (string) $data['message'] : null
        );

        return $this->json(['status' => $result['status'], 'stage' => $result['stage']], 'Progress recorded.');
    }

    /** POST /api/rfid/enrollment/captured */
    public function captured(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id' => 'required|int',
            'card_uid'   => 'required|uid',
        ], [
            'card_uid' => 'Card UID',
        ]);

        $result = RfidEnrollmentService::capture(
            (int) $data['request_id'],
            (int) $device['id'],
            (string) $data['card_uid']
        );

        return $this->json([
            'status'         => $result['status'],
            'card_uid'       => $result['card_uid'],
            'display_line_1' => 'CARD READ',
            'display_line_2' => mb_substr((string) $result['card_uid'], 0, 20),
            'led'            => 'green',
            'buzzer'         => 'short',
        ], 'Card read.');
    }

    /** POST /api/rfid/enrollment/failed */
    public function failed(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'request_id' => 'required|int',
            'reason'     => 'nullable|string|max:255',
        ]);

        RfidEnrollmentService::fail(
            (int) $data['request_id'],
            (int) $device['id'],
            (string) ($data['reason'] ?? 'The terminal reported a failure.')
        );

        return $this->json([
            'display_line_1' => 'NO CARD',
            'display_line_2' => '',
            'led'            => 'red',
            'buzzer'         => 'long',
        ], 'Failure recorded.');
    }
}
