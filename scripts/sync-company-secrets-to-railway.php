<?php

/**
 * Push company credentials from local Laravel DB → Railway Postgres.
 *
 * Encrypted fields only work on Railway if APP_KEY matches local, OR you pass
 * --railway-app-key=base64:... to re-encrypt for Railway's key.
 *
 * Usage:
 *   php scripts/sync-company-secrets-to-railway.php
 *   php scripts/sync-company-secrets-to-railway.php --railway-app-key=base64:XXXX
 *
 * database.env must contain the Railway DATABASE_URL (one line).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require $root.'/scripts/railway-database-url.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Encryption\Encrypter as EncrypterImpl;
use Illuminate\Support\Facades\Crypt;

function pdoFromUrl(string $url): PDO
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }
    $db = ltrim($parts['path'] ?? '', '/');
    $db = explode('?', $db)[0];
    $port = $parts['port'] ?? 5432;
    $user = urldecode($parts['user'] ?? '');
    $pass = urldecode($parts['pass'] ?? '');
    $query = [];
    if (! empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $sslmode = $query['sslmode'] ?? 'require';
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $parts['host'], $port, $db, $sslmode);

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function parseArgv(array $argv): array
{
    $out = ['railway_app_key' => getenv('RAILWAY_APP_KEY') ?: null];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--railway-app-key=')) {
            $out['railway_app_key'] = substr($arg, strlen('--railway-app-key='));
        }
    }

    return $out;
}

function makeEncrypter(string $appKey): Encrypter
{
    $key = $appKey;
    if (str_starts_with($key, 'base64:')) {
        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 APP_KEY');
        }
        $key = $decoded;
    }

    return new EncrypterImpl($key, 'AES-256-CBC');
}

function encryptOrNull(?Encrypter $encrypter, ?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }

    if ($encrypter) {
        return $encrypter->encryptString($plain);
    }

    return Crypt::encryptString($plain);
}

$opts = parseArgv($argv);
$railwayUrl = loadRailwayDatabaseUrl($root);
$remote = pdoFromUrl($railwayUrl);

$reencrypt = null;
if (! empty($opts['railway_app_key'])) {
    $reencrypt = makeEncrypter((string) $opts['railway_app_key']);
    echo "Mode: re-encrypt with Railway APP_KEY\n";
} else {
    echo "Mode: encrypt with LOCAL APP_KEY (set Railway APP_KEY to match local .env)\n";
    $localKey = (string) config('app.key');
    $hintPath = $root.'/storage/app/railway-set-app-key.txt';
    file_put_contents(
        $hintPath,
        "Paste this exact value into Railway → Variables → APP_KEY, then redeploy:\n\n{$localKey}\n"
    );
    echo "Wrote local APP_KEY to storage/app/railway-set-app-key.txt (gitignored via storage/).\n";
    echo "You MUST set Railway APP_KEY to that value or Calendly/Multilogin secrets will not decrypt.\n";
}

$update = $remote->prepare('
    UPDATE companies SET
        lead_api_url = :lead_api_url,
        lead_api_key_encrypted = :lead_api_key_encrypted,
        calendly_org_uri = :calendly_org_uri,
        calendly_api_token_encrypted = :calendly_api_token_encrypted,
        calendly_webhook_signing_key_encrypted = :calendly_webhook_signing_key_encrypted,
        multilogin_base_url = :multilogin_base_url,
        multilogin_token_encrypted = :multilogin_token_encrypted,
        updated_at = NOW()
    WHERE slug = :slug
');

$companies = Company::query()->orderBy('slug')->get();
if ($companies->isEmpty()) {
    throw new RuntimeException('No local companies found.');
}

foreach ($companies as $company) {
    $slug = $company->slug;
    $exists = $remote->prepare('SELECT id FROM companies WHERE slug = :slug');
    $exists->execute(['slug' => $slug]);
    $row = $exists->fetch();
    if (! $row) {
        echo "SKIP {$slug}: not found on Railway (create the company first or run full DB migrate).\n";
        continue;
    }

    $params = [
        'slug' => $slug,
        'lead_api_url' => (string) $company->lead_api_url,
        'lead_api_key_encrypted' => encryptOrNull($reencrypt, $company->getLeadApiKey()),
        'calendly_org_uri' => (string) $company->calendly_org_uri,
        'calendly_api_token_encrypted' => encryptOrNull($reencrypt, $company->getCalendlyApiToken()),
        'calendly_webhook_signing_key_encrypted' => encryptOrNull($reencrypt, $company->getCalendlyWebhookSigningKey()),
        'multilogin_base_url' => (string) ($company->multilogin_base_url ?: 'https://api.multilogin.com'),
        'multilogin_token_encrypted' => encryptOrNull($reencrypt, $company->getMultiloginToken()),
    ];

    $update->execute($params);

    echo sprintf(
        "OK %s — lead_key=%s calendly=%s org=%s multilogin=%s\n",
        $slug,
        $company->getLeadApiKey() ? 'yes' : 'no',
        $company->getCalendlyApiToken() ? 'yes' : 'no',
        $company->calendly_org_uri ? 'yes' : 'no',
        $company->getMultiloginToken() ? 'yes' : 'no'
    );
}

// Sync integration_settings blobs that Laravel encrypts with APP_KEY.
$localSettings = App\Models\IntegrationSetting::query()->get();
$settingsUpsert = $remote->prepare('
    INSERT INTO integration_settings (provider, encrypted_json, enabled, created_at, updated_at)
    VALUES (:provider, :encrypted_json, :enabled, NOW(), NOW())
    ON CONFLICT (provider) DO UPDATE SET
        encrypted_json = EXCLUDED.encrypted_json,
        enabled = EXCLUDED.enabled,
        updated_at = NOW()
');

foreach ($localSettings as $setting) {
    $plain = app(App\Services\IntegrationSettingsService::class)->getSettings($setting->provider);
    $json = $reencrypt
        ? $reencrypt->encryptString(json_encode($plain, JSON_UNESCAPED_UNICODE))
        : Crypt::encryptString(json_encode($plain, JSON_UNESCAPED_UNICODE));

    $settingsUpsert->execute([
        'provider' => $setting->provider,
        'encrypted_json' => $json,
        'enabled' => $setting->enabled ? 't' : 'f',
    ]);
    echo "OK integration_settings:{$setting->provider}\n";
}

echo "Done. Open Railway Companies — Calendly/Multilogin should show configured after APP_KEY matches.\n";
echo "Then Sync each company (Calendly pull can take a few minutes).\n";
