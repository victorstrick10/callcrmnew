<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Services\AppointmentService;
use App\Services\ProfileNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AppointmentController extends Controller
{
    public function index(\Illuminate\Http\Request $request): View
    {
        $companySlug = $request->query('company');
        $query = Appointment::query()
            ->with(['contact', 'profiles', 'company'])
            ->orderByDesc('start_time');

        if ($companySlug) {
            $query->whereHas('company', fn ($q) => $q->where('slug', $companySlug));
        }

        $appointments = $query->get();
        $companies = \App\Models\Company::query()->orderBy('name')->get();

        return view('appointments.index', compact('appointments', 'companies', 'companySlug'));
    }

    public function show(Appointment $appointment, ProfileNumberService $numbers): View
    {
        $appointment->load(['contact', 'profiles']);
        $proxyCandidates = $appointment->proxy_candidates_json ?? [];
        if (! is_array($proxyCandidates)) {
            $proxyCandidates = [];
        }

        return view('appointments.show', [
            'appointment' => $appointment,
            'proxyCandidates' => $proxyCandidates,
            'formatNumber' => fn (int $n) => $numbers->formatNumber($n),
        ]);
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

    public function createProfiles(Appointment $appointment, string $mode, AppointmentService $service): RedirectResponse
    {
        try {
            $service->createProfiles($appointment, $mode);

            return redirect()->route('appointments.show', $appointment)
                ->with('success', 'Profile creation process completed. Review the statuses below.');
        } catch (Throwable $e) {
            $type = str_contains($e->getMessage(), 'already exist') ? 'warning' : 'danger';

            return redirect()->route('appointments.show', $appointment)->with($type, $e->getMessage());
        }
    }

    public function demo(AppointmentService $service): RedirectResponse
    {
        $appt = $service->createDemo();

        return redirect()->route('appointments.show', $appt)->with('success', 'Demo appointment created.');
    }
}
