<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientsTodayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeContactWithCall(Company $company, string $name, string $utcStart): Contact
    {
        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => $name,
            'last_name' => 'Tester',
            'email' => strtolower($name).'@example.com',
        ]);

        Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Discovery Call',
            'start_time' => Carbon::parse($utcStart, 'UTC'),
            'status' => 'scheduled',
        ]);

        return $contact;
    }

    /**
     * "Today's calls" must follow the operator display timezone, not UTC.
     *
     * At 2026-09-02 22:30 UTC it is already 2026-09-03 00:30 in Europe/Belgrade
     * (CEST, UTC+2). So "today" for the operator is Sep 3, and a Sep 2 call must
     * NOT appear while a Sep 3 call must.
     */
    public function test_today_filter_uses_display_timezone(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-02 22:30:00', 'UTC'));

        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'enabled' => true,
        ]);

        // Sep 2 17:00 Belgrade -> belongs to "yesterday" for the operator.
        $yesterday = $this->makeContactWithCall($company, 'Yesterdaycall', '2026-09-02 15:00:00');
        // Sep 3 12:00 Belgrade -> belongs to "today" for the operator.
        $today = $this->makeContactWithCall($company, 'Todaycall', '2026-09-03 10:00:00');

        $response = $this->get(route('clients.index', ['schedule' => 'today']));

        $response->assertOk();
        $response->assertSee($today->email);
        $response->assertDontSee($yesterday->email);
    }
}
