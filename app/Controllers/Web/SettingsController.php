<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\SchoolYearService;
use App\Services\SettingsService;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = SettingsService::grouped();
        $grouped  = [];

        foreach ($settings as $setting) {
            $grouped[(string) $setting['group_name']][] = $setting;
        }

        return $this->view('admin.settings.index', [
            'pageTitle'   => 'System Settings',
            'groups'      => $grouped,
            'schoolYears' => SchoolYearService::all(),
            'current'     => SchoolYearService::current(),
        ]);
    }

    /**
     * Save settings.
     *
     * Any change touching a sensitive key requires the administrator to
     * re-enter their password (Part 2 business rules), because these values
     * govern how long a session lives and how strictly attendance is enforced.
     */
    public function update(Request $request): Response
    {
        /** @var array<string,mixed> $values */
        $values = $request->input('settings', []);

        if (!is_array($values) || $values === []) {
            return $this->fail('NO_CHANGES', 'No settings were submitted.', 422);
        }

        $requiresReauth = false;

        foreach (array_keys($values) as $key) {
            if (SettingsService::isSensitive((string) $key)) {
                $requiresReauth = true;
                break;
            }
        }

        if ($requiresReauth) {
            $this->requirePasswordConfirmation($request);
        }

        $result = SettingsService::updateMany($values, $this->requireUserId());

        if ($result['changed'] === []) {
            return $this->json([], 'No changes were needed.');
        }

        AuditService::log(
            AuditService::SETTINGS_UPDATED,
            'settings',
            'settings',
            null,
            array_map(static fn (array $c): mixed => $c['old'], $result['changed']),
            array_map(static fn (array $c): mixed => $c['new'], $result['changed']),
            sprintf('Updated %d setting(s): %s.', count($result['changed']), implode(', ', array_keys($result['changed'])))
        );

        return $this->json(
            ['changed' => $result['changed']],
            sprintf('%d setting(s) saved.', count($result['changed']))
        );
    }

    public function setSchoolYear(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $schoolYearId = $request->int('school_year_id');

        SchoolYearService::setCurrent($schoolYearId);

        return $this->json([], 'Active school year updated.');
    }
}
