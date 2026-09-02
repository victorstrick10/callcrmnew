<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard): View
    {
        return view('dashboard', [
            'stats' => $dashboard->stats(),
            'upcoming' => $dashboard->upcoming(),
            'recentLogs' => $dashboard->recentLogs(),
        ]);
    }
}
