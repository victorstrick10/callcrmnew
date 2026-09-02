# Migrate local call_crm Postgres → Railway Postgres.
# Prerequisites:
#   1. Put the Railway Postgres URL in database.env (one line), e.g.
#      postgresql://user:pass@host:port/railway
#   2. Local DB reachable (see .env DB_* — default 127.0.0.1:5433/call_crm)
#   3. Docker Desktop running (used for pg_dump/psql if not installed)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$dbEnvPath = Join-Path $root 'database.env'
if (-not (Test-Path $dbEnvPath) -or (Get-Item $dbEnvPath).Length -eq 0) {
    throw "database.env is empty. Paste the Railway Postgres DATABASE_URL into database.env (one line)."
}

$railwayUrl = (Get-Content $dbEnvPath -Raw).Trim()
if ($railwayUrl -notmatch '^postgres(ql)?://') {
    throw "database.env must start with postgresql:// or postgres://"
}

# Ensure SSL for Railway if missing
if ($railwayUrl -notmatch 'sslmode=') {
    $sep = if ($railwayUrl.Contains('?')) { '&' } else { '?' }
    $railwayUrl = "$railwayUrl${sep}sslmode=require"
}

$localHost = if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }
$localPort = if ($env:DB_PORT) { $env:DB_PORT } else { '5433' }
$localDb   = if ($env:DB_DATABASE) { $env:DB_DATABASE } else { 'call_crm' }
$localUser = if ($env:DB_USERNAME) { $env:DB_USERNAME } else { 'call_crm' }
$localPass = if ($env:DB_PASSWORD) { $env:DB_PASSWORD } else { 'call_crm' }

# Prefer .env values when present
$dotenv = Join-Path $root '.env'
if (Test-Path $dotenv) {
    Get-Content $dotenv | ForEach-Object {
        if ($_ -match '^\s*DB_HOST=(.+)$') { $localHost = $Matches[1].Trim().Trim('"') }
        if ($_ -match '^\s*DB_PORT=(.+)$') { $localPort = $Matches[1].Trim().Trim('"') }
        if ($_ -match '^\s*DB_DATABASE=(.+)$') { $localDb = $Matches[1].Trim().Trim('"') }
        if ($_ -match '^\s*DB_USERNAME=(.+)$') { $localUser = $Matches[1].Trim().Trim('"') }
        if ($_ -match '^\s*DB_PASSWORD=(.+)$') { $localPass = $Matches[1].Trim().Trim('"') }
    }
}

$dumpFile = Join-Path $root "storage\app\railway-migrate-$(Get-Date -Format 'yyyyMMdd-HHmmss').sql"
New-Item -ItemType Directory -Force -Path (Split-Path $dumpFile) | Out-Null

function Invoke-PgTool {
    param([string]$Tool, [string[]]$Args, [hashtable]$EnvVars = @{})
    $local = Get-Command $Tool -ErrorAction SilentlyContinue
    if ($local) {
        foreach ($k in $EnvVars.Keys) { Set-Item -Path "env:$k" -Value $EnvVars[$k] }
        & $Tool @Args
        if ($LASTEXITCODE -ne 0) { throw "$Tool failed with exit $LASTEXITCODE" }
        return
    }

    docker version | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "$Tool not found and Docker is not available. Install PostgreSQL client tools or start Docker Desktop."
    }

    $dockerArgs = @('run', '--rm', '--network', 'host', '-e', "PGPASSWORD=$($EnvVars['PGPASSWORD'])", 'postgres:16', $Tool) + $Args
    & docker @dockerArgs
    if ($LASTEXITCODE -ne 0) { throw "docker $Tool failed with exit $LASTEXITCODE" }
}

Write-Host "Dumping local $localHost`:$localPort/$localDb ..."
Invoke-PgTool -Tool 'pg_dump' -EnvVars @{ PGPASSWORD = $localPass } -Args @(
    '-h', $localHost,
    '-p', $localPort,
    '-U', $localUser,
    '-d', $localDb,
    '--no-owner',
    '--no-acl',
    '-F', 'p',
    '-f', $dumpFile
)

# On Windows without local pg_dump, docker --network host may not reach host Postgres.
# Retry via host.docker.internal if dump file missing/empty.
if (-not (Test-Path $dumpFile) -or (Get-Item $dumpFile).Length -eq 0) {
    Write-Host "Retrying dump via host.docker.internal ..."
    $dockerArgs = @(
        'run', '--rm',
        '-e', "PGPASSWORD=$localPass",
        '-v', "${dumpFile}:/dump.sql",
        'postgres:16',
        'pg_dump',
        '-h', 'host.docker.internal',
        '-p', $localPort,
        '-U', $localUser,
        '-d', $localDb,
        '--no-owner',
        '--no-acl',
        '-F', 'p',
        '-f', '/dump.sql'
    )
    # Mount parent dir instead
    $dumpDir = Split-Path $dumpFile
    $dumpName = Split-Path $dumpFile -Leaf
    $dockerArgs = @(
        'run', '--rm',
        '-e', "PGPASSWORD=$localPass",
        '-v', "${dumpDir}:/dump",
        'postgres:16',
        'pg_dump',
        '-h', 'host.docker.internal',
        '-p', $localPort,
        '-U', $localUser,
        '-d', $localDb,
        '--no-owner',
        '--no-acl',
        '-F', 'p',
        '-f', "/dump/$dumpName"
    )
    & docker @dockerArgs
    if ($LASTEXITCODE -ne 0) { throw "docker pg_dump via host.docker.internal failed" }
}

if (-not (Test-Path $dumpFile) -or (Get-Item $dumpFile).Length -eq 0) {
    throw "Dump file is empty: $dumpFile"
}

Write-Host "Restoring into Railway (this replaces schema/data in that database) ..."
$uri = [Uri]$railwayUrl.Replace('postgresql://', 'http://').Replace('postgres://', 'http://')
# Parse with Npgsql-style URL manually
if ($railwayUrl -match 'postgres(?:ql)?://([^:]+):([^@]+)@([^:/]+):(\d+)/([^?]+)') {
    $rUser = $Matches[1]
    $rPass = [Uri]::UnescapeDataString($Matches[2])
    $rHost = $Matches[3]
    $rPort = $Matches[4]
    $rDb   = $Matches[5]
} else {
    throw "Could not parse Railway DATABASE_URL"
}

$dumpDir = Split-Path $dumpFile
$dumpName = Split-Path $dumpFile -Leaf

$localPsql = Get-Command psql -ErrorAction SilentlyContinue
if ($localPsql) {
    $env:PGPASSWORD = $rPass
    & psql -h $rHost -p $rPort -U $rUser -d $rDb -v ON_ERROR_STOP=1 -f $dumpFile
    if ($LASTEXITCODE -ne 0) { throw "psql restore failed" }
} else {
    & docker run --rm `
        -e "PGPASSWORD=$rPass" `
        -v "${dumpDir}:/dump" `
        'postgres:16' `
        psql -h $rHost -p $rPort -U $rUser -d $rDb -v ON_ERROR_STOP=1 -f "/dump/$dumpName"
    if ($LASTEXITCODE -ne 0) { throw "docker psql restore failed" }
}

Write-Host "Done. Dump kept at: $dumpFile"
Write-Host "Set Railway DATABASE_URL to the same URL (add ?sslmode=require if missing)."
