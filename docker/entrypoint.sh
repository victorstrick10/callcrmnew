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
        # Run the cron loop in the background and serve /health so Railway's
        # healthcheck (shared railway.toml) keeps the service marked healthy.
        php artisan schedule:work &
        PORT="${PORT:-8080}"
        echo "Scheduler running; health server on 0.0.0.0:${PORT}"
        cd /var/www/html/public
        exec php -S "0.0.0.0:${PORT}" \
            ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
        ;;
    *)
        echo "Unknown CONTAINER_ROLE: $CONTAINER_ROLE (expected web, worker, or scheduler)"
        exit 1
        ;;
esac
