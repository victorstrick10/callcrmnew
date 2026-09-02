<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\StaticProxy;
use App\Services\AppointmentService;
use App\Services\StaticProxyService;
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
        $schedulePreset = trim((string) $request->query('schedule', '')); // today|tomorrow|week|''
        $scheduleFrom = trim((string) $request->query('from', ''));
        $scheduleTo = trim((string) $request->query('to', ''));
        $sort = trim((string) $request->query('sort', 'created'));
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        [$rangeStart, $rangeEnd] = $this->resolveScheduleRange($schedulePreset, $scheduleFrom, $scheduleTo);

        $contacts = $this->filteredContacts(
            $companySlug,
            $search,
            $hasCall,
            $rangeStart,
            $rangeEnd
        );

        $contacts = $this->sortContacts($contacts, $sort, $dir, (bool) ($rangeStart && $rangeEnd));

        $companies = Company::query()->orderBy('name')->get();

        return view('clients.index', compact(
            'contacts',
            'companies',
            'companySlug',
            'search',
            'hasCall',
            'schedulePreset',
            'scheduleFrom',
            'scheduleTo',
            'sort',
            'dir'
        ));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Contact>  $contacts
     * @return \Illuminate\Support\Collection<int, Contact>
     */
    private function sortContacts($contacts, string $sort, string $dir, bool $rangeActive)
    {
        $keys = [
            'name' => fn (Contact $c) => mb_strtolower((string) $c->full_name),
            'company' => fn (Contact $c) => mb_strtolower((string) ($c->ownerCompany?->name ?? '')),
            'created' => fn (Contact $c) => optional($c->created_at)->timestamp ?? 0,
            'call' => fn (Contact $c) => optional($c->next_call_at)->timestamp ?? 0,
            'calls' => fn (Contact $c) => (int) $c->calls_count,
            'location' => fn (Contact $c) => mb_strtolower((string) ($c->geo_location ?? '')),
        ];

        $key = $keys[$sort] ?? $keys['created'];
        $sorted = $contacts->sortBy($key, SORT_REGULAR, $dir === 'desc');

        return $sorted->values();
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
            fputcsv($out, ['Name Surname', 'Email']);

            foreach ($contacts as $contact) {
                $fullName = trim(((string) ($contact->first_name ?? '')).' '.((string) ($contact->last_name ?? '')));
                $email = (string) ($contact->email ?? '');
                $username = $email !== '' ? explode('@', $email, 2)[0] : '';
                fputcsv($out, [
                    $fullName !== '' ? $fullName : $username,
                    $username,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function createMissingProfiles(Request $request, AppointmentService $service): RedirectResponse
    {
        $role = (string) $request->input('role', 'both');
        $onlyRoles = match ($role) {
            'geo' => ['geo'],
            'static' => ['static'],
            default => null,
        };

        $selected = array_values(array_filter(array_map('intval', (array) $request->input('appointment_ids', []))));

        $redirectQuery = array_filter([
            'company' => $request->input('company', ''),
            'q' => $request->input('q', ''),
            'has_call' => $request->input('has_call', ''),
            'schedule' => $request->input('schedule', ''),
            'from' => $request->input('from', ''),
            'to' => $request->input('to', ''),
        ], fn ($v) => $v !== '' && $v !== null);

        // Never auto-create for all leads — only the leads explicitly selected.
        if ($selected === []) {
            $msg = 'Select at least one lead (tick the checkboxes) before creating profiles. Bulk auto-create for all leads is disabled.';
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => $msg, 'log' => [$msg], 'created' => []]);
            }

            return redirect()->route('clients.index', $redirectQuery)->with('warning', $msg);
        }

        $createdGeo = 0;
        $createdStatic = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $log = [];

        foreach ($selected as $appointmentId) {
            $appointment = Appointment::query()->with(['contact', 'profiles', 'company'])->find($appointmentId);
            if (! $appointment) {
                $failed++;
                continue;
            }

            $who = $appointment->contact?->full_name ?: "Appointment #{$appointmentId}";

            try {
                $result = $service->createMissingProfiles($appointment, $onlyRoles);
                $createdGeo += in_array('geo', $result['created'], true) ? 1 : 0;
                $createdStatic += in_array('static', $result['created'], true) ? 1 : 0;
                $skipped += count($result['skipped']);
                $failed += count($result['failed']);
                foreach (($result['log'] ?? []) as $line) {
                    $log[] = "{$who} — {$line}";
                }
                foreach ($result['failed'] as $item) {
                    $errors[] = "#{$appointmentId} {$item['role']}: {$item['error']}";
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "#{$appointmentId}: {$e->getMessage()}";
                $log[] = "{$who} — ✗ {$e->getMessage()}";
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

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $flashType !== 'danger',
                'message' => $message,
                'log' => $log,
                'created' => [],
            ]);
        }

        return redirect()->route('clients.index', $redirectQuery)->with($flashType, $message);
    }

    /**
     * Run IPinfo geolocation for a bounded batch of leads that have a captured
     * IP but are not enriched yet. The rest is picked up by the 15-minute
     * background job (ipinfo:enrich).
     */
    public function enrichGeo(Request $request, AppointmentService $service): RedirectResponse
    {
        @set_time_limit(120);
        $result = $service->enrichPending(150);

        $msg = "IPinfo: enriched {$result['enriched']} lead(s)"
            .($result['failed'] ? ", {$result['failed']} failed" : '')
            .($result['remaining'] ? ". {$result['remaining']} remaining — the background job continues every 15 min." : '.');

        return back()->with($result['enriched'] > 0 ? 'success' : 'warning', $msg);
    }

    /**
     * Best location match among enabled static proxies (prefers mobile).
     *
     * @param  \Illuminate\Support\Collection<int, StaticProxy>  $proxies
     * @return array{provider:string,location:string,level:string}|null
     */
    private function bestStaticMatch($proxies, ?string $city, ?string $region, ?string $country, ?string $isp = ''): ?array
    {
        $svc = app(StaticProxyService::class);
        $rank = ['city_region' => 5, 'city' => 4, 'region' => 3, 'isp' => 2.5, 'country' => 2];
        $best = null;
        $bestScore = 0.0;

        foreach ($proxies as $p) {
            $level = $svc->matchLevel($p, $city, $region, $country, $isp);
            if (! isset($rank[$level])) {
                continue;
            }

            $score = $rank[$level] + ($p->network_type === 'mobile' ? 0.5 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'provider' => (string) $p->provider,
                    'location' => (string) $p->location,
                    'level' => $level,
                    'exit_country' => (string) $p->exit_country,
                    'exit_region' => (string) $p->exit_region,
                    'exit_city' => (string) $p->exit_city,
                    'exit_isp' => (string) $p->exit_isp,
                    'checked' => $p->last_check_status === 'up',
                ];
            }
        }

        return $best;
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

        $enabledProxies = StaticProxy::query()->enabled()->get();

        $contacts = $query->get()->map(function (Contact $contact) use ($rangeStart, $rangeEnd, $enabledProxies) {
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

            $contact->next_call_at = $display?->localStart();
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

            $contact->geo_city = trim((string) ($display?->city ?? ''));
            $contact->geo_region = trim((string) ($display?->region ?? ''));
            $contact->geo_country = trim((string) ($display?->country ?: $display?->country_code ?: ''));
            $contact->geo_country_code = trim((string) ($display?->country_code ?? ''));
            $contact->geo_provider = trim((string) ($display?->client_isp ?: $display?->client_org ?: ''));

            $parts = array_values(array_filter([
                $contact->geo_city,
                $contact->geo_region,
                trim((string) ($display?->country_code ?: $display?->country ?: '')),
            ], fn ($p) => $p !== ''));
            $contact->geo_location = $parts ? implode(', ', $parts) : '';
            $contact->geo_profile_name = $display
                ? (string) ($display->profiles
                    ->where('profile_role', 'geo')
                    ->whereIn('status', ['reserved', 'created'])
                    ->sortByDesc('id')
                    ->first()
                    ?->profile_name ?? '')
                : '';

            // Our (static) proxy match — is a MobileHop/ProxyCheap proxy ready for STATIC?
            $sm = $this->bestStaticMatch(
                $enabledProxies,
                $contact->geo_city,
                $contact->geo_region,
                $contact->geo_country_code ?: $contact->geo_country,
                $contact->geo_provider
            );
            $contact->our_proxy_ready = $sm !== null;
            $contact->our_proxy_provider = $sm['provider'] ?? '';
            $contact->our_proxy_location = $sm['location'] ?? '';
            $contact->our_proxy_level = $sm['level'] ?? '';
            $contact->our_proxy_checked = (bool) ($sm['checked'] ?? false);
            $contact->our_proxy_country = $sm['exit_country'] ?? '';
            $contact->our_proxy_region = $sm['exit_region'] ?? '';
            $contact->our_proxy_city = $sm['exit_city'] ?? '';
            $contact->our_proxy_isp = $sm['exit_isp'] ?? '';

            // Multilogin (GEO) proxy readiness from the display appointment.
            $contact->ml_proxy_ready = (bool) ($display && $display->proxy_status === 'ready');
            $contact->ml_proxy_country = trim((string) ($display?->proxy_actual_country ?: $display?->country_code ?: $display?->country ?: ''));
            $contact->ml_proxy_region = trim((string) ($display?->proxy_actual_region ?: $display?->region ?: ''));
            $contact->ml_proxy_city = trim((string) ($display?->proxy_actual_city ?: $display?->city ?: ''));
            $contact->ml_proxy_isp = trim((string) ($display?->proxy_isp ?: ''));
            $contact->ml_proxy_level = trim((string) ($display?->proxy_match_level ?? ''));

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

        if ($preset === 'week') {
            $start = Carbon::now($tz)->startOfDay();

            return [$start, $start->copy()->addDays(7)];
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
