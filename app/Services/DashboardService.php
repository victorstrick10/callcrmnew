<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BrowserProfile;
use App\Models\Company;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function stats(): array
    {
        $tz = config('app.timezone');
        $todayStart = Carbon::now($tz)->startOfDay();
        $todayEnd = $todayStart->copy()->addDay();

        return [
            'appointments' => Appointment::query()->count(),
            'scheduled' => Appointment::query()->where('status', 'scheduled')->count(),
            'profiles' => BrowserProfile::query()->where('status', 'created')->count(),
            'failed' => BrowserProfile::query()->where('status', 'failed')->count(),
            'pending_profiles' => $this->pendingProfiles(9999)->count(),
            'calls_today' => Appointment::query()
                ->where('status', 'scheduled')
                ->whereBetween('start_time', [$todayStart, $todayEnd])
                ->count(),
            'calls_upcoming' => Appointment::query()
                ->where('status', 'scheduled')
                ->where('start_time', '>=', Carbon::now($tz))
                ->count(),
            'companies' => Company::query()->count(),
        ];
    }

    /**
     * Scheduled appointments still missing a GEO and/or STATIC Multilogin
     * profile (fewer than the two expected active-role profiles).
     */
    public function pendingProfiles(int $limit = 8)
    {
        return Appointment::query()
            ->with(['contact', 'company', 'profiles'])
            ->where('status', 'scheduled')
            ->orderByDesc('start_time')
            ->get()
            ->filter(function (Appointment $a) {
                $roles = $a->profiles
                    ->whereIn('status', ['reserved', 'created'])
                    ->pluck('profile_role')
                    ->unique();

                return $roles->count() < 2;
            })
            ->take($limit)
            ->values();
    }

    public function upcoming(int $limit = 8)
    {
        return Appointment::query()
            ->with(['contact', 'company'])
            ->where('status', 'scheduled')
            ->where('start_time', '>=', Carbon::now(config('app.timezone'))->startOfDay())
            ->orderBy('start_time')
            ->limit($limit)
            ->get();
    }

    public function recentLogs(int $limit = 8)
    {
        return AuditLog::query()->orderByDesc('created_at')->limit($limit)->get();
    }
}
