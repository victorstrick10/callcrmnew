<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\Company;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

class AppointmentService
{
    public function __construct(
        private IpInfoService $ipInfo,
        private MultiloginClient $multilogin,
        private ProfileNumberService $numbers,
        private AuditService $audit,
        private ProfileNameFormatter $names,
        private StaticProxyService $staticProxies,
        private IntegrationSettingsService $settings,
    ) {
    }

    private function multiloginFor(Appointment $appointment): MultiloginClient
    {
        $appointment->loadMissing('company');

        return $this->multilogin->forCompany($appointment->company);
    }

    private function requireCompanyMultilogin(Appointment $appointment): Company
    {
        $appointment->loadMissing('company');
        $company = $appointment->company;
        if (! $company) {
            throw new RuntimeException('This appointment has no company; assign one before creating profiles.');
        }
        if (! $this->multilogin->isConfiguredFor($company)) {
            throw new RuntimeException(
                'No Multilogin token available for "'.$company->name.'". Add it on the company '
                .'(Companies → Edit) or globally in Integrations → Multilogin.'
            );
        }

        return $company;
    }

    /**
     * Automatically enrich an appointment's geolocation from its captured IP
     * using the IPinfo token, as soon as the lead/call arrives. Best-effort:
     * silently skips when there is no IP, when geo is already known, or when
     * no IPinfo token is configured, and never throws into the caller.
     */
    public function autoEnrich(Appointment $appointment): bool
    {
        if (! $appointment->ip_address) {
            return false;
        }

        // Already geolocated — do not spend another IPinfo lookup.
        if (trim((string) ($appointment->country_code ?: $appointment->country)) !== '') {
            return false;
        }

        $token = $this->settings->getSettings('ipinfo')['api_token'] ?? '';
        if (! $token) {
            return false;
        }

        try {
            $this->enrich($appointment);
            $this->audit->log(
                'Auto IPinfo enrichment on intake',
                "Appointment #{$appointment->id}: {$appointment->city}, {$appointment->region}, {$appointment->country}"
            );

            return true;
        } catch (Throwable $e) {
            $this->audit->log('Auto IPinfo enrichment failed', "Appointment #{$appointment->id}: {$e->getMessage()}");

            return false;
        }
    }

    public function enrich(Appointment $appointment): Appointment
    {
        if (! $appointment->ip_address) {
            throw new RuntimeException('This appointment has no IP address to enrich.');
        }

        $result = $this->ipInfo->lookup($appointment->ip_address);
        foreach (['city', 'region', 'country', 'country_code', 'timezone', 'latitude', 'longitude'] as $key) {
            $appointment->{$key} = $result[$key] ?? null;
        }
        $appointment->client_org = $result['org'] ?? '';
        $appointment->client_isp = ($result['isp'] ?? '') ?: $this->multilogin->_isp_name($result);
        $appointment->client_asn = $result['asn'] ?? '';
        $appointment->geo_json = $result['raw'] ?? $result;
        $appointment->geo_enriched_at = now();
        $appointment->save();

        $this->audit->log(
            'Enriched appointment location',
            "Appointment #{$appointment->id}: {$appointment->city}, {$appointment->region}, {$appointment->country}"
        );

        return $appointment;
    }

    /**
     * Bulk-enrich appointments that have a captured IP but have not been
     * geolocated yet. Bounded per run so it is safe for a request or a
     * scheduled background job. Returns counts.
     *
     * @return array{enriched:int,failed:int,remaining:int}
     */
    public function enrichPending(int $limit = 200): array
    {
        if (! ($this->settings->getSettings('ipinfo')['api_token'] ?? '')) {
            return ['enriched' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $base = Appointment::query()
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->whereNull('geo_enriched_at');

        $remaining = (clone $base)->count();

        $enriched = 0;
        $failed = 0;
        foreach ($base->orderByDesc('start_time')->limit($limit)->get() as $appointment) {
            try {
                $this->enrich($appointment);
                $enriched++;
                usleep(120000); // gentle pacing for the IPinfo API
            } catch (Throwable $e) {
                $failed++;
                // Mark as attempted so a single bad IP doesn't block the queue forever.
                $appointment->forceFill(['geo_enriched_at' => now()])->save();
            }
        }

        return ['enriched' => $enriched, 'failed' => $failed, 'remaining' => max(0, $remaining - $enriched - $failed)];
    }

    /**
     * Auto-generate a Multilogin GEO proxy for upcoming scheduled calls that
     * have a known country but no ready proxy, so the GEO profile is ready to
     * create. Bounded per run to control Multilogin proxy usage.
     *
     * @return array{ready:int,failed:int}
     */
    public function prepareGeoProxies(int $limit = 20): array
    {
        $tz = config('app.timezone');

        $appointments = Appointment::query()
            ->with('company')
            ->where('status', 'scheduled')
            ->where('start_time', '>=', \Illuminate\Support\Carbon::now($tz)->startOfDay())
            ->where(function ($q) {
                $q->where('country_code', '!=', '')->orWhere('country', '!=', '');
            })
            ->where(function ($q) {
                $q->whereNull('proxy_status')->orWhere('proxy_status', '!=', 'ready');
            })
            ->orderBy('start_time')
            ->limit($limit)
            ->get();

        $ready = 0;
        $failed = 0;
        foreach ($appointments as $appointment) {
            if (! $this->multilogin->isConfiguredFor($appointment->company)) {
                continue;
            }
            try {
                $this->getProxy($appointment, 3, true);
                $ready++;
                usleep(200000);
            } catch (Throwable $e) {
                $failed++;
            }
        }

        return ['ready' => $ready, 'failed' => $failed];
    }

    public function getProxy(Appointment $appointment, int $candidateCount = 5, bool $autoSelect = true): array
    {
        try {
            return $this->multiloginFor($appointment)->save_proxy_for_appointment(
                $appointment,
                $candidateCount,
                $autoSelect
            );
        } catch (Throwable $exc) {
            $appointment->proxy_status = 'failed';
            $appointment->proxy_last_error = $exc->getMessage();
            $appointment->save();
            $this->audit->log('Proxy generation failed', "Appointment={$appointment->id}; {$exc->getMessage()}");
            throw $exc;
        }
    }

    public function selectProxy(Appointment $appointment, int $candidateId): array
    {
        $candidates = $appointment->proxy_candidates_json ?? [];
        if (! is_array($candidates) || $candidateId < 0 || $candidateId >= count($candidates)) {
            throw new RuntimeException('Proxy candidate was not found.');
        }

        return $this->multiloginFor($appointment)->select_proxy_candidate($appointment, $candidates[$candidateId]);
    }

    /**
     * Create missing GEO/STATIC Multilogin profiles for an appointment.
     *
     * @param  string|null  $staticProvider  When set (e.g. 'mobilehop'), the STATIC
     *                                        profile uses a proxy from that provider only.
     * @return array{created: list<string>, skipped: list<string>, failed: list<array{role: string, error: string}>}
     */
    public function createMissingProfiles(Appointment $appointment, ?array $onlyRoles = null, ?string $staticProvider = null): array
    {
        $this->requireCompanyMultilogin($appointment);
        $appointment->loadMissing(['contact', 'profiles', 'company']);

        $existing = $appointment->profiles
            ->whereIn('status', ['reserved', 'created'])
            ->pluck('profile_role')
            ->all();

        $country = $appointment->country_code ?: $appointment->country;
        if (! $this->names->hasUsableGeoLocation($appointment->city, $appointment->region, $country)) {
            if ($appointment->ip_address) {
                try {
                    $this->enrich($appointment);
                    $appointment->refresh();
                    $country = $appointment->country_code ?: $appointment->country;
                } catch (Throwable) {
                    // Leave without GEO eligibility.
                }
            }
        }

        $canGeo = $this->names->hasUsableGeoLocation(
            $appointment->city,
            $appointment->region,
            $appointment->country_code ?: $appointment->country
        );

        $want = [];
        if ($canGeo && ! in_array('geo', $existing, true)) {
            $want[] = 'geo';
        }
        if (! in_array('static', $existing, true)) {
            $want[] = 'static';
        }

        if ($onlyRoles !== null) {
            $want = array_values(array_intersect($want, $onlyRoles));
        }

        if (! $want) {
            return [
                'created' => [],
                'created_names' => [],
                'skipped' => ['geo', 'static'],
                'failed' => [],
                'log' => ['Nothing to create — the requested profile(s) already exist for this lead.'],
            ];
        }

        $failed = [];
        $log = [];

        if (in_array('geo', $want, true) && ! MultiloginClient::saved_proxy_from_appointment($appointment)) {
            try {
                $this->getProxy($appointment, 3, true);
                $appointment->refresh();
                $matchTxt = str_replace('_', '+', (string) ($appointment->proxy_match_level ?: 'country'));
                $log[] = 'GEO: Multilogin proxy ready — '
                    .trim(($appointment->proxy_actual_city ?: $appointment->city).', '
                        .($appointment->proxy_actual_region ?: $appointment->region).', '
                        .($appointment->proxy_country ?: $appointment->country_code), ', ')
                    ." ({$matchTxt} match)";
            } catch (Throwable $exc) {
                // Do not block STATIC when Multilogin proxy generation/inspection fails.
                $want = array_values(array_filter($want, fn ($role) => $role !== 'geo'));
                $failed[] = ['role' => 'geo', 'error' => $exc->getMessage()];
                $log[] = 'GEO: proxy generation failed — '.$exc->getMessage();
                $this->audit->log(
                    'GEO proxy preparation failed; continuing without GEO',
                    "Appointment={$appointment->id}; {$exc->getMessage()}"
                );
            }
        }

        if (! $want) {
            return [
                'created' => [],
                'created_names' => [],
                'skipped' => array_values(array_diff(['geo', 'static'], array_column($failed, 'role'))),
                'failed' => $failed,
                'log' => $log,
            ];
        }

        $company = $this->requireCompanyMultilogin($appointment);

        // Sync profile numbers for BOTH companies before allocating, so every
        // GEO / STATIC / STATIC-MHop action reconciles the latest Multilogin
        // inventory (created + deleted) across all companies first.
        $this->syncNumbersForAllCompanies($log);

        $created = [];
        $createdNames = [];

        $companyShort = $company->short_name
            ?: (explode(' ', trim((string) $company->name))[0] ?? '');
        $cc = $appointment->country_code ?: $appointment->country;
        $nameTime = $appointment->localStart()?->format('H:i') ?? '';
        $nameCode = $appointment->country_code ?: $appointment->country;
        $nameRegion = $appointment->region;
        $nameCity = $appointment->city;

        foreach ($want as $role) {
            // Each profile gets its OWN sequential number (e.g. GEO 001, STATIC 002).
            $number = $this->numbers->allocateNextNumber((int) $company->id, $appointment->id);
            $log[] = 'Number allocated for '.strtoupper($role).': '.$this->numbers->formatNumber($number).' (fresh check against Multilogin).';

            $fullName = $appointment->contact->full_name;
            $name = $role === 'geo'
                ? $this->names->geo($number, $fullName, $companyShort, $nameTime, $nameCode, $nameRegion, $nameCity)
                : $this->names->staticName($number, $fullName, $companyShort, $nameTime, $nameCode, $nameRegion, $nameCity);

            $profile = BrowserProfile::create([
                'appointment_id' => $appointment->id,
                'number' => $number,
                'profile_role' => $role,
                'profile_name' => $name,
                'status' => 'reserved',
            ]);

            try {
                if ($role === 'geo') {
                    $mlId = $this->multiloginFor($appointment)->create_geo_profile($name, $appointment);
                } else {
                    if ($staticProvider) {
                        $staticProxy = $this->staticProxies->pickForProvider(
                            $staticProvider,
                            $appointment->city,
                            $appointment->region,
                            $cc
                        );
                        $matchLevel = $this->staticProxies->matchLevel($staticProxy, $appointment->city, $appointment->region, $cc, '');
                    } else {
                        $staticProxy = $this->staticProxies->pickForLocation(
                            $appointment->city,
                            $appointment->region,
                            $cc,
                            $appointment->client_isp
                        );
                        $matchLevel = $this->staticProxies->matchLevel($staticProxy, $appointment->city, $appointment->region, $cc, $appointment->client_isp);
                    }
                    $providerLabel = $this->providerLabel($staticProxy->provider);
                    $profile->proxy_label = $staticProxy->label ?: $staticProxy->host;
                    $log[] = $matchLevel === 'random'
                        ? "STATIC: no city/region/country match — using random {$providerLabel} proxy ({$staticProxy->location})"
                        : "STATIC: matched {$providerLabel} proxy — {$staticProxy->location} (".str_replace('_', '+', $matchLevel).' match)';
                    $mlId = $this->multiloginFor($appointment)->create_static_profile(
                        $name,
                        $staticProxy->toMultiloginProxy()
                    );
                }

                $profile->multilogin_profile_id = $mlId;
                $profile->status = 'created';
                $profile->save();
                $createdNames[] = $name;
                $log[] = '✓ Created '.strtoupper($role).' profile: '.$name;

                $numberRow = $this->numbers->findForCompany((int) $appointment->company_id, $number);
                if ($numberRow) {
                    $numberRow->status = 'created';
                    $numberRow->multilogin_profile_id = $mlId;
                    $numberRow->profile_name = $name;
                    $numberRow->profile_type = $role;
                    $numberRow->appointment_id = $appointment->id;
                    $numberRow->save();
                }

                $this->audit->log('Created Multilogin profile', "{$name} / {$mlId}");
                $created[] = $role;
            } catch (Throwable $exc) {
                $profile->status = 'failed';
                $profile->error_message = $exc->getMessage();
                $profile->save();
                // Free the number so a retry reuses it instead of skipping ahead.
                $this->numbers->releaseNumber((int) $company->id, $number);
                $this->audit->log('Multilogin profile creation failed', "{$name}: {$exc->getMessage()}");
                $failed[] = ['role' => $role, 'error' => $exc->getMessage()];
                $log[] = '✗ '.strtoupper($role).' failed: '.$exc->getMessage();
            }
        }

        return [
            'created' => $created,
            'created_names' => $createdNames,
            'skipped' => array_values(array_diff(['geo', 'static'], $want)),
            'failed' => $failed,
            'log' => $log,
        ];
    }

    /**
     * Reconcile Multilogin profile numbers for every configured company. Runs
     * before each profile-creation action so numbering is fresh across both
     * companies. Non-destructive in simulation. Appends a line per company to
     * the provided log. Never throws — a sync failure for one company must not
     * block profile creation.
     *
     * @param  list<string>  $log
     */
    private function syncNumbersForAllCompanies(array &$log): void
    {
        foreach (Company::query()->orderBy('name')->get() as $co) {
            if (! $this->multilogin->isConfiguredFor($co)) {
                continue;
            }

            try {
                $client = $this->multilogin->forCompany($co);
                $profiles = $client->search_profiles();
                $result = $this->numbers->syncFromProfiles($co->id, $profiles, ! $client->simulation);
                $log[] = '↻ Synced numbers · '.$co->name.' ('.($result['numbers_marked'] ?? 0).' used, next '
                    .$this->numbers->formatNumber($this->numbers->nextNumber($co->id)).')';
            } catch (Throwable $e) {
                $log[] = '↻ Number sync failed · '.$co->name.': '.$e->getMessage();
            }
        }
    }

    private function providerLabel(?string $provider): string
    {
        return match ($provider) {
            'proxycheap' => 'ProxyCheap',
            'mobilehop' => 'MobileHop',
            default => 'pool',
        };
    }

    public function createProfiles(Appointment $appointment, string $mode): void
    {
        if (! in_array($mode, ['geo', 'static', 'both'], true)) {
            throw new RuntimeException('Invalid profile creation mode.');
        }

        $only = $mode === 'both' ? null : [$mode];
        $result = $this->createMissingProfiles($appointment, $only);

        if ($result['created'] === [] && $result['failed'] === []) {
            throw new RuntimeException('The requested profile or profiles already exist.');
        }

        if ($result['created'] === [] && $result['failed'] !== []) {
            throw new RuntimeException($result['failed'][0]['error'] ?? 'Profile creation failed.');
        }
    }

    public function retryProfile(BrowserProfile $profile): BrowserProfile
    {
        $appointment = $profile->appointment;
        $this->requireCompanyMultilogin($appointment);

        try {
            $mlId = $profile->profile_role === 'geo'
                ? $this->multiloginFor($appointment)->create_geo_profile($profile->profile_name, $appointment)
                : $this->multiloginFor($appointment)->create_static_profile(
                    $profile->profile_name,
                    $this->staticProxies->pickForLocation(
                        $appointment->city,
                        $appointment->region,
                        $appointment->country_code ?: $appointment->country,
                        $appointment->client_isp
                    )->toMultiloginProxy()
                );

            $profile->multilogin_profile_id = $mlId;
            $profile->status = 'created';
            $profile->error_message = '';
            $profile->save();

            $numberRow = $this->numbers->findForCompany((int) $appointment->company_id, (int) $profile->number);
            if ($numberRow) {
                $numberRow->status = 'created';
                $numberRow->multilogin_profile_id = $mlId;
                $numberRow->profile_name = $profile->profile_name;
                $numberRow->save();
            }

            $this->audit->log('Retried Multilogin profile', "{$profile->profile_name} / {$mlId}");
        } catch (Throwable $exc) {
            $profile->status = 'failed';
            $profile->error_message = $exc->getMessage();
            $profile->save();
            throw $exc;
        }

        return $profile;
    }

    public function createDemo(): Appointment
    {
        $count = \App\Models\Contact::query()->count() + 1;
        $company = Company::query()->where('slug', 'default')->first()
            ?: Company::query()->orderBy('id')->first();

        $contact = \App\Models\Contact::create([
            'company_id' => $company?->id,
            'first_name' => "Demo{$count}",
            'last_name' => 'Client',
            'email' => "demo{$count}@example.com",
            'phone' => '+1 555 0100',
            'company' => 'Demo Company',
        ]);

        $appt = Appointment::create([
            'company_id' => $company?->id,
            'contact_id' => $contact->id,
            'event_name' => 'Marketing Strategy Call',
            'start_time' => Carbon::now(),
            'invitee_timezone' => 'Europe/London',
            'status' => 'scheduled',
            'ip_address' => '8.8.8.8',
            'city' => 'Stirling',
            'region' => 'Scotland',
            'country' => 'United Kingdom',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'latitude' => 56.1165,
            'longitude' => -3.9369,
        ]);

        $this->audit->log('Created demo appointment', "Appointment #{$appt->id}");

        return $appt;
    }
}
