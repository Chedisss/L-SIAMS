<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\FingerprintSyncService;

/**
 * Handing a terminal the templates it is missing.
 *
 * A sensor can only match what is in its own flash, so a teacher enrolled in
 * Room 101 was unknown to the reader in Room 102. This is how the template
 * gets there: the terminal asks what it is short of, writes what it is given
 * into its sensor, and confirms.
 *
 * Pull, not push — the same shape as enrolment and card issuance. The server
 * never opens a connection to a terminal, so a terminal that is switched off
 * collects its backlog when it returns rather than needing the server to track
 * retries.
 *
 * This route sends biometric templates over the wire, which nothing else in
 * this system does. What guards it:
 *
 *   · the full device-auth chain — a registered device id, a valid API key, an
 *     HMAC signature over the request, a fresh timestamp and a single-use
 *     nonce. A terminal cannot ask on another terminal's behalf, because the
 *     device it is answered for comes from the authenticated identity and
 *     never from the request body.
 *   · one template per call, addressed to one slot on that terminal.
 *   · TLS in any deployment that has it. On a plain-HTTP LAN this is the one
 *     endpoint where that choice actually costs something, and DEPLOYMENT.md
 *     says so.
 */
final class FingerprintSyncApiController extends Controller
{
    /**
     * GET /api/fingerprint/sync — the next template this terminal is missing.
     *
     * One at a time. Writing a template is a multi-packet UART transfer that
     * blocks the terminal's loop; a terminal that disappears for the length of
     * a twenty-template backlog is a terminal that misses taps. One per poll
     * keeps the reader responsive while the backlog drains.
     */
    public function pending(Request $httpRequest): Response
    {
        $device = Auth::device();
        $next   = FingerprintSyncService::nextPendingFor((int) $device['id']);

        if ($next === null) {
            return $this->json([
                'template'     => null,
                'poll_seconds' => (int) Config::get('attendance.fingerprint.sync_poll_seconds', 15),
            ], 'Nothing to sync.');
        }

        return $this->json([
            'template' => [
                'slot'         => $next['slot'],
                'bytes'        => $next['bytes'],
                'data'         => $next['template'],
                'teacher_name' => $next['teacher'],
            ],
            'poll_seconds' => (int) Config::get('attendance.fingerprint.sync_poll_seconds', 15),
        ], 'Template to store.');
    }

    /**
     * POST /api/fingerprint/sync/stored — the terminal confirming it wrote one.
     *
     * The confirmation is what marks the slot present, not the fact that the
     * template was sent. A sensor whose flash has stopped accepting writes
     * acknowledges a store and keeps nothing, so the firmware reads the slot
     * back before calling this — and a slot marked present on the strength of
     * having been *offered* would leave a teacher unable to open their class
     * with nothing anywhere saying why.
     */
    public function stored(Request $httpRequest): Response
    {
        $device = Auth::device();

        $data = $this->validate($httpRequest, [
            'slot'   => 'required|int|between:1,999',
            'stored' => 'required|bool',
            'reason' => 'nullable|string|max:255',
        ], [
            'slot' => 'Sensor slot',
        ]);

        $slot = (int) $data['slot'];

        if ((bool) $data['stored']) {
            FingerprintSyncService::markStored((int) $device['id'], $slot);

            return $this->json([], 'Template recorded as present.');
        }

        FingerprintSyncService::markFailed(
            (int) $device['id'],
            $slot,
            (string) ($data['reason'] ?? 'The terminal reported the write failed.')
        );

        return $this->json([], 'Failure recorded.');
    }
}
