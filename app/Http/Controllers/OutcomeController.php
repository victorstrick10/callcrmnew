<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $rangeParam = $request->query('range');
        if ($rangeParam !== null) {
            $range = (string) $rangeParam;
        } elseif ($from !== '' || $to !== '') {
            $range = 'custom';
        } else {
            $range = 'today';
        }
        if (! in_array($range, ['today', 'this_week', 'last_week', 'all', 'custom'], true)) {
            $range = 'today';
        }

        [$start, $end] = $this->rangeBounds($range, $from, $to);

        $query = Appointment::query()
            ->with(['contact', 'company', 'profiles'])
            ->orderBy('start_time'); // earliest call first

        if ($start && $end) {
            $query->whereBetween('start_time', [$start, $end]);
        }

        $appointments = $query->get();

        $summary = [
            'total' => $appointments->count(),
            'joined' => $appointments->whereIn('outcome', Appointment::OUTCOMES_ATTENDED)->count(),
            'deals' => $appointments->where('outcome', Appointment::OUTCOME_DEAL)->count(),
            'no_show' => $appointments->where('outcome', 'no_show')->count(),
            'rescheduled' => $appointments->where('outcome', 'rescheduled')->count(),
            'commented' => $appointments->filter(fn (Appointment $a) => trim((string) $a->outcome_note) !== '')->count(),
        ];

        return view('outcomes.index', [
            'appointments' => $appointments,
            'summary' => $summary,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'outcomes' => Appointment::OUTCOMES,
            'analytics' => $this->analytics(),
            'trend' => $this->trend(),
        ]);
    }

    /**
     * Outcome distribution + rates for rolling windows (1/3/6/12 months).
     */
    private function analytics(): array
    {
        $tz = config('app.display_timezone') ?: config('app.timezone');
        $now = Carbon::now($tz);

        $windows = [
            'month' => $now->copy()->subMonth(),
            'q3' => $now->copy()->subMonths(3),
            'q6' => $now->copy()->subMonths(6),
            'year' => $now->copy()->subYear(),
        ];

        $out = [];
        foreach ($windows as $key => $start) {
            $by = Appointment::query()
                ->where('start_time', '>=', $start->copy()->utc())
                ->selectRaw('outcome, count(*) as c')
                ->groupBy('outcome')
                ->pluck('c', 'outcome');

            $b = [
                'scheduled' => (int) ($by['scheduled'] ?? 0) + (int) ($by['pending'] ?? 0),
                'joined_line' => (int) ($by['joined_line'] ?? 0),
                'joined_vorr' => (int) ($by['joined_vorr'] ?? 0),
                'joined_left' => (int) ($by['joined_left'] ?? 0),
                'no_show' => (int) ($by['no_show'] ?? 0),
                'rescheduled' => (int) ($by['rescheduled'] ?? 0),
                'canceled' => (int) ($by['canceled'] ?? 0),
            ];
            $total = array_sum($b);
            $attended = $b['joined_line'] + $b['joined_vorr'] + $b['joined_left'];
            $won = $b['joined_line'];

            $out[$key] = [
                'counts' => $b,
                'total' => $total,
                'show_rate' => $total > 0 ? (int) round($attended / $total * 100) : 0,
                'win_rate' => $attended > 0 ? (int) round($won / $attended * 100) : 0,
                'deals' => $won,
            ];
        }

        return $out;
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

            $by = Appointment::query()
                ->whereBetween('start_time', [$start, $end])
                ->selectRaw('outcome, count(*) as c')
                ->groupBy('outcome')
                ->pluck('c', 'outcome');

            $outcomes = [
                'scheduled' => (int) ($by['scheduled'] ?? 0) + (int) ($by['pending'] ?? 0),
                'joined_line' => (int) ($by['joined_line'] ?? 0),
                'joined_vorr' => (int) ($by['joined_vorr'] ?? 0),
                'joined_left' => (int) ($by['joined_left'] ?? 0),
                'no_show' => (int) ($by['no_show'] ?? 0),
                'rescheduled' => (int) ($by['rescheduled'] ?? 0),
                'canceled' => (int) ($by['canceled'] ?? 0),
            ];

            $out[] = [
                'label' => $monthStart->format('M y'),
                'calls' => array_sum($outcomes),
                'deals' => $outcomes['joined_line'],
                'outcomes' => $outcomes,
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

        $rangeParam = $request->query('range');
        if ($rangeParam !== null) {
            $range = (string) $rangeParam;
        } elseif ($from !== '' || $to !== '') {
            $range = 'custom';
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
        $appointments = $query->get();

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
