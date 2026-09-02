<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\DashboardService;
use App\Services\SystemStatusService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard, SystemStatusService $status): View
    {
        return view('dashboard', [
            'stats' => $dashboard->stats(),
            'upcoming' => $dashboard->upcoming(),
            'pending' => $dashboard->pendingProfiles(),
            'recentLogs' => $dashboard->recentLogs(),
            'systemStatus' => $status->snapshot(),
            'companies' => Company::query()->orderBy('name')->get(),
            'todayCalls' => $dashboard->callTimes(0),
            'tomorrowCalls' => $dashboard->callTimes(1),
        ]);
    }
}
