<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->updateOrCreate(
            ['slug' => 'diligent'],
            [
                'name' => 'Diligent Placers',
                'lead_api_url' => 'https://diligentplacers.com/api.php',
                'multilogin_base_url' => 'https://api.multilogin.com',
                'enabled' => true,
            ]
        );

        Company::query()->updateOrCreate(
            ['slug' => 'diligent-cold'],
            [
                'name' => 'Diligent Cold',
                'lead_api_url' => 'https://diligentplacers.com/start-now/api.php',
                'multilogin_base_url' => 'https://api.multilogin.com',
                'enabled' => true,
            ]
        );

        // Remove placeholder tenants from earlier seeds.
        Company::query()
            ->whereIn('slug', ['default', 'company-two', 'company-three', 'diligent-coll'])
            ->delete();
    }
}
