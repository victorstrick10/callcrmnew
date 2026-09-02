<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientsScheduleFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contact(Company $company, string $name): Contact
    {
        return Contact::create([
            'company_id' => $company->id,
            'first_name' => $name,
            'last_name' => 'Tester',
            'email' => strtolower($name).'@example.com',
        ]);
    }

    private function scheduleCall(Contact $contact, string $utcStart): void
    {
        Appointment::create([
            'company_id' => $contact->company_id,
            'contact_id' => $contact->id,
            'event_name' => 'Discovery Call',
            'start_time' => Carbon::parse($utcStart, 'UTC'),
            'status' => 'scheduled',
        ]);
    }

    public function test_default_shows_today_all_sentinel_shows_everything(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true]);

        $todayLead = $this->contact($company, 'Todaylead');
        $this->scheduleCall($todayLead, '2026-09-10 13:00:00'); // today (Belgrade)

        $futureLead = $this->contact($company, 'Futurelead');
        $this->scheduleCall($futureLead, '2026-09-15 13:00:00'); // 5 days out

        $noCallLead = $this->contact($company, 'Nocalllead'); // no appointments at all

        // First visit (no params) -> Today's calls only.
        $first = $this->get(route('clients.index'));
        $first->assertOk();
        $first->assertSee($todayLead->email);
        $first->assertDontSee($futureLead->email);
        $first->assertDontSee($noCallLead->email);

        // Explicit "All dates" sentinel -> every lead, including call-less ones.
        $all = $this->get(route('clients.index', ['schedule' => 'all']));
        $all->assertOk();
        $all->assertSee($todayLead->email);
        $all->assertSee($futureLead->email);
        $all->assertSee($noCallLead->email);

        // Today preset -> only today's lead.
        $today = $this->get(route('clients.index', ['schedule' => 'today']));
        $today->assertOk();
        $today->assertSee($todayLead->email);
        $today->assertDontSee($futureLead->email);
    }
}
