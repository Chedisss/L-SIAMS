<?php
declare(strict_types=1);

/**
 * API routes.
 *
 * @var \App\Core\Router $router
 *
 * Two distinct authentication models live here and they never mix:
 *
 *   Device API (/api/device, /api/attendance/*, /api/fingerprint, /api/rfid)
 *     HMAC-signed requests from ESP32 terminals. No cookies, no CSRF — there is
 *     no ambient authority for a forged cross-site request to ride on, and the
 *     signature covers method, path, body, timestamp and nonce.
 *
 *   Browser API (everything else)
 *     Cookie session + CSRF, identical to the web routes.
 */

use App\Controllers\Api\AttendanceApiController;
use App\Controllers\Api\ConstraintApiController;
use App\Controllers\Api\DashboardApiController;
use App\Controllers\Api\DeviceApiController;
use App\Controllers\Api\FingerprintEnrollmentApiController;
use App\Controllers\Api\RfidEnrollmentApiController;
use App\Controllers\Api\RealtimeApiController;
use App\Controllers\Web\AttendanceController;
use App\Controllers\Web\ScheduleController;
use App\Core\Clock;
use App\Core\Response;

// The device chain. `device-auth` performs steps 1–6 of the Part 15.2 sequence;
// `idempotency` sits in front of the controller so a retried tap never reaches
// the attendance engine twice.
$deviceChain = [
    'security-headers',
    'network-allowlist:device',
    'audit-context',
    'rate-limit:device',
    'device-auth',
    'idempotency',
];

// The claim endpoint is the one device route that runs before the terminal has
// credentials — it authenticates with its single-use claim token instead.
$claimChain = [
    'security-headers',
    'network-allowlist:device',
    'audit-context',
    'rate-limit:device',
];

$browserChain = [
    'security-headers',
    'network-allowlist',
    'audit-context',
    'auth',
    'session-timeout',
    'force-password',
];

$adminApiChain   = array_merge($browserChain, ['role:administrator']);
$anyRoleApiChain = array_merge($browserChain, ['role:administrator,teacher']);

// ---------------------------------------------------------------------------
// Health check — unauthenticated on purpose so monitoring can reach it, but it
// discloses nothing beyond liveness and the server clock.
// ---------------------------------------------------------------------------

$router->get('/api/health', static function (): Response {
    return Response::ok([
        'status'      => 'ok',
        'server_time' => Clock::atom(),
    ], 'L-SIAMS is running.');
}, ['security-headers', 'network-allowlist']);

// ---------------------------------------------------------------------------
// Device API
// ---------------------------------------------------------------------------

$router->post('/api/device/claim', DeviceApiController::class . '@claim', $claimChain);

$router->group('/api/device', $deviceChain, static function ($router): void {
    $router->post('/authenticate', DeviceApiController::class . '@authenticate');
    $router->post('/heartbeat', DeviceApiController::class . '@heartbeat');
    $router->post('/sync', DeviceApiController::class . '@sync');
    $router->post('/log', DeviceApiController::class . '@log');
    $router->get('/status', DeviceApiController::class . '@status');
    $router->get('/time', DeviceApiController::class . '@time');
});

$router->group('/api/attendance', $deviceChain, static function ($router): void {
    // Fingerprint verification is what opens a session — nothing else does.
    $router->post('/start', AttendanceApiController::class . '@start');
    $router->post('/tap', AttendanceApiController::class . '@tap');
    $router->post('/time-in', AttendanceApiController::class . '@timeIn');
    $router->post('/time-out', AttendanceApiController::class . '@timeOut');
    $router->post('/end', AttendanceApiController::class . '@end');
});

// Alias kept so an older firmware build that still posts to /record keeps
// working after a server upgrade; it resolves the same way as /tap.
$router->post('/api/attendance/record', AttendanceApiController::class . '@tap', $deviceChain);

$router->post('/api/fingerprint/verify', AttendanceApiController::class . '@start', $deviceChain);

// Enrolment, driven from the browser and executed by the sensor. The terminal
// polls `enrollment` while idle and reports its way through the capture cycle;
// the slot the sensor allocates comes back here rather than being typed into a
// form by whoever is standing at the terminal.
$router->group('/api/fingerprint/enrollment', $deviceChain, static function ($router): void {
    $router->get('', FingerprintEnrollmentApiController::class . '@pending');
    $router->post('/progress', FingerprintEnrollmentApiController::class . '@progress');
    $router->post('/complete', FingerprintEnrollmentApiController::class . '@complete');
    $router->post('/failed', FingerprintEnrollmentApiController::class . '@failed');
    $router->post('/discarded', FingerprintEnrollmentApiController::class . '@discarded');
});

$router->post('/api/rfid/scan', AttendanceApiController::class . '@tap', $deviceChain);

// Card issuance, the same hand-off as fingerprint enrolment. Separate from the
// tap route above on purpose: reading a card to issue it records no attendance,
// needs no open session and needs no classroom, so a terminal on the
// registrar's desk can do it.
$router->group('/api/rfid/enrollment', $deviceChain, static function ($router): void {
    $router->get('', RfidEnrollmentApiController::class . '@pending');
    $router->post('/progress', RfidEnrollmentApiController::class . '@progress');
    $router->post('/captured', RfidEnrollmentApiController::class . '@captured');
    $router->post('/failed', RfidEnrollmentApiController::class . '@failed');
});

// ---------------------------------------------------------------------------
// Browser API — dashboards, live feed, realtime transport
// ---------------------------------------------------------------------------

$router->group('/api', $anyRoleApiChain, static function ($router): void {
    $router->get('/attendance/live', AttendanceApiController::class . '@live');
    $router->get('/attendance/session/{id:\d+}/summary', AttendanceApiController::class . '@sessionSummary');

    $router->post('/realtime/ticket', RealtimeApiController::class . '@ticket');
    $router->get('/realtime/replay', RealtimeApiController::class . '@replay');
    $router->get('/realtime/poll', RealtimeApiController::class . '@poll');
    $router->get('/realtime/stream', RealtimeApiController::class . '@stream');

    $router->get('/dashboard/teacher', DashboardApiController::class . '@teacher');
    $router->get('/search', DashboardApiController::class . '@search');
});

$router->group('/api', $adminApiChain, static function ($router): void {
    $router->get('/dashboard/admin', DashboardApiController::class . '@admin');
    $router->get('/analytics', DashboardApiController::class . '@analytics');
    $router->get('/devices/status', DashboardApiController::class . '@devices');
    $router->get('/security/summary', DashboardApiController::class . '@security');

    // Part 14.5 — constraint resolution for the cascading dropdowns.
    $router->get('/subjects/assignable', ConstraintApiController::class . '@assignableSubjects');
    $router->get('/sections/assignable', ConstraintApiController::class . '@assignableSections');
    $router->get('/subjects/{id:\d+}/grade-levels', ConstraintApiController::class . '@subjectGradeLevels');
    $router->get('/teachers/assignable', ConstraintApiController::class . '@assignableTeachers');
    $router->get('/classrooms/assignable', ConstraintApiController::class . '@assignableClassrooms');
    $router->get('/teachers/{id:\d+}/constraints', ConstraintApiController::class . '@teacherConstraints');

    $router->post('/schedules/check-conflict', ScheduleController::class . '@checkConflict');
    $router->get('/attendance/records', AttendanceController::class . '@index');
});
