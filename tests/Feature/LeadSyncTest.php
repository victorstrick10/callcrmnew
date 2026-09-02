<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Services\LeadSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_upserts_by_company_and_email_and_stores_metadata(): void
    {
        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'lead_api_url' => 'https://diligentplacers.com/api.php',
            'enabled' => true,
        ]);
        $company->setLeadApiKey('123123123');
        $company->save();

        Http::fake([
            'diligentplacers.com/api.php*' => Http::sequence()
                ->push([
                    [
                        'ok' => true,
                        'first_name' => 'Léonard',
                        'last_name' => 'Facchino',
                        'email' => 'leonard.facchino@gmail.com',
                        'full_name' => 'Léonard Facchino',
                        'referrer' => 'https://www.linkedin.com/',
                        'user_agent' => 'Mozilla/5.0',
                    ],
                ])
                ->push([
                    [
                        'ok' => true,
                        'first_name' => 'Léonard',
                        'last_name' => 'Facchino Updated',
                        'email' => 'leonard.facchino@gmail.com',
                        'full_name' => 'Léonard Facchino Updated',
                        'referrer' => 'https://www.linkedin.com/',
                        'user_agent' => 'Mozilla/5.0 Chrome',
                    ],
                ]),
        ]);

        $sync = app(LeadSyncService::class);
        $first = $sync->syncCompany($company);
        $second = $sync->syncCompany($company);

        $this->assertSame(1, Contact::query()->where('company_id', $company->id)->count());
        $contact = Contact::query()->first();
        $this->assertSame('Facchino Updated', $contact->last_name);
        $this->assertSame('https://www.linkedin.com/', $contact->referrer);
        $this->assertSame('Mozilla/5.0 Chrome', $contact->lead_user_agent);
        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(0, $second['created']);
    }
}
