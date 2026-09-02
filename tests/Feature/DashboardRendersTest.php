<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardRendersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_renders_with_week_strip_and_new_calls_banner(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Dash',
            'last_name' => 'Lead',
            'email' => 'dash@example.com',
        ]);
        // A call today so the week strip / today panels have data.
        Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Discovery Call',
            'start_time' => Carbon::parse('2026-09-10 13:00:00', 'UTC'),
            'status' => 'scheduled',
        ]);

        // First visit (no cookie): banner shows the "now tracking" state and the week panel.
        $first = $this->get('/');
        $first->assertOk();
        $first->assertSee('Calls this week');
        $first->assertSee('Now tracking new calls');

        // A newer scheduled call created after a known "last seen" time should count as new.
        $appt = Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'New Call',
            'start_time' => Carbon::parse('2026-09-11 13:00:00', 'UTC'),
            'status' => 'scheduled',
        ]);
        Appointment::where('id', $appt->id)->update(['created_at' => Carbon::parse('2026-09-10 11:30:00', 'UTC')]);

        $second = $this->withUnencryptedCookie('crm_last_seen', '2026-09-10T10:00:00+00:00')->get('/');
        $second->assertOk();
        $second->assertSee('new call');
    }
}
