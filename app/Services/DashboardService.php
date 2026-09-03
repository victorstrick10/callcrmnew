<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BrowserProfile;
use App\Models\Company;
use App\Models\Contact;
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
     * Scheduled-call counts for each day of the CURRENT calendar week
     * (Monday → Sunday) in the display timezone, plus a week total. Includes
     * days that have already passed this week so the total reflects the whole
     * week. Each entry carries a date label, weekday, ISO date, count, and
     * today/tomorrow/past markers.
     *
     * @param  int  $weekOffset  0 = this week, 1 = next week, etc.
     * @return array{days:list<array{label:string,weekday:string,date:string,count:int,is_today:bool,is_tomorrow:bool,is_past:bool}>,total:int}
     */
    public function callsByDay(int $weekOffset = 0): array
    {
        $tz = $this->tz();
        $weekStart = Carbon::now($tz)->startOfWeek(Carbon::MONDAY)->addWeeks($weekOffset);
        $today = Carbon::now($tz)->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $out = [];
        $total = 0;

        for ($i = 0; $i < 7; $i++) {
            $start = $weekStart->copy()->addDays($i);
            $end = $start->copy()->addDay();
            $count = Appointment::query()
                ->where('status', 'scheduled')
                ->whereBetween('start_time', [$start->copy()->utc(), $end->copy()->utc()])
                ->count();
            $total += $count;
            $weekday = $start->format('D');
            $isToday = $start->isSameDay($today);
            $isTomorrow = $start->isSameDay($tomorrow);
            $out[] = [
                'label' => $start->format('d.m'),
                'weekday' => $isToday ? 'Today · '.$weekday : ($isTomorrow ? 'Tomorrow · '.$weekday : $weekday),
                'date' => $start->format('Y-m-d'),
                'count' => $count,
                'is_today' => $isToday,
                'is_tomorrow' => $isTomorrow,
                'is_past' => $start->lt($today),
            ];
        }

        return ['days' => $out, 'total' => $total];
    }

    /** UTC bounds for the current calendar week (Mon 00:00 → next Mon 00:00). */
    private function weekBoundsUtc(): array
    {
        $tz = $this->tz();
        $start = Carbon::now($tz)->startOfWeek(Carbon::MONDAY);

        return [$start->copy()->utc(), $start->copy()->addWeek()->utc()];
    }

    /**
     * Call-outcome breakdown for THIS week plus derived rates and the number of
     * "kept" deal browsers. Powers the "This week at a glance" panel.
     */
    public function outcomeStats(): array
    {
        [$start, $end] = $this->weekBoundsUtc();

        $by = Appointment::query()
            ->whereBetween('start_time', [$start, $end])
            ->selectRaw('outcome, count(*) as c')
            ->groupBy('outcome')
            ->pluck('c', 'outcome');

        $total = (int) $by->sum();
        $won = (int) ($by[Appointment::OUTCOME_DEAL] ?? 0);
        $noShow = (int) ($by['no_show'] ?? 0);
        $rescheduled = (int) ($by['rescheduled'] ?? 0);

        $attended = 0;
        foreach (Appointment::OUTCOMES_ATTENDED as $key) {
            $attended += (int) ($by[$key] ?? 0);
        }

        return [
            'total' => $total,
            'joined' => $attended,
            'won' => $won,
            'no_show' => $noShow,
            'rescheduled' => $rescheduled,
            'kept_browsers' => (int) BrowserProfile::query()->where('is_kept', true)->count(),
            'show_rate' => $total > 0 ? (int) round(($attended / $total) * 100) : 0,
            'no_show_rate' => $total > 0 ? (int) round(($noShow / $total) * 100) : 0,
            'win_rate' => $attended > 0 ? (int) round(($won / $attended) * 100) : 0,
        ];
    }

    /**
     * Lead → Call → Profile → Deal conversion funnel (all-time).
     *
     * @return list<array{label:string,value:int,pct:int}>
     */
    public function funnel(): array
    {
        $leads = (int) Contact::query()->count();
        $withCalls = (int) Contact::query()->whereHas('appointments', fn ($q) => $q->where('status', 'scheduled'))->count();
        $withProfiles = (int) Contact::query()
            ->whereHas('appointments.profiles', fn ($q) => $q->where('status', 'created'))
            ->count();
        $deals = (int) Contact::query()
            ->whereHas('appointments', fn ($q) => $q->where('outcome', Appointment::OUTCOME_DEAL))
            ->count();

        $base = max(1, $leads);

        return [
            ['label' => 'Leads', 'value' => $leads, 'pct' => 100],
            ['label' => 'Scheduled a call', 'value' => $withCalls, 'pct' => (int) round($withCalls / $base * 100)],
            ['label' => 'Browser profile built', 'value' => $withProfiles, 'pct' => (int) round($withProfiles / $base * 100)],
            ['label' => 'Deal won', 'value' => $deals, 'pct' => (int) round($deals / $base * 100)],
        ];
    }

    /**
     * Top lead countries by number of scheduled calls.
     *
     * @return list<array{code:string,count:int,pct:int}>
     */
    public function topCountries(int $limit = 6): array
    {
        $rows = Appointment::query()
            ->where('status', 'scheduled')
            ->whereNotNull('country_code')
            ->where('country_code', '!=', '')
            ->selectRaw('upper(country_code) as code, count(*) as c')
            ->groupBy('code')
            ->orderByDesc('c')
            ->limit($limit)
            ->get();

        $max = (int) ($rows->max('c') ?: 1);

        return $rows->map(fn ($r) => [
            'code' => (string) $r->code,
            'count' => (int) $r->c,
            'pct' => (int) round(((int) $r->c) / $max * 100),
        ])->all();
    }

    /**
     * New leads created today / this week / total (display timezone).
     *
     * @return array{today:int,week:int,total:int}
     */
    public function newLeads(): array
    {
        $tz = $this->tz();
        $todayStart = Carbon::now($tz)->startOfDay()->utc();
        [$weekStart] = $this->weekBoundsUtc();

        return [
            'today' => (int) Contact::query()->where('created_at', '>=', $todayStart)->count(),
            'week' => (int) Contact::query()->where('created_at', '>=', $weekStart)->count(),
            'total' => (int) Contact::query()->count(),
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
