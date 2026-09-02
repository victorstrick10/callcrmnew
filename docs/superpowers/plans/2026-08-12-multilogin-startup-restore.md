# Multilogin Startup Restore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New GEO and STATIC Multilogin profiles are created with Startup behavior = Restore (`startup_behavior: recover`).

**Architecture:** Minimal change in `MultiloginClient` create payloads; update/add HTTP-fake tests that assert the POST body. No orchestration or config changes.

**Tech Stack:** Laravel 10, PHPUnit, `Illuminate\Support\Facades\Http`

**Spec:** `docs/superpowers/specs/2026-08-12-multilogin-startup-restore-design.md`

## Global Constraints

- API enum for UI Restore is exactly `recover`.
- Omit `custom_start_urls` when `startup_behavior` is `recover`.
- Touch both `create_geo_profile` and `create_static_profile`.
- Do not call Multilogin update/partial_update to change startup on existing profiles.

## File Structure

| File | Responsibility |
|------|----------------|
| `app/Services/MultiloginClient.php` | Set `recover`; drop custom start URLs; remove dead helper if unused |
| `tests/Feature/CreateStaticProfileTest.php` | Assert STATIC create payload |
| `tests/Feature/CreateGeoProfileTest.php` | Assert GEO create payload (new file) |

---

### Task 1: Failing tests for recover startup

**Files:**
- Modify: `tests/Feature/CreateStaticProfileTest.php`
- Create: `tests/Feature/CreateGeoProfileTest.php`

**Interfaces:**
- Consumes: `MultiloginClient::create_static_profile`, `MultiloginClient::create_geo_profile`
- Produces: PHPUnit assertions that create payloads use `recover` and omit `custom_start_urls`

- [x] **Step 1: Update STATIC create assertion**

In `test_create_static_profile_posts_to_create_not_clone`, change:

```php
&& ($body['parameters']['flags']['startup_behavior'] ?? null) === 'recover'
&& ! array_key_exists('custom_start_urls', $body['parameters'] ?? [])
```

Remove the old expectations for `'custom'` and `['https://ipinfo.io/json']`.

- [x] **Step 2: Add GEO create test**

Create `tests/Feature/CreateGeoProfileTest.php` mirroring STATIC setup (`RefreshDatabase`, Multilogin settings with `geo_folder_id`, Http fake for `/profile/create` and `/bookmarks/import`).

Build a minimal appointment-like object or use existing factories so `saved_proxy_from_appointment` returns a proxy (same shape STATIC uses). Prefer the lightest path already used elsewhere in the suite — if appointment factory + saved proxy helpers exist, use them; otherwise stub via reflection/public helpers already on `MultiloginClient`.

Assert create POST body:

```php
($body['parameters']['flags']['startup_behavior'] ?? null) === 'recover'
&& ! array_key_exists('custom_start_urls', $body['parameters'] ?? [])
```

- [x] **Step 3: Run tests — expect FAIL**

Run:

```bash
php artisan test --filter=CreateStaticProfileTest::test_create_static_profile_posts_to_create_not_clone
php artisan test --filter=CreateGeoProfileTest
```

Expected: FAIL because production code still sends `custom` and `custom_start_urls`.

- [x] **Step 4: Commit tests**

```bash
git add tests/Feature/CreateStaticProfileTest.php tests/Feature/CreateGeoProfileTest.php
git commit -m "test: expect Multilogin create startup_behavior recover"
```

---

### Task 2: Set recover on GEO and STATIC creates

**Files:**
- Modify: `app/Services/MultiloginClient.php` (`default_custom_start_urls`, `create_geo_profile`, `create_static_profile`)

**Interfaces:**
- Consumes: existing create method signatures (unchanged)
- Produces: create payloads with `flags.startup_behavior = recover` and no `custom_start_urls`

- [x] **Step 1: Change both create methods**

In `create_geo_profile` and `create_static_profile`:

```php
'startup_behavior' => 'recover',
```

Remove the `'custom_start_urls' => self::default_custom_start_urls(),` line from both `parameters` arrays.

- [x] **Step 2: Remove dead helper**

If `default_custom_start_urls` has no remaining callers, delete the method and its docblock.

- [x] **Step 3: Run tests — expect PASS**

Run:

```bash
php artisan test --filter=CreateStaticProfileTest
php artisan test --filter=CreateGeoProfileTest
```

Expected: PASS.

- [x] **Step 4: Commit implementation**

```bash
git add app/Services/MultiloginClient.php
git commit -m "fix: create Multilogin profiles with startup Restore (recover)"
```

---

### Task 3: Verification

**Files:** none (verify only)

- [x] **Step 1: Full Multilogin-related filter**

Run:

```bash
php artisan test --filter=Multilogin
php artisan test --filter=CreateStaticProfileTest
php artisan test --filter=CreateGeoProfileTest
```

Expected: all PASS.

- [ ] **Step 2: Manual spot-check (optional)**

Create one GEO and one STATIC profile against Multilogin (non-simulation). In Multilogin UI → profile General → Startup behavior should show **Restore**.

---

## Self-Review

1. **Spec coverage:** GEO + STATIC recover, omit custom URLs, no existing-profile update — Tasks 1–2.
2. **Placeholders:** none.
3. **Type consistency:** API string `recover` used everywhere; UI label Restore only in docs/comments.
