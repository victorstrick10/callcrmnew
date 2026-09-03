<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
            'no_show' => $appointments->where('outcome', 'no_show')->count(),
            'rescheduled' => $appointments->where('outcome', 'rescheduled')->count(),
            'left_early' => $appointments->where('outcome', 'left_early')->count(),
            'closed_won' => $appointments->where('outcome', 'closed_won')->count(),
            'commented' => $appointments->filter(fn (Appointment $a) => trim((string) $a->outcome_note) !== '')->count(),
        ];

        return view('outcomes.index', [
            'appointments' => $appointments,
            'summary' => $summary,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'outcomes' => Appointment::OUTCOMES,
        ]);
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
