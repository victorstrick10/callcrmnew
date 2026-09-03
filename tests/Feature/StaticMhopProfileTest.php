<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\Company;
use App\Models\Contact;
use App\Models\StaticProxy;
use App\Services\IntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticMhopProfileTest extends TestCase
{
    use RefreshDatabase;

    private function bootMultiloginSimulation(): void
    {
        app(IntegrationSettingsService::class)->saveSettings('multilogin', [
            'simulation_mode' => 'true',
            'automation_token' => 'sim-token',
            'base_url' => 'https://api.multilogin.com',
            'workspace_id' => 'ws-1',
            'static_folder_id' => 'static-folder',
        ]);
    }

    public function test_static_mhop_button_creates_static_profile_with_mobilehop_proxy(): void
    {
        $this->bootMultiloginSimulation();

        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true]);
        $company->setMultiloginToken('company-token');
        $company->save();

        // A MobileHop proxy matching the lead location, and a ProxyCheap one that also matches.
        StaticProxy::create([
            'label' => 'mhop-ny', 'host' => 'ny.mhop.com', 'port' => 1, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'mobilehop', 'network_type' => 'mobile',
            'exit_country' => 'US', 'exit_region' => 'New York', 'exit_city' => 'New York', 'last_check_status' => 'up',
        ]);
        StaticProxy::create([
            'label' => 'pc-ny', 'host' => 'ny.pc.com', 'port' => 2, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'proxycheap', 'network_type' => 'mobile',
            'exit_country' => 'US', 'exit_region' => 'New York', 'exit_city' => 'New York', 'last_check_status' => 'up',
        ]);

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Nia', 'last_name' => 'Ny', 'email' => 'nia@example.com',
        ]);
        $appointment = Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Call',
            'start_time' => now(),
            'status' => 'scheduled',
            'city' => 'New York', 'region' => 'New York', 'country' => 'US', 'country_code' => 'US',
        ]);

        $response = $this->post(route('clients.create-missing-profiles'), [
            'appointment_ids' => [$appointment->id],
            'role' => 'static_mhop',
        ]);
        $response->assertRedirect();

        $static = BrowserProfile::query()
            ->where('appointment_id', $appointment->id)
            ->where('profile_role', 'static')
            ->where('status', 'created')
            ->first();

        $this->assertNotNull($static, 'STATIC-MHop should create a static profile');
        $this->assertSame('mhop-ny', $static->proxy_label, 'STATIC-MHop must use a MobileHop proxy, not ProxyCheap');
    }

    public function test_static_mhop_creates_even_when_a_static_already_exists(): void
    {
        $this->bootMultiloginSimulation();

        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true]);
        $company->setMultiloginToken('company-token');
        $company->save();

        StaticProxy::create([
            'label' => 'mhop-1', 'host' => 'mhop.com', 'port' => 1, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'mobilehop', 'network_type' => 'mobile',
            'exit_country' => 'US', 'last_check_status' => 'up',
        ]);

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Kat', 'last_name' => 'P', 'email' => 'kat@example.com',
        ]);
        $appointment = Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Call',
            'start_time' => now(),
            'status' => 'scheduled',
            'city' => 'Reno', 'region' => 'Nevada', 'country' => 'US', 'country_code' => 'US',
        ]);

        // A STATIC profile already exists for this lead.
        BrowserProfile::create([
            'appointment_id' => $appointment->id,
            'number' => 1,
            'profile_role' => 'static',
            'profile_name' => '001 Kat STATIC',
            'status' => 'created',
        ]);

        $response = $this->post(route('clients.create-missing-profiles'), [
            'appointment_ids' => [$appointment->id],
            'role' => 'static_mhop',
        ]);
        $response->assertRedirect();

        // STATIC-MHop must add a SECOND static profile even though one existed.
        $this->assertSame(
            2,
            BrowserProfile::query()->where('appointment_id', $appointment->id)->where('profile_role', 'static')->count(),
            'STATIC-MHop should create an additional static profile even when one already exists'
        );
    }
}
