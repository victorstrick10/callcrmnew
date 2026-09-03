<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ProfileNumber;
use App\Services\ProfileNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_number_is_1_when_empty(): void
    {
        $appointmentId = $this->makeAppointmentId();
        $svc = app(ProfileNumberService::class);

        $this->assertSame([1], $svc->allocateNumbers($appointmentId, 1));
        $this->assertSame('001', $svc->formatNumber(1));
    }

    public function test_allocates_sequential_from_max_browser_profile(): void
    {
        $appointmentId = $this->makeAppointmentId();
        BrowserProfile::query()->create([
            'appointment_id' => $appointmentId,
            'number' => 5,
            'profile_role' => 'geo',
            'profile_name' => '005 Test',
            'status' => 'created',
        ]);

        $svc = app(ProfileNumberService::class);

        // Same appointment reuses 5 for additional roles.
        $this->assertSame([5, 5], $svc->allocateNumbers($appointmentId, 2));
    }

    public function test_next_appointment_gets_lowest_free_gap(): void
    {
        $company = $this->makeCompany('gap-co');
        $firstId = $this->makeAppointmentId('first@example.com', $company);
        $svc = app(ProfileNumberService::class);
        $svc->initializeForCompany($company->id);

        foreach ([1, 2, 3, 5] as $n) {
            ProfileNumber::query()->where('company_id', $company->id)->where('number', $n)->update([
                'status' => 'created',
                'multilogin_profile_id' => "ml-{$n}",
                'profile_name' => sprintf('%03d Manual', $n),
            ]);
        }

        $secondId = $this->makeAppointmentId('second@example.com', $company);

        $this->assertSame(4, $svc->allocateNumberForAppointment($secondId));
    }

    public function test_companies_have_independent_pools(): void
    {
        $a = $this->makeCompany('co-a');
        $b = $this->makeCompany('co-b');
        $svc = app(ProfileNumberService::class);

        $apptA = $this->makeAppointmentId('a@example.com', $a);
        $this->assertSame(1, $svc->allocateNumberForAppointment($apptA));

        $apptB = $this->makeAppointmentId('b@example.com', $b);
        $this->assertSame(1, $svc->allocateNumberForAppointment($apptB));
    }

    public function test_sync_marks_and_frees_numbers_for_company_only(): void
    {
        $a = $this->makeCompany('sync-a');
        $b = $this->makeCompany('sync-b');
        $svc = app(ProfileNumberService::class);
        $svc->initializeForCompany($a->id);
        $svc->initializeForCompany($b->id);

        ProfileNumber::query()->where('company_id', $b->id)->where('number', 1)->update([
            'status' => 'created',
            'multilogin_profile_id' => 'other',
            'profile_name' => '001 Other',
        ]);

        $result = $svc->syncFromProfiles($a->id, [
            ['id' => 'ml-1', 'name' => '001 Ana'],
            ['id' => 'ml-2', 'name' => '002 Ana Black Static'],
            ['id' => 'ml-3', 'name' => '003 Ana'],
            ['id' => 'ml-5', 'name' => '005 Ana Black Houston,Texas,US'],
        ]);

        $this->assertSame(4, $result['numbers_marked']);
        $this->assertSame(4, ProfileNumber::query()->where('company_id', $a->id)->where('status', 'created')->count());
        $this->assertSame(4, $result['next_free']);

        $row2 = ProfileNumber::query()->where('company_id', $a->id)->where('number', 2)->first();
        $this->assertSame('ml-2', $row2->multilogin_profile_id);
        $this->assertSame('002 Ana Black Static', $row2->profile_name);

        // Company B untouched.
        $this->assertSame('created', ProfileNumber::query()->where('company_id', $b->id)->where('number', 1)->value('status'));
    }

    public function test_allocation_releases_stale_reservation_and_fills_lowest_free(): void
    {
        $company = $this->makeCompany('stale-co');
        $svc = app(ProfileNumberService::class);
        $svc->initializeForCompany($company->id);

        $apptId = $this->makeAppointmentId('fran@example.com', $company);

        // A prior FAILED attempt left number 3 reserved to this lead (no created profile).
        ProfileNumber::query()->where('company_id', $company->id)->where('number', 3)->update([
            'status' => 'reserved',
            'appointment_id' => $apptId,
            'reserved_at' => now(),
        ]);
        BrowserProfile::query()->create([
            'appointment_id' => $apptId,
            'number' => 3,
            'profile_role' => 'geo',
            'profile_name' => '003 Fran',
            'status' => 'failed',
        ]);

        // 001 is free → allocation must return 001, not the stale/failed 003.
        $this->assertSame(1, $svc->allocateNumberForAppointment($apptId));
        // The stale reservation on 3 is released back to available.
        $this->assertSame('available', ProfileNumber::query()->where('company_id', $company->id)->where('number', 3)->value('status'));
        // 001 is now reserved to this lead.
        $this->assertSame('reserved', ProfileNumber::query()->where('company_id', $company->id)->where('number', 1)->value('status'));
    }

    public function test_allocate_next_number_is_sequential_and_never_reuses(): void
    {
        $company = $this->makeCompany('seq-co');
        $svc = app(ProfileNumberService::class);
        $apptId = $this->makeAppointmentId('seq@example.com', $company);

        // Same lead's GEO then STATIC get distinct sequential numbers (001, 002, 003…).
        $this->assertSame(1, $svc->allocateNextNumber($company->id, $apptId));
        $this->assertSame(2, $svc->allocateNextNumber($company->id, $apptId));
        $this->assertSame(3, $svc->allocateNextNumber($company->id, $apptId));
    }

    public function test_allocation_reuses_created_number_for_same_lead(): void
    {
        $company = $this->makeCompany('reuse-co');
        $svc = app(ProfileNumberService::class);
        $svc->initializeForCompany($company->id);
        $apptId = $this->makeAppointmentId('joe@example.com', $company);

        // A CREATED geo profile numbered 5 for this lead.
        BrowserProfile::query()->create([
            'appointment_id' => $apptId,
            'number' => 5,
            'profile_role' => 'geo',
            'profile_name' => '005 Joe',
            'status' => 'created',
        ]);

        // STATIC for the same lead reuses 5 (shared number), not the lowest free.
        $this->assertSame(5, $svc->allocateNumberForAppointment($apptId));
    }

    public function test_extract_number_supports_padded_three_digits(): void
    {
        $svc = app(ProfileNumberService::class);

        $this->assertSame(2, $svc->extractNumber('002 Ana Black Houston,Texas,US'));
        $this->assertSame(1000, $svc->extractNumber('1000 Ana Black Houston,Texas,US'));
    }

    private function makeCompany(string $slug): Company
    {
        return Company::create([
            'name' => $slug,
            'slug' => $slug,
            'enabled' => true,
        ]);
    }

    private function makeAppointmentId(string $email = 'ana@example.com', ?Company $company = null): int
    {
        $company ??= Company::query()->first() ?: $this->makeCompany('test-co');

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Ana',
            'last_name' => 'Black',
            'email' => $email,
        ]);

        return Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Scheduled Call',
        ])->id;
    }
}
