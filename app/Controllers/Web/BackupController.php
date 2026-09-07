<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\SettingsService;

final class BackupController extends Controller
{
    public function index(Request $request): Response
    {
        $history = BackupService::history();

        if ($request->wantsJson()) {
            return $this->json(['rows' => $history]);
        }

        return $this->view('admin.backup.index', [
            'pageTitle' => 'Backup & Restore',
            'backups'   => $history,
            'schedule'  => [
                'enabled' => SettingsService::bool('backup.schedule_enabled', false),
                'time'    => substr(SettingsService::string('backup.schedule_time', '01:00'), 0, 5),
                'retain'  => SettingsService::int('backup.retain_count', 30),
            ],
            'lastScheduled' => BackupService::lastScheduledAt(),
        ]);
    }

    /**
     * The automatic-backup schedule.
     *
     * The worker has always been able to write a nightly backup, gated on
     * these three settings — and until now nothing in the interface could set
     * them, so the feature was unreachable: the rows existed and no screen
     * wrote to them. This is that screen's other half.
     *
     * Only these three keys are ever touched here, named explicitly rather than
     * passed through from the request, so the backup page cannot become a
     * general settings writer.
     */
    public function schedule(Request $request): Response
    {
        // `time` is passed as a rule array, not a pipe string: the HH:MM regex
        // contains a `|` (in the hour alternation) that a pipe-delimited rule
        // list would split the pattern on.
        $data = $this->validate($request, [
            'enabled' => 'nullable|bool',
            'time'    => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'retain'  => 'required|int|between:1,365',
        ], [
            'time'   => 'Backup time',
            'retain' => 'Backups to keep',
        ]);

        $result = SettingsService::updateMany([
            'backup.schedule_enabled' => (bool) ($data['enabled'] ?? false),
            'backup.schedule_time'    => substr((string) $data['time'], 0, 5),
            'backup.retain_count'     => (int) $data['retain'],
        ], $this->requireUserId());

        if ($result['changed'] === []) {
            return $this->json([], 'The schedule was already set to those values.');
        }

        AuditService::log(
            AuditService::SETTINGS_UPDATED,
            'settings',
            'settings',
            null,
            array_map(static fn (array $c): mixed => $c['old'], $result['changed']),
            array_map(static fn (array $c): mixed => $c['new'], $result['changed']),
            'Updated the automatic backup schedule.'
        );

        return $this->json(['changed' => $result['changed']], 'Backup schedule saved.');
    }

    public function create(Request $request): Response
    {
        $result = BackupService::create('manual', $this->requireUserId());

        return $this->json($result, sprintf(
            'Backup completed: %d tables, %s.',
            $result['tables'],
            BackupService::humanSize($result['size'])
        ));
    }

    public function verify(Request $request): Response
    {
        $result = BackupService::verify($request->routeInt('id'));

        return Response::json([
            'success' => $result['ok'],
            'code'    => $result['ok'] ? 'OK' : 'VERIFICATION_FAILED',
            'message' => $result['message'],
            'data'    => ['checks' => $result['checks']],
        ], $result['ok'] ? 200 : 422);
    }

    public function download(Request $request): Response
    {
        $backup = BackupService::download($request->routeInt('id'));

        return Response::attachment(
            $backup['content'],
            $backup['filename'],
            'application/octet-stream'
        );
    }

    /**
     * Restore.
     *
     * The single most destructive action in the system, so it requires password
     * re-authentication, an explicit typed confirmation, and it takes its own
     * safety backup before overwriting anything.
     */
    public function restore(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        if ($request->string('confirm_phrase', '') !== 'RESTORE') {
            return $this->fail(
                'CONFIRMATION_REQUIRED',
                'Type RESTORE in the confirmation box to proceed.',
                422
            );
        }

        $result = BackupService::restore($request->routeInt('id'), $this->requireUserId());

        return $this->json($result, sprintf(
            'Database restored (%d statements). A safety backup (%s) was taken first.',
            $result['statements'],
            $result['safety_backup']
        ));
    }
}
