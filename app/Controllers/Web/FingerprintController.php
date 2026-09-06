<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Services\FingerprintEnrollmentService;
use App\Services\FingerprintService;
use App\Services\FingerprintSyncService;
use App\Services\TeacherService;

/**
 * Fingerprint enrolment management.
 *
 * The capture itself happens on the R307 attached to a terminal; this
 * controller records the resulting sensor slot and its owner, and since
 * migration 018 the encrypted template as well, so one enrolment can be copied
 * to every terminal the teacher teaches at.
 */
final class FingerprintController extends Controller
{
    public function index(Request $request): Response
    {
        $enrolments = FingerprintService::listEnrolments();

        if ($request->wantsJson()) {
            return $this->json(['rows' => $enrolments]);
        }

        // "Awaiting enrolment" means no template row exists — not merely that
        // teachers.fingerprint_status says so. The two can disagree: disabling
        // an enrolment sets the column to 'disabled' while the template stays,
        // and a row edited outside the application can drift either way. Keying
        // off the template table is what makes the two lists on this page
        // mutually exclusive, so nobody is ever listed twice or, worse, missing
        // from both.
        $pending = Database::instance()->select(
            "SELECT t.teacher_id, t.employee_number, t.first_name, t.last_name, d.department_name
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
          LEFT JOIN fingerprint_templates fp ON fp.teacher_id = t.teacher_id
              WHERE t.deleted_at IS NULL AND t.status = 'active' AND fp.fingerprint_id IS NULL
              ORDER BY t.last_name, t.first_name"
        );

        return $this->view('admin.fingerprints.index', [
            'pageTitle'   => 'Fingerprints',
            'enrolments'  => $enrolments,
            'pending'     => $pending,
            // Counted separately so the page can tell "no teacher has been
            // registered yet" apart from "every teacher is already enrolled".
            // Both leave the two tables empty, and they need opposite actions.
            'teacherCount' => (int) Database::instance()->scalar(
                "SELECT COUNT(*) FROM teachers WHERE deleted_at IS NULL AND status = 'active'"
            ),
            // Scanners first, then classroom terminals; health comes along so
            // the wizard can say why one will not answer before somebody walks
            // to the far end of the building to stand in front of it.
            'devices'     => FingerprintEnrollmentService::captureDevices(),
            'nextSlot'    => FingerprintService::nextAvailableSlot(),
            // A sensor holding something other than what these rows describe
            // is the one failure this page could not previously show: every
            // teacher reads "Active" while the reader recognises nobody.
            'mismatches'  => FingerprintService::sensorMismatches(),
            // How much of the roll each terminal's sensor actually holds. A
            // terminal added after the enrolments starts at zero and fills up
            // as it polls; without this the only way to discover it was still
            // empty was a teacher failing to open a class in that room.
            'coverage'    => FingerprintSyncService::terminalStatus(),
            'syncable'    => FingerprintSyncService::syncableCount(),
            // Whether a sensor is given the whole staff or only its own room's
            // teachers is a deployment setting, and the page has to describe
            // the installation it is running on rather than one of the two.
            'syncScope'   => FingerprintSyncService::scope(),
            // Enrolled before templates were stored, so no terminal but the
            // one that captured them can ever match these teachers.
            'recapture'   => FingerprintSyncService::awaitingRecapture(),
        ]);
    }

    /**
     * Record a completed enrolment.
     *
     * The wizard drives the device through its capture cycle and posts the slot
     * the sensor allocated, together with the quality score it reported.
     */
    public function enroll(Request $request): Response
    {
        $data = $this->validate($request, [
            'teacher_id'         => 'required|int|exists:teachers,teacher_id',
            'sensor_template_id' => 'required|int|between:1,999',
            'device_row_id'      => 'nullable|int',
            'quality_score'      => 'nullable|int|between:0,255',
            'sample_count'       => 'nullable|int|between:0,10',
        ], [
            'sensor_template_id' => 'Sensor slot',
        ]);

        $result = FingerprintService::enroll(
            (int) $data['teacher_id'],
            (int) $data['sensor_template_id'],
            empty($data['device_row_id']) ? null : (int) $data['device_row_id'],
            isset($data['quality_score']) ? (int) $data['quality_score'] : null,
            (int) ($data['sample_count'] ?? 0),
            $this->requireUserId()
        );

        return $this->json($result, $result['re_enrolled']
            ? 'Fingerprint re-enrolled successfully.'
            : 'Fingerprint enrolled successfully. The teacher can now open attendance sessions.');
    }

    /**
     * Ask a terminal to capture a fingerprint.
     *
     * Nothing is recorded here — this only opens the request. The terminal
     * performs the capture and reports the slot back through the device API,
     * which is what makes the recorded slot the slot the sensor actually used.
     */
    public function startScan(Request $request): Response
    {
        $data = $this->validate($request, [
            'teacher_id'    => 'required|int|exists:teachers,teacher_id',
            'device_row_id' => 'required|int',
        ], [
            'device_row_id' => 'Terminal',
        ]);

        $enrolment = FingerprintEnrollmentService::open(
            (int) $data['teacher_id'],
            (int) $data['device_row_id'],
            $this->requireUserId()
        );

        return $this->json(
            $this->scanPayload($enrolment),
            'Go to the terminal — it is waiting for the fingerprint.'
        );
    }

    /**
     * Ask a terminal to capture a fingerprint for somebody not yet registered.
     *
     * The registration form calls this before it has a teacher to point at. The
     * slot the sensor allocates is held against the returned request until the
     * form is saved, at which point the two are bound together.
     */
    public function startRegistrationScan(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'          => 'nullable|string|max:120|no_html',
            'device_row_id' => 'required|int',
        ], [
            'device_row_id' => 'Terminal',
        ]);

        $enrolment = FingerprintEnrollmentService::openForRegistration(
            (string) ($data['name'] ?? ''),
            (int) $data['device_row_id'],
            $this->requireUserId()
        );

        return $this->json(
            $this->scanPayload($enrolment),
            'Go to the terminal — it is waiting for the fingerprint.'
        );
    }

    /** Polled by the enrolment wizard while the teacher is at the sensor. */
    public function scanStatus(Request $request): Response
    {
        $enrolment = FingerprintEnrollmentService::find($request->routeInt('id'));

        if ($enrolment === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Enrolment request not found.');
        }

        // Expiry is evaluated on read as well as on write, so a wizard left
        // open on a terminal that was switched off stops saying "waiting" on
        // its own rather than waiting for the next device poll to notice.
        if (in_array((string) $enrolment['status'], ['pending', 'scanning'], true)) {
            FingerprintEnrollmentService::expireStale();
            $enrolment = FingerprintEnrollmentService::find($request->routeInt('id')) ?? $enrolment;
        }

        return $this->json($this->scanPayload($enrolment));
    }

    public function cancelScan(Request $request): Response
    {
        // abandon() rather than cancel(): a capture that already completed has
        // written a template to the sensor, and simply marking the row would
        // leave that print occupying a slot nothing owns.
        FingerprintEnrollmentService::abandon($request->routeInt('id'), $this->requireUserId());

        return $this->json([], 'Enrolment cancelled.');
    }

    /**
     * @param  array<string,mixed> $enrolment
     * @return array<string,mixed>
     */
    private function scanPayload(array $enrolment): array
    {
        // "Waiting for the terminal to pick this up…" is true whether the
        // terminal is thinking about it or has been unplugged since Tuesday.
        // The difference is knowable here, so it is sent rather than left for
        // somebody to work out from the Devices page.
        // A terminal reporting on schedule with a dead sensor was the case
        // this query could not see: health reads "online" because every
        // measure of online is satisfied, while the sensor that would pick
        // this request up never answered at boot. d.fingerprint_ok is the
        // terminal's own verdict on itself, and NULL there means firmware too
        // old to say — which must not be shown as a fault.
        // Selected only where the schema has it. On a server whose files are
        // newer than its database — pulled without running migrate — naming
        // the column outright made this dialog fail to load at all, which
        // replaces a useful warning with a broken page. The dialog degrades to
        // what it said before instead.
        $db      = Database::instance();
        $hasFlag = $db->hasColumn('devices', 'fingerprint_ok');

        $terminal = $db->selectOne(
            'SELECT v.health, v.seconds_since_heartbeat'
            . ($hasFlag ? ', d.fingerprint_ok' : ', NULL AS fingerprint_ok')
            . ' FROM v_device_status v
                JOIN devices d ON d.id = v.device_row_id
               WHERE v.device_row_id = :id',
            ['id' => (int) $enrolment['device_row_id']]
        );

        $silentFor = $terminal === null || $terminal['seconds_since_heartbeat'] === null
            ? null
            : (int) $terminal['seconds_since_heartbeat'];

        return [
            'terminal_health'     => $terminal === null ? 'unknown' : (string) $terminal['health'],
            'terminal_silent_for' => $silentFor,
            'terminal_sensor_ok'  => $terminal === null || $terminal['fingerprint_ok'] === null
                ? null
                : (bool) $terminal['fingerprint_ok'],
            'request_id'         => (int) $enrolment['request_id'],
            'status'             => (string) $enrolment['status'],
            'stage'              => (string) $enrolment['stage'],
            'message'            => (string) ($enrolment['message'] ?? ''),
            // Why it failed, in a form the browser does not have to read the
            // prose to understand. Absent on a server that has the files but
            // not migration 035, and on rows written before it; the wizard
            // treats that as an ordinary failure rather than breaking.
            'failure_code'       => (string) ($enrolment['failure_code'] ?? ''),
            'sensor_template_id' => (int) $enrolment['sensor_template_id'],
            'quality_score'      => $enrolment['quality_score'] === null ? null : (int) $enrolment['quality_score'],
            'sample_count'       => (int) $enrolment['sample_count'],
            'teacher_name'       => (string) ($enrolment['display_name'] ?? 'this teacher'),
            'device_id'          => (string) $enrolment['device_id'],
            'room_number'        => $enrolment['room_number'],
            'finished'           => !in_array((string) $enrolment['status'], ['pending', 'scanning'], true),
        ];
    }

    public function delete(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        FingerprintService::delete($request->routeInt('id'), $this->requireUserId());

        return $this->json([], 'Fingerprint deleted. The teacher cannot open sessions until re-enrolled.');
    }

    public function setStatus(Request $request): Response
    {
        $status = $request->string('status', '');

        if (!in_array($status, ['active', 'disabled'], true)) {
            return $this->fail('INVALID_STATUS', 'Choose active or disabled.', 422);
        }

        FingerprintService::setStatus($request->routeInt('id'), $status);

        return $this->json([], sprintf('Fingerprint enrolment set to %s.', $status));
    }

    public function logs(Request $request): Response
    {
        return $this->json([
            'rows' => FingerprintService::logsForTeacher($request->routeInt('id'), 100),
        ]);
    }

    /**
     * Ask a terminal to erase its sensor.
     *
     * The page has been recommending this since migration 015 with no way to
     * do it. A sensor holding templates the server has no record of still
     * matches fingers, and a scan that lands on one is refused as
     * unrecognised — a teacher who is genuinely enrolled, turned away for
     * matching the wrong copy of their own finger.
     *
     * Wiping is only reasonable because the server can rewrite what it holds:
     * the refill starts on the terminal's next poll. Templates that exist
     * nowhere else are the exception, and the count is put in front of the
     * administrator before they can go ahead.
     */
    public function wipeSensor(Request $request): Response
    {
        $deviceRowId = $request->routeInt('id');

        $lost = FingerprintSyncService::requestSensorWipe(
            $deviceRowId,
            $this->requireUserId(),
            $request->bool('accept_loss')
        );

        $message = $lost > 0
            ? sprintf(
                'The terminal will erase its sensor on its next poll. %d fingerprint%s existed '
                . 'only there and %s now be enrolled again; everything else is rewritten '
                . 'automatically.',
                $lost,
                $lost === 1 ? '' : 's',
                $lost === 1 ? 'must' : 'must'
            )
            : 'The terminal will erase its sensor on its next poll, then the templates are '
              . 'rewritten automatically over the following few minutes.';

        if ($request->wantsJson()) {
            return $this->json(['templates_lost' => $lost], $message);
        }

        Flash::success($message);

        return Response::redirect('/admin/fingerprints');
    }

    /**
     * Put refused template writes back in the queue.
     *
     * The non-destructive half of the pair with wipeSensor(): this asks the
     * terminal to try the same writes again, where the wipe erases everything
     * and starts over. It should always be the first thing tried, so it is the
     * first thing offered.
     */
    public function retrySensor(Request $request): Response
    {
        $deviceRowId = $request->routeInt('id');

        $requeued = FingerprintSyncService::retryFailed($deviceRowId, $this->requireUserId());

        $message = sprintf(
            '%d template%s queued again. The terminal collects one per poll, so give it a '
            . 'few minutes. If the same write fails again the sensor itself is at fault — '
            . 'check its power and wiring before clearing it.',
            $requeued,
            $requeued === 1 ? ' is' : 's are'
        );

        if ($request->wantsJson()) {
            return $this->json(['requeued' => $requeued], $message);
        }

        Flash::success($message);

        return Response::redirect('/admin/fingerprints');
    }

    public function nextSlot(Request $request): Response
    {
        return $this->json([
            'sensor_template_id' => FingerprintService::nextAvailableSlot(
                $request->int('device_row_id', 0) ?: null
            ),
        ]);
    }

    public function detail(Request $request): Response
    {
        $teacherId = $request->routeInt('id');

        return $this->json([
            'teacher' => TeacherService::find($teacherId),
            'logs'    => FingerprintService::logsForTeacher($teacherId, 50),
        ]);
    }
}
