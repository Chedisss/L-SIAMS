<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\FingerprintService;
use App\Services\TeacherService;

/**
 * Fingerprint enrolment management.
 *
 * The capture itself happens on the R307 attached to a terminal; this
 * controller records the resulting sensor slot and its owner. No biometric
 * template is transmitted to or stored by the server.
 */
final class FingerprintController extends Controller
{
    public function index(Request $request): Response
    {
        $enrolments = FingerprintService::listEnrolments();

        if ($request->wantsJson()) {
            return $this->json(['rows' => $enrolments]);
        }

        $pending = Database::instance()->select(
            "SELECT t.teacher_id, t.employee_number, t.first_name, t.last_name, d.department_name
               FROM teachers t
               JOIN departments d ON d.department_id = t.department_id
              WHERE t.deleted_at IS NULL AND t.status = 'active' AND t.fingerprint_status <> 'enrolled'
              ORDER BY t.last_name"
        );

        return $this->view('admin.fingerprints.index', [
            'pageTitle'   => 'Fingerprints',
            'enrolments'  => $enrolments,
            'pending'     => $pending,
            'devices'     => Database::instance()->select(
                "SELECT d.id, d.device_id, c.room_number
                   FROM devices d
                   LEFT JOIN classrooms c ON c.classroom_id = d.classroom_id
                  WHERE d.deleted_at IS NULL AND d.status IN ('active','offline')
                  ORDER BY c.room_number"
            ),
            'nextSlot'    => FingerprintService::nextAvailableSlot(),
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
