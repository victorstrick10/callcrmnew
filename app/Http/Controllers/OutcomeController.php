<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutcomeController extends Controller
{
    /**
     * Call outcomes board: log what happened on each call (no-show, reschedule,
     * deal closed, …), add per-client comments, mark which browser to keep, and
     * see a weekly summary.
     */
    public function index(Request $request): View
    {
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        $search = trim((string) $request->query('q', ''));

        $rangeParam = $request->query('range');
        if ($rangeParam !== null) {
            $range = (string) $rangeParam;
        } elseif ($from !== '' || $to !== '') {
            $range = 'custom';
        } elseif ($search !== '') {
            // Searching should find old calls across all dates by default.
            $range = 'all';
        } else {
            $range = 'today';
        }
        if (! in_array($range, ['today', 'this_week', 'last_week', 'month', 'q3', 'q6', 'year', 'all', 'custom'], true)) {
            $range = 'today';
        }

        [$start, $end] = $this->rangeBounds($range, $from, $to);

        $query = Appointment::query()
            ->with(['contact.appointments.profiles', 'company', 'profiles'])
            ->orderBy('start_time'); // earliest call first

        if ($start && $end) {
            $query->whereBetween('start_time', [$start, $end]);
        }
        $this->applySearch($query, $search);

        $all = $query->get();

        // Summary by EFFECTIVE outcome (canceled/rescheduled/scheduled derived
        // from status) so the cards match the gauges.
        $summary = [
            'total' => $all->count(),
            'joined' => $all->filter(fn (Appointment $a) => in_array($a->effectiveOutcome(), Appointment::OUTCOMES_ATTENDED, true))->count(),
            'deals' => $all->filter(fn (Appointment $a) => $a->effectiveOutcome() === Appointment::OUTCOME_DEAL)->count(),
            'no_show' => $all->filter(fn (Appointment $a) => $a->effectiveOutcome() === 'no_show')->count(),
            'rescheduled' => $all->filter(fn (Appointment $a) => $a->effectiveOutcome() === 'rescheduled')->count(),
            'commented' => $all->filter(fn (Appointment $a) => trim((string) $a->outcome_note) !== '')->count(),
        ];

        // Clickable stat cards filter the visible calls by effective outcome.
        $outcomeFilter = (string) $request->query('outcome', '');
        $filtered = $this->filterByOutcome($all, $outcomeFilter);

        // Paginate the visible table (default 10/page; user-selectable).
        $perPageParam = (string) $request->query('per_page', '10');
        if (! in_array($perPageParam, ['10', '20', '25', 'all'], true)) {
            $perPageParam = '10';
        }
        $perPage = $perPageParam === 'all' ? max(1, $filtered->count()) : (int) $perPageParam;

        $page = LengthAwarePaginator::resolveCurrentPage();
        $appointments = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('outcomes.index', [
            'appointments' => $appointments,
            'copyList' => $filtered,
            'summary' => $summary,
            'outcomeFilter' => $outcomeFilter,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'search' => $search,
            'perPage' => $perPageParam,
            'outcomes' => Appointment::OUTCOMES,
            'analytics' => $this->bucketizeCollection($all),
            'trend' => $this->trend(),
        ]);
    }

    /**
     * Bucketize the currently-filtered calls (a collection) by effective outcome,
     * so the gauges reflect exactly the calls you're viewing/updating.
     */
    private function bucketizeCollection($items): array
    {
        $b = ['scheduled' => 0, 'joined_line' => 0, 'joined_vorr' => 0, 'joined_left' => 0, 'no_show' => 0, 'rescheduled' => 0, 'canceled' => 0];
        $total = 0;

        foreach ($items as $a) {
            $total++;
            $eff = $this->effectiveKey((string) $a->outcome, (string) $a->status);
            if (isset($b[$eff])) {
                $b[$eff]++;
            }
        }

        $attended = $b['joined_line'] + $b['joined_vorr'] + $b['joined_left'];
        $won = $b['joined_line'];

        return [
            'counts' => $b,
            'total' => $total,
            'show_rate' => $total > 0 ? (int) round($attended / $total * 100) : 0,
            'win_rate' => $attended > 0 ? (int) round($won / $attended * 100) : 0,
            'deals' => $won,
        ];
    }

    /**
     * Aggregate (outcome, status) count rows into the 7 display buckets using
     * the EFFECTIVE outcome — so un-logged canceled/rescheduled/scheduled calls
     * are counted from their Calendly status, not left as "pending".
     *
     * @param  \Illuminate\Support\Collection  $rows  rows of {outcome, status, c}
     */
    private function bucketize($rows): array
    {
        $b = ['scheduled' => 0, 'joined_line' => 0, 'joined_vorr' => 0, 'joined_left' => 0, 'no_show' => 0, 'rescheduled' => 0, 'canceled' => 0];
        $total = 0;

        foreach ($rows as $r) {
            $c = (int) $r->c;
            $total += $c;
            $eff = $this->effectiveKey((string) $r->outcome, (string) $r->status);
            if (isset($b[$eff])) {
                $b[$eff] += $c;
            }
        }

        $attended = $b['joined_line'] + $b['joined_vorr'] + $b['joined_left'];
        $won = $b['joined_line'];

        return [
            'counts' => $b,
            'total' => $total,
            'show_rate' => $total > 0 ? (int) round($attended / $total * 100) : 0,
            'win_rate' => $attended > 0 ? (int) round($won / $attended * 100) : 0,
            'deals' => $won,
        ];
    }

    /** Effective outcome bucket key: logged outcome, else derived from status. */
    private function effectiveKey(string $outcome, string $status): string
    {
        if (! in_array($outcome, ['', 'pending'], true)) {
            return $outcome;
        }

        return match ($status) {
            'canceled' => 'canceled',
            'rescheduled' => 'rescheduled',
            default => 'scheduled',
        };
    }

    /**
     * Calls + deals per month for the last 12 months (chronological).
     *
     * @return list<array{label:string,calls:int,deals:int}>
     */
    private function trend(): array
    {
        $tz = config('app.display_timezone') ?: config('app.timezone');
        $out = [];

        for ($i = 11; $i >= 0; $i--) {
            $monthStart = Carbon::now($tz)->startOfMonth()->subMonths($i);
            $start = $monthStart->copy()->utc();
            $end = $monthStart->copy()->addMonth()->utc();

            $rows = Appointment::query()
                ->whereBetween('start_time', [$start, $end])
                ->selectRaw('outcome, status, count(*) as c')
                ->groupBy('outcome', 'status')
                ->get();

            $bk = $this->bucketize($rows);

            $out[] = [
                'label' => $monthStart->format('M y'),
                'calls' => $bk['total'],
                'deals' => $bk['counts']['joined_line'],
                'outcomes' => $bk['counts'],
            ];
        }

        return $out;
    }

    /**
     * CSV export of the calls in the current filter:
     * Lead Name | Company | Call time (GMT+1) | Outcome | Comment.
     */
    public function export(Request $request): StreamedResponse
    {
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        $search = trim((string) $request->query('q', ''));

        $rangeParam = $request->query('range');
        if ($rangeParam !== null) {
            $range = (string) $rangeParam;
        } elseif ($from !== '' || $to !== '') {
            $range = 'custom';
        } elseif ($search !== '') {
            $range = 'all';
        } else {
            $range = 'today';
        }
        if (! in_array($range, ['today', 'this_week', 'last_week', 'all', 'custom'], true)) {
            $range = 'today';
        }

        [$start, $end] = $this->rangeBounds($range, $from, $to);

        $query = Appointment::query()->with(['contact', 'company'])->orderBy('start_time');
        if ($start && $end) {
            $query->whereBetween('start_time', [$start, $end]);
        }
        $this->applySearch($query, $search);
        $appointments = $this->filterByOutcome($query->get(), (string) $request->query('outcome', ''));

        $filename = 'call-stats-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($appointments) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Lead Name', 'Company', 'Call time', 'Outcome', 'Comment']);

            foreach ($appointments as $a) {
                $label = $a->hasCustomOutcome()
                    ? $a->outcome
                    : (Appointment::OUTCOMES[$a->effectiveOutcome()] ?? $a->effectiveOutcome());

                fputcsv($out, [
                    (string) ($a->contact?->full_name ?? ''),
                    (string) ($a->company?->name ?? ''),
                    $a->localStart()?->format('d.m.Y H:i') ?? '',
                    $label,
                    (string) $a->outcome_note,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $request->validate(['outcome_note' => ['nullable', 'string', 'max:2000']]);

        $outcome = (string) $request->input('outcome', 'pending');
        if ($outcome === '__custom__') {
            $request->validate(['outcome_custom' => ['required', 'string', 'max:30']]);
            $outcome = trim((string) $request->input('outcome_custom'));
        } elseif (! array_key_exists($outcome, Appointment::OUTCOMES)) {
            $outcome = 'pending';
        }

        $appointment->outcome = mb_substr($outcome, 0, 30);
        $appointment->outcome_note = trim((string) $request->input('outcome_note', ''));
        $appointment->outcome_at = now();
        $appointment->save();

        return back()->with('success', 'Saved outcome for '.($appointment->contact?->full_name ?: 'lead').'.');
    }

    /**
     * Toggle "keep this browser forever" (the profile saved when a deal closes).
     */
    public function keepProfile(BrowserProfile $browserProfile): RedirectResponse
    {
        $browserProfile->is_kept = ! $browserProfile->is_kept;
        $browserProfile->save();

        return back()->with(
            'success',
            $browserProfile->is_kept
                ? 'Marked browser '.$browserProfile->profile_name.' as kept (deal).'
                : 'Unmarked browser '.$browserProfile->profile_name.'.'
        );
    }

    /** Filter a call collection by the clicked stat card (effective outcome). */
    private function filterByOutcome($items, string $outcome)
    {
        return (match ($outcome) {
            'joined' => $items->filter(fn (Appointment $a) => in_array($a->effectiveOutcome(), Appointment::OUTCOMES_ATTENDED, true)),
            'deals' => $items->filter(fn (Appointment $a) => $a->effectiveOutcome() === Appointment::OUTCOME_DEAL),
            'no_show' => $items->filter(fn (Appointment $a) => $a->effectiveOutcome() === 'no_show'),
            'rescheduled' => $items->filter(fn (Appointment $a) => $a->effectiveOutcome() === 'rescheduled'),
            'canceled' => $items->filter(fn (Appointment $a) => $a->effectiveOutcome() === 'canceled'),
            'scheduled' => $items->filter(fn (Appointment $a) => $a->effectiveOutcome() === 'scheduled'),
            'commented' => $items->filter(fn (Appointment $a) => trim((string) $a->outcome_note) !== ''),
            default => $items,
        })->values();
    }

    /** Filter calls by lead name/email, company name, or event name. */
    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function ($w) use ($like) {
            $w->where('event_name', 'ilike', $like)
                ->orWhereHas('contact', function ($c) use ($like) {
                    $c->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like)
                        // Match the full "First Last" name (with the space between).
                        ->orWhereRaw("(coalesce(first_name,'') || ' ' || coalesce(last_name,'')) ILIKE ?", [$like])
                        // And the reversed "Last First" order.
                        ->orWhereRaw("(coalesce(last_name,'') || ' ' || coalesce(first_name,'')) ILIKE ?", [$like]);
                })
                ->orWhereHas('company', fn ($c) => $c->where('name', 'ilike', $like));
        });
    }

    /**
     * @return array{0:?Carbon,1:?Carbon} UTC bounds for the selected range.
     */
    private function rangeBounds(string $range, string $from = '', string $to = ''): array
    {
        if ($range === 'all') {
            return [null, null];
        }

        $tz = config('app.display_timezone') ?: config('app.timezone');

        if ($range === 'today') {
            $start = Carbon::now($tz)->startOfDay();

            return [$start->copy()->utc(), $start->copy()->addDay()->utc()];
        }

        if ($range === 'this_week') {
            $start = Carbon::now($tz)->startOfWeek(Carbon::MONDAY);

            return [$start->copy()->utc(), $start->copy()->addWeek()->utc()];
        }

        if ($range === 'last_week') {
            $start = Carbon::now($tz)->startOfWeek(Carbon::MONDAY)->subWeek();

            return [$start->copy()->utc(), $start->copy()->addWeek()->utc()];
        }

        $months = ['month' => 1, 'q3' => 3, 'q6' => 6, 'year' => 12];
        if (isset($months[$range])) {
            $start = Carbon::now($tz)->subMonths($months[$range])->startOfDay();
            $end = Carbon::now($tz)->startOfDay()->addDay();

            return [$start->copy()->utc(), $end->copy()->utc()];
        }

        // Custom from/to range.
        $start = null;
        $end = null;
        try {
            if ($from !== '') {
                $start = Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay();
            }
            if ($to !== '') {
                $end = Carbon::createFromFormat('Y-m-d', $to, $tz)->startOfDay()->addDay();
            }
        } catch (\Throwable) {
            return [null, null];
        }

        if ($start && ! $end) {
            $end = $start->copy()->addDay();
        }
        if ($end && ! $start) {
            $start = $end->copy()->subDay();
        }
        if ($start && $end && $end->lte($start)) {
            $end = $start->copy()->addDay();
        }

        return [$start?->copy()->utc(), $end?->copy()->utc()];
    }
}
