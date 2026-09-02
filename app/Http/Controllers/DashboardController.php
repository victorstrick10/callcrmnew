<?php

namespace App\Http\Controllers;

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
        ]);
    }
}
