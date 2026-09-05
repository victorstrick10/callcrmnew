<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\AppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_clients_geo_column_prints_country_region_code_zip_and_ip(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'UTC'));

        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true]);
        $contact = Contact::create([
            'company_id' => $company->id, 'first_name' => 'Geo', 'last_name' => 'Print', 'email' => 'geo@example.com',
        ]);

        Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Call',
            'start_time' => Carbon::parse('2026-09-05 09:00:00', 'UTC'), // today
            'status' => 'scheduled',
            'ip_address' => '174.198.133.97',
            'city' => 'Denver',
            'region' => 'Colorado',
            'country' => 'United States',
            'country_code' => 'US',
            'client_isp' => 'Verizon Business',
            'geo_json' => ['status' => 'success', 'region' => 'CO', 'regionName' => 'Colorado', 'zip' => '80206', 'query' => '174.198.133.97'],
            'geo_enriched_at' => now(),
        ]);

        $html = $this->get(route('clients.index', ['schedule' => 'today']))->assertOk()->getContent();

        $this->assertStringContainsString('United States (US)', $html);
        $this->assertStringContainsString('CO · Colorado', $html);
        $this->assertStringContainsString('80206', $html);           // ZIP
        $this->assertStringContainsString('174.198.133.97', $html);  // IP
        $this->assertStringContainsString('Verizon Business', $html);
        $this->assertStringContainsString('Refresh IP info', $html);
    }

    public function test_enrich_persists_zip_and_region_code(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status' => 'success', 'country' => 'United States', 'countryCode' => 'US',
                'region' => 'CO', 'regionName' => 'Colorado', 'city' => 'Denver', 'zip' => '80206',
                'isp' => 'Comcast', 'query' => '73.9.9.9',
            ], 200),
        ]);

        $company = Company::create(['name' => 'Acme', 'slug' => 'acme2', 'enabled' => true]);
        $contact = Contact::create(['company_id' => $company->id, 'first_name' => 'Z', 'last_name' => 'Ip', 'email' => 'z@example.com']);
        $appt = Appointment::create([
            'company_id' => $company->id, 'contact_id' => $contact->id, 'event_name' => 'Call',
            'start_time' => now(), 'status' => 'scheduled', 'ip_address' => '73.9.9.9',
        ]);

        app(AppointmentService::class)->enrich($appt);

        $this->assertSame('80206', $appt->fresh()->postal);
        $this->assertSame('CO', $appt->fresh()->region_code);
        $this->assertSame('Colorado', $appt->fresh()->region);
    }
}
