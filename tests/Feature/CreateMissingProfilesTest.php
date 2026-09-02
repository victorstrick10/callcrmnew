<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\Company;
use App\Models\Contact;
use App\Models\StaticProxy;
use App\Services\AppointmentService;
use App\Services\IntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CreateMissingProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function seedCompanyWithToken(string $token = 'company-ml-token'): Company
    {
        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'multilogin_base_url' => 'https://api.multilogin.com',
            'enabled' => true,
        ]);
        $company->setMultiloginToken($token);
        $company->save();

        return $company;
    }

    private function makeAppointment(Company $company, array $overrides = []): Appointment
    {
        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Ana',
            'last_name' => 'Black',
            'email' => 'ana'.uniqid().'@example.com',
        ]);

        return Appointment::create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Discovery Call',
            'start_time' => now(),
            'status' => 'scheduled',
            'ip_address' => '8.8.8.8',
            'city' => 'Houston',
            'region' => 'Texas',
            'country' => 'US',
            'country_code' => 'US',
            'proxy_status' => 'ready',
            'proxy_host' => 'gate.multilogin.com',
            'proxy_port' => 8080,
            'proxy_username' => 'geo-user',
            'proxy_password' => 'geo-pass',
            'proxy_protocol' => 'http',
            'proxy_country' => 'US',
            'proxy_region' => 'Texas',
            'proxy_city' => 'Houston',
        ], $overrides));
    }

    private function configureMultilogin(): void
    {
        app(IntegrationSettingsService::class)->saveSettings('multilogin', [
            'simulation_mode' => 'false',
            'automation_token' => 'global-should-not-be-used',
            'base_url' => 'https://api.multilogin.com',
            'workspace_id' => 'ws-1',
            'geo_folder_id' => 'geo-folder',
            'static_folder_id' => 'static-folder',
            'profile_create_endpoint' => '/profile/create',
            'browser_type' => 'mimic',
            'os_type' => 'windows',
        ]);
    }

    private function seedStaticProxy(): StaticProxy
    {
        return StaticProxy::create([
            'label' => 'pool-1',
            'host' => 'static.example.com',
            'port' => 9000,
            'username' => 'suser',
            'password' => 'spass',
            'protocol' => 'http',
            'enabled' => true,
        ]);
    }

    public function test_creates_geo_and_static_when_missing(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = $this->seedCompanyWithToken('company-ml-token');
        $appointment = $this->makeAppointment($company);

        Http::fake([
            'https://api.multilogin.com/*' => Http::response(['data' => ['id' => 'ml-created-1']]),
        ]);

        $result = app(AppointmentService::class)->createMissingProfiles($appointment);

        $this->assertSame(['geo', 'static'], $result['created']);
        $this->assertCount(2, $appointment->fresh()->profiles()->where('status', 'created')->get());

        $geo = $appointment->profiles()->where('profile_role', 'geo')->first();
        $static = $appointment->profiles()->where('profile_role', 'static')->first();
        $this->assertSame(1, $geo->number);
        $this->assertSame(1, $static->number);
        $this->assertSame('001 Ana Black Houston,Texas,US (api)', $geo->profile_name);
        $this->assertSame('001 Ana Black Static (api)', $static->profile_name);
    }

    public function test_skips_existing_geo_and_creates_static_only(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = $this->seedCompanyWithToken();
        $appointment = $this->makeAppointment($company);

        BrowserProfile::create([
            'appointment_id' => $appointment->id,
            'number' => 1,
            'profile_role' => 'geo',
            'profile_name' => '001 Ana Black Houston,Texas,US',
            'status' => 'created',
            'multilogin_profile_id' => 'existing-geo',
        ]);

        Http::fake([
            'https://api.multilogin.com/*' => Http::response(['data' => ['id' => 'ml-static']]),
        ]);

        $result = app(AppointmentService::class)->createMissingProfiles($appointment);

        $this->assertSame(['static'], $result['created']);
        $this->assertContains('geo', $result['skipped']);
        $static = $appointment->profiles()->where('profile_role', 'static')->first();
        $this->assertSame(1, $static->number);
        $this->assertSame('001 Ana Black Static (api)', $static->profile_name);
    }

    public function test_no_location_and_no_ip_creates_static_only(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = $this->seedCompanyWithToken();
        $appointment = $this->makeAppointment($company, [
            'ip_address' => '',
            'city' => '',
            'region' => '',
            'country' => '',
            'country_code' => '',
            'proxy_status' => 'not_requested',
            'proxy_host' => null,
            'proxy_port' => null,
        ]);

        Http::fake([
            'https://api.multilogin.com/*' => Http::response(['data' => ['id' => 'ml-static-only']]),
        ]);

        $result = app(AppointmentService::class)->createMissingProfiles($appointment);

        $this->assertSame(['static'], $result['created']);
        $this->assertSame(0, $appointment->profiles()->where('profile_role', 'geo')->count());
        $this->assertSame('001 Ana Black Static (api)', $appointment->profiles()->first()->profile_name);
    }

    public function test_both_exist_returns_empty_created(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = $this->seedCompanyWithToken();
        $appointment = $this->makeAppointment($company);

        foreach (['geo', 'static'] as $i => $role) {
            BrowserProfile::create([
                'appointment_id' => $appointment->id,
                'number' => 1 + $i,
                'profile_role' => $role,
                'profile_name' => sprintf('%03d Ana Black %s', 1 + $i, $role === 'geo' ? 'Houston,Texas,US' : 'Static'),
                'status' => 'created',
            ]);
        }

        $result = app(AppointmentService::class)->createMissingProfiles($appointment);
        $this->assertSame([], $result['created']);
        $this->assertSame(['geo', 'static'], $result['skipped']);
    }

    public function test_uses_company_multilogin_token_not_global(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = $this->seedCompanyWithToken('company-secret-token');
        $appointment = $this->makeAppointment($company, [
            'city' => '',
            'region' => '',
            'country' => '',
            'country_code' => '',
            'ip_address' => '',
            'proxy_status' => 'not_requested',
            'proxy_host' => null,
            'proxy_port' => null,
        ]);

        Http::fake([
            'https://api.multilogin.com/*' => Http::response(['data' => ['id' => 'ml-1']]),
        ]);

        app(AppointmentService::class)->createMissingProfiles($appointment);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';

            return str_contains($request->url(), '/profile/create')
                && str_contains($auth, 'company-secret-token');
        });
    }

    public function test_company_token_from_other_workspace_uses_token_folder_not_global(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();

        // Multilogin JWTs use workspaceID (capital ID). Global folders belong to ws-1.
        $token = $this->fakeMultiloginJwt([
            'workspaceID' => 'rusell-workspace',
            'isAutomation' => true,
        ]);
        $company = $this->seedCompanyWithToken($token);
        $appointment = $this->makeAppointment($company, [
            'city' => '',
            'region' => '',
            'country' => '',
            'country_code' => '',
            'ip_address' => '',
            'proxy_status' => 'not_requested',
            'proxy_host' => null,
            'proxy_port' => null,
        ]);

        Http::fake([
            'https://api.multilogin.com/*' => Http::response(['data' => ['id' => 'ml-rusell']]),
        ]);

        app(AppointmentService::class)->createMissingProfiles($appointment);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/profile/create')) {
                return false;
            }
            $body = $request->data();

            return ($body['folder_id'] ?? null) === 'rusell-workspace';
        });
        Http::assertNotSent(function ($request) {
            if (! str_contains($request->url(), '/profile/create')) {
                return false;
            }
            $body = $request->data();

            return in_array($body['folder_id'] ?? null, ['static-folder', 'geo-folder', 'ws-1'], true);
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function fakeMultiloginJwt(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode($claims).'.sig';
    }

    public function test_company_without_multilogin_token_fails(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = Company::create([
            'name' => 'No Token Co',
            'slug' => 'notoken',
            'enabled' => true,
        ]);
        $appointment = $this->makeAppointment($company);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no Multilogin token');

        app(AppointmentService::class)->createMissingProfiles($appointment);

        Http::assertNothingSent();
    }

    public function test_clients_bulk_create_posts_selected_appointment_ids(): void
    {
        $this->configureMultilogin();
        $this->seedStaticProxy();
        $company = $this->seedCompanyWithToken();
        $appointment = $this->makeAppointment($company, [
            'city' => '',
            'region' => '',
            'country' => '',
            'country_code' => '',
            'ip_address' => '',
            'proxy_status' => 'not_requested',
            'proxy_host' => null,
            'proxy_port' => null,
        ]);

        Http::fake([
            'https://api.multilogin.com/*' => Http::response(['data' => ['id' => 'ml-bulk']]),
        ]);

        $response = $this->post(route('clients.create-missing-profiles'), [
            'appointment_ids' => [$appointment->id],
            'schedule' => 'today',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, $appointment->profiles()->where('profile_role', 'static')->where('status', 'created')->count());
    }
}
