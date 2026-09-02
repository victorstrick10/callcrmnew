# Railway Docker Hosting Design

**Date:** 2026-07-31  
**Status:** Approved in chat; pending final file review

## Problem

Deploy call-crm (Laravel 10, Postgres, Vite) to Railway via a Docker image, matching a known working Laravel-on-Railway template.

## Current state

- Repo-root Laravel app (not a `backend/` monorepo)
- PHP `^8.1`, Laravel `^10`, Vite 4; no `package-lock.json`
- `DB_CONNECTION=pgsql` already
- `QUEUE_CONNECTION=sync`; no `app/Jobs`
- Scheduler: `leads:sync` every 15 minutes in `app/Console/Kernel.php`
- No Dockerfile, `docker/`, `railway.toml`, or `/health` route yet

## Goals

1. Multi-stage production Dockerfile (Composer vendor → npm Vite build → PHP 8.2 CLI Alpine runtime)
2. Entrypoint that caches config/routes/views, runs migrations, then starts by `CONTAINER_ROLE`
3. Deploy **web** + **scheduler** Railway services from the same image
4. Health check at `GET /health`
5. Document Railway env vars and networking (Target Port 8080)

## Non-goals

- Deploying a queue worker service (entrypoint may still accept `worker` for future use)
- Switching queues from `sync` to `database`/`redis`
- nginx / FrankenPHP / Sail-based runtime
- Changing app business logic beyond adding `/health`

## Architecture

```
Dockerfile stages:
  vendor  → php:8.2-cli-alpine + composer install --no-dev
  assets  → node:20-alpine + npm install + npm run build
  runtime → php:8.2-cli-alpine + pdo_pgsql, pgsql, intl, zip, bcmath, opcache
            COPY vendor app + public/build + entrypoint + php-production.ini
```

**Files to add/change**

| Path | Purpose |
|------|---------|
| `Dockerfile` | Multi-stage build |
| `docker/entrypoint.sh` | Boot + role dispatch |
| `docker/php-production.ini` | PHP/opcache production settings |
| `railway.toml` | Dockerfile builder + `/health` |
| `.dockerignore` | Slim build context |
| `routes/web.php` | Add `GET /health` → `ok` |

**CONTAINER_ROLE**

| Role | Command | Railway service |
|------|---------|-----------------|
| `web` (default) | `php -S 0.0.0.0:$PORT` with Laravel `server.php` | Primary (public domain) |
| `scheduler` | `php artisan schedule:work` | Second service, same image |
| `worker` | `php artisan queue:work ...` | Not deployed now |

Entrypoint always: require `APP_KEY` → `package:discover` → production caches → `migrate --force` → `storage:link` → role.

## Railway configuration

**Web service env (essentials)**

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (required), `APP_URL`
- `LOG_CHANNEL=stderr`
- `DB_CONNECTION=pgsql`, `DATABASE_URL` from Railway Postgres (`?sslmode=require` or `DB_SSLMODE=require`)
- `CACHE_DRIVER=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`
- `CONTAINER_ROLE=web`, `PORT=8080`
- Networking: public domain Target Port **8080** (or Railway’s injected `$PORT`)

**Scheduler service:** same image/env, `CONTAINER_ROLE=scheduler` (no public domain required).

**Build:** `railway.toml` `builder = "DOCKERFILE"`, `dockerfilePath = "Dockerfile"`, healthcheck `/health`, timeout 300s, restart on failure.

## Error handling

- Missing `APP_KEY` → exit 1 with clear message
- Unknown `CONTAINER_ROLE` → exit 1
- Migrate failures fail the container start (visible in Railway logs)
- `storage:link` failures ignored (`|| true`)

## Testing / verification

- Local: `docker build` succeeds; optional run with Postgres + `APP_KEY` hits `/health`
- Railway: web healthcheck green; scheduler logs show `schedule:work`; `leads:sync` runs on interval

## Implementation notes

- Assets stage: `npm install` (no lockfile); copy `package.json`, `vite.config.js`, `resources/`
- Strip CRLF from `entrypoint.sh` in image for Alpine
- Do not commit `.env` or secrets; use Railway variables
