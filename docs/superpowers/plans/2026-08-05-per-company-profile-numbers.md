# Per-company Profile Numbers Implementation Plan

> **For agentic workers:** Implement task-by-task with TDD. Steps use checkbox syntax.

**Goal:** Per-company profile number pools, company-filtered `/numbers` with rename forms, sync via company Multilogin token, gap-fill allocation before create.

**Architecture:** Scope `profile_numbers` by `company_id`; sync/allocate/rename through company Multilogin client; `nextNumber` = lowest free in company pool.

**Tech Stack:** Laravel 10, PHPUnit, Blade, Multilogin HTTP API

---

### Task 1: Migration + model
- [ ] Add migration `company_id`, `profile_name`; composite unique; backfill
- [ ] Update `ProfileNumber` model fillable/relations

### Task 2: ProfileNumberService (TDD)
- [ ] Failing tests: per-company init, gap fill nextNumber, scoped sync
- [ ] Implement company-scoped methods

### Task 3: Sync + create path
- [ ] `SettingsService::syncNumbers(Company)`
- [ ] Sync before allocate in `AppointmentService::createMissingProfiles`
- [ ] Tests for company token + gap after sync

### Task 4: Controller + UI
- [ ] Company filter required; sync; rename endpoint
- [ ] Update `numbers/index.blade.php`
- [ ] Feature tests

### Task 5: Verify
- [ ] Run relevant PHPUnit tests
- [ ] `graphify update .`
