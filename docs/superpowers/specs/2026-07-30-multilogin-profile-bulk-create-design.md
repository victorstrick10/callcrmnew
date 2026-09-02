# Multilogin Profile Bulk Create Design

**Date:** 2026-07-30  
**Status:** Approved (Approach A)  
**Graphify:** Not installed; context from repo exploration.

## Problem

Operators need to create Multilogin browser profiles for scheduled calls (today/tomorrow) from the Clients page. Each appointment should get up to two profiles:

1. **GEO** — location from lead IP (IPinfo) → Multilogin residential proxy matched to city → region → country  
2. **STATIC** — new profile with random fingerprint + random proxy from a CRM-managed static proxy pool

Naming and numbering must follow operator rules (base **1000**, each profile **N+1** from DB).

## Current state

| Area | Today |
|------|--------|
| IPinfo | **Global** `IpInfoService` + Settings dashboard (`provider=ipinfo`) — same for all companies |
| Company Multilogin | Each `Company` has encrypted `multilogin_token` + `multilogin_base_url`; `MultiloginClient::forCompany()` already exists |
| GEO create | `MultiloginClient::create_geo_profile` via Multilogin HTTP API |
| STATIC create | Clones a template profile (`create_static_profile`) — **not** the desired behavior |
| Naming | `001 - Name - GEO - dd.mm.YYYY` / `… STATIC TEMPLATE` |
| Numbers | Pool `profile_numbers` 001–999; two numbers per appointment |
| Clients UI | Today/tomorrow filter exists; **no** bulk profile create |
| Local Python | Not required — Multilogin cloud API already used from Laravel |

Product language: **Contact** + **Appointment** (not Lead/Call). GoLogin = Multilogin in this codebase.

## Goals

1. Numbering: first new profile **1000**, then DB-checked **N+1** per profile created  
2. Names: `{n} {Full Name} {City},{Region},{Country}` and `{n} {Full Name} Static`  
3. GEO: enrich IP → proxy with city→region→country fallback → create via API  
4. STATIC: new profile (no template clone), random fingerprint defaults, random enabled static proxy  
5. Skip roles that already exist (`reserved`/`created`); if location unusable → **STATIC only**  
6. Clients page: today/tomorrow filters + checkboxes + select-all + “Create missing profiles”  
7. Keep appointment detail create buttons working with the same pipeline  
8. **Multilogin is per company** (lead → company → that company’s Multilogin token/base URL)  
9. **IPinfo / location lookup is global** (one shared token in Settings for all companies)  

## Non-goals

- Local Multilogin agent / Python desktop script  
- Graphify install  
- Queue/async bulk (Approach B deferred unless latency becomes painful)  
- Replacing Multilogin with another anti-detect browser  
- Per-company IPinfo tokens (shared global geolocation only)  

## Tenant credentials

| Concern | Scope | Source |
|---------|--------|--------|
| Multilogin create / proxy generate / folder IDs | **Per company** | `companies.multilogin_token` (+ `multilogin_base_url`); via `MultiloginClient::forCompany($appointment->company)` |
| IPinfo lead IP → city/region/country | **Global (all companies)** | `integration_settings` provider `ipinfo` |
| Static proxy pool (`static_proxies`) | **Global** (v1) | Shared CRM table; same pool for every company |

A lead (`Contact`) belongs to a `Company`. The appointment used for the call carries `company_id`. Every Multilogin API call in the profile pipeline **must** use that company’s Multilogin credentials. If the company has no Multilogin token configured, fail with a clear error for that lead (do not silently create under another company’s account). Global Multilogin settings remain only as workspace defaults (folders/endpoints) where the client still needs them after cloning with the company token.

## Architecture (Approach A)

Extend existing Laravel services. One pipeline method used by:

- Appointment show actions (`geo` / `static` / `both`)  
- Clients bulk action (selected appointments / filtered set)

```
Clients bulk / Appointment button
        │
        ▼
AppointmentService::createMissingProfiles(appointment)
        │
        ├─ resolve company Multilogin client (required)
        ├─ existing geo/static? skip role
        ├─ GEO eligible? IPinfo (global) enrich → company ML proxy fallback → create_geo_profile
        └─ STATIC missing? pick static_proxies random → company ML create_static_profile (new)
```

All Multilogin traffic stays on **server-side HTTP API** using **the lead’s company token**.

## Data model

### Numbering

- Next number = `max(highest used in browser_profiles.number, highest used in profile_numbers.number, 999) + 1`  
- First create → **1000**  
- GEO and STATIC each consume one number (consecutive when both created)  
- Allocation inside a DB transaction with lock to avoid races  
- Sync-from-Multilogin should still parse leading integers from profile names (update regex for 4+ digits)

### Name format

- GEO: `1000 Ana Black Houston,Texas,US`  
- STATIC: `1001 Ana Black Static`  
- Join location with commas, no spaces around commas; omit empty segments; if no usable location after enrich attempt → do not create GEO  

### `static_proxies` (new)

| Column | Notes |
|--------|--------|
| `id` | PK |
| `label` | Optional display name |
| `host` | Required |
| `port` | Required |
| `username` | Nullable/empty allowed if provider needs none |
| `password` | Stored encrypted or plain per existing proxy secret pattern on appointments |
| `protocol` | `http` \| `socks5` (default `http`) |
| `enabled` | Boolean; only enabled rows are eligible for random pick |
| timestamps | |

Simple CRUD under Settings (or `/static-proxies`) is enough for v1.

### Existing tables

- Keep `browser_profiles` (`profile_role` geo/static, status, multilogin id, number, name)  
- Keep appointment geo + Multilogin residential proxy fields for GEO  
- IPinfo token remains **global** in `integration_settings`  
- Company Multilogin token/base URL already on `companies`  

## Profile pipeline

### Company Multilogin resolution

1. Load `$appointment->company` (or contact’s `ownerCompany`)  
2. Require company Multilogin token; build client with `multilogin->forCompany($company)`  
3. If token missing → mark failure / flash error: “Company X has no Multilogin token”  
4. Use that client for: generate GEO proxy, create GEO profile, create STATIC profile  

### Missing-only rules

- Skip GEO if appointment already has geo profile in `reserved` or `created`  
- Skip STATIC if static already `reserved` or `created`  
- If IP/location unusable for GEO → create STATIC only when static is missing  

### GEO

1. If city/region/country empty and `ip_address` present → **global** `IpInfoService::lookup` (same token for every company)  
2. If still no country (minimum) → skip GEO  
3. Generate Multilogin residential proxy **with company Multilogin client**, fallback payload:  
   - city + region + country  
   - else region + country  
   - else country only  
4. Create profile via **company** Multilogin API with custom proxy + fingerprint flags (existing GEO defaults)  
5. Persist `browser_profiles` + number row  

### STATIC

1. Fail clearly if no enabled `static_proxies` row  
2. Random enabled proxy (global pool)  
3. **New** Multilogin profile on **company** account (not clone): empty/random fingerprint object + custom proxy from table  
4. Name `{n} {Name} Static`  
5. Persist  

### Errors

- Per-role failures recorded on `browser_profiles` (`failed` + `error_message`) without blocking the other role when possible  
- Bulk action returns a summary: created / skipped / failed counts  

## Clients UI (`/clients?schedule=today|tomorrow`)

- Keep existing today/tomorrow/date filters  
- Add checkbox column + “select all” for visible rows  
- Action: **Create missing profiles**  
  - Selected rows if any checked  
  - Else all rows in current filter (document this in UI helper text)  
- Resolve each contact’s in-range **appointment** (same as schedule filter display) and run pipeline  
- Contacts with no appointment in range: skip  

## Appointment UI

- Keep Create both / GEO / STATIC  
- Align naming + numbering + STATIC behavior with this design  
- GEO may auto-get proxy inside pipeline when missing (bulk-friendly); appointment page can keep manual Get Proxy for ISP fine-tuning  

## API vs local script

**Create profiles via Multilogin API from Laravel.** No local Python required for create/clone/proxy generate. Local script only if Multilogin cloud API becomes unavailable (out of scope).

## Testing

- Unit: next-number from DB floor 1000; name formatter; location eligibility; static proxy random from enabled only  
- Unit/feature: GEO proxy fallback clears city then region  
- Feature: create missing skips existing roles; missing location → static only  
- Feature: clients bulk posts selected appointment ids  
- Feature: profile create uses **company** Multilogin Authorization header (Http::fake assert), not another company’s token  
- Feature: IPinfo enrich uses global settings token regardless of company  
- Http::fake Multilogin + IPinfo  

## Success criteria

1. First profile number is ≥ 1000 and increments by 1 per profile in DB  
2. Names match operator format  
3. GEO uses global IPinfo + location fallback; STATIC uses random static proxy  
4. Bulk create on Clients respects today/tomorrow, checkboxes, select-all, missing-only  
5. Profiles land in the **lead’s company Multilogin** account  
6. No local Multilogin desktop script in the workflow  
