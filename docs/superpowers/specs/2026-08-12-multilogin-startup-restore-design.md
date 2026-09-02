# Multilogin Startup Restore Design

**Date:** 2026-08-12  
**Status:** Approved (approach A — GEO + STATIC)

## Problem

Newly created Multilogin profiles use **Custom** startup behavior (`parameters.flags.startup_behavior = "custom"`) and open `custom_start_urls` (e.g. `https://ipinfo.io/json`). The desired UI setting is **Restore**, which reopens the previous session’s tabs.

## Decisions

- Apply to **both** GEO and STATIC profile creates.
- Multilogin API value for UI **Restore** is `recover` (not the string `"restore"`).
- Omit `custom_start_urls` from the create payload when using `recover`.
- Remove unused `default_custom_start_urls()` if nothing else calls it.
- Do **not** attempt to change startup behavior on existing profiles (Multilogin locks this after create).

## Behavior

| Create path | Before | After |
|-------------|--------|-------|
| `create_geo_profile` | `startup_behavior: custom` + start URLs | `startup_behavior: recover`, no `custom_start_urls` |
| `create_static_profile` | same | same |

All other create flags (proxy, fingerprint masking, bookmarks import) stay unchanged.

## Non-goals

- Config/env toggle between recover and custom
- Updating already-created Multilogin profiles
- Refactoring duplicated GEO/STATIC flag arrays beyond the startup change

## Testing

- Update `CreateStaticProfileTest` to expect `recover` and assert `custom_start_urls` is absent.
- Add or extend a GEO create HTTP fake test with the same expectations.
