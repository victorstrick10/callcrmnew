<?php

/**
 * Remap CRM profile numbers 1xxx → xxx (3-digit, e.g. 1002 → 002) on Railway Postgres,
 * and rename only Multilogin profiles referenced by this CRM (browser_profiles /
 * profile_numbers). Does not touch other Multilogin profiles.
 *
 * Run locally (no Railway shell):
 *   php scripts/remap-profile-numbers-to-3digit.php --dry-run
 *   php scripts/remap-profile-numbers-to-3digit.php
 *   php scripts/remap-profile-numbers-to-3digit.php --railway-app-key=base64:...
 *
 * database.env must contain the Railway DATABASE_URL (one line).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require $root.'/scripts/railway-database-url.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
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
    $out = [
        'dry_run' => false,
        'railway_app_key' => getenv('RAILWAY_APP_KEY') ?: null,
    ];
    foreach ($argv as $arg) {
        if ($arg === '--dry-run') {
            $out['dry_run'] = true;
        }
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

function decryptNullable(?Encrypter $encrypter, ?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if ($encrypter) {
        try {
            return $encrypter->decryptString($value);
        } catch (Throwable) {
            // fall through
        }
    }

    try {
        return Crypt::decryptString($value);
    } catch (Throwable) {
        return null;
    }
}

function remapName(string $oldName, int $newNumber): string
{
    $padded = sprintf('%03d', $newNumber);
    if (preg_match('/^\d+(\s+.*)$/s', $oldName, $m)) {
        return $padded.$m[1];
    }

    return $padded.' '.$oldName;
}

function resolveMultiloginToken(PDO $pdo, ?Encrypter $railwayKey): array
{
    // Prefer company tokens from Railway DB.
    foreach ($pdo->query('SELECT id, multilogin_token_encrypted, multilogin_base_url FROM companies') as $row) {
        $token = decryptNullable($railwayKey, $row['multilogin_token_encrypted'] ?? null);
        if ($token) {
            return [$token, (string) ($row['multilogin_base_url'] ?? '')];
        }
    }

    // Local company fallback.
    foreach (App\Models\Company::query()->cursor() as $company) {
        $token = $company->getMultiloginToken();
        if ($token) {
            return [$token, (string) ($company->multilogin_base_url ?? '')];
        }
    }

    // Global integration settings (local).
    $global = app(IntegrationSettingsService::class)->getSettings('multilogin');
    $token = $global['automation_token'] ?? null;
    if ($token) {
        return [$token, (string) ($global['base_url'] ?? '')];
    }

    // Global integration settings from Railway ciphertext.
    $row = $pdo->query("SELECT encrypted_json FROM integration_settings WHERE provider = 'multilogin'")->fetch();
    if ($row && ! empty($row['encrypted_json'])) {
        $json = decryptNullable($railwayKey, $row['encrypted_json']);
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data) && ! empty($data['automation_token'])) {
                return [$data['automation_token'], (string) ($data['base_url'] ?? '')];
            }
        }
        // encrypted_json is Crypt of a JSON string — try decryptDict style via Encrypter
        if ($railwayKey) {
            try {
                $raw = $railwayKey->decryptString($row['encrypted_json']);
                $data = json_decode($raw, true);
                if (is_array($data) && ! empty($data['automation_token'])) {
                    return [$data['automation_token'], (string) ($data['base_url'] ?? '')];
                }
            } catch (Throwable) {
                // ignore
            }
        }
        try {
            $raw = Crypt::decryptString($row['encrypted_json']);
            $data = json_decode($raw, true);
            if (is_array($data) && ! empty($data['automation_token'])) {
                return [$data['automation_token'], (string) ($data['base_url'] ?? '')];
            }
        } catch (Throwable) {
            // ignore
        }
    }

    throw new RuntimeException('No Multilogin token found. Pass --railway-app-key=... or configure a company token.');
}

$opts = parseArgv($argv);
$dryRun = (bool) $opts['dry_run'];
$railwayKey = $opts['railway_app_key'] ? makeEncrypter((string) $opts['railway_app_key']) : null;

$pdo = pdoFromUrl(loadRailwayDatabaseUrl($root));
echo ($dryRun ? "[DRY-RUN] " : '')."Connected to Railway DB via database.env\n";

[$mlxToken, $mlxBase] = resolveMultiloginToken($pdo, $railwayKey);
$mlx = new MultiloginClient($mlxToken, $mlxBase);
echo 'Multilogin token resolved ('.substr($mlxToken, -4).")\n";

// Build id → current Multilogin name for CRM-owned profile IDs only.
$ownedIds = [];
foreach ($pdo->query(
    "SELECT DISTINCT multilogin_profile_id AS id FROM browser_profiles
     WHERE multilogin_profile_id IS NOT NULL AND multilogin_profile_id <> ''
     UNION
     SELECT DISTINCT multilogin_profile_id AS id FROM profile_numbers
     WHERE status IN ('created','reserved')
       AND multilogin_profile_id IS NOT NULL AND multilogin_profile_id <> ''
       AND number >= 1000"
) as $row) {
    $ownedIds[trim((string) $row['id'])] = true;
}

echo 'Loading Multilogin inventory to resolve names for '.count($ownedIds)." CRM-owned profile(s)...\n";
[$allMlProfiles] = $mlx->get_profiles();
$mlxNames = [];
foreach ($allMlProfiles as $profile) {
    $pid = (string) ($profile['id'] ?? '');
    if ($pid !== '' && isset($ownedIds[$pid])) {
        $mlxNames[$pid] = (string) ($profile['name'] ?? '');
    }
}
echo 'Matched '.count($mlxNames)." owned profile name(s) in Multilogin.\n";

$errors = [];
$bpOk = 0;
$poolOk = 0;
$mlxOk = 0;
$mlxSkip = 0;
$renamedIds = [];

$pdo->beginTransaction();

try {
    // 1) browser_profiles number + name
    $profiles = $pdo->query(
        'SELECT id, number, profile_name, multilogin_profile_id, status
         FROM browser_profiles
         WHERE number >= 1000
         ORDER BY number, id'
    )->fetchAll();

    foreach ($profiles as $row) {
        $oldNumber = (int) $row['number'];
        $newNumber = $oldNumber - 1000;
        if ($newNumber < 1 || $newNumber > 999) {
            $errors[] = "browser_profiles #{$row['id']}: {$oldNumber} remaps outside 001-999";
            continue;
        }

        $oldName = (string) $row['profile_name'];
        $newName = remapName($oldName, $newNumber);
        $mlId = trim((string) $row['multilogin_profile_id']);

        echo sprintf(
            "  BP #%d %d → %03d | %s → %s\n",
            $row['id'],
            $oldNumber,
            $newNumber,
            $oldName,
            $newName
        );

        if (! $dryRun) {
            $stmt = $pdo->prepare(
                'UPDATE browser_profiles SET number = :n, profile_name = :name WHERE id = :id'
            );
            $stmt->execute([':n' => $newNumber, ':name' => $newName, ':id' => $row['id']]);
        }
        $bpOk++;

        if ($mlId === '' || $row['status'] !== 'created') {
            $mlxSkip++;
            continue;
        }

        $sourceName = $mlxNames[$mlId] ?? $oldName;
        $inInventory = isset($mlxNames[$mlId]);
        $mlxNewName = remapName($sourceName, $newNumber);

        if (! $inInventory) {
            echo "    skip Multilogin {$mlId}: profile not found in Multilogin (CRM id stale?)\n";
            $mlxSkip++;
            continue;
        }

        if ($dryRun) {
            echo "    [DRY-RUN] Multilogin {$mlId}: {$sourceName} → {$mlxNewName}\n";
            $renamedIds[$mlId] = true;
            $mlxOk++;
            continue;
        }

        try {
            $mlx->update_profile_name($mlId, $mlxNewName);
            echo "    Multilogin renamed OK ({$mlId})\n";
            $renamedIds[$mlId] = true;
            $mlxOk++;
        } catch (Throwable $e) {
            echo "    skip Multilogin {$mlId}: ".$e->getMessage()."\n";
            $mlxSkip++;
        }
    }

    // 2) profile_numbers remap + Multilogin rename for created IDs not already renamed
    $poolRows = $pdo->query(
        'SELECT * FROM profile_numbers WHERE number >= 1000 ORDER BY number'
    )->fetchAll();

    foreach ($poolRows as $pool) {
        $oldNumber = (int) $pool['number'];
        $newNumber = $oldNumber - 1000;
        $status = (string) $pool['status'];
        $mlId = trim((string) $pool['multilogin_profile_id']);

        // Unused high pool slots: drop them (1000 available → delete, not 000).
        if (in_array($status, ['available', ''], true) || $newNumber < 1) {
            echo sprintf("  pool %d (%s) → DELETE unused high slot\n", $oldNumber, $status ?: 'available');
            if (! $dryRun) {
                $pdo->prepare('DELETE FROM profile_numbers WHERE number = :n')->execute([':n' => $oldNumber]);
            }
            $poolOk++;
            continue;
        }

        if ($newNumber > 999) {
            $errors[] = "profile_numbers {$oldNumber}: remaps outside 001-999";
            continue;
        }

        echo sprintf("  pool %d → %03d (%s) mlx=%s\n", $oldNumber, $newNumber, $status, $mlId ?: '(none)');

        if (! $dryRun) {
            $existing = $pdo->prepare('SELECT number FROM profile_numbers WHERE number = :n');
            $existing->execute([':n' => $newNumber]);
            $target = $existing->fetch();

            if ($target) {
                $upd = $pdo->prepare(
                    'UPDATE profile_numbers
                     SET status = :status,
                         appointment_id = :appointment_id,
                         profile_type = :profile_type,
                         multilogin_profile_id = :multilogin_profile_id,
                         reserved_at = :reserved_at,
                         created_at = :created_at
                     WHERE number = :number'
                );
                $upd->execute([
                    ':status' => $pool['status'],
                    ':appointment_id' => $pool['appointment_id'],
                    ':profile_type' => $pool['profile_type'],
                    ':multilogin_profile_id' => $pool['multilogin_profile_id'],
                    ':reserved_at' => $pool['reserved_at'],
                    ':created_at' => $pool['created_at'],
                    ':number' => $newNumber,
                ]);
                $pdo->prepare('DELETE FROM profile_numbers WHERE number = :n')->execute([':n' => $oldNumber]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO profile_numbers
                        (number, status, appointment_id, profile_type, multilogin_profile_id, reserved_at, created_at)
                     VALUES
                        (:number, :status, :appointment_id, :profile_type, :multilogin_profile_id, :reserved_at, :created_at)'
                );
                $ins->execute([
                    ':number' => $newNumber,
                    ':status' => $pool['status'],
                    ':appointment_id' => $pool['appointment_id'],
                    ':profile_type' => $pool['profile_type'],
                    ':multilogin_profile_id' => $pool['multilogin_profile_id'],
                    ':reserved_at' => $pool['reserved_at'],
                    ':created_at' => $pool['created_at'],
                ]);
                $pdo->prepare('DELETE FROM profile_numbers WHERE number = :n')->execute([':n' => $oldNumber]);
            }
        }
        $poolOk++;

        if ($mlId === '' || isset($renamedIds[$mlId])) {
            if ($mlId !== '' && isset($renamedIds[$mlId])) {
                $mlxSkip++;
            }
            continue;
        }

        $sourceName = $mlxNames[$mlId] ?? '';
        if ($sourceName === '') {
            echo "    skip Multilogin {$mlId}: profile not found in Multilogin (CRM id stale?)\n";
            $mlxSkip++;
            continue;
        }

        // Only rename if the Multilogin name still starts with the old 1xxx number.
        if (! preg_match('/^'.$oldNumber.'(\s|$)/', $sourceName)) {
            echo "    skip Multilogin {$mlId}: name does not start with {$oldNumber} ({$sourceName})\n";
            $mlxSkip++;
            continue;
        }

        $mlxNewName = remapName($sourceName, $newNumber);

        if ($dryRun) {
            echo "    [DRY-RUN] Multilogin {$mlId}: {$sourceName} → {$mlxNewName}\n";
            $renamedIds[$mlId] = true;
            $mlxOk++;
            continue;
        }

        try {
            $mlx->update_profile_name($mlId, $mlxNewName);
            echo "    Multilogin renamed OK ({$mlId})\n";
            $renamedIds[$mlId] = true;
            $mlxOk++;
        } catch (Throwable $e) {
            echo "    skip Multilogin {$mlId}: ".$e->getMessage()."\n";
            $mlxSkip++;
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "[DRY-RUN] rolled back DB changes.\n";
    } else {
        $pdo->commit();
        echo "DB committed.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FATAL: '.$e->getMessage()."\n");
    exit(1);
}

echo "Done. browser_profiles={$bpOk}; pool_rows={$poolOk}; multilogin_renamed={$mlxOk}; multilogin_skipped={$mlxSkip}\n";
if ($errors !== []) {
    echo "Errors:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
    exit(1);
}

exit(0);
