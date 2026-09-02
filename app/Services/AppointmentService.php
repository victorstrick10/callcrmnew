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
        $appointment->save();

        $this->audit->log(
            'Enriched appointment location',
            "Appointment #{$appointment->id}: {$appointment->city}, {$appointment->region}, {$appointment->country}"
        );

        return $appointment;
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
     * @return array{created: list<string>, skipped: list<string>, failed: list<array{role: string, error: string}>}
     */
    public function createMissingProfiles(Appointment $appointment, ?array $onlyRoles = null): array
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
                'skipped' => ['geo', 'static'],
                'failed' => [],
            ];
        }

        $failed = [];

        if (in_array('geo', $want, true) && ! MultiloginClient::saved_proxy_from_appointment($appointment)) {
            try {
                $this->getProxy($appointment, 3, true);
                $appointment->refresh();
            } catch (Throwable $exc) {
                // Do not block STATIC when Multilogin proxy generation/inspection fails.
                $want = array_values(array_filter($want, fn ($role) => $role !== 'geo'));
                $failed[] = ['role' => 'geo', 'error' => $exc->getMessage()];
                $this->audit->log(
                    'GEO proxy preparation failed; continuing without GEO',
                    "Appointment={$appointment->id}; {$exc->getMessage()}"
                );
            }
        }

        if (! $want) {
            return [
                'created' => [],
                'skipped' => array_values(array_diff(['geo', 'static'], array_column($failed, 'role'))),
                'failed' => $failed,
            ];
        }

        $company = $this->requireCompanyMultilogin($appointment);
        $mlProfiles = $this->multiloginFor($appointment)->search_profiles();
        $this->numbers->syncFromProfiles($company->id, $mlProfiles);

        $number = $this->numbers->allocateNumberForAppointment($appointment->id);
        $created = [];

        foreach ($want as $role) {
            $fullName = $appointment->contact->full_name;
            $name = $role === 'geo'
                ? $this->names->geo(
                    $number,
                    $fullName,
                    $appointment->city,
                    $appointment->region,
                    $appointment->country_code ?: $appointment->country
                )
                : $this->names->staticName($number, $fullName);

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
                    $staticProxy = $this->staticProxies->randomEnabled();
                    $profile->proxy_label = $staticProxy->label ?: $staticProxy->host;
                    $mlId = $this->multiloginFor($appointment)->create_static_profile(
                        $name,
                        $staticProxy->toMultiloginProxy()
                    );
                }

                $profile->multilogin_profile_id = $mlId;
                $profile->status = 'created';
                $profile->save();

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
                $this->audit->log('Multilogin profile creation failed', "{$name}: {$exc->getMessage()}");
                $failed[] = ['role' => $role, 'error' => $exc->getMessage()];
            }
        }

        return [
            'created' => $created,
            'skipped' => array_values(array_diff(['geo', 'static'], $want)),
            'failed' => $failed,
        ];
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
                    $this->staticProxies->randomEnabled()->toMultiloginProxy()
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
