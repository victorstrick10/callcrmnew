# Multilogin Profile Bulk Create Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create missing GEO/STATIC Multilogin profiles per appointment with numbering from 1000 (N+1), new name rules, static proxy pool, city→region→country GEO fallback, and Clients bulk create with checkboxes.

**Architecture:** Extend Laravel `AppointmentService` + `MultiloginClient` + `ProfileNumberService`. Add `static_proxies` table and Clients bulk POST. Multilogin create/proxy calls use **the lead’s company** token via `forCompany($appointment->company)` (fail if missing). IPinfo geolocation stays **global** for all companies. No local Python.

**Tech Stack:** Laravel, Eloquent, PHPUnit, Http::fake, Blade

**Spec:** `docs/superpowers/specs/2026-07-30-multilogin-profile-bulk-create-design.md`

---

## File map

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_07_30_000001_create_static_proxies_table.php` | `static_proxies` schema |
| `app/Models/StaticProxy.php` | Eloquent model |
| `app/Services/ProfileNumberService.php` | Next number from DB max (≥1000) |
| `app/Services/ProfileNameFormatter.php` | GEO/STATIC name strings |
| `app/Services/StaticProxyService.php` | Random enabled proxy |
| `app/Services/MultiloginClient.php` | Proxy location fallback; new STATIC create (no clone) |
| `app/Services/AppointmentService.php` | Missing-only pipeline; enrich; auto GEO proxy |
| `app/Http/Controllers/ClientController.php` | Bulk create action |
| `app/Http/Controllers/StaticProxyController.php` | CRUD list/create/delete |
| `resources/views/clients/index.blade.php` | Checkboxes + bulk button |
| `resources/views/static-proxies/index.blade.php` | Manage pool |
| `routes/web.php` | New routes |
| `tests/Unit/ProfileNumberServiceTest.php` | Numbering |
| `tests/Unit/ProfileNameFormatterTest.php` | Names |
| `tests/Feature/CreateMissingProfilesTest.php` | Pipeline + bulk |

---

### Task 1: `static_proxies` migration + model

**Files:**
- Create: `database/migrations/2026_07_30_000001_create_static_proxies_table.php`
- Create: `app/Models/StaticProxy.php`
- Test: `tests/Unit/StaticProxyModelTest.php` (optional smoke) — prefer Feature later; for this task use migration run as verify

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_proxies', function (Blueprint $table) {
            $table->id();
            $table->string('label', 255)->default('');
            $table->string('host', 255);
            $table->unsignedInteger('port');
            $table->string('username', 255)->default('');
            $table->text('password')->nullable();
            $table->string('protocol', 20)->default('http');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_proxies');
    }
};
```

- [ ] **Step 2: Create model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaticProxy extends Model
{
    protected $fillable = [
        'label', 'host', 'port', 'username', 'password', 'protocol', 'enabled',
    ];

    protected $casts = [
        'port' => 'integer',
        'enabled' => 'boolean',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function toMultiloginProxy(): array
    {
        return [
            'host' => $this->host,
            'port' => (int) $this->port,
            'username' => (string) $this->username,
            'password' => (string) ($this->password ?? ''),
            'protocol' => $this->protocol ?: 'http',
        ];
    }
}
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate --path=database/migrations/2026_07_30_000001_create_static_proxies_table.php`  
Expected: Migrated successfully; table exists.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_30_000001_create_static_proxies_table.php app/Models/StaticProxy.php
git commit -m "feat: add static_proxies table for Multilogin STATIC profiles"
```

---

### Task 2: Numbering — start at 1000, N+1 from DB

**Files:**
- Modify: `app/Services/ProfileNumberService.php`
- Test: `tests/Unit/ProfileNumberServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\BrowserProfile;
use App\Models\ProfileNumber;
use App\Services\ProfileNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_number_is_1000_when_empty(): void
    {
        $svc = app(ProfileNumberService::class);
        $this->assertSame([1000], $svc->allocateNumbers(1, 1));
    }

    public function test_allocates_sequential_from_max_browser_profile(): void
    {
        // Minimal contact/appointment factories or manual inserts as used elsewhere in tests
        $appointmentId = $this->makeAppointmentId();
        BrowserProfile::query()->create([
            'appointment_id' => $appointmentId,
            'number' => 1005,
            'profile_role' => 'geo',
            'profile_name' => '1005 Test',
            'status' => 'created',
        ]);

        $svc = app(ProfileNumberService::class);
        $this->assertSame([1006, 1007], $svc->allocateNumbers($appointmentId, 2));
    }

    public function test_extract_number_supports_four_digits(): void
    {
        $svc = app(ProfileNumberService::class);
        $this->assertSame(1000, $svc->extractNumber('1000 Ana Black Houston,Texas,US'));
        $this->assertSame(1001, $svc->extractNumber('1001 Ana Black Static'));
    }
}
```

Helper `makeAppointmentId()`: copy pattern from `tests/Feature/AppointmentSyncTest.php` (create contact + appointment). If factories missing, insert with required columns.

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=ProfileNumberServiceTest`  
Expected: FAIL (allocateNumbers missing / extract still 3-digit).

- [ ] **Step 3: Implement allocation + extract**

In `ProfileNumberService`:

```php
public function nextNumber(): int
{
    $maxBrowser = (int) BrowserProfile::query()->max('number');
    $maxPool = (int) ProfileNumber::query()->max('number');

    return max($maxBrowser, $maxPool, 999) + 1;
}

/**
 * @return list<int>
 */
public function allocateNumbers(int $appointmentId, int $count): array
{
    return DB::transaction(function () use ($appointmentId, $count) {
        // Lock existing high rows if using profile_numbers; otherwise lock browser_profiles
        BrowserProfile::query()->lockForUpdate()->orderByDesc('number')->limit(1)->get();
        ProfileNumber::query()->lockForUpdate()->orderByDesc('number')->limit(1)->get();

        $start = $this->nextNumber();
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $start + $i;
            ProfileNumber::query()->updateOrInsert(
                ['number' => $n],
                [
                    'status' => 'reserved',
                    'appointment_id' => $appointmentId,
                    'profile_type' => '',
                    'reserved_at' => now(),
                    'multilogin_profile_id' => '',
                ]
            );
            $numbers[] = $n;
        }

        return $numbers;
    });
}

public function extractNumber(?string $name): ?int
{
    if (! preg_match('/^(\d+)\s+/', (string) $name, $match)) {
        return null;
    }
    $value = (int) $match[1];

    return $value >= 1 ? $value : null;
}

public function formatNumber(int $number): string
{
    // No zero-pad for 1000+; keep unpadded integer string
    return (string) $number;
}
```

Deprecate/replace `reserveNumbers()` callers to use `allocateNumbers($appointmentId, count($roles))` then zip with roles.

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=ProfileNumberServiceTest`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProfileNumberService.php tests/Unit/ProfileNumberServiceTest.php
git commit -m "feat: allocate Multilogin profile numbers from 1000 with N+1"
```

---

### Task 3: Profile name formatter

**Files:**
- Create: `app/Services/ProfileNameFormatter.php`
- Test: `tests/Unit/ProfileNameFormatterTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Unit;

use App\Services\ProfileNameFormatter;
use Tests\TestCase;

class ProfileNameFormatterTest extends TestCase
{
    public function test_geo_name(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame(
            '1000 Ana Black Houston,Texas,US',
            $f->geo(1000, 'Ana Black', 'Houston', 'Texas', 'US')
        );
    }

    public function test_geo_omits_empty_segments(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('1000 Ana Black Texas,US', $f->geo(1000, 'Ana Black', '', 'Texas', 'US'));
        $this->assertSame('1000 Ana Black US', $f->geo(1000, 'Ana Black', '', '', 'US'));
    }

    public function test_static_name(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertSame('1001 Ana Black Static', $f->staticName(1001, 'Ana Black'));
    }

    public function test_has_usable_geo_location_requires_country(): void
    {
        $f = new ProfileNameFormatter();
        $this->assertFalse($f->hasUsableGeoLocation('', '', ''));
        $this->assertTrue($f->hasUsableGeoLocation('', '', 'US'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test --filter=ProfileNameFormatterTest`

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services;

class ProfileNameFormatter
{
    public function geo(int $number, string $fullName, ?string $city, ?string $region, ?string $country): string
    {
        $parts = array_values(array_filter([
            trim((string) $city),
            trim((string) $region),
            trim((string) $country),
        ], fn ($p) => $p !== ''));

        $location = implode(',', $parts);

        return trim($number.' '.$fullName.' '.$location);
    }

    public function staticName(int $number, string $fullName): string
    {
        return $number.' '.$fullName.' Static';
    }

    public function hasUsableGeoLocation(?string $city, ?string $region, ?string $country): bool
    {
        return trim((string) $country) !== '';
    }
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProfileNameFormatter.php tests/Unit/ProfileNameFormatterTest.php
git commit -m "feat: add Multilogin profile name formatter"
```

---

### Task 4: StaticProxyService — random enabled proxy

**Files:**
- Create: `app/Services/StaticProxyService.php`
- Test: `tests/Unit/StaticProxyServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_picks_only_enabled(): void
{
    StaticProxy::create(['label' => 'off', 'host' => 'a.com', 'port' => 1, 'enabled' => false, 'protocol' => 'http']);
    $on = StaticProxy::create(['label' => 'on', 'host' => 'b.com', 'port' => 2, 'enabled' => true, 'protocol' => 'http']);

    $picked = app(StaticProxyService::class)->randomEnabled();
    $this->assertSame($on->id, $picked->id);
}

public function test_throws_when_none_enabled(): void
{
    $this->expectException(RuntimeException::class);
    app(StaticProxyService::class)->randomEnabled();
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services;

use App\Models\StaticProxy;
use RuntimeException;

class StaticProxyService
{
    public function randomEnabled(): StaticProxy
    {
        $proxy = StaticProxy::query()->enabled()->inRandomOrder()->first();
        if (! $proxy) {
            throw new RuntimeException('No enabled static proxies configured. Add at least one in Static Proxies.');
        }

        return $proxy;
    }
}
```

- [ ] **Step 4: Run — PASS + commit**

```bash
git commit -m "feat: pick random enabled static proxy for STATIC profiles"
```

---

### Task 5: Multilogin GEO proxy city→region→country fallback

**Files:**
- Modify: `app/Services/MultiloginClient.php` (`generate_multilogin_proxy` and helpers)
- Test: `tests/Unit/MultiloginClientTest.php` (extend) or `tests/Feature/MultiloginProxyFallbackTest.php`

- [ ] **Step 1: Write failing test with Http::fake**

Fake Multilogin proxy endpoint:
1. First POST body with city → return 400  
2. Second without city (region+country) → 200 with connection string  
Assert second attempt used empty city / omitted city.

Alternatively unit-test a new helper:

```php
public static function proxy_location_attempts(array $location): array
{
    $country = self::_country_code($location['country'] ?? '');
    $region = self::_snake_case_location($location['region'] ?? '');
    $city = self::_snake_case_location($location['city'] ?? '');

    $attempts = [];
    if ($country && $region && $city) {
        $attempts[] = ['country' => $country, 'region' => $region, 'city' => $city];
    }
    if ($country && $region) {
        $attempts[] = ['country' => $country, 'region' => $region, 'city' => ''];
    }
    if ($country) {
        $attempts[] = ['country' => $country, 'region' => '', 'city' => ''];
    }

    return $attempts;
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement helper + loop in `generate_multilogin_proxy`**

For each attempt, POST payload; on success parse and return; collect errors; if all fail throw with joined messages. Prefer omitting empty `city`/`region` keys from JSON if Multilogin rejects empty strings (match observed API behavior).

- [ ] **Step 4: Run — PASS + commit**

```bash
git commit -m "feat: fall back Multilogin GEO proxy from city to region to country"
```

---

### Task 6: Multilogin STATIC = new profile + custom proxy (no clone)

**Files:**
- Modify: `app/Services/MultiloginClient.php` — `create_static_profile`
- Test: feature/unit with Http::fake asserting POST to create endpoint, not clone

- [ ] **Step 1: Write failing test**

Assert request URL is profile create (not `/profile/clone`) and body contains `parameters.proxy` from passed proxy array and `name`.

- [ ] **Step 2: Change signature**

```php
public function create_static_profile(string $name, array $proxy): string
```

Build payload similar to GEO flags but:
- `folder_id` = `static_folder_id` or workspace  
- `parameters.fingerprint` = `(object) []` (Multilogin random)  
- `parameters.proxy` from `$proxy`  
- Do **not** call clone endpoint  

Reuse `_post_create_auto` + `_validate_profile_proxy_payload`.

Update all callers (`AppointmentService`).

- [ ] **Step 3: Run tests — PASS + commit**

```bash
git commit -m "feat: create STATIC Multilogin profiles with random fingerprint and pool proxy"
```

---

### Task 7: AppointmentService missing-only pipeline

**Files:**
- Modify: `app/Services/AppointmentService.php`
- Test: `tests/Feature/CreateMissingProfilesTest.php`

- [ ] **Step 1: Write failing feature tests**

Cases:
1. No profiles + location present → creates geo + static (Http::fake both)  
2. Geo exists → only static  
3. No country and no IP → only static  
4. Both exist → throws or returns summary with zeros created  
5. Company A token used on Authorization (not global / not company B)  
6. Company without Multilogin token → clear failure, no Multilogin HTTP call  

Seed one `StaticProxy` enabled. Attach appointment to a `Company` with `multilogin_token`.

- [ ] **Step 2: Implement `createMissingProfiles`**

Company Multilogin is mandatory; IPinfo enrich stays on global `IpInfoService`.

```php
public function createMissingProfiles(Appointment $appointment): array
{
    $appointment->loadMissing(['contact', 'profiles', 'company']);

    $company = $appointment->company;
    if (! $company || ! $company->getMultiloginToken()) {
        throw new RuntimeException(
            'Company "'.($company?->name ?? 'unknown').'" has no Multilogin token configured.'
        );
    }
    // All GEO proxy + profile creates below MUST use $this->multiloginFor($appointment)
    // (never the unbound global MultiloginClient alone).

    $existing = $appointment->profiles
        ->whereIn('status', ['reserved', 'created'])
        ->pluck('profile_role')
        ->all();

    $want = [];
    $formatter = app(ProfileNameFormatter::class);

    // Ensure location when possible — GLOBAL IPinfo (same for every company)
    if (! $formatter->hasUsableGeoLocation($appointment->city, $appointment->region, $appointment->country_code ?: $appointment->country)) {
        if ($appointment->ip_address) {
            try {
                $this->enrich($appointment); // IpInfoService / integration_settings ipinfo
                $appointment->refresh();
            } catch (Throwable) {
                // leave without GEO
            }
        }
    }

    $canGeo = $formatter->hasUsableGeoLocation(
        $appointment->city,
        $appointment->region,
        $appointment->country_code ?: $appointment->country
    );

    if ($canGeo && ! in_array('geo', $existing, true)) {
        $want[] = 'geo';
    }
    if (! in_array('static', $existing, true)) {
        $want[] = 'static';
    }

    if (! $want) {
        return ['created' => [], 'skipped' => ['geo', 'static'], 'failed' => []];
    }

    // For GEO: ensure saved Multilogin proxy (auto get if missing)
    if (in_array('geo', $want, true) && ! MultiloginClient::saved_proxy_from_appointment($appointment)) {
        $this->getProxy($appointment, 3, true);
        $appointment->refresh();
    }

    $numbers = $this->numbers->allocateNumbers($appointment->id, count($want));
    $created = [];
    $failed = [];

    foreach ($want as $i => $role) {
        $number = $numbers[$i];
        $name = $role === 'geo'
            ? $formatter->geo(
                $number,
                $appointment->contact->full_name,
                $appointment->city,
                $appointment->region,
                $appointment->country_code ?: $appointment->country
            )
            : $formatter->staticName($number, $appointment->contact->full_name);

        $profile = BrowserProfile::create([
            'appointment_id' => $appointment->id,
            'number' => $number,
            'profile_role' => $role,
            'profile_name' => $name,
            'status' => 'reserved',
        ]);

        try {
            if ($role === 'geo') {
                $mlId = $this->multiloginFor($appointment)->create_geo_profile($name, $appointment);
            } else {
                $staticProxy = app(StaticProxyService::class)->randomEnabled();
                $profile->proxy_label = $staticProxy->label ?: $staticProxy->host;
                $mlId = $this->multiloginFor($appointment)->create_static_profile(
                    $name,
                    $staticProxy->toMultiloginProxy()
                );
            }
            $profile->multilogin_profile_id = $mlId;
            $profile->status = 'created';
            $profile->save();
            // mark ProfileNumber created…
            $created[] = $role;
        } catch (Throwable $exc) {
            $profile->status = 'failed';
            $profile->error_message = $exc->getMessage();
            $profile->save();
            $failed[] = ['role' => $role, 'error' => $exc->getMessage()];
        }
    }

    return compact('created', 'failed') + ['skipped' => array_values(array_diff(['geo', 'static'], $want))];
}
```

Wire `createProfiles($appointment, $mode)` to call the same internals for `geo`/`static`/`both` but still respect “already exist” errors for single-appointment buttons (or switch those buttons to missing-only for consistency — prefer **missing-only everywhere**).

- [ ] **Step 3: Run feature tests — PASS + commit**

```bash
git commit -m "feat: create missing GEO/STATIC Multilogin profiles with new naming"
```

---

### Task 8: Static proxies CRUD UI

**Files:**
- Create: `app/Http/Controllers/StaticProxyController.php`
- Create: `resources/views/static-proxies/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/partials/sidebar.blade.php` (link)

- [ ] **Step 1: Routes**

```php
Route::get('/static-proxies', [StaticProxyController::class, 'index'])->name('static-proxies.index');
Route::post('/static-proxies', [StaticProxyController::class, 'store'])->name('static-proxies.store');
Route::put('/static-proxies/{staticProxy}', [StaticProxyController::class, 'update'])->name('static-proxies.update');
Route::delete('/static-proxies/{staticProxy}', [StaticProxyController::class, 'destroy'])->name('static-proxies.destroy');
```

- [ ] **Step 2: Controller store validation**

`host` required, `port` 1–65535, `protocol` in http/socks5, `enabled` boolean.

- [ ] **Step 3: Blade table + add form** matching Orbit settings styling.

- [ ] **Step 4: Manual smoke** — add one proxy, see in list, toggle enabled.

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: add Static Proxies management UI"
```

---

### Task 9: Clients bulk create UI + endpoint

**Files:**
- Modify: `app/Http/Controllers/ClientController.php`
- Modify: `resources/views/clients/index.blade.php`
- Modify: `routes/web.php`
- Test: extend `tests/Feature/CreateMissingProfilesTest.php`

- [ ] **Step 1: Route**

```php
Route::post('/clients/create-missing-profiles', [ClientController::class, 'createMissingProfiles'])
    ->name('clients.create-missing-profiles');
```

- [ ] **Step 2: Controller action**

Accept:
- `appointment_ids` array (from checked rows)  
- OR if empty, re-run current schedule filter and collect in-range appointment ids  

For each id: `AppointmentService::createMissingProfiles`, aggregate summary flash:

`Created GEO: n, STATIC: m, skipped: x, failed: y`

Include `appointment_id` on each client row payload / data attribute so checkboxes submit appointment ids for the displayed call.

- [ ] **Step 3: Blade**

- Checkbox column; header “select all” JS  
- Hidden/form POST with selected `appointment_ids[]`  
- Button “Create missing profiles”  
- Helper text: “If none selected, runs for all leads in the current filter.”

- [ ] **Step 4: Feature test**

POST with one appointment id; assert browser_profiles created (Http::fake).

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: bulk create missing Multilogin profiles from Clients schedule view"
```

---

### Task 10: Appointment show copy + regression pass

**Files:**
- Modify: `resources/views/appointments/show.blade.php` (button labels / notes if STATIC no longer template)
- Run full relevant tests

- [ ] **Step 1: Update UI notes**

Replace “STATIC TEMPLATE” wording; note STATIC uses random pool proxy; GEO naming shows location.

- [ ] **Step 2: Run tests**

```bash
php artisan test --filter=ProfileNumberServiceTest
php artisan test --filter=ProfileNameFormatterTest
php artisan test --filter=StaticProxyServiceTest
php artisan test --filter=CreateMissingProfilesTest
php artisan test --filter=MultiloginClientTest
```

Expected: all PASS

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: align appointment UI with GEO/STATIC profile rules"
```

---

## Self-review

1. **Spec coverage:** numbering 1000/N+1, names, static_proxies, GEO fallback, STATIC new+random proxy, missing-only + static-only without location, Clients checkboxes+select-all+filter, **per-company Multilogin**, **global IPinfo**, API not Python — all have tasks.  
2. **Placeholders:** none intentional.  
3. **Types:** `create_static_profile(string $name, array $proxy)` must be updated everywhere; `allocateNumbers` replaces `reserveNumbers` for new flow.  
4. **Tenant rule:** never create profiles with unbound global Multilogin when appointment has a company; require company token.

## Out of scope (follow-ups)

- Queue jobs for large bulk (Approach B)  
- Graphify install  
- Encrypting `static_proxies.password` with Crypt (optional hardening)

---

**Plan complete and saved to `docs/superpowers/plans/2026-07-30-multilogin-profile-bulk-create.md`.**

**Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks  
2. **Inline Execution** — execute tasks in this session with executing-plans checkpoints  

Which approach?
