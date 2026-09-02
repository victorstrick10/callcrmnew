<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\StaticProxy;
use App\Services\AppointmentService;
use App\Services\MultiloginClient;
use App\Services\ProfileNameFormatter;
use App\Services\ProfileNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AppointmentController extends Controller
{
    public function index(\Illuminate\Http\Request $request): View
    {
        $companySlug = trim((string) $request->query('company', ''));
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $sort = trim((string) $request->query('sort', 'date'));
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Appointment::query()
            ->with(['contact', 'profiles', 'company'])
            ->orderByDesc('start_time');

        if ($companySlug !== '') {
            $query->whereHas('company', fn ($q) => $q->where('slug', $companySlug));
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('event_name', 'ilike', $like)
                    ->orWhereHas('contact', function ($c) use ($like) {
                        $c->where('first_name', 'ilike', $like)
                            ->orWhere('last_name', 'ilike', $like)
                            ->orWhere('email', 'ilike', $like);
                    });
            });
        }

        $appointments = $this->sortAppointments($query->get(), $sort, $dir);

        $companies = \App\Models\Company::query()->orderBy('name')->get();

        return view('appointments.index', compact(
            'appointments',
            'companies',
            'companySlug',
            'search',
            'status',
            'sort',
            'dir'
        ));
    }

    private function sortAppointments($appointments, string $sort, string $dir)
    {
        $keys = [
            'company' => fn (Appointment $a) => mb_strtolower((string) ($a->company?->name ?? '')),
            'client' => fn (Appointment $a) => mb_strtolower((string) ($a->contact?->full_name ?? '')),
            'event' => fn (Appointment $a) => mb_strtolower((string) $a->event_name),
            'date' => fn (Appointment $a) => optional($a->start_time)->timestamp ?? 0,
            'status' => fn (Appointment $a) => (string) $a->status,
            'location' => fn (Appointment $a) => mb_strtolower((string) ($a->city ?: $a->country ?: '')),
            'profiles' => fn (Appointment $a) => $a->profiles->count(),
        ];

        $key = $keys[$sort] ?? $keys['date'];

        return $appointments->sortBy($key, SORT_REGULAR, $dir === 'desc')->values();
    }

    public function show(
        Appointment $appointment,
        ProfileNumberService $numbers,
        ProfileNameFormatter $names,
        MultiloginClient $multilogin
    ): View {
        $appointment->load(['contact', 'profiles', 'company']);
        $proxyCandidates = $appointment->proxy_candidates_json ?? [];
        if (! is_array($proxyCandidates)) {
            $proxyCandidates = [];
        }

        $builder = $this->builderData($appointment, $numbers, $names, $multilogin);

        return view('appointments.show', [
            'appointment' => $appointment,
            'proxyCandidates' => $proxyCandidates,
            'formatNumber' => fn (int $n) => $numbers->formatNumber($n),
            'builder' => $builder,
        ]);
    }

    /**
     * Pre-creation preview data for the advanced "Profile Builder" modal:
     * the number that will be used, live GEO/STATIC name previews, eligibility
     * of each role, and readiness warnings.
     */
    private function builderData(
        Appointment $appointment,
        ProfileNumberService $numbers,
        ProfileNameFormatter $names,
        MultiloginClient $multilogin
    ): array {
        $company = $appointment->company;
        $fullName = $appointment->contact->full_name;

        $existingRoles = $appointment->profiles
            ->whereIn('status', ['reserved', 'created'])
            ->pluck('profile_role')
            ->unique()
            ->all();

        $previewNumber = (int) ($appointment->profiles->where('number', '>', 0)->min('number') ?? 0);
        if ($previewNumber < 1 && $company) {
            try {
                $previewNumber = $numbers->nextNumber($company->id);
            } catch (Throwable) {
                $previewNumber = 0;
            }
        }

        $country = $appointment->country_code ?: $appointment->country;
        $geoEligible = $names->hasUsableGeoLocation($appointment->city, $appointment->region, $country);

        $companyShort = $company?->short_name
            ?: (explode(' ', trim((string) $company?->name))[0] ?? '');
        $nameTime = $appointment->localStart()?->format('H:i') ?? '';
        $nameCode = $appointment->country_code ?: $appointment->country;

        $geoName = $previewNumber >= 1
            ? $names->geo($previewNumber, $fullName, $companyShort, $nameTime, $nameCode, $appointment->region, $appointment->city)
            : null;
        $staticName = $previewNumber >= 1
            ? $names->staticName($previewNumber, $fullName, $companyShort, $nameTime, $nameCode, $appointment->region, $appointment->city)
            : null;

        return [
            'preview_number' => $previewNumber,
            'preview_number_label' => $previewNumber >= 1 ? $numbers->formatNumber($previewNumber) : '—',
            'geo_name' => $geoName,
            'static_name' => $staticName,
            'geo_eligible' => $geoEligible,
            'geo_exists' => in_array('geo', $existingRoles, true),
            'static_exists' => in_array('static', $existingRoles, true),
            'multilogin_ready' => $company ? $multilogin->isConfiguredFor($company) : false,
            'static_proxy_count' => StaticProxy::query()->enabled()->count(),
            'proxy_ready' => $appointment->proxy_status === 'ready',
        ];
    }

    public function enrich(Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        try {
            $service->enrich($appointment);

            return redirect()->route('appointments.show', $appointment)->with('success', 'Location enriched successfully.');
        } catch (Throwable $e) {
            return redirect()->route('appointments.show', $appointment)->with('danger', 'IPinfo lookup failed: '.$e->getMessage());
        }
    }

    public function getProxy(Request $request, Appointment $appointment, AppointmentService $service): RedirectResponse
    {
        try {
            $proxy = $service->getProxy(
                $appointment,
                (int) ($request->input('candidate_count', 5) ?: 5),
                $request->input('selection_mode', 'auto') === 'auto'
            );
            $location = $proxy['target_location'] ?? [];
            $matchText = str_replace('_', ' ', $appointment->fresh()->proxy_match_level ?? 'country');

            return redirect()->route('appointments.show', $appointment)->with(
                'success',
                sprintf(
                    'Proxy ready: %s, %s, %s (%s match).',
                    $location['city'] ?? '',
                    $location['region'] ?? '',
                    $location['country'] ?? '',
                    $matchText
                )
            );
        } catch (Throwable $e) {
            return redirect()->route('appointments.show', $appointment)->with('danger', 'Proxy failed: '.$e->getMessage());
        }
    }

    public function selectProxy(Appointment $appointment, int $candidateId, AppointmentService $service): RedirectResponse
    {
        try {
            $selected = $service->selectProxy($appointment, $candidateId);

            return redirect()->route('appointments.show', $appointment)->with(
                'success',
                sprintf(
                    'Selected %s — %s, %s.',
                    $selected['isp'] ?? 'Unknown ISP',
                    $selected['city'] ?? '',
                    $selected['region'] ?? ''
                )
            );
        } catch (Throwable $e) {
            return redirect()->route('appointments.show', $appointment)->with('danger', 'Proxy selection failed: '.$e->getMessage());
        }
    }

    public function createProfiles(Request $request, Appointment $appointment, string $mode, AppointmentService $service)
    {
        $only = $mode === 'both' ? null : [$mode];

        try {
            $result = $service->createMissingProfiles($appointment, $only);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage(), 'log' => ['✗ '.$e->getMessage()], 'created' => []]);
            }

            return redirect()->route('appointments.show', $appointment)->with('danger', $e->getMessage());
        }

        $createdCount = count($result['created']);
        $failedCount = count($result['failed']);
        $summary = $createdCount > 0
            ? "Created {$createdCount} profile(s)."
            : ($failedCount > 0 ? 'Profile creation failed — see log.' : 'Nothing to create — the profile(s) already exist.');

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $failedCount === 0,
                'message' => $summary,
                'log' => $result['log'] ?? [],
                'created' => $result['created_names'] ?? [],
            ]);
        }

        $type = $createdCount > 0 ? 'success' : ($failedCount > 0 ? 'danger' : 'warning');

        return redirect()->route('appointments.show', $appointment)->with($type, $summary);
    }

    public function demo(AppointmentService $service): RedirectResponse
    {
        $appt = $service->createDemo();

        return redirect()->route('appointments.show', $appt)->with('success', 'Demo appointment created.');
    }
}
