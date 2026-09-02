<?php

namespace Tests\Feature;

use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_diligent_placers_and_diligent_cold(): void
    {
        $this->seed(CompanySeeder::class);

        $this->assertDatabaseHas('companies', [
            'slug' => 'diligent',
            'name' => 'Diligent Placers',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('companies', [
            'slug' => 'diligent-cold',
            'name' => 'Diligent Cold',
            'lead_api_url' => 'https://diligentplacers.com/start-now/api.php',
            'enabled' => true,
        ]);
    }
}
