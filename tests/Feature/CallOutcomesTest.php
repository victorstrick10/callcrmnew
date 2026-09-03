<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CallOutcomesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedCall(string $utcStart): Appointment
    {
        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Owen', 'last_name' => 'Case', 'email' => 'owen@example.com',
        ]);

        return Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Call',
            'start_time' => Carbon::parse($utcStart, 'UTC'),
            'status' => 'scheduled',
        ]);
    }

    public function test_outcomes_page_lists_calls_and_summary(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC')); // Thursday

        $appt = $this->seedCall('2026-09-10 09:00:00'); // this week

        $this->get(route('outcomes.index'))
            ->assertOk()
            ->assertSee('owen@example.com')
            ->assertSee('Call Outcomes');
    }

    public function test_can_set_outcome_and_comment(): void
    {
        $appt = $this->seedCall('2026-09-10 09:00:00');

        $this->put(route('outcomes.update', $appt), [
            'outcome' => 'no_show',
            'outcome_note' => 'Did not answer, will retry tomorrow',
        ])->assertRedirect();

        $appt->refresh();
        $this->assertSame('no_show', $appt->outcome);
        $this->assertSame('Did not answer, will retry tomorrow', $appt->outcome_note);
        $this->assertNotNull($appt->outcome_at);
    }

    public function test_can_toggle_kept_browser(): void
    {
        $appt = $this->seedCall('2026-09-10 09:00:00');
        $profile = BrowserProfile::create([
            'appointment_id' => $appt->id,
            'number' => 1,
            'profile_role' => 'geo',
            'profile_name' => '001 Owen GEO',
            'status' => 'created',
        ]);

        $this->post(route('outcomes.keep-profile', $profile))->assertRedirect();
        $this->assertTrue($profile->fresh()->is_kept);

        $this->post(route('outcomes.keep-profile', $profile))->assertRedirect();
        $this->assertFalse($profile->fresh()->is_kept);
    }

    public function test_rejects_invalid_outcome(): void
    {
        $appt = $this->seedCall('2026-09-10 09:00:00');

        $this->put(route('outcomes.update', $appt), ['outcome' => 'bogus'])
            ->assertSessionHasErrors('outcome');
    }
}
