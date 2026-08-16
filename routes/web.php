<?php
declare(strict_types=1);

/**
 * Web routes.
 *
 * @var \App\Core\Router $router
 *
 * Read the middleware list on each group as the security contract for
 * everything inside it. Nothing here reaches a controller without passing
 * through the chain declared above it, and a route with no auth middleware is
 * public by explicit declaration rather than by an omission a reviewer has to
 * spot.
 *
 * The standard authenticated chain is:
 *   security-headers → network-allowlist → audit-context → rate-limit
 *   → auth → session-timeout → force-password → csrf → role
 *
 * session-timeout must sit after auth (it needs a resolved session) and before
 * everything that reads data, so an expired session cannot see a single row.
 */

use App\Controllers\Web\AcademicController;
use App\Controllers\Web\AdminController;
use App\Controllers\Web\AttendanceController;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\BackupController;
use App\Controllers\Web\DeviceController;
use App\Controllers\Web\FingerprintController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\ReportController;
use App\Controllers\Web\RfidController;
use App\Controllers\Web\ScheduleController;
use App\Controllers\Web\SecurityController;
use App\Controllers\Web\SettingsController;
use App\Controllers\Web\StudentController;
use App\Controllers\Web\TeacherManagementController;
use App\Controllers\Web\TeacherPortalController;
use App\Controllers\Web\UserController;
use App\Core\Response;

$public        = ['security-headers', 'network-allowlist', 'audit-context'];
$authenticated = array_merge($public, ['auth', 'session-timeout', 'force-password']);
$adminChain    = array_merge($authenticated, ['csrf', 'role:administrator']);
$teacherChain  = array_merge($authenticated, ['csrf', 'role:teacher']);
$anyRoleChain  = array_merge($authenticated, ['csrf', 'role:administrator,teacher']);

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------

$router->get('/', static fn (): Response => Response::redirect('/login'), $public);

$router->group('', array_merge($public, ['guest', 'rate-limit:web']), static function ($router): void {
    $router->get('/login', AuthController::class . '@showLogin', [], 'login');
    $router->get('/forgot-password', AuthController::class . '@showForgotPassword', []);
});

// Login itself is rate limited on (username, IP) and CSRF-protected.
$router->group('', array_merge($public, ['rate-limit:login', 'csrf']), static function ($router): void {
    $router->post('/login', AuthController::class . '@login');
    $router->post('/forgot-password', AuthController::class . '@forgotPassword');
});

$router->post('/logout', AuthController::class . '@logout', array_merge($public, ['auth', 'csrf']));
$router->get('/logout', AuthController::class . '@logout', array_merge($public, ['auth']));

// Password change stays reachable while `must_change_password` is set, which is
// why it sits outside the force-password middleware.
$router->group('', array_merge($public, ['auth', 'session-timeout', 'csrf']), static function ($router): void {
    $router->get('/password/change', AuthController::class . '@showChangePassword');
    $router->post('/password/change', AuthController::class . '@changePassword');
});

// Session lifecycle. keepalive counts as activity; session-status is passive.
$router->group('/api/auth', array_merge($public, ['auth', 'session-timeout']), static function ($router): void {
    $router->post('/keepalive', AuthController::class . '@keepAlive');
    $router->get('/session-status', AuthController::class . '@sessionStatus');
});

// ---------------------------------------------------------------------------
// Administrator
// ---------------------------------------------------------------------------

$router->group('/admin', $adminChain, static function ($router): void {
    $router->get('', AdminController::class . '@dashboard', [], 'admin.dashboard');
    $router->get('/analytics', AdminController::class . '@analytics');

    // --- Students ---
    $router->get('/students', StudentController::class . '@index', [], 'admin.students');
    $router->get('/students/create', StudentController::class . '@create');
    $router->post('/students', StudentController::class . '@store');
    $router->get('/students/import', StudentController::class . '@importForm');
    $router->get('/students/import/template', StudentController::class . '@importTemplate');
    $router->post('/students/import/preview', StudentController::class . '@importPreview');
    $router->post('/students/import/commit', StudentController::class . '@importCommit');
    $router->get('/students/export', StudentController::class . '@export');
    $router->post('/students/bulk-archive', StudentController::class . '@bulkArchive');
    $router->get('/students/{id:\d+}', StudentController::class . '@show');
    $router->get('/students/{id:\d+}/edit', StudentController::class . '@edit');
    $router->put('/students/{id:\d+}', StudentController::class . '@update');
    $router->post('/students/{id:\d+}/transfer', StudentController::class . '@transfer');
    $router->post('/students/{id:\d+}/archive', StudentController::class . '@archive');
    $router->post('/students/{id:\d+}/restore', StudentController::class . '@restore');

    // --- Teachers ---
    $router->get('/teachers', TeacherManagementController::class . '@index', [], 'admin.teachers');
    $router->get('/teachers/create', TeacherManagementController::class . '@create');
    $router->post('/teachers', TeacherManagementController::class . '@store');
    $router->get('/teachers/{id:\d+}', TeacherManagementController::class . '@show');
    $router->get('/teachers/{id:\d+}/edit', TeacherManagementController::class . '@edit');
    $router->put('/teachers/{id:\d+}', TeacherManagementController::class . '@update');
    $router->post('/teachers/{id:\d+}/change-impact', TeacherManagementController::class . '@changeImpact');
    $router->post('/teachers/{id:\d+}/archive', TeacherManagementController::class . '@archive');
    $router->post('/teachers/{id:\d+}/reset-password', TeacherManagementController::class . '@resetPassword');

    // --- Academic setup (Part 13) ---
    $router->get('/departments', AcademicController::class . '@departments', [], 'admin.departments');
    $router->post('/departments', AcademicController::class . '@storeDepartment');
    $router->get('/departments/{id:\d+}', AcademicController::class . '@departmentDetail');
    $router->put('/departments/{id:\d+}', AcademicController::class . '@updateDepartment');
    $router->post('/departments/{id:\d+}/archive', AcademicController::class . '@archiveDepartment');

    $router->get('/grade-levels', AcademicController::class . '@gradeLevels', [], 'admin.gradeLevels');
    $router->post('/grade-levels', AcademicController::class . '@storeGradeLevel');

    $router->get('/sections', AcademicController::class . '@sections', [], 'admin.sections');
    $router->post('/sections', AcademicController::class . '@storeSection');
    $router->get('/sections/{id:\d+}', AcademicController::class . '@showSection');
    $router->put('/sections/{id:\d+}', AcademicController::class . '@updateSection');
    $router->post('/sections/{id:\d+}/archive', AcademicController::class . '@archiveSection');

    $router->get('/subjects', AcademicController::class . '@subjects', [], 'admin.subjects');
    $router->post('/subjects', AcademicController::class . '@storeSubject');
    $router->get('/subjects/{id:\d+}', AcademicController::class . '@subjectDetail');
    $router->put('/subjects/{id:\d+}', AcademicController::class . '@updateSubject');

    $router->get('/classrooms', AcademicController::class . '@classrooms', [], 'admin.classrooms');
    $router->post('/classrooms', AcademicController::class . '@storeClassroom');

    // --- Schedules ---
    $router->get('/schedules', ScheduleController::class . '@index', [], 'admin.schedules');
    $router->post('/schedules', ScheduleController::class . '@store');
    $router->post('/schedules/check-conflict', ScheduleController::class . '@checkConflict');
    $router->get('/schedules/{id:\d+}', ScheduleController::class . '@show');
    $router->put('/schedules/{id:\d+}', ScheduleController::class . '@update');
    $router->post('/schedules/{id:\d+}/archive', ScheduleController::class . '@archive');

    // --- RFID ---
    $router->get('/rfid', RfidController::class . '@index', [], 'admin.rfid');
    $router->post('/rfid/assign', RfidController::class . '@assign');
    $router->post('/rfid/read', RfidController::class . '@startRead');
    $router->get('/rfid/read/{id:\d+}', RfidController::class . '@readStatus');
    $router->post('/rfid/read/{id:\d+}/assign', RfidController::class . '@assignRead');
    $router->post('/rfid/read/{id:\d+}/cancel', RfidController::class . '@cancelRead');
    $router->get('/rfid/without-card', RfidController::class . '@withoutCard');
    $router->get('/rfid/history', RfidController::class . '@history');
    $router->get('/rfid/unknown', RfidController::class . '@unknown');
    $router->post('/rfid/unknown/{id:\d+}/resolve', RfidController::class . '@resolveUnknown');
    $router->post('/rfid/{id:\d+}/status', RfidController::class . '@setStatus');

    // --- Fingerprints ---
    $router->get('/fingerprints', FingerprintController::class . '@index', [], 'admin.fingerprints');
    $router->post('/fingerprints/enroll', FingerprintController::class . '@enroll');
    $router->get('/fingerprints/next-slot', FingerprintController::class . '@nextSlot');

    // The scan-driven path: the browser opens a request, the terminal performs
    // the capture, the browser watches. Declared before the {id} routes so
    // "scan" is never mistaken for a teacher id.
    $router->post('/fingerprints/scan', FingerprintController::class . '@startScan');
    $router->post('/fingerprints/scan/registration', FingerprintController::class . '@startRegistrationScan');
    $router->get('/fingerprints/scan/{id:\d+}', FingerprintController::class . '@scanStatus');
    $router->post('/fingerprints/scan/{id:\d+}/cancel', FingerprintController::class . '@cancelScan');
    $router->get('/fingerprints/{id:\d+}', FingerprintController::class . '@detail');
    $router->get('/fingerprints/{id:\d+}/logs', FingerprintController::class . '@logs');
    $router->post('/fingerprints/{id:\d+}/status', FingerprintController::class . '@setStatus');
    $router->post('/fingerprints/{id:\d+}/delete', FingerprintController::class . '@delete');

    // --- Devices ---
    $router->get('/devices', DeviceController::class . '@index', [], 'admin.devices');
    $router->post('/devices', DeviceController::class . '@store');
    $router->get('/devices/import', DeviceController::class . '@importForm');
    $router->get('/devices/import/template', DeviceController::class . '@importTemplate');
    $router->post('/devices/import/preview', DeviceController::class . '@importPreview');
    $router->post('/devices/import/commit', DeviceController::class . '@importCommit');
    $router->get('/devices/{id:\d+}', DeviceController::class . '@show');
    $router->put('/devices/{id:\d+}', DeviceController::class . '@update');
    $router->post('/devices/{id:\d+}/rotate-key', DeviceController::class . '@rotateKey');
    $router->post('/devices/{id:\d+}/revoke-key', DeviceController::class . '@revokeKey');
    $router->get('/devices/{id:\d+}/key-history', DeviceController::class . '@keyHistory');
    $router->post('/devices/{id:\d+}/provisioning-file', DeviceController::class . '@provisioningFile');
    $router->post('/devices/{id:\d+}/status', DeviceController::class . '@setStatus');
    $router->post('/devices/{id:\d+}/test', DeviceController::class . '@testConnection');

    // --- Attendance ---
    $router->get('/attendance', AttendanceController::class . '@index', [], 'admin.attendance');
    $router->get('/attendance/live', AttendanceController::class . '@live');
    $router->get('/attendance/export', AttendanceController::class . '@export');
    $router->get('/attendance/sessions', AttendanceController::class . '@sessions');
    $router->get('/attendance/sessions/{id:\d+}', AttendanceController::class . '@sessionDetail');
    $router->post('/attendance/sessions/{id:\d+}/close', AttendanceController::class . '@closeSession');
    $router->get('/attendance/{id:\d+}', AttendanceController::class . '@show');
    $router->post('/attendance/{id:\d+}/correct', AttendanceController::class . '@correct');

    // --- Reports ---
    $router->get('/reports', ReportController::class . '@index', [], 'admin.reports');
    $router->post('/reports/preview', ReportController::class . '@preview');
    $router->post('/reports/generate', ReportController::class . '@generate');
    $router->get('/reports/students/search', ReportController::class . '@searchStudents');
    $router->get('/reports/{id:\d+}/download', ReportController::class . '@download');

    // --- Security Center ---
    $router->get('/security', SecurityController::class . '@center', [], 'admin.security');
    $router->get('/security/logs', SecurityController::class . '@logs');
    $router->post('/security/logs/{id:\d+}/resolve', SecurityController::class . '@resolveLog');
    $router->get('/security/sessions', SecurityController::class . '@sessions');
    $router->post('/security/sessions/{id:\d+}/terminate', SecurityController::class . '@terminateSession');
    $router->post('/security/users/{id:\d+}/terminate-sessions', SecurityController::class . '@terminateUserSessions');
    $router->post('/security/sessions/terminate-all', SecurityController::class . '@terminateAllSessions');
    $router->post('/security/block-ip', SecurityController::class . '@blockIp');
    $router->post('/security/unblock-ip', SecurityController::class . '@unblockIp');

    $router->get('/audit', SecurityController::class . '@auditLogs', [], 'admin.audit');
    $router->get('/audit/export', SecurityController::class . '@exportAudit');

    // --- Users ---
    $router->get('/users', UserController::class . '@index', [], 'admin.users');
    $router->post('/users', UserController::class . '@store');
    $router->get('/users/check-username', UserController::class . '@checkUsername');
    $router->get('/users/suggest-username', UserController::class . '@suggestUsername');
    $router->post('/users/check-password', UserController::class . '@checkPassword');
    $router->get('/users/generate-password', UserController::class . '@generatePassword');
    $router->put('/users/{id:\d+}', UserController::class . '@update');
    $router->post('/users/{id:\d+}/reset-password', UserController::class . '@resetPassword');
    $router->post('/users/{id:\d+}/archive', UserController::class . '@archive');
    $router->post('/users/{id:\d+}/restore', UserController::class . '@restore');

    // --- Settings & backup ---
    $router->get('/settings', SettingsController::class . '@index', [], 'admin.settings');
    $router->post('/settings', SettingsController::class . '@update');
    $router->post('/settings/school-year', SettingsController::class . '@setSchoolYear');

    $router->get('/backup', BackupController::class . '@index', [], 'admin.backup');
    $router->post('/backup', BackupController::class . '@create');
    $router->post('/backup/{id:\d+}/verify', BackupController::class . '@verify');
    $router->get('/backup/{id:\d+}/download', BackupController::class . '@download');
    $router->post('/backup/{id:\d+}/restore', BackupController::class . '@restore');
});

// ---------------------------------------------------------------------------
// Teacher
// ---------------------------------------------------------------------------

$router->group('/teacher', $teacherChain, static function ($router): void {
    $router->get('', TeacherPortalController::class . '@dashboard', [], 'teacher.dashboard');
    $router->get('/schedule', TeacherPortalController::class . '@schedule');
    $router->get('/sections', TeacherPortalController::class . '@sections');
    $router->get('/sections/{id:\d+}', TeacherPortalController::class . '@sectionRoster');
    $router->get('/attendance', TeacherPortalController::class . '@attendance');
    $router->get('/sessions', TeacherPortalController::class . '@sessions');
    $router->get('/sessions/{id:\d+}', TeacherPortalController::class . '@sessionDetail');
    $router->post('/sessions/{id:\d+}/close', TeacherPortalController::class . '@closeSession');
    $router->get('/profile', TeacherPortalController::class . '@profile');
    $router->put('/profile', TeacherPortalController::class . '@updateProfile');
    $router->get('/reports', TeacherPortalController::class . '@reports');
    $router->post('/reports/preview', ReportController::class . '@preview');
    $router->post('/reports/generate', ReportController::class . '@generate');
    $router->get('/reports/students/search', ReportController::class . '@searchStudents');
    // Ownership is enforced inside the action: a teacher may only download a
    // report they generated themselves.
    $router->get('/reports/{id:\d+}/download', ReportController::class . '@download');
});

// ---------------------------------------------------------------------------
// Shared (both roles)
// ---------------------------------------------------------------------------

$router->group('', $anyRoleChain, static function ($router): void {
    $router->get('/profile', ProfileController::class . '@show', [], 'profile');
    $router->put('/profile', ProfileController::class . '@update');
    $router->post('/profile/photo', ProfileController::class . '@uploadPhoto');
    $router->post('/profile/sessions/{id:\d+}/terminate', ProfileController::class . '@terminateSession');

    $router->get('/notifications', ProfileController::class . '@notifications');
    $router->get('/notifications/unread-count', ProfileController::class . '@unreadCount');
    $router->post('/notifications/{id:\d+}/read', ProfileController::class . '@markRead');
    $router->post('/notifications/read-all', ProfileController::class . '@markAllRead');
});
