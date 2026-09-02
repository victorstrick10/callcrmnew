<?php

/**
 * Upsert seeded companies (including Diligent Cold) into Railway Postgres.
 *
 * Usage:
 *   php scripts/seed-companies-to-railway.php
 *
 * database.env must contain the Railway DATABASE_URL (comments allowed).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require $root.'/scripts/railway-database-url.php';

$railwayUrl = loadRailwayDatabaseUrl($root);
putenv('DATABASE_URL='.$railwayUrl);
$_ENV['DATABASE_URL'] = $railwayUrl;
$_SERVER['DATABASE_URL'] = $railwayUrl;

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.url' => $railwayUrl,
    'database.connections.pgsql.sslmode' => 'require',
]);

Illuminate\Support\Facades\DB::purge('pgsql');
Illuminate\Support\Facades\DB::reconnect('pgsql');
Illuminate\Support\Facades\DB::connection('pgsql')->getPdo();

(new Database\Seeders\CompanySeeder)->run();
app(App\Services\ProfileNumberService::class)->initializeAllCompanies();

$companies = App\Models\Company::query()->orderBy('name')->get(['id', 'name', 'slug', 'enabled']);
echo "Companies on Railway:\n";
foreach ($companies as $company) {
    echo sprintf("  #%d %s (%s) enabled=%s\n", $company->id, $company->name, $company->slug, $company->enabled ? 'yes' : 'no');
}
echo "Done.\n";
