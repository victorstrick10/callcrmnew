# Railway Docker Hosting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a production Docker image and Railway config so call-crm can run as web + scheduler services on Railway.

**Architecture:** Multi-stage Dockerfile (Composer vendor → Vite assets → PHP 8.2 Alpine runtime with Postgres extensions). Entrypoint caches Laravel, migrates, then starts `web` (`php -S` on `$PORT`) or `scheduler` (`schedule:work`) via `CONTAINER_ROLE`. Health check at `GET /health`.

**Tech Stack:** PHP 8.2 Alpine, Composer 2, Node 20, Laravel 10, Vite 4, Railway Dockerfile builder, Postgres.

**Spec:** `docs/superpowers/specs/2026-07-31-railway-docker-design.md`

---

## File map

| File | Responsibility |
|------|----------------|
| `Dockerfile` | Multi-stage production image |
| `docker/entrypoint.sh` | Boot, migrate, role dispatch |
| `docker/php-production.ini` | PHP/opcache production settings |
| `railway.toml` | Railway build/deploy/healthcheck |
| `.dockerignore` | Exclude junk from build context |
| `routes/web.php` | Add `/health` route |
| `tests/Feature/HealthCheckTest.php` | Assert `/health` returns 200 `ok` |

---

### Task 1: Health check route (TDD)

**Files:**
- Create: `tests/Feature/HealthCheckTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HealthCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $response->assertSee('ok');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=HealthCheckTest`

Expected: FAIL (404 or missing route)

- [ ] **Step 3: Add the health route**

In `routes/web.php`, add near the top (after the `use` imports, before the dashboard route):

```php
Route::get('/health', function () {
    return response('ok', 200);
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=HealthCheckTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/HealthCheckTest.php routes/web.php
git commit -m "feat: add /health endpoint for Railway healthchecks"
```

(Skip commit if the user has not asked to commit.)

---

### Task 2: Docker PHP config and entrypoint

**Files:**
- Create: `docker/php-production.ini`
- Create: `docker/entrypoint.sh`

- [ ] **Step 1: Create `docker/php-production.ini`**

```ini
[PHP]
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 120

[opcache]
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
```

- [ ] **Step 2: Create `docker/entrypoint.sh`**

Use LF line endings (not CRLF). Contents:

```sh
#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is required. Generate one with: php artisan key:generate --show"
    exit 1
fi

php artisan package:discover --ansi

if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

php artisan migrate --force --no-interaction
php artisan storage:link --force 2>/dev/null || true

case "${CONTAINER_ROLE:-web}" in
    web)
        PORT="${PORT:-8080}"
        echo "Listening on 0.0.0.0:${PORT} (Railway Networking target port must match)"
        cd /var/www/html/public
        exec php -S "0.0.0.0:${PORT}" \
            ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
        ;;
    worker)
        exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "Unknown CONTAINER_ROLE: $CONTAINER_ROLE (expected web, worker, or scheduler)"
        exit 1
        ;;
esac
```

- [ ] **Step 3: Commit**

```bash
git add docker/php-production.ini docker/entrypoint.sh
git commit -m "chore: add Docker entrypoint and PHP production config"
```

(Skip commit if the user has not asked to commit.)

---

### Task 3: Dockerfile and .dockerignore

**Files:**
- Create: `Dockerfile`
- Create: `.dockerignore`

- [ ] **Step 1: Create `Dockerfile`**

```dockerfile
# syntax=docker/dockerfile:1

FROM php:8.2-cli-alpine AS vendor
WORKDIR /app
RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-ansi \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.2-cli-alpine AS runtime
RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pdo_pgsql \
        pgsql \
        zip \
    && rm -rf /var/cache/apk/*
COPY docker/php-production.ini /usr/local/etc/php/conf.d/99-production.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
EXPOSE 8080
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CONTAINER_ROLE=web
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
```

- [ ] **Step 2: Create `.dockerignore`**

```
.git
.github
.env
.env.*
!.env.example
node_modules
vendor
storage/logs/*
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*
tests
.phpunit.cache
.phpunit.result.cache
npm-debug.log
yarn-error.log
docker-compose*.yml
```

- [ ] **Step 3: Commit**

```bash
git add Dockerfile .dockerignore
git commit -m "chore: add multi-stage Dockerfile for Railway"
```

(Skip commit if the user has not asked to commit.)

---

### Task 4: railway.toml

**Files:**
- Create: `railway.toml`

- [ ] **Step 1: Create `railway.toml`**

```toml
[build]
builder = "DOCKERFILE"
dockerfilePath = "Dockerfile"

[deploy]
healthcheckPath = "/health"
healthcheckTimeout = 300
restartPolicyType = "ON_FAILURE"
restartPolicyMaxRetries = 10
```

- [ ] **Step 2: Commit**

```bash
git add railway.toml
git commit -m "chore: add railway.toml with healthcheck"
```

(Skip commit if the user has not asked to commit.)

---

### Task 5: Verify

**Files:** none (verification only)

- [ ] **Step 1: Re-run health test**

Run: `php artisan test --filter=HealthCheckTest`

Expected: PASS

- [ ] **Step 2: Confirm required files exist**

Run (PowerShell):

```powershell
@(
  'Dockerfile',
  'docker/entrypoint.sh',
  'docker/php-production.ini',
  'railway.toml',
  '.dockerignore'
) | ForEach-Object { if (-not (Test-Path $_)) { throw "missing $_" }; $_ }
```

Expected: each path printed; no throw

- [ ] **Step 3: Optional local Docker build**

If Docker Desktop is available:

```bash
docker build -t call-crm:railway .
```

Expected: build completes successfully (Composer + npm + PHP stages)

If Docker is unavailable, skip and note it; Railway build will be the real verification.

---

## Railway deploy checklist (manual, after merge)

1. Create Railway project from this repo; builder = Dockerfile.
2. Add Postgres plugin; set `DATABASE_URL=${{Postgres.DATABASE_URL}}` and `DB_SSLMODE=require`.
3. Set `APP_KEY` (from `php artisan key:generate --show`), `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `CACHE_DRIVER=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`, `CONTAINER_ROLE=web`, `PORT=8080`.
4. Networking → public domain → Target Port `8080`.
5. Duplicate service from same image with `CONTAINER_ROLE=scheduler` (no public domain needed).
