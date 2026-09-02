<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\AppointmentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_stores_multiple_calendly_events_per_lead(): void
    {
        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'lead_api_url' => 'https://diligentplacers.com/api.php',
            'calendly_org_uri' => 'https://api.calendly.com/organizations/ABC',
            'enabled' => true,
        ]);
        $company->setCalendlyApiToken('cal-token');
        $company->save();

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Léonard',
            'last_name' => 'Facchino',
            'email' => 'leonard.facchino@gmail.com',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/users/me')) {
                return Http::response([
                    'resource' => [
                        'uri' => 'https://api.calendly.com/users/USER1',
                        'current_organization' => 'https://api.calendly.com/organizations/ABC',
                    ],
                ]);
            }

            if (str_contains($url, '/scheduled_events?') && str_contains($url, 'status=active')) {
                return Http::response([
                    'collection' => [[
                        'uri' => 'https://api.calendly.com/scheduled_events/EVT2',
                        'name' => 'Discovery Call',
                        'status' => 'active',
                        'start_time' => '2026-08-10T15:00:00.000000Z',
                        'end_time' => '2026-08-10T15:30:00.000000Z',
                    ]],
                    'pagination' => [],
                ]);
            }

            if (str_contains($url, '/scheduled_events?') && str_contains($url, 'status=canceled')) {
                return Http::response([
                    'collection' => [[
                        'uri' => 'https://api.calendly.com/scheduled_events/EVT1',
                        'name' => 'Discovery Call',
                        'status' => 'canceled',
                        'start_time' => '2026-08-01T15:00:00.000000Z',
                        'end_time' => '2026-08-01T15:30:00.000000Z',
                    ]],
                    'pagination' => [],
                ]);
            }

            if (str_contains($url, '/invitees')) {
                return Http::response([
                    'collection' => [[
                        'uri' => 'https://api.calendly.com/invitees/INV1',
                        'email' => 'leonard.facchino@gmail.com',
                        'timezone' => 'Europe/Paris',
                    ]],
                    'pagination' => [],
                ]);
            }

            return Http::response(['collection' => [], 'pagination' => []]);
        });

        $result = app(AppointmentSyncService::class)->syncContact($company, $contact);

        $this->assertSame(2, Appointment::query()->where('contact_id', $contact->id)->count());
        $this->assertSame(2, $result['created']);
        $this->assertTrue(
            Appointment::query()->where('calendly_event_uri', 'https://api.calendly.com/scheduled_events/EVT2')
                ->where('status', 'scheduled')->exists()
        );
        $this->assertTrue(
            Appointment::query()->where('calendly_event_uri', 'https://api.calendly.com/scheduled_events/EVT1')
                ->where('status', 'canceled')->exists()
        );
    }

    public function test_sync_company_creates_lead_from_calendly_when_missing_in_api(): void
    {
        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'lead_api_url' => 'https://diligentplacers.com/api.php',
            'calendly_org_uri' => 'https://api.calendly.com/organizations/ABC',
            'enabled' => true,
        ]);
        $company->setCalendlyApiToken('cal-token');
        $company->save();

        Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Existing',
            'last_name' => 'Lead',
            'email' => 'existing@example.com',
            'referrer' => 'https://linkedin.com',
            'lead_user_agent' => 'Mozilla/5.0',
            'lead_ip' => '1.2.3.4',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/users/me')) {
                return Http::response([
                    'resource' => [
                        'uri' => 'https://api.calendly.com/users/USER1',
                        'current_organization' => 'https://api.calendly.com/organizations/ABC',
                    ],
                ]);
            }

            if (str_contains($url, '/scheduled_events') && str_contains($url, 'status=active')) {
                return Http::response([
                    'collection' => [
                        [
                            'uri' => 'https://api.calendly.com/scheduled_events/EVT_EXISTING',
                            'name' => 'Call',
                            'status' => 'active',
                            'start_time' => '2026-08-10T15:00:00.000000Z',
                            'end_time' => '2026-08-10T15:30:00.000000Z',
                        ],
                        [
                            'uri' => 'https://api.calendly.com/scheduled_events/EVT_NEW',
                            'name' => 'Call',
                            'status' => 'active',
                            'start_time' => '2026-08-11T15:00:00.000000Z',
                            'end_time' => '2026-08-11T15:30:00.000000Z',
                        ],
                    ],
                    'pagination' => [],
                ]);
            }

            if (str_contains($url, '/scheduled_events') && str_contains($url, 'status=canceled')) {
                return Http::response(['collection' => [], 'pagination' => []]);
            }

            if (str_contains($url, 'EVT_EXISTING') && str_contains($url, '/invitees')) {
                return Http::response([
                    'collection' => [[
                        'uri' => 'https://api.calendly.com/invitees/INV_E',
                        'email' => 'existing@example.com',
                        'name' => 'Existing Lead',
                        'timezone' => 'Europe/Paris',
                    ]],
                    'pagination' => [],
                ]);
            }

            if (str_contains($url, 'EVT_NEW') && str_contains($url, '/invitees')) {
                return Http::response([
                    'collection' => [[
                        'uri' => 'https://api.calendly.com/invitees/INV_N',
                        'email' => 'calendly-only@example.com',
                        'name' => 'Calendly Only',
                        'timezone' => 'Europe/Berlin',
                    ]],
                    'pagination' => [],
                ]);
            }

            return Http::response(['collection' => [], 'pagination' => []]);
        });

        $result = app(AppointmentSyncService::class)->syncCompany($company);

        $this->assertSame(1, $result['leads_created']);
        $this->assertSame(1, $result['leads_matched']);
        $this->assertSame(2, Contact::query()->where('company_id', $company->id)->count());

        $newLead = Contact::query()->where('email', 'calendly-only@example.com')->first();
        $this->assertNotNull($newLead);
        $this->assertSame('Calendly', $newLead->first_name);
        $this->assertSame('Only', $newLead->last_name);
        $this->assertSame('', $newLead->lead_ip);
        $this->assertNull($newLead->lead_user_agent);
        $this->assertSame('', $newLead->referrer);
        $this->assertSame('calendly', $newLead->lead_raw_json['source'] ?? null);

        $existing = Contact::query()->where('email', 'existing@example.com')->first();
        $this->assertSame('https://linkedin.com', $existing->referrer);
        $this->assertSame('Mozilla/5.0', $existing->lead_user_agent);

        $this->assertTrue(
            Appointment::query()->where('calendly_event_uri', 'https://api.calendly.com/scheduled_events/EVT_NEW')->exists()
        );
    }
}
