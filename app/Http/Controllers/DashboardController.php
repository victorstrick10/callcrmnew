<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\DashboardService;
use App\Services\SystemStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** ~1 year, in minutes, for the "last seen" cookie. */
    private const LAST_SEEN_TTL = 60 * 24 * 365;

    public function __invoke(Request $request, DashboardService $dashboard, SystemStatusService $status): View
    {
        $lastSeen = null;
        $raw = $request->cookie('crm_last_seen');
        if (is_string($raw) && $raw !== '') {
            try {
                $lastSeen = Carbon::parse($raw);
            } catch (\Throwable) {
                $lastSeen = null;
            }
        }

        $newCalls = $dashboard->newScheduledCalls($lastSeen);

        // Advance the baseline so the next visit reports only calls added after now.
        Cookie::queue('crm_last_seen', Carbon::now('UTC')->toIso8601String(), self::LAST_SEEN_TTL);

        return view('dashboard', [
            'stats' => $dashboard->stats(),
            'upcoming' => $dashboard->upcoming(),
            'pending' => $dashboard->pendingProfiles(),
            'recentLogs' => $dashboard->recentLogs(),
            'systemStatus' => $status->snapshot(),
            'companies' => Company::query()->orderBy('name')->get(),
            'todayCalls' => $dashboard->callTimes(0),
            'tomorrowCalls' => $dashboard->callTimes(1),
            'weekCalls' => $dashboard->callsByDay(0),
            'nextWeekCalls' => $dashboard->callsByDay(1),
            'newCalls' => $newCalls,
            'newCallsSince' => $lastSeen,
            'newCallsFirstVisit' => $lastSeen === null,
        ]);
    }
}
