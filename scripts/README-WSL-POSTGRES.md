# Local database: WSL Ubuntu Postgres (Docker not used)

## Start DB

```bat
wsl -d Ubuntu -u root -e bash /mnt/c/Users/win02/Documents/GitHub/call-crm/scripts/wsl-postgres.sh
```

Listens on **127.0.0.1:5433** (5432 is already in use on this Windows host).

## Laravel `.env`

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=call_crm
DB_USERNAME=call_crm
DB_PASSWORD=call_crm
```

## App

```bat
php artisan migrate --seed
php artisan serve
```
