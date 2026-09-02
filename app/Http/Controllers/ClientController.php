<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\AppointmentService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $companySlug = trim((string) $request->query('company', ''));
        $search = trim((string) $request->query('q', ''));
        $hasCall = $request->query('has_call'); // upcoming|any|none|''
        $schedulePreset = trim((string) $request->query('schedule', '')); // today|tomorrow|''
        $scheduleFrom = trim((string) $request->query('from', ''));
        $scheduleTo = trim((string) $request->query('to', ''));

        [$rangeStart, $rangeEnd] = $this->resolveScheduleRange($schedulePreset, $scheduleFrom, $scheduleTo);

        $contacts = $this->filteredContacts(
            $companySlug,
            $search,
            $hasCall,
            $rangeStart,
            $rangeEnd
        );

        $companies = Company::query()->orderBy('name')->get();

        return view('clients.index', compact(
            'contacts',
            'companies',
            'companySlug',
            'search',
            'hasCall',
            'schedulePreset',
            'scheduleFrom',
            'scheduleTo'
        ));
    }

    public function export(Request $request): StreamedResponse
    {
        $companySlug = trim((string) $request->query('company', ''));
        $search = trim((string) $request->query('q', ''));
        $hasCall = $request->query('has_call');
        $schedulePreset = trim((string) $request->query('schedule', ''));
        $scheduleFrom = trim((string) $request->query('from', ''));
        $scheduleTo = trim((string) $request->query('to', ''));

        [$rangeStart, $rangeEnd] = $this->resolveScheduleRange($schedulePreset, $scheduleFrom, $scheduleTo);

        $contacts = $this->filteredContacts(
            $companySlug,
            $search,
            $hasCall,
            $rangeStart,
            $rangeEnd
        );

        $filename = 'clients-export-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($contacts) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens special characters correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['First Name', 'Last Name', 'Email']);

            foreach ($contacts as $contact) {
                fputcsv($out, [
                    (string) ($contact->first_name ?? ''),
                    (string) ($contact->last_name ?? ''),
                    (string) ($contact->email ?? ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function createMissingProfiles(Request $request, AppointmentService $service): RedirectResponse
    {
        $selected = array_values(array_filter(array_map('intval', (array) $request->input('appointment_ids', []))));

        $redirectQuery = array_filter([
            'company' => $request->input('company', ''),
            'q' => $request->input('q', ''),
            'has_call' => $request->input('has_call', ''),
            'schedule' => $request->input('schedule', ''),
            'from' => $request->input('from', ''),
            'to' => $request->input('to', ''),
        ], fn ($v) => $v !== '' && $v !== null);

        if ($selected === []) {
            [$rangeStart, $rangeEnd] = $this->resolveScheduleRange(
                trim((string) $request->input('schedule', '')),
                trim((string) $request->input('from', '')),
                trim((string) $request->input('to', ''))
            );

            $contacts = $this->filteredContacts(
                trim((string) $request->input('company', '')),
                trim((string) $request->input('q', '')),
                $request->input('has_call'),
                $rangeStart,
                $rangeEnd
            );

            $selected = $contacts
                ->map(fn (Contact $c) => (int) ($c->display_appointment_id ?? 0))
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($selected === []) {
            return redirect()->route('clients.index', $redirectQuery)
                ->with('warning', 'No appointments found to create profiles for.');
        }

        $createdGeo = 0;
        $createdStatic = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($selected as $appointmentId) {
            $appointment = Appointment::query()->with(['contact', 'profiles', 'company'])->find($appointmentId);
            if (! $appointment) {
                $failed++;
                continue;
            }

            try {
                $result = $service->createMissingProfiles($appointment);
                $createdGeo += in_array('geo', $result['created'], true) ? 1 : 0;
                $createdStatic += in_array('static', $result['created'], true) ? 1 : 0;
                $skipped += count($result['skipped']);
                $failed += count($result['failed']);
                foreach ($result['failed'] as $item) {
                    $errors[] = "#{$appointmentId} {$item['role']}: {$item['error']}";
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "#{$appointmentId}: {$e->getMessage()}";
            }
        }

        $message = sprintf(
            'Profiles: GEO created %d, STATIC created %d, skipped slots %d, failed %d.',
            $createdGeo,
            $createdStatic,
            $skipped,
            $failed
        );
        if ($errors) {
            $message .= ' '.implode(' | ', array_slice($errors, 0, 3));
        }

        $flashType = $failed > 0 && ($createdGeo + $createdStatic) === 0 ? 'danger' : 'success';

        return redirect()->route('clients.index', $redirectQuery)->with($flashType, $message);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Contact>
     */
    private function filteredContacts(
        string $companySlug,
        string $search,
        mixed $hasCall,
        ?Carbon $rangeStart,
        ?Carbon $rangeEnd
    ) {
        $query = Contact::query()
            ->with([
                'ownerCompany',
                'appointments' => fn ($q) => $q->orderBy('start_time'),
                'appointments.profiles',
            ])
            ->orderByDesc('created_at');

        if ($companySlug !== '') {
            $query->whereHas('ownerCompany', fn ($q) => $q->where('slug', $companySlug));
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('email', 'ilike', $like)
                    ->orWhere('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('referrer', 'ilike', $like);
            });
        }

        if ($rangeStart && $rangeEnd) {
            $query->whereHas('appointments', function ($q) use ($rangeStart, $rangeEnd) {
                $q->where('status', 'scheduled')
                    ->where('start_time', '>=', $rangeStart)
                    ->where('start_time', '<', $rangeEnd);
            });
        } elseif ($hasCall === 'upcoming') {
            $query->whereHas('appointments', function ($q) {
                $q->where('status', 'scheduled')
                    ->where('start_time', '>=', now());
            });
        } elseif ($hasCall === 'any') {
            $query->whereHas('appointments');
        } elseif ($hasCall === 'none') {
            $query->whereDoesntHave('appointments');
        }

        $contacts = $query->get()->map(function (Contact $contact) use ($rangeStart, $rangeEnd) {
            $scheduled = $contact->appointments->where('status', 'scheduled');

            $inRange = null;
            if ($rangeStart && $rangeEnd) {
                $inRange = $scheduled
                    ->filter(fn ($a) => $a->start_time
                        && $a->start_time->gte($rangeStart)
                        && $a->start_time->lt($rangeEnd))
                    ->sortBy('start_time')
                    ->first();
            }

            $upcoming = $scheduled
                ->filter(fn ($a) => $a->start_time && $a->start_time->gte(now()))
                ->sortBy('start_time')
                ->first();

            $latest = $contact->appointments->sortByDesc('start_time')->first();
            $display = $inRange ?? $upcoming ?? $latest;

            $contact->next_call_at = $display?->start_time;
            $contact->next_call_status = $display?->status;
            $contact->calls_count = $contact->appointments->count();
            $contact->display_appointment_id = $display?->id;

            $roles = $display
                ? $display->profiles
                    ->whereIn('status', ['reserved', 'created'])
                    ->pluck('profile_role')
                    ->all()
                : [];
            $contact->has_geo_profile = in_array('geo', $roles, true);
            $contact->has_static_profile = in_array('static', $roles, true);

            $parts = array_values(array_filter([
                trim((string) ($display?->city ?? '')),
                trim((string) ($display?->region ?? '')),
                trim((string) ($display?->country_code ?: $display?->country ?: '')),
            ], fn ($p) => $p !== ''));
            $contact->geo_location = $parts ? implode(',', $parts) : '';
            $contact->geo_profile_name = $display
                ? (string) ($display->profiles
                    ->where('profile_role', 'geo')
                    ->whereIn('status', ['reserved', 'created'])
                    ->sortByDesc('id')
                    ->first()
                    ?->profile_name ?? '')
                : '';

            return $contact;
        });

        if ($rangeStart && $rangeEnd) {
            $contacts = $contacts->sortBy(fn ($c) => optional($c->next_call_at)->timestamp ?? PHP_INT_MAX)->values();
        }

        return $contacts;
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveScheduleRange(string $preset, string $from, string $to): array
    {
        $tz = config('app.timezone');

        if ($preset === 'today') {
            $start = Carbon::now($tz)->startOfDay();

            return [$start, $start->copy()->addDay()];
        }

        if ($preset === 'tomorrow') {
            $start = Carbon::now($tz)->addDay()->startOfDay();

            return [$start, $start->copy()->addDay()];
        }

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

        return [$start, $end];
    }
}
