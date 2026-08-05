<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\BackupService;

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
        ]);
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
