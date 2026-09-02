<?php

/**
 * Copy all public tables from local .env Postgres → Railway URL in database.env
 * Usage: php scripts/migrate-to-railway.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/scripts/railway-database-url.php';

function loadDotEnv(string $path): array
{
    $out = [];
    if (! is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\"'");
    }

    return $out;
}

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

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_TIMEOUT => 30,
    ]);
    $pdo->exec("SET statement_timeout = '120s'");

    return $pdo;
}

function pdoFromEnv(array $env): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '5432',
        $env['DB_DATABASE'] ?? 'postgres'
    );

    return new PDO($dsn, $env['DB_USERNAME'] ?? 'postgres', $env['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function normalizeValue(mixed $value): mixed
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_bool($value)) {
        return $value ? 't' : 'f';
    }

    return $value;
}

function insertBatches(PDO $remote, string $table, array $columns, array $rows, int $batchSize = 50): void
{
    if ($rows === []) {
        return;
    }

    $colList = '"'.implode('","', $columns).'"';
    $total = count($rows);

    for ($offset = 0; $offset < $total; $offset += $batchSize) {
        $chunk = array_slice($rows, $offset, $batchSize);
        $valuesSql = [];
        $params = [];

        foreach ($chunk as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $placeholders[] = '?';
                $params[] = normalizeValue($row[$col] ?? null);
            }
            $valuesSql[] = '('.implode(',', $placeholders).')';
        }

        $sql = 'INSERT INTO "'.$table.'" ('.$colList.') VALUES '.implode(',', $valuesSql);
        $stmt = $remote->prepare($sql);
        $stmt->execute($params);

        $done = min($offset + count($chunk), $total);
        echo "  {$table}: {$done}/{$total}\n";
        flush();
    }
}

$localEnv = loadDotEnv($root.'/.env');
try {
    $railwayUrl = loadRailwayDatabaseUrl($root);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

echo "Connecting local...\n";
flush();
$local = pdoFromEnv($localEnv);
echo "Connecting Railway...\n";
flush();
$remote = pdoFromUrl($railwayUrl);

// Parents before children where possible
$preferredOrder = [
    'users',
    'password_reset_tokens',
    'personal_access_tokens',
    'failed_jobs',
    'migrations',
    'companies',
    'contacts',
    'appointments',
    'browser_profiles',
    'profile_numbers',
    'static_proxies',
    'integration_settings',
    'audit_logs',
];

$existing = $local->query("
    SELECT tablename
    FROM pg_tables
    WHERE schemaname = 'public'
")->fetchAll(PDO::FETCH_COLUMN);

$tables = [];
foreach ($preferredOrder as $name) {
    if (in_array($name, $existing, true)) {
        $tables[] = $name;
    }
}
foreach ($existing as $name) {
    if (! in_array($name, $tables, true)) {
        $tables[] = $name;
    }
}

echo 'Tables: '.implode(', ', $tables)."\n";
flush();

$remote->exec('SET session_replication_role = replica');

foreach (array_reverse($tables) as $table) {
    echo "Truncating {$table}...\n";
    flush();
    $remote->exec('TRUNCATE TABLE "'.$table.'" RESTART IDENTITY CASCADE');
}

foreach ($tables as $table) {
    $rows = $local->query('SELECT * FROM "'.$table.'"')->fetchAll();
    echo "Copying {$table} (".count($rows)." rows)...\n";
    flush();
    if ($rows === []) {
        continue;
    }
    $columns = array_keys($rows[0]);
    insertBatches($remote, $table, $columns, $rows, 25);
}

foreach ($tables as $table) {
    $seqs = $remote->query("
        SELECT a.attname AS column_name,
               pg_get_serial_sequence(format('%I.%I', n.nspname, c.relname), a.attname) AS seq
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_attribute a ON a.attrelid = c.oid
        WHERE n.nspname = 'public'
          AND c.relname = ".$remote->quote($table)."
          AND a.attnum > 0
          AND NOT a.attisdropped
          AND pg_get_serial_sequence(format('%I.%I', n.nspname, c.relname), a.attname) IS NOT NULL
    ")->fetchAll();

    foreach ($seqs as $seq) {
        $col = $seq['column_name'];
        $remote->exec('SELECT setval('.$remote->quote($seq['seq']).', COALESCE((SELECT MAX("'.$col.'") FROM "'.$table.'"), 1), true)');
    }
}

$remote->exec('SET session_replication_role = DEFAULT');

echo "Done. Railway DB populated from local.\n";
