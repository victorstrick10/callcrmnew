<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProfileNumber;
use App\Services\IntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileNumbersPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedCompany(string $slug = 'diligent', string $token = 'company-ml-token'): Company
    {
        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => $slug,
            'multilogin_base_url' => 'https://api.multilogin.com',
            'enabled' => true,
        ]);
        $company->setMultiloginToken($token);
        $company->save();

        app(IntegrationSettingsService::class)->saveSettings('multilogin', [
            'simulation_mode' => 'false',
            'automation_token' => 'global-unused',
            'base_url' => 'https://api.multilogin.com',
            'workspace_id' => 'ws-1',
            'profile_search_endpoint' => '/profile/search',
            'profile_update_endpoint' => '/profile/partial_update',
        ]);

        return $company;
    }

    public function test_index_requires_company_filter(): void
    {
        $this->seedCompany();

        $this->get(route('numbers.index'))
            ->assertOk()
            ->assertSee('Select a company')
            ->assertDontSee('Sync Multilogin profiles');
    }

    public function test_sync_without_company_fails(): void
    {
        $this->seedCompany();

        $this->post(route('numbers.sync'))
            ->assertRedirect(route('numbers.index'))
            ->assertSessionHas('danger');
    }

    public function test_sync_uses_company_and_marks_gaps(): void
    {
        $company = $this->seedCompany();

        Http::fake([
            'https://api.multilogin.com/profile/search' => Http::response([
                'data' => [
                    ['id' => 'a', 'name' => '001 One'],
                    ['id' => 'b', 'name' => '002 Two'],
                    ['id' => 'c', 'name' => '003 Three'],
                    ['id' => 'e', 'name' => '005 Five'],
                ],
            ]),
        ]);

        $this->post(route('numbers.sync'), ['company_id' => $company->id])
            ->assertRedirect(route('numbers.index', ['company_id' => $company->id]))
            ->assertSessionHas('success');

        $this->assertSame('created', ProfileNumber::query()
            ->where('company_id', $company->id)->where('number', 5)->value('status'));
        $this->assertSame('available', ProfileNumber::query()
            ->where('company_id', $company->id)->where('number', 4)->value('status'));

        $this->get(route('numbers.index', ['company_id' => $company->id]))
            ->assertOk()
            ->assertSee('004')
            ->assertSee('001 One');
    }

    public function test_rename_updates_multilogin_and_local_name(): void
    {
        $company = $this->seedCompany();
        app(\App\Services\ProfileNumberService::class)->initializeForCompany($company->id);

        $row = ProfileNumber::query()->where('company_id', $company->id)->where('number', 1)->first();
        $row->status = 'created';
        $row->multilogin_profile_id = 'ml-rename';
        $row->profile_name = '001 Old Name';
        $row->save();

        Http::fake([
            'https://api.multilogin.com/profile/partial_update' => Http::response(['status' => 'ok']),
        ]);

        $this->put(route('numbers.update', $row), [
            'profile_name' => '001 New Full Name',
        ])
            ->assertRedirect(route('numbers.index', ['company_id' => $company->id]))
            ->assertSessionHas('success');

        $this->assertSame('001 New Full Name', $row->fresh()->profile_name);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'partial_update')
                && ($request['profile_id'] ?? null) === 'ml-rename'
                && ($request['name'] ?? null) === '001 New Full Name';
        });
    }

    public function test_rename_with_new_leading_number_remaps_pool_and_multilogin(): void
    {
        $company = $this->seedCompany();
        $svc = app(\App\Services\ProfileNumberService::class);
        $svc->initializeForCompany($company->id);

        $contact = \App\Models\Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Someone',
            'last_name' => 'Test',
            'email' => 'someone'.uniqid().'@example.com',
        ]);
        $appointment = \App\Models\Appointment::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'event_name' => 'Call',
        ]);

        $row = ProfileNumber::query()->where('company_id', $company->id)->where('number', 159)->first();
        $row->status = 'created';
        $row->multilogin_profile_id = 'ml-159';
        $row->profile_name = '159 Someone Static';
        $row->appointment_id = $appointment->id;
        $row->profile_type = 'static';
        $row->save();

        \App\Models\BrowserProfile::query()->create([
            'appointment_id' => $appointment->id,
            'number' => 159,
            'profile_role' => 'static',
            'profile_name' => '159 Someone Static',
            'multilogin_profile_id' => 'ml-159',
            'status' => 'created',
        ]);

        Http::fake([
            'https://api.multilogin.com/profile/partial_update' => Http::response(['status' => 'ok']),
        ]);

        $this->put(route('numbers.update', $row), [
            'profile_name' => '007 Someone Static',
        ])
            ->assertRedirect(route('numbers.index', ['company_id' => $company->id]))
            ->assertSessionHas('success');

        $this->assertSame('available', ProfileNumber::query()
            ->where('company_id', $company->id)->where('number', 159)->value('status'));
        $this->assertSame('', (string) ProfileNumber::query()
            ->where('company_id', $company->id)->where('number', 159)->value('multilogin_profile_id'));

        $target = ProfileNumber::query()->where('company_id', $company->id)->where('number', 7)->first();
        $this->assertSame('created', $target->status);
        $this->assertSame('ml-159', $target->multilogin_profile_id);
        $this->assertSame('007 Someone Static', $target->profile_name);
        $this->assertSame($appointment->id, $target->appointment_id);

        $bp = \App\Models\BrowserProfile::query()->where('multilogin_profile_id', 'ml-159')->first();
        $this->assertSame(7, $bp->number);
        $this->assertSame('007 Someone Static', $bp->profile_name);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'partial_update')
                && ($request['profile_id'] ?? null) === 'ml-159'
                && ($request['name'] ?? null) === '007 Someone Static';
        });
    }
}
