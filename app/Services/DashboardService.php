<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BrowserProfile;
use App\Models\Company;
use Illuminate\Support\Carbon;

class DashboardService
{
    /** Operator display timezone (GMT+1 by default) for today/tomorrow logic. */
    private function tz(): string
    {
        return config('app.display_timezone') ?: config('app.timezone');
    }

    /**
     * Scheduled call times for a given day offset (0 = today, 1 = tomorrow),
     * across all companies, formatted in the display timezone. Returns the
     * date label, count, and the list of HH:MM times.
     *
     * @return array{date:string,count:int,times:list<string>}
     */
    public function callTimes(int $dayOffset = 0): array
    {
        $tz = $this->tz();
        $start = Carbon::now($tz)->startOfDay()->addDays($dayOffset);
        $end = $start->copy()->addDay();

        $appointments = Appointment::query()
            ->where('status', 'scheduled')
            ->whereBetween('start_time', [$start->copy()->utc(), $end->copy()->utc()])
            ->orderBy('start_time')
            ->get();

        $times = $appointments
            ->map(fn (Appointment $a) => $a->start_time?->copy()->setTimezone($tz)->format('H:i'))
            ->filter()
            ->values()
            ->all();

        return [
            'date' => $start->format('D d.m.Y'),
            'count' => count($times),
            'times' => $times,
        ];
    }

    public function stats(): array
    {
        $tz = $this->tz();
        $todayStart = Carbon::now($tz)->startOfDay();
        $todayEnd = $todayStart->copy()->addDay();
        $tomorrowEnd = $todayEnd->copy()->addDay();

        return [
            'appointments' => Appointment::query()->count(),
            'scheduled' => Appointment::query()->where('status', 'scheduled')->count(),
            'profiles' => BrowserProfile::query()->where('status', 'created')->count(),
            'failed' => BrowserProfile::query()->where('status', 'failed')->count(),
            'pending_profiles' => $this->pendingProfiles(9999)->count(),
            'calls_today' => Appointment::query()
                ->where('status', 'scheduled')
                ->whereBetween('start_time', [$todayStart->copy()->utc(), $todayEnd->copy()->utc()])
                ->count(),
            'calls_tomorrow' => Appointment::query()
                ->where('status', 'scheduled')
                ->whereBetween('start_time', [$todayEnd->copy()->utc(), $tomorrowEnd->copy()->utc()])
                ->count(),
            'calls_upcoming' => Appointment::query()
                ->where('status', 'scheduled')
                ->where('start_time', '>=', Carbon::now('UTC'))
                ->count(),
            'companies' => Company::query()->count(),
        ];
    }

    /**
     * TODAY's scheduled calls (all companies) still missing a GEO and/or STATIC
     * Multilogin profile.
     */
    public function pendingProfiles(int $limit = 8)
    {
        $tz = $this->tz();
        $todayStart = Carbon::now($tz)->startOfDay();
        $todayEnd = $todayStart->copy()->addDay();

        return Appointment::query()
            ->with(['contact', 'company', 'profiles'])
            ->where('status', 'scheduled')
            ->whereBetween('start_time', [$todayStart->copy()->utc(), $todayEnd->copy()->utc()])
            ->orderBy('start_time')
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

    /**
     * TOMORROW's scheduled calls (all companies).
     */
    public function upcoming(int $limit = 12)
    {
        $tz = $this->tz();
        $tomorrowStart = Carbon::now($tz)->addDay()->startOfDay();
        $tomorrowEnd = $tomorrowStart->copy()->addDay();

        return Appointment::query()
            ->with(['contact', 'company'])
            ->where('status', 'scheduled')
            ->whereBetween('start_time', [$tomorrowStart->copy()->utc(), $tomorrowEnd->copy()->utc()])
            ->orderBy('start_time')
            ->limit($limit)
            ->get();
    }

    public function recentLogs(int $limit = 8)
    {
        return AuditLog::query()->orderByDesc('created_at')->limit($limit)->get();
    }
}
