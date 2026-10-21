# Runs one PHP test script in isolated synthetic mode against the synthetic MariaDB instance only.
# Creates a fresh synthetic database when -NewDatabase is given. The run token is generated per execution, stored only as
# its SHA-256 in the marker, passed to the child process environment and removed afterwards. Secrets are never printed.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File tools\run_isolated_tests.ps1 -Script tests\x.php [-NewDatabase] [-Arguments ...]

param(
    [Parameter(Mandatory = $true)][string]$Script,
    [switch]$NewDatabase,
    [string]$DatabaseName,
    [string]$StdinText,
    [string[]]$Arguments = @()
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Inst = Join-Path $Root '.runtime\synthetic-test-mariadb'
$Marker = Join-Path $Inst 'isolated-marker.json'
$Client = Join-Path $Inst 'client.ini'
$Php = Join-Path $Root '.runtime\php\php.exe'
$Mysql = Join-Path $Root '.runtime\mariadb\bin\mariadb.exe'
$Ext = Join-Path $Root '.runtime\php\ext'
$Instance = Join-Path $PSScriptRoot 'synthetic_test_instance.ps1'

if (-not (Test-Path -LiteralPath $Marker)) { throw 'synthetic instance not initialized' }
powershell -NoProfile -ExecutionPolicy Bypass -File $Instance -Action start | Out-Null
$m = Get-Content -LiteralPath $Marker -Raw | ConvertFrom-Json
$port = [int]$m.port
$scriptPath = if ([IO.Path]::IsPathRooted($Script)) { $Script } else { Join-Path $Root $Script }
if (-not (Test-Path -LiteralPath $scriptPath)) { throw "script not found: $Script" }

$pw = ((Get-Content -LiteralPath $Client) | Where-Object { $_ -match '^password=' }) -replace '^password=', ''
$dbName = if ($DatabaseName) { $DatabaseName } else { $null }
if ($NewDatabase) {
    $dbName = if ($DatabaseName) { $DatabaseName } else { 'centro_auth_synthetic_' + ([guid]::NewGuid().ToString('N').Substring(0, 12)) }
    $sql = 'CREATE DATABASE `' + $dbName + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
    $sql | & $Mysql "--defaults-file=$Client" --protocol=tcp --host=127.0.0.1 "--port=$port" --skip-ssl --batch
    if ($LASTEXITCODE) { throw 'synthetic database creation failed' }
}

$token = -join ((1..64) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
$sha = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($token))).Replace('-', '').ToLower()
$m | Add-Member -NotePropertyName token_sha256 -NotePropertyValue $sha -Force
$m | Add-Member -NotePropertyName token_issued_at -NotePropertyValue (Get-Date -Format 'o') -Force
[IO.File]::WriteAllText($Marker, ($m | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))

$names = @('CENTRO_ISOLATED_TEST_CONFIG', 'CENTRO_ISOLATED_TEST_MARKER', 'CENTRO_ISOLATED_TEST_TOKEN', 'CENTRO_ENV', 'CENTRO_AUTH_DRIVER', 'CENTRO_DB_ENABLED', 'CENTRO_DB_HOST', 'CENTRO_DB_PORT', 'CENTRO_DB_NAME', 'CENTRO_DB_USER', 'CENTRO_DB_PASSWORD', 'CENTRO_MIGRATION_DB_ENABLED', 'CENTRO_MIGRATION_DB_HOST', 'CENTRO_MIGRATION_DB_PORT', 'CENTRO_MIGRATION_DB_NAME', 'CENTRO_MIGRATION_DB_USER', 'CENTRO_MIGRATION_DB_PASSWORD', 'CENTRO_PRIVATE_DIR')
$saved = @{}; foreach ($n in $names) { $saved[$n] = [Environment]::GetEnvironmentVariable($n) }
try {
    $env:CENTRO_ISOLATED_TEST_CONFIG = '1'
    $env:CENTRO_ISOLATED_TEST_MARKER = $Marker
    $env:CENTRO_ISOLATED_TEST_TOKEN = $token
    $env:CENTRO_ENV = 'local'
    $env:CENTRO_AUTH_DRIVER = 'mysql'
    $env:CENTRO_DB_ENABLED = '1'
    $env:CENTRO_DB_HOST = '127.0.0.1'
    $env:CENTRO_DB_PORT = [string]$port
    $env:CENTRO_DB_USER = 'root'
    $env:CENTRO_DB_PASSWORD = $pw
    $env:CENTRO_MIGRATION_DB_ENABLED = '1'
    $env:CENTRO_MIGRATION_DB_HOST = '127.0.0.1'
    $env:CENTRO_MIGRATION_DB_PORT = [string]$port
    $env:CENTRO_MIGRATION_DB_USER = 'root'
    $env:CENTRO_MIGRATION_DB_PASSWORD = $pw
    $env:CENTRO_PRIVATE_DIR = (Join-Path $Inst 'private')
    New-Item -ItemType Directory -Force -Path $env:CENTRO_PRIVATE_DIR | Out-Null
    if ($dbName) { $env:CENTRO_DB_NAME = $dbName; $env:CENTRO_MIGRATION_DB_NAME = $dbName }
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    if ($StdinText) {
        # PowerShell pipelines add a BOM and CRLF to native stdin, so the exact bytes are written to a private file and redirected.
        $stdinFile = Join-Path $Inst ('stdin-' + [guid]::NewGuid().ToString('N') + '.bin')
        $outFile = $stdinFile + '.out'; $errFile = $stdinFile + '.err'
        [IO.File]::WriteAllBytes($stdinFile, (New-Object Text.UTF8Encoding $false).GetBytes($StdinText))
        $argList = @('-n', '-d', "extension_dir=$Ext", '-d', 'extension=pdo_mysql', $scriptPath) + $Arguments
        $p = Start-Process -FilePath $Php -ArgumentList $argList -RedirectStandardInput $stdinFile -RedirectStandardOutput $outFile -RedirectStandardError $errFile -NoNewWindow -Wait -PassThru
        $output = @((Get-Content -LiteralPath $outFile -ErrorAction SilentlyContinue), (Get-Content -LiteralPath $errFile -ErrorAction SilentlyContinue))
        $code = $p.ExitCode
        Remove-Item -LiteralPath $stdinFile, $outFile, $errFile -Force -ErrorAction SilentlyContinue
    } else {
        $output = & $Php -n -d "extension_dir=$Ext" -d extension=pdo_mysql $scriptPath @Arguments 2>&1
        $code = $LASTEXITCODE
    }
    $ErrorActionPreference = $previousPreference
} finally {
    foreach ($n in $names) { [Environment]::SetEnvironmentVariable($n, $saved[$n]) }
    $token = $null; $pw = $null
}
[pscustomobject]@{ script = $Script; exit = $code; database = $dbName; port = $port; output = (($output | Out-String).Trim()) } | ConvertTo-Json -Depth 4
exit $code
