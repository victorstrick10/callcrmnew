<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_downloads_csv_for_filtered_leads(): void
    {
        $diligent = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'enabled' => true,
        ]);

        $other = Company::create([
            'name' => 'Other Co',
            'slug' => 'other',
            'enabled' => true,
        ]);

        Contact::create([
            'company_id' => $diligent->id,
            'first_name' => 'Ana',
            'last_name' => 'Black',
            'email' => 'ana@example.com',
        ]);

        Contact::create([
            'company_id' => $other->id,
            'first_name' => 'Other',
            'last_name' => 'Person',
            'email' => 'other@example.com',
        ]);

        $response = $this->get(route('clients.export', ['company' => 'diligent']));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('First Name', $csv);
        $this->assertStringContainsString('Last Name', $csv);
        $this->assertStringContainsString('Email', $csv);
        $this->assertStringContainsString('Ana,Black,ana@example.com', $csv);
        $this->assertStringNotContainsString('other@example.com', $csv);
    }
}
