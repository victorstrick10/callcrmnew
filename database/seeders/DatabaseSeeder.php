<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\ProfileNumberService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CompanySeeder::class);

        app(ProfileNumberService::class)->initializeAllCompanies();

        if (Contact::query()->count() > 0) {
            return;
        }

        $company = Company::query()->where('slug', 'default')->first()
            ?: Company::query()->where('slug', 'diligent')->first()
            ?: Company::query()->first();

        $contact = Contact::create([
            'company_id' => $company?->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john.smith@example.com',
            'company' => 'Example Client',
        ]);

        Appointment::create([
            'company_id' => $company?->id,
            'contact_id' => $contact->id,
            'event_name' => 'Marketing Consultation',
            'start_time' => Carbon::create(2026, 7, 29, 14, 0),
            'end_time' => Carbon::create(2026, 7, 29, 14, 30),
            'invitee_timezone' => 'Europe/London',
            'status' => 'scheduled',
            'ip_address' => '8.8.8.8',
            'city' => 'Stirling',
            'region' => 'Scotland',
            'country' => 'United Kingdom',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'latitude' => 56.1165,
            'longitude' => -3.9369,
        ]);
    }
}
