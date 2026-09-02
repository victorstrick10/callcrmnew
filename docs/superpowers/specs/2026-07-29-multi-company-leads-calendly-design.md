# Multi-Company Leads + Calendly Design

**Date:** 2026-07-29  
**Status:** Draft for review

## Problem

Orbit CRM currently has one global Calendly + Multilogin config (`integration_settings`) and a flat `contacts` / `appointments` model. We need **3 companies**, each with its own:

1. Lead enrichment API endpoint + key (example: Diligent `api.php`)
2. Calendly API token (separate Calendly account)
3. Multilogin token (per-company browser automation credentials)

We must ingest leads from each company API, dedupe them, store lead metadata (name, email, referrer, user agent, IP when available), then resolve **call/appointment time** via that company’s Calendly API.

## Current state (codebase context)

- Domain today: `Contact` + `Appointment` (not `leads` / `calls`)
- Calendly inbound: `CalendlyWebhookService` creates contact + appointment (global, no company)
- Multilogin is global via `IntegrationSetting` provider `multilogin` (not GoLogin)
- `contacts.email` is globally unique; `contacts.company` is a free-text string, not an entity
- Appointments already store `ip_address`, `user_agent`, geo, and Calendly URIs

## Goals

1. Introduce a first-class `companies` table
2. Scope contacts and appointments to a company
3. Sync leads from each company’s lead API with duplicate protection
4. Enrich / create appointments using that company’s Calendly API (call start/end times)
5. Use each company’s Multilogin credentials for later profile/proxy work
6. Keep existing appointment workflow (enrich IP, proxy, Multilogin profiles) company-aware

## Non-goals (this update)

- Replacing Multilogin with GoLogin (token field is Multilogin; rename only if product requires GoLogin later)
- Building a full public REST API for the CRM UI
- Auth / multi-tenant login (still open web UI unless requested later)
- Auto-installing Graphify (optional follow-up; use for agent context once installed)

## Data model

### `companies`

| Column | Notes |
|--------|--------|
| `id` | PK |
| `name` | Display name (e.g. Diligent Placers) |
| `slug` | Unique short key for routing/webhooks |
| `lead_api_url` | Base URL, e.g. `https://diligentplacers.com/api.php` |
| `lead_api_key` | Encrypted at rest |
| `calendly_api_token` | Encrypted; PAT for that company’s Calendly |
| `calendly_org_uri` | Optional; used to filter scheduled events / map webhooks |
| `calendly_webhook_signing_key` | Encrypted optional |
| `multilogin_token` | Encrypted; company-specific Multilogin automation token |
| `multilogin_base_url` | Default `https://api.multilogin.com` |
| `enabled` | Soft disable without deleting |
| timestamps | |

Secrets stored with Laravel `Crypt` (same pattern as `IntegrationSettingsService`), either as encrypted columns or one encrypted JSON blob per company. Prefer **named encrypted columns or one `credentials_json` encrypted blob** for clarity.

### `contacts` changes

- Add `company_id` FK → `companies`
- Add lead metadata: `referrer`, `lead_user_agent`, `lead_ip`, `lead_raw_json` (jsonb), `lead_synced_at`
- Change uniqueness from global `email` → **unique (`company_id`, `email`)**
- Keep string `company` column only if still useful for employer name; otherwise stop using it for tenant identity

### `appointments` changes

- Add `company_id` FK → `companies` (denormalized for easy filtering; must match contact’s company)
- Keep `calendly_event_uri` unique (or unique per company if URIs could collide across accounts — prefer unique globally if Calendly URIs are globally unique)
- Preserve existing IP/geo/proxy fields

### Multilogin / settings

- Phase 1: company Multilogin token used when creating profiles for appointments belonging to that company
- Global `integration_settings.multilogin` remains as fallback / shared workspace config (folders, proxy endpoints) until fully migrated
- Global Calendly settings become optional fallback; primary source is company credentials

## Ingestion flows

### A. Lead API sync (primary for Diligent-style sources)

1. For each enabled company, call `{lead_api_url}?all=1&key={lead_api_key}`
2. Normalize each row to: email, first_name, last_name, full_name, referrer, user_agent, ip (if present)
3. Upsert contact on `(company_id, email)` — update metadata if changed; never create duplicate contacts
4. For contacts missing a future/recent appointment, call Calendly API with company token:
   - Resolve user/org
   - List scheduled events / invitees filtered by email when possible
   - Create or update `appointments` with `start_time` / `end_time` / event URIs
5. Audit log each sync batch (counts created/updated/skipped/errors)

Also support single-email lookup: `{lead_api_url}?email={email}&key={key}` for on-demand refresh.

### B. Calendly webhook (keep + company-scope)

1. Map webhook → company via signing key, org URI, or dedicated path `/webhooks/calendly/{company_slug}`
2. Upsert contact under that `company_id`
3. Create/cancel appointment as today
4. Optionally refresh lead metadata from company lead API by email after booking

## Services (new / changed)

| Service | Responsibility |
|---------|----------------|
| `CompanyLeadApiClient` | HTTP client for `?all=1` / `?email=` |
| `LeadSyncService` | Dedupe upsert into contacts |
| `CalendlyApiClient` | Authenticated Calendly REST (users/me, scheduled events, invitees) |
| `AppointmentSyncService` | Match leads ↔ Calendly events → appointments |
| `CalendlyWebhookService` | Company-aware webhook handling |
| `MultiloginClient` / `AppointmentService` | Load token from appointment’s company |

## UI

- Companies CRUD under Settings (or `/companies`)
- Masked display of secrets
- “Test lead API”, “Test Calendly”, “Sync leads now” actions per company
- Contacts / Appointments lists filterable by company

## Commands / schedule

- `php artisan leads:sync {company?}` — sync all or one company
- Optional schedule every N minutes in `Kernel`

## Duplicate rules

1. Contact: same email + same company → update; different company → separate contact rows allowed
2. Appointment: same `calendly_event_uri` → no second row; cancel events update status
3. Lead sync without Calendly match → contact only (no fake appointment)

## Success criteria

- Seed/create 3 companies with distinct credentials
- Sync Diligent-style API into contacts without duplicates
- Appointments get real Calendly start/end times via company token
- Webhook bookings attach to the correct company
- Multilogin operations for an appointment use that company’s token
