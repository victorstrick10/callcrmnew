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

    /**
     * Number of scheduled calls that entered the CRM since the operator's last
     * visit. When there is no baseline yet (first visit), returns 0 so the whole
     * backlog isn't reported as "new".
     */
    public function newScheduledCalls(?Carbon $since): int
    {
        if ($since === null) {
            return 0;
        }

        return Appointment::query()
            ->where('status', 'scheduled')
            ->where('created_at', '>', $since)
            ->count();
    }

    /**
     * Scheduled-call counts for each of the next N days (starting today) in the
     * display timezone, plus a total. Each entry carries a date label, weekday,
     * ISO date (for linking), and count.
     *
     * @return array{days:list<array{label:string,weekday:string,date:string,count:int,is_today:bool}>,total:int}
     */
    public function callsByDay(int $days = 7): array
    {
        $tz = $this->tz();
        $today = Carbon::now($tz)->startOfDay();
        $out = [];
        $total = 0;

        for ($i = 0; $i < $days; $i++) {
            $start = $today->copy()->addDays($i);
            $end = $start->copy()->addDay();
            $count = Appointment::query()
                ->where('status', 'scheduled')
                ->whereBetween('start_time', [$start->copy()->utc(), $end->copy()->utc()])
                ->count();
            $total += $count;
            $out[] = [
                'label' => $start->format('d.m'),
                'weekday' => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $start->format('D')),
                'date' => $start->format('Y-m-d'),
                'count' => $count,
                'is_today' => $i === 0,
            ];
        }

        return ['days' => $out, 'total' => $total];
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
