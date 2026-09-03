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

    public function test_search_matches_full_name_with_space(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        // seedCall creates first_name "Owen", last_name "Case".
        $this->seedCall('2026-09-05 09:00:00', 'owenc@example.com');

        $this->get(route('outcomes.index', ['q' => 'Owen Case']))->assertOk()->assertSee('owenc@example.com');
        $this->get(route('outcomes.index', ['q' => 'Case Owen']))->assertOk()->assertSee('owenc@example.com');
    }

    public function test_outcome_card_filters_calls(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $deal = $this->seedCall('2026-09-10 09:00:00', 'deal@example.com');
        $deal->update(['outcome' => 'joined_line']);
        $miss = $this->seedCall('2026-09-10 10:00:00', 'miss@example.com');
        $miss->update(['outcome' => 'no_show']);

        $this->get(route('outcomes.index', ['range' => 'today', 'outcome' => 'deals']))
            ->assertOk()->assertSee('deal@example.com')->assertDontSee('miss@example.com');

        $this->get(route('outcomes.index', ['range' => 'today', 'outcome' => 'no_show']))
            ->assertOk()->assertSee('miss@example.com')->assertDontSee('deal@example.com');
    }

    public function test_lines_page_shows_only_closed_deals(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        $deal = $this->seedCall('2026-09-05 09:00:00', 'deal@example.com');
        $deal->update(['outcome' => 'joined_line']);
        $miss = $this->seedCall('2026-09-05 10:00:00', 'miss@example.com');
        $miss->update(['outcome' => 'no_show']);

        $this->get(route('outcomes.lines', ['range' => 'all']))
            ->assertOk()
            ->assertSee('deal@example.com')
            ->assertDontSee('miss@example.com');
    }

    public function test_gauge_counts_lead_once_by_best_outcome(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));

        // Same lead: an early rescheduled call + a later closed deal.
        $company = Company::create(['name' => 'Acme', 'slug' => 'acme-dedupe', 'enabled' => true]);
        $contact = Contact::create(['company_id' => $company->id, 'first_name' => 'Dee', 'last_name' => 'Dupe', 'email' => 'dee@example.com']);
        Appointment::create(['company_id' => $company->id, 'contact_id' => $contact->id, 'event_name' => 'c1', 'start_time' => Carbon::parse('2026-09-08 09:00:00', 'UTC'), 'status' => 'rescheduled', 'outcome' => 'pending']);
        Appointment::create(['company_id' => $company->id, 'contact_id' => $contact->id, 'event_name' => 'c2', 'start_time' => Carbon::parse('2026-09-10 09:00:00', 'UTC'), 'status' => 'scheduled', 'outcome' => 'joined_line']);

        // The analytics JSON should count this lead once as a deal, not also as rescheduled.
        $html = $this->get(route('outcomes.index', ['range' => 'all']))->assertOk()->getContent();
        $pos = strpos($html, 'window.__analytics');
        $json = substr($html, $pos, 220);
        // deal counted; rescheduled for the same lead suppressed → joined_line >=1, rescheduled 0 for this lead.
        $this->assertStringContainsString('"joined_line":1', $json);
        $this->assertStringContainsString('"rescheduled":0', $json);
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
