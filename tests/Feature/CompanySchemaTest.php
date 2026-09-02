<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_companies_table_exists_and_contacts_are_scoped(): void
    {
        $this->assertTrue(Schema::hasTable('companies'));
        $this->assertTrue(Schema::hasColumns('contacts', [
            'company_id', 'referrer', 'lead_user_agent', 'lead_ip', 'lead_raw_json', 'lead_synced_at',
        ]));
        $this->assertTrue(Schema::hasColumn('appointments', 'company_id'));

        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'lead_api_url' => 'https://diligentplacers.com/api.php',
            'enabled' => true,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Léonard',
            'last_name' => 'Facchino',
            'email' => 'leonard.facchino@gmail.com',
        ]);

        $this->assertSame(1, Contact::query()->where('company_id', $company->id)->count());

        $this->expectException(QueryException::class);
        Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Dup',
            'last_name' => 'User',
            'email' => 'leonard.facchino@gmail.com',
        ]);
    }
}
