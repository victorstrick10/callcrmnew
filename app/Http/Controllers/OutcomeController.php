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
        $range = (string) $request->query('range', 'this_week');
        if (! in_array($range, ['this_week', 'last_week', 'all'], true)) {
            $range = 'this_week';
        }

        [$start, $end] = $this->rangeBounds($range);

        $query = Appointment::query()
            ->with(['contact', 'company', 'profiles'])
            ->orderByDesc('start_time');

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
            'outcomes' => Appointment::OUTCOMES,
        ]);
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(Appointment::OUTCOMES))],
            'outcome_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment->outcome = $data['outcome'];
        $appointment->outcome_note = trim((string) ($data['outcome_note'] ?? ''));
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
    private function rangeBounds(string $range): array
    {
        if ($range === 'all') {
            return [null, null];
        }

        $tz = config('app.display_timezone') ?: config('app.timezone');
        $weekStart = Carbon::now($tz)->startOfWeek(Carbon::MONDAY);

        if ($range === 'last_week') {
            $start = $weekStart->copy()->subWeek();

            return [$start->copy()->utc(), $weekStart->copy()->utc()];
        }

        return [$weekStart->copy()->utc(), $weekStart->copy()->addWeek()->utc()];
    }
}
