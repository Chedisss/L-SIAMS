<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\RfidEnrollmentService;
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

        // The queue is rendered from the server on first paint so the page is
        // useful before any script runs; every later refresh goes through
        // withoutCard() below.
        $queue = RfidService::studentsWithoutCard([], 1, self::QUEUE_PAGE_SIZE);

        return $this->view('admin.rfid.index', [
            'pageTitle'  => 'RFID Cards',
            'cards'      => $result['rows'],
            'pagination' => $meta,
            'filters'    => $filters,
            'summary'    => RfidService::summary(),
            'sections'   => AcademicStructureService::sections(['status' => 'active']),
            'readers'    => RfidEnrollmentService::captureDevices(),
            'students'   => StudentService::paginate(['status' => 'active'], 1, 1000)['rows'],
            'queue'      => $queue['rows'],
            'queueTotal' => $queue['total'],
            'queueSize'  => self::QUEUE_PAGE_SIZE,
        ]);
    }

    /**
     * How many of the waiting students to hand over at a time.
     *
     * Large enough that a whole section arrives in one response — a section is
     * capped well below this — so working down one class never pages.
     */
    private const QUEUE_PAGE_SIZE = 100;

    /**
     * The students still waiting for a card.
     *
     * Answers JSON only: the panel refreshes itself after every issue, and
     * reloading the whole page between two students would throw away the
     * reader selection and the operator's place in the list.
     */
    public function withoutCard(Request $request): Response
    {
        $page   = max(1, $request->int('page', 1));
        $result = RfidService::studentsWithoutCard([
            'section_id' => $request->int('section_id', 0) ?: null,
            'search'     => $request->string('search', ''),
        ], $page, self::QUEUE_PAGE_SIZE);

        return $this->json([
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'per_page'  => self::QUEUE_PAGE_SIZE,
            'has_more'  => $result['total'] > $page * self::QUEUE_PAGE_SIZE,
        ]);
    }

    // ------------------------------------------------- issue by tapping ----

    /**
     * Ask a terminal to read a card for a named student.
     *
     * The student is chosen first, deliberately. Reading the card first and
     * asking afterwards leaves a UID sitting in the browser belonging to
     * nobody, and the person at the reader holding a stack of identical white
     * cards has no way to tell which one it was. Naming the student up front
     * also puts their name on the terminal's display, so the card being
     * presented and the record being written are checked against each other by
     * the person doing it.
     *
     * It is checked here as well as in the service so the reader is never
     * asked for a card that could not be issued once it arrived.
     */
    public function startRead(Request $request): Response
    {
        $data = $this->validate($request, [
            'device_row_id' => 'required|int',
            'student_id'    => 'required|int|exists:students,student_id',
        ], [
            'device_row_id' => 'Terminal',
            'student_id'    => 'Student',
        ]);

        $enrolment = RfidEnrollmentService::open(
            (int) $data['device_row_id'],
            $this->requireUserId(),
            (int) $data['student_id']
        );

        return $this->json($this->readPayload($enrolment), 'Present the card to the terminal.');
    }

    public function readStatus(Request $request): Response
    {
        $enrolment = RfidEnrollmentService::find($request->routeInt('id'));

        if ($enrolment === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Card read not found.');
        }

        // Evaluated on read as well as on write, so a panel left open against a
        // terminal that was switched off stops saying "waiting" by itself.
        if (in_array((string) $enrolment['status'], ['pending', 'waiting', 'captured'], true)) {
            RfidEnrollmentService::expireStale();
            $enrolment = RfidEnrollmentService::find($request->routeInt('id')) ?? $enrolment;
        }

        return $this->json($this->readPayload($enrolment));
    }

    /**
     * Issue the card that was just read.
     *
     * The student comes from the request rather than from the browser: it was
     * named before the reader was ever asked, and accepting a different one
     * here would let the confirmation step quietly issue the card to somebody
     * other than the person whose name was on the terminal.
     */
    public function assignRead(Request $request): Response
    {
        $data = $this->validate($request, [
            'notes' => 'nullable|string|max:255|no_html',
        ]);

        $enrolment = RfidEnrollmentService::find($request->routeInt('id'));

        if ($enrolment === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Card read not found.');
        }

        if ($enrolment['student_id'] === null) {
            throw new HttpException(409, 'STUDENT_REQUIRED', 'This card read names no student.');
        }

        $result = RfidEnrollmentService::assignCaptured(
            $request->routeInt('id'),
            (int) $enrolment['student_id'],
            $this->requireUserId(),
            $data['notes'] ?? null
        );

        return $this->json(
            $this->readPayload($result['request']) + ['rfid_id' => $result['rfid_id']],
            'Card issued. The student keeps all previous attendance history.'
        );
    }

    public function cancelRead(Request $request): Response
    {
        $enrolment = RfidEnrollmentService::cancel($request->routeInt('id'), $this->requireUserId());

        return $this->json($this->readPayload($enrolment), 'Card read cancelled.');
    }

    /**
     * @param  array<string,mixed> $enrolment
     * @return array<string,mixed>
     */
    private function readPayload(array $enrolment): array
    {
        // "Waiting for the terminal" reads the same whether the reader is about
        // to answer or has been unplugged since Tuesday, so the terminal's
        // liveness travels with the status.
        $terminal = Database::instance()->selectOne(
            'SELECT health, seconds_since_heartbeat FROM v_device_status WHERE device_row_id = :id',
            ['id' => (int) $enrolment['device_row_id']]
        );

        return [
            'request_id'  => (int) $enrolment['request_id'],
            'status'      => (string) $enrolment['status'],
            'stage'       => (string) $enrolment['stage'],
            'message'     => (string) ($enrolment['message'] ?? ''),
            'card_uid'    => $enrolment['card_uid'],
            'label'       => (string) ($enrolment['display_name'] ?? 'the next card'),
            'device_id'   => (string) $enrolment['device_id'],
            'room_number' => $enrolment['room_number'],
            'student_id'  => $enrolment['student_id'] === null ? null : (int) $enrolment['student_id'],
            'student_name'   => $enrolment['first_name'] === null
                ? null
                : trim($enrolment['first_name'] . ' ' . $enrolment['last_name']),
            'student_number' => $enrolment['student_number'],
            'finished'    => !in_array((string) $enrolment['status'], ['pending', 'waiting'], true),
            'terminal_health'     => $terminal === null ? 'unknown' : (string) $terminal['health'],
            'terminal_silent_for' => $terminal === null || $terminal['seconds_since_heartbeat'] === null
                ? null
                : (int) $terminal['seconds_since_heartbeat'],
        ];
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
