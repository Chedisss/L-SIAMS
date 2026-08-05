<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceQueryService;
use App\Services\AttendanceSessionService;
use App\Services\AuditService;
use App\Services\DashboardService;
use App\Services\DeviceService;
use App\Services\SecurityLogService;

final class AdminController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $overview = DashboardService::adminOverview();

        return $this->view('admin.dashboard', [
            'pageTitle'      => 'Dashboard',
            'overview'       => $overview,
            'trend'          => DashboardService::attendanceTrend(14),
            'liveFeed'       => AttendanceQueryService::liveFeed(0, 25),
            'rejections'     => AttendanceQueryService::recentRejections(10),
            'devices'        => DeviceService::fleet(),
            'openSessions'   => AttendanceSessionService::openSessions(),
            'deviceActivity' => DashboardService::recentDeviceActivity(10),
            'securityEvents' => SecurityLogService::recent(8),
            'auditTrail'     => AuditService::recent(8),
            'actionItems'    => DashboardService::actionItems(),
        ]);
    }

    public function analytics(Request $request): Response
    {
        $from = $request->string('date_from', Clock::now()->modify('-30 days')->format('Y-m-d'));
        $to   = $request->string('date_to', Clock::today());

        return $this->view('admin.analytics', [
            'pageTitle'    => 'Analytics',
            'dateFrom'     => $from,
            'dateTo'       => $to,
            'gradeLevels'  => \App\Services\AcademicStructureService::gradeLevels(),
            'sections'     => \App\Services\AcademicStructureService::sections(['status' => 'active']),
        ]);
    }
}
