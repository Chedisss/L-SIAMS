<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\RfidService;
use App\Services\StudentService;

final class RfidController extends Controller
{
    public function index(Request $request): Response
    {
        $pagination = $this->pagination($request);

        $filters = [
            'search'     => $request->string('search', ''),
            'status'     => $request->string('status', ''),
            'section_id' => $request->int('section_id', 0) ?: null,
        ];

        $result = RfidService::paginate($filters, $pagination['page'], $pagination['per_page']);
        $meta   = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('admin.rfid.index', [
            'pageTitle'  => 'RFID Cards',
            'cards'      => $result['rows'],
            'pagination' => $meta,
            'filters'    => $filters,
            'summary'    => RfidService::summary(),
            'sections'   => AcademicStructureService::sections(['status' => 'active']),
        ]);
    }

    public function assign(Request $request): Response
    {
        $data = $this->validate($request, [
            'student_id' => 'required|int|exists:students,student_id',
            'card_uid'   => 'required|uid',
            'notes'      => 'nullable|string|max:255|no_html',
        ], [
            'card_uid' => 'Card UID',
        ]);

        $rfidId = RfidService::assign(
            (int) $data['student_id'],
            (string) $data['card_uid'],
            $this->requireUserId(),
            $data['notes'] ?? null
        );

        return $this->json(
            ['rfid_id' => $rfidId],
            'RFID card assigned successfully. The student keeps all previous attendance history.'
        );
    }

    public function setStatus(Request $request): Response
    {
        $data = $this->validate($request, [
            'status' => 'required|in:active,inactive,lost,blacklisted',
            'reason' => 'nullable|string|max:255|no_html',
        ]);

        // Blacklisting is the one status change with security weight, so it
        // demands a reason rather than accepting a bare status flip.
        if ((string) $data['status'] === 'blacklisted' && empty($data['reason'])) {
            return $this->fail('REASON_REQUIRED', 'Give a reason when blacklisting a card.', 422);
        }

        RfidService::setStatus(
            $request->routeInt('id'),
            (string) $data['status'],
            $this->requireUserId(),
            $data['reason'] ?? null
        );

        return $this->json([], sprintf('Card set to %s.', $data['status']));
    }

    public function history(Request $request): Response
    {
        $uid = $request->string('uid', '');

        if ($uid === '') {
            return $this->fail('UID_REQUIRED', 'Provide a card UID.', 422);
        }

        return $this->json(['rows' => RfidService::tapHistory($uid, 100)]);
    }

    public function unknown(Request $request): Response
    {
        $resolution = $request->string('resolution', 'pending');
        $rows       = RfidService::unknownCards($resolution);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $rows]);
        }

        return $this->view('admin.rfid.unknown', [
            'pageTitle'  => 'Unknown RFID Cards',
            'cards'      => $rows,
            'resolution' => $resolution,
            'students'   => StudentService::paginate(['status' => 'active'], 1, 1000)['rows'],
        ]);
    }

    public function resolveUnknown(Request $request): Response
    {
        $data = $this->validate($request, [
            'resolution' => 'required|in:assigned,ignored,blacklisted',
            'student_id' => 'nullable|int',
            'notes'      => 'nullable|string|max:255|no_html',
        ]);

        $unknownId = $request->routeInt('id');

        // "Assign" is really a card issuance; routing it through assign() keeps
        // the one-active-card invariant and the history chain intact.
        if ((string) $data['resolution'] === 'assigned') {
            if (empty($data['student_id'])) {
                return $this->fail('STUDENT_REQUIRED', 'Choose the student this card belongs to.', 422);
            }

            $unknown = \App\Core\Database::instance()->selectOne(
                'SELECT card_uid FROM unknown_rfid_logs WHERE unknown_id = :id',
                ['id' => $unknownId]
            );

            if ($unknown === null) {
                return $this->fail('NOT_FOUND', 'Unknown card entry not found.', 404);
            }

            RfidService::assign(
                (int) $data['student_id'],
                (string) $unknown['card_uid'],
                $this->requireUserId(),
                'Assigned from unknown-card triage.'
            );

            return $this->json([], 'Card assigned to the student.');
        }

        RfidService::resolveUnknown(
            $unknownId,
            (string) $data['resolution'],
            $this->requireUserId(),
            $data['notes'] ?? null
        );

        return $this->json([], sprintf('Card marked %s.', $data['resolution']));
    }
}
