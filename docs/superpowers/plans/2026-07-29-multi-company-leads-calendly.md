# Multi-Company Leads + Calendly Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `companies` table so each of 3 tenants has its own lead API, Calendly token, and Multilogin token; sync leads with dedupe; resolve call times from Calendly into appointments.

**Architecture:** Company is the tenant root. Contacts and appointments gain `company_id`. New clients sync Diligent-style lead APIs and Calendly REST per company. Existing webhook + Multilogin appointment flow become company-aware. Secrets stay encrypted with Laravel `Crypt`.

**Tech Stack:** Laravel 10, Postgres, Eloquent, HTTP client, existing Orbit CRM Blade UI, PHPUnit/Feature tests if present (otherwise Feature tests under `tests/Feature`).

**Spec:** `docs/superpowers/specs/2026-07-29-multi-company-leads-calendly-design.md`

**Codebase note:** Product language today is Contact + Appointment (not Lead/Call). Browser automation is Multilogin (user “GoLogin” maps to `multilogin_token` on company). Graphify was not installed; this plan used repo exploration as context.

---

## File map

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_07_29_000001_create_companies_and_scope_crm.php` | companies table + alter contacts/appointments |
| `app/Models/Company.php` | Company model + encrypted credential accessors |
| `app/Models/Contact.php` | company relation + lead fields |
| `app/Models/Appointment.php` | company relation |
| `app/Services/CompanyLeadApiClient.php` | HTTP to company lead API |
| `app/Services/LeadSyncService.php` | Upsert contacts from lead API |
| `app/Services/CalendlyApiClient.php` | Calendly REST with company token |
| `app/Services/AppointmentSyncService.php` | Match emails → Calendly events → appointments |
| `app/Services/CalendlyWebhookService.php` | Company-scoped webhook |
| `app/Http/Controllers/CompanyController.php` | CRUD + test + sync actions |
| `app/Console/Commands/SyncCompanyLeadsCommand.php` | Artisan sync |
| `routes/web.php` | Company routes + webhook slug route |
| `resources/views/companies/*` | Companies UI |
| `database/seeders/CompanySeeder.php` | Seed 3 company stubs |
| `tests/Feature/LeadSyncTest.php` | Dedupe + sync tests |
| `tests/Feature/AppointmentSyncTest.php` | Calendly matching tests |

---

### Task 1: Companies migration + model

**Files:**
- Create: `database/migrations/2026_07_29_000001_create_companies_and_scope_crm.php`
- Create: `app/Models/Company.php`
- Modify: `app/Models/Contact.php`
- Modify: `app/Models/Appointment.php`
- Test: `tests/Feature/CompanySchemaTest.php`

- [ ] **Step 1: Write failing schema test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
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

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Léonard',
            'last_name' => 'Facchino',
            'email' => 'leonard.facchino@gmail.com',
        ]);

        $this->assertSame($company->id, $contact->company_id);

        Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Dup',
            'last_name' => 'User',
            'email' => 'leonard.facchino@gmail.com',
        ]);
    }
}
```

Expect Step 1’s second create to eventually fail on unique `(company_id, email)` once migration exists — first assert failures will be missing table/columns.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanySchemaTest`

Expected: FAIL (missing `companies` table / columns)

- [ ] **Step 3: Write migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 80)->unique();
            $table->string('lead_api_url', 500)->default('');
            $table->text('lead_api_key_encrypted')->nullable();
            $table->text('calendly_api_token_encrypted')->nullable();
            $table->string('calendly_org_uri', 500)->default('');
            $table->text('calendly_webhook_signing_key_encrypted')->nullable();
            $table->text('multilogin_token_encrypted')->nullable();
            $table->string('multilogin_base_url', 255)->default('https://api.multilogin.com');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // Default company for existing rows
        $defaultId = DB::table('companies')->insertGetId([
            'name' => 'Default',
            'slug' => 'default',
            'lead_api_url' => '',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('contacts', function (Blueprint $table) use ($defaultId) {
            $table->foreignId('company_id')->default($defaultId)->after('id')->constrained('companies')->cascadeOnDelete();
            $table->string('referrer', 500)->default('');
            $table->text('lead_user_agent')->nullable();
            $table->string('lead_ip', 80)->default('');
            $table->jsonb('lead_raw_json')->nullable();
            $table->timestamp('lead_synced_at')->nullable();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['company_id', 'email']);
        });

        Schema::table('appointments', function (Blueprint $table) use ($defaultId) {
            $table->foreignId('company_id')->default($defaultId)->after('id')->constrained('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'email']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['referrer', 'lead_user_agent', 'lead_ip', 'lead_raw_json', 'lead_synced_at']);
            $table->unique('email');
        });

        Schema::dropIfExists('companies');
    }
};
```

- [ ] **Step 4: Write Company model with encrypted secret helpers**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'lead_api_url',
        'calendly_org_uri',
        'multilogin_base_url',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function setLeadApiKey(?string $value): void
    {
        $this->lead_api_key_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getLeadApiKey(): ?string
    {
        return $this->decryptNullable($this->lead_api_key_encrypted);
    }

    public function setCalendlyApiToken(?string $value): void
    {
        $this->calendly_api_token_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getCalendlyApiToken(): ?string
    {
        return $this->decryptNullable($this->calendly_api_token_encrypted);
    }

    public function setCalendlyWebhookSigningKey(?string $value): void
    {
        $this->calendly_webhook_signing_key_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getCalendlyWebhookSigningKey(): ?string
    {
        return $this->decryptNullable($this->calendly_webhook_signing_key_encrypted);
    }

    public function setMultiloginToken(?string $value): void
    {
        $this->multilogin_token_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getMultiloginToken(): ?string
    {
        return $this->decryptNullable($this->multilogin_token_encrypted);
    }

    private function decryptNullable(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

Update `Contact` fillable + `belongsTo(Company::class)`. Update `Appointment` fillable + `belongsTo(Company::class)`. Backfill existing appointments’ `company_id` from their contact in a data step if needed after migrate.

- [ ] **Step 5: Run migration + test**

Run:
```bash
php artisan migrate
php artisan test --filter=CompanySchemaTest
```

Expected: PASS on schema asserts; duplicate email in same company should throw query exception / unique violation — adjust test to `expectException` if preferred:

```php
$this->expectException(\Illuminate\Database\QueryException::class);
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_29_000001_create_companies_and_scope_crm.php app/Models/Company.php app/Models/Contact.php app/Models/Appointment.php tests/Feature/CompanySchemaTest.php
git commit -m "feat: add companies table and scope contacts/appointments"
```

---

### Task 2: Lead API client + sync with dedupe

**Files:**
- Create: `app/Services/CompanyLeadApiClient.php`
- Create: `app/Services/LeadSyncService.php`
- Test: `tests/Feature/LeadSyncTest.php`

- [ ] **Step 1: Write failing lead sync test**

```php
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
```

If the real `?all=1` response is `{ok:true, leads:[...]}` instead of a bare array, adjust the client parser once the live payload shape is confirmed — support both shapes.

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test --filter=LeadSyncTest`

- [ ] **Step 3: Implement client**

```php
<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CompanyLeadApiClient
{
    public function fetchAll(Company $company): array
    {
        return $this->request($company, ['all' => '1']);
    }

    public function fetchByEmail(Company $company, string $email): ?array
    {
        $rows = $this->request($company, ['email' => $email]);

        return $rows[0] ?? null;
    }

    private function request(Company $company, array $query): array
    {
        $url = trim($company->lead_api_url);
        $key = $company->getLeadApiKey();
        if ($url === '' || ! $key) {
            throw new RuntimeException("Company {$company->slug} missing lead API url/key");
        }

        $query['key'] = $key;
        $response = Http::timeout(30)->get($url, $query)->throw()->json();

        if (isset($response['email'])) {
            return [$response];
        }
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        if (isset($response['leads']) && is_array($response['leads'])) {
            return $response['leads'];
        }
        if (is_array($response) && array_is_list($response)) {
            return $response;
        }

        return [];
    }
}
```

- [ ] **Step 4: Implement LeadSyncService**

```php
<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Support\Str;

class LeadSyncService
{
    public function __construct(
        private CompanyLeadApiClient $client,
        private AuditService $audit,
    ) {
    }

    public function syncCompany(Company $company): array
    {
        $rows = $this->client->fetchAll($company);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $contact = Contact::query()->firstOrNew([
                'company_id' => $company->id,
                'email' => $email,
            ]);

            $isNew = ! $contact->exists;
            $contact->fill([
                'first_name' => (string) ($row['first_name'] ?? Str::before($row['full_name'] ?? '', ' ')),
                'last_name' => (string) ($row['last_name'] ?? Str::after($row['full_name'] ?? '', ' ')),
                'referrer' => (string) ($row['referrer'] ?? ''),
                'lead_user_agent' => (string) ($row['user_agent'] ?? ''),
                'lead_ip' => (string) ($row['ip'] ?? $row['ip_address'] ?? ''),
                'lead_raw_json' => $row,
                'lead_synced_at' => now(),
            ]);
            $contact->save();

            $isNew ? $created++ : $updated++;
        }

        $this->audit->log(
            'Lead sync completed',
            "Company {$company->slug}: created={$created} updated={$updated} skipped={$skipped}"
        );

        return compact('created', 'updated', 'skipped');
    }
}
```

- [ ] **Step 5: Run tests — expect PASS**

Run: `php artisan test --filter=LeadSyncTest`

- [ ] **Step 6: Commit**

```bash
git add app/Services/CompanyLeadApiClient.php app/Services/LeadSyncService.php tests/Feature/LeadSyncTest.php
git commit -m "feat: sync company leads with email dedupe"
```

---

### Task 3: Calendly API client + appointment time sync

**Files:**
- Create: `app/Services/CalendlyApiClient.php`
- Create: `app/Services/AppointmentSyncService.php`
- Modify: `app/Services/CalendlyWebhookService.php`
- Test: `tests/Feature/AppointmentSyncTest.php`

- [ ] **Step 1: Write failing appointment sync test**

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\AppointmentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_appointment_with_calendly_times_for_contact_email(): void
    {
        $company = Company::create([
            'name' => 'Diligent Placers',
            'slug' => 'diligent',
            'lead_api_url' => 'https://diligentplacers.com/api.php',
            'calendly_org_uri' => 'https://api.calendly.com/organizations/ABC',
            'enabled' => true,
        ]);
        $company->setCalendlyApiToken('cal-token');
        $company->save();

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Léonard',
            'last_name' => 'Facchino',
            'email' => 'leonard.facchino@gmail.com',
        ]);

        Http::fake([
            'api.calendly.com/scheduled_events*' => Http::response([
                'collection' => [[
                    'uri' => 'https://api.calendly.com/scheduled_events/EVT1',
                    'name' => 'Discovery Call',
                    'status' => 'active',
                    'start_time' => '2026-08-01T15:00:00.000000Z',
                    'end_time' => '2026-08-01T15:30:00.000000Z',
                ]],
            ]),
            'api.calendly.com/scheduled_events/EVT1/invitees*' => Http::response([
                'collection' => [[
                    'uri' => 'https://api.calendly.com/invitees/INV1',
                    'email' => 'leonard.facchino@gmail.com',
                    'timezone' => 'Europe/Paris',
                ]],
            ]),
        ]);

        $result = app(AppointmentSyncService::class)->syncCompany($company);

        $this->assertSame(1, $result['created']);
        $appointment = Appointment::query()->first();
        $this->assertSame($contact->id, $appointment->contact_id);
        $this->assertSame($company->id, $appointment->company_id);
        $this->assertSame('https://api.calendly.com/scheduled_events/EVT1', $appointment->calendly_event_uri);
        $this->assertNotNull($appointment->start_time);
    }
}
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test --filter=AppointmentSyncTest`

- [ ] **Step 3: Implement CalendlyApiClient**

```php
<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CalendlyApiClient
{
    public function listScheduledEvents(Company $company, array $query = []): array
    {
        $token = $company->getCalendlyApiToken();
        if (! $token) {
            throw new RuntimeException("Company {$company->slug} missing Calendly token");
        }

        $org = $company->calendly_org_uri;
        if ($org !== '') {
            $query['organization'] = $org;
        }

        $query = array_merge([
            'status' => 'active',
            'count' => 100,
        ], $query);

        return Http::withToken($token)
            ->timeout(30)
            ->get('https://api.calendly.com/scheduled_events', $query)
            ->throw()
            ->json('collection') ?? [];
    }

    public function listEventInvitees(Company $company, string $eventUri): array
    {
        $token = $company->getCalendlyApiToken();
        $uuid = basename(parse_url($eventUri, PHP_URL_PATH) ?: $eventUri);

        return Http::withToken($token)
            ->timeout(30)
            ->get("https://api.calendly.com/scheduled_events/{$uuid}/invitees", ['count' => 100])
            ->throw()
            ->json('collection') ?? [];
    }

    public function testToken(Company $company): array
    {
        $token = $company->getCalendlyApiToken();
        $json = Http::withToken($token)
            ->timeout(20)
            ->get('https://api.calendly.com/users/me')
            ->throw()
            ->json();

        return $json['resource'] ?? $json;
    }
}
```

- [ ] **Step 4: Implement AppointmentSyncService**

Match invitee email → contact for that company; create appointment if `calendly_event_uri` missing; update times if exists.

```php
// Core loop sketch inside AppointmentSyncService::syncCompany
foreach ($this->calendly->listScheduledEvents($company) as $event) {
    $invitees = $this->calendly->listEventInvitees($company, $event['uri']);
    foreach ($invitees as $invitee) {
        $email = strtolower((string) ($invitee['email'] ?? ''));
        $contact = Contact::query()
            ->where('company_id', $company->id)
            ->where('email', $email)
            ->first();
        if (! $contact) {
            continue; // or create minimal contact from invitee
        }
        $appointment = Appointment::query()->firstOrNew([
            'calendly_event_uri' => $event['uri'],
        ]);
        $appointment->fill([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'calendly_invitee_uri' => $invitee['uri'] ?? '',
            'event_name' => $event['name'] ?? 'Scheduled Call',
            'start_time' => $event['start_time'] ?? null,
            'end_time' => $event['end_time'] ?? null,
            'invitee_timezone' => $invitee['timezone'] ?? '',
            'status' => ($event['status'] ?? '') === 'canceled' ? 'canceled' : 'scheduled',
        ]);
        $appointment->save();
    }
}
```

Also add `syncContact(Company $company, Contact $contact)` that syncs events then filters invitees for that email (used after lead sync / single refresh).

- [ ] **Step 5: Make webhook company-aware**

Modify `CalendlyWebhookService::handle` to accept `?Company $company = null` (or resolve from route slug). When creating contact/appointment, set `company_id`. Prefer route:

`POST /webhooks/calendly/{company:slug}`

Keep legacy `/webhooks/calendly` mapping to `slug=default` for backward compatibility. Add slug to CSRF except list.

- [ ] **Step 6: Run tests — expect PASS**

Run: `php artisan test --filter=AppointmentSyncTest`

- [ ] **Step 7: Commit**

```bash
git add app/Services/CalendlyApiClient.php app/Services/AppointmentSyncService.php app/Services/CalendlyWebhookService.php app/Http/Controllers/CalendlyWebhookController.php routes/web.php tests/Feature/AppointmentSyncTest.php
git commit -m "feat: sync Calendly call times per company"
```

---

### Task 4: Artisan command + combined sync pipeline

**Files:**
- Create: `app/Console/Commands/SyncCompanyLeadsCommand.php`
- Modify: `app/Console/Kernel.php` (optional schedule)
- Modify: `app/Services/LeadSyncService.php` (call appointment sync after leads)

- [ ] **Step 1: Implement command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AppointmentSyncService;
use App\Services\LeadSyncService;
use Illuminate\Console\Command;

class SyncCompanyLeadsCommand extends Command
{
    protected $signature = 'leads:sync {company? : Company slug} {--skip-calendly}';
    protected $description = 'Sync leads from company APIs and resolve Calendly call times';

    public function handle(LeadSyncService $leads, AppointmentSyncService $appointments): int
    {
        $slug = $this->argument('company');
        $query = Company::query()->where('enabled', true);
        if ($slug) {
            $query->where('slug', $slug);
        }

        foreach ($query->get() as $company) {
            $this->info("Syncing leads: {$company->slug}");
            $leadStats = $leads->syncCompany($company);
            $this->line(json_encode($leadStats));

            if (! $this->option('skip-calendly')) {
                $this->info("Syncing Calendly: {$company->slug}");
                $apptStats = $appointments->syncCompany($company);
                $this->line(json_encode($apptStats));
            }
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Manual smoke (with Http fake or real Diligent key in local only)**

Run: `php artisan leads:sync diligent --skip-calendly`

Expected: command runs; contacts upserted when API reachable.

- [ ] **Step 3: Optional schedule**

In `app/Console/Kernel.php`:
```php
$schedule->command('leads:sync')->everyFifteenMinutes();
```

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/SyncCompanyLeadsCommand.php app/Console/Kernel.php
git commit -m "feat: add leads:sync artisan command"
```

---

### Task 5: Companies UI + seeder for 3 companies

**Files:**
- Create: `app/Http/Controllers/CompanyController.php`
- Create: `resources/views/companies/index.blade.php`
- Create: `resources/views/companies/edit.blade.php`
- Modify: `routes/web.php`
- Modify: nav partial / layout to link Companies
- Create: `database/seeders/CompanySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Routes**

```php
Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
Route::post('/companies/{company}/test-lead-api', [CompanyController::class, 'testLeadApi'])->name('companies.test-lead-api');
Route::post('/companies/{company}/test-calendly', [CompanyController::class, 'testCalendly'])->name('companies.test-calendly');
Route::post('/companies/{company}/sync', [CompanyController::class, 'sync'])->name('companies.sync');
```

- [ ] **Step 2: Controller store/update**

Accept: name, slug, lead_api_url, lead_api_key (only set if non-empty), calendly_api_token, calendly_org_uri, calendly_webhook_signing_key, multilogin_token, multilogin_base_url, enabled. Never echo decrypted secrets back — show masked via `IntegrationSettingsService::masked` or company helper.

- [ ] **Step 3: Blade forms**

Match existing Orbit CRM settings styling (`resources/views/settings/*`). Fields for the three credential groups: Lead API, Calendly, Multilogin.

- [ ] **Step 4: Seeder stubs**

```php
foreach ([
    ['name' => 'Diligent Placers', 'slug' => 'diligent', 'lead_api_url' => 'https://diligentplacers.com/api.php'],
    ['name' => 'Company Two', 'slug' => 'company-two', 'lead_api_url' => ''],
    ['name' => 'Company Three', 'slug' => 'company-three', 'lead_api_url' => ''],
] as $row) {
    Company::query()->updateOrCreate(['slug' => $row['slug']], $row + ['enabled' => true]);
}
```

Do **not** commit real API keys in the seeder.

- [ ] **Step 5: Filter clients/appointments by company (minimal)**

Add optional `?company=` query on `ClientController` and `AppointmentController` index. Default show all with company name column.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CompanyController.php resources/views/companies routes/web.php database/seeders/CompanySeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat: companies UI and seeder for three tenants"
```

---

### Task 6: Multilogin company token wiring

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` and/or `app/Services/MultiloginClient.php`
- Modify: `app/Services/AppointmentService.php`
- Test: extend existing Multilogin test if any, else feature test with Http fake

- [ ] **Step 1: Allow MultiloginClient to run with an explicit token**

Add method `withCompany(Company $company): self` (clone/config) or pass token into create profile methods:

```php
public function forCompany(Company $company): static
{
    $clone = clone $this;
    $clone->token = $company->getMultiloginToken() ?: $this->token;
    $clone->baseUrl = $company->multilogin_base_url ?: $this->baseUrl;
    return $clone;
}
```

Exact shape depends on current `MultiloginClient` constructor — inspect `AppServiceProvider` binding and adapt without breaking global settings fallback.

- [ ] **Step 2: AppointmentService uses appointment.company**

When creating geo/static profiles, resolve `$appointment->company` and call Multilogin with that company’s token.

- [ ] **Step 3: Manual / test verification**

Http::fake Multilogin endpoints; assert Authorization header uses company token, not global.

- [ ] **Step 4: Commit**

```bash
git add app/Services/MultiloginClient.php app/Services/AppointmentService.php
git commit -m "feat: use per-company Multilogin token for profiles"
```

---

### Task 7: End-to-end verification

- [ ] **Step 1: Migrate + seed**

```bash
php artisan migrate
php artisan db:seed --class=CompanySeeder
```

- [ ] **Step 2: Configure Diligent in UI**

Set lead API key `123123123` (or real key), Calendly token, Multilogin token for slug `diligent`.

- [ ] **Step 3: Sync**

```bash
php artisan leads:sync diligent
```

Expected:
- Contacts created/updated from lead API with referrer + user_agent
- Re-run creates 0 new contacts for same emails
- Appointments get Calendly start/end when invitee email matches

- [ ] **Step 4: Run full test suite**

```bash
php artisan test
```

Expected: all new tests green; no regressions in existing CRM tests.

- [ ] **Step 5: Final commit if any fixes**

```bash
git add -A
git commit -m "fix: polish multi-company lead and Calendly sync"
```

---

## Self-review

1. **Spec coverage:** companies table, encrypted credentials, lead sync + dedupe, Calendly call times, webhook scoping, Multilogin per company, UI, artisan command — each has a task.
2. **Placeholders:** none intentional; live `?all=1` JSON envelope may need a one-line parser tweak once confirmed against production.
3. **Naming:** Multilogin (not GoLogin) matches existing codebase; field is `multilogin_token_encrypted`.
4. **Duplicates:** unique `(company_id, email)` + unique `calendly_event_uri`.

## Open points to confirm during implementation

1. Exact JSON shape of `?all=1` (list vs `{leads:[...]}`) — client already supports multiple shapes.
2. Whether missing Calendly matches should auto-create contacts from Calendly invitees only.
3. Final names/slugs/API URLs for company 2 and company 3.
