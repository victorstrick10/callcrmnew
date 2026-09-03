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

    private function seedCall(string $utcStart, string $email = 'owen@example.com'): Appointment
    {
        $slug = 'acme-'.substr(md5($email.$utcStart), 0, 8);
        $company = Company::create(['name' => 'Acme', 'slug' => $slug, 'enabled' => true]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Owen', 'last_name' => 'Case', 'email' => $email,
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
            ->assertSee('Call Stats');
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

    public function test_can_set_custom_outcome(): void
    {
        $appt = $this->seedCall('2026-09-10 09:00:00');

        $this->put(route('outcomes.update', $appt), [
            'outcome' => '__custom__',
            'outcome_custom' => 'Wants proposal',
        ])->assertRedirect();

        $appt->refresh();
        $this->assertSame('Wants proposal', $appt->outcome);
        $this->assertTrue($appt->hasCustomOutcome());
        $this->assertSame('Wants proposal', $appt->outcomeLabel());
    }

    public function test_custom_outcome_requires_text(): void
    {
        $appt = $this->seedCall('2026-09-10 09:00:00');

        $this->put(route('outcomes.update', $appt), ['outcome' => '__custom__', 'outcome_custom' => ''])
            ->assertSessionHasErrors('outcome_custom');
    }

    public function test_csv_export_has_expected_columns_and_rows(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $appt = $this->seedCall('2026-09-10 09:00:00');
        $appt->update(['outcome' => 'no_show', 'outcome_note' => 'no answer, retry']);

        $res = $this->get(route('outcomes.export', ['range' => 'today']));
        $res->assertOk();

        $csv = $res->streamedContent();
        foreach (['Lead Name', 'Company', 'Call time', 'Outcome', 'Comment'] as $col) {
            $this->assertStringContainsString($col, $csv);
        }
        $this->assertStringContainsString('Owen Case', $csv);
        $this->assertStringContainsString("Didn't join", $csv);
        $this->assertStringContainsString('no answer, retry', $csv);
    }

    public function test_search_finds_old_calls_across_all_dates(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $this->seedCall('2026-07-15 09:00:00', 'olddude@example.com'); // ~2 months ago

        // Default (today) view must NOT show the old call.
        $this->get(route('outcomes.index'))->assertOk()->assertDontSee('olddude@example.com');

        // Searching spans all dates and finds it.
        $this->get(route('outcomes.index', ['q' => 'olddude']))->assertOk()->assertSee('olddude@example.com');
    }

    public function test_defaults_to_today_and_sorts_earliest_first(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        // Two calls today (Belgrade) + one earlier this week but not today.
        $late = $this->seedCall('2026-09-10 16:00:00', 'late@example.com');
        $early = $this->seedCall('2026-09-10 08:00:00', 'early@example.com');
        $notToday = $this->seedCall('2026-09-08 09:00:00', 'old@example.com');

        $res = $this->get(route('outcomes.index')); // default = today
        $res->assertOk();
        // Only today's two calls appear; earliest first.
        $res->assertSeeInOrder([
            $early->contact->email,
            $late->contact->email,
        ]);
    }
}
