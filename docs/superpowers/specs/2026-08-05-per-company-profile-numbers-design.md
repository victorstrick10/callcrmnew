# Per-company Profile Numbers Design

**Date:** 2026-08-05  
**Status:** Approved  
**Graphify:** Used for context (`ProfileNumberService`, `MultiloginClient`, `/numbers`).

## Problem

Operators need:

1. Rename Multilogin profiles (full name) from `/numbers` for manual or CRM-created profiles  
2. Gap-aware numbering after deletes (e.g. used 1,2,3,5 → next **004**)  
3. Sync using the **company’s** Multilogin token before allocating/creating  
4. Company filter on `/numbers` (required for sync and list)

## Decisions

| Topic | Choice |
|-------|--------|
| Number pools | **Per company** (`company_id` + `number`) |
| Rename | **Full Multilogin name**; push via API when profile ID exists |
| Sync on `/numbers` | Require company filter; sync that company only |
| Next number | Lowest free in that company’s pool (fill gaps) |
| Before create | Sync that appointment’s company Multilogin, then allocate |

## Data

- `profile_numbers.company_id` (FK, required after migrate)  
- `profile_numbers.profile_name` (stored Multilogin display name)  
- Unique `(company_id, number)`  
- Seed 001–999 per company on first sync/allocate  

Migration: attach existing rows via `appointment_id → appointments.company_id`, else first/default company.

## Behavior

- `/numbers?company_id=` — filter required for table + Sync  
- Rename form → company Multilogin `update_profile_name` + local `profile_name` (+ linked `browser_profiles`)  
- `nextNumber(company)` = min available status in company pool (1–999)  
- `AppointmentService::createMissingProfiles` syncs company profiles before allocate  

## Non-goals

- Async/queue sync  
- Changing GEO/STATIC naming format beyond rename UI  
- Shared Multilogin collision prevention beyond sync-before-create  
