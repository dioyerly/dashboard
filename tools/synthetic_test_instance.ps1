# Isolated synthetic MariaDB instance for tests only. Reuses the MariaDB binaries already present in .runtime/mariadb/bin
# (no installation, no download). Own port, own data directory, own random root password, all under .runtime (ignored by Git).
# Never touches the operational instance, its data directory, or any private configuration.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File tools\synthetic_test_instance.ps1 -Action init|start|stop|status

param([Parameter(Mandatory = $true)][ValidateSet('init', 'start', 'stop', 'status')][string]$Action)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Bin = Join-Path $Root '.runtime\mariadb\bin'
$Inst = Join-Path $Root '.runtime\synthetic-test-mariadb'
$Data = Join-Path $Inst 'data'
$Client = Join-Path $Inst 'client.ini'
$My = Join-Path $Inst 'my.ini'
$Marker = Join-Path $Inst 'isolated-marker.json'
$PidFile = Join-Path $Inst 'server.pid'
$Log = Join-Path $Inst 'server-error.log'
$PortFile = Join-Path $Inst 'port.txt'
$Operational = (Join-Path $Root '.runtime\mariadb\data')

function Get-Port { $l = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0); $l.Start(); $p = ($l.LocalEndpoint).Port; $l.Stop(); return $p }
function Get-Port-Value { if (-not (Test-Path $PortFile)) { throw 'instance not initialized' }; return [int](Get-Content $PortFile -Raw).Trim() }
function Test-Listening([int]$Port) { return [bool](Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) }

switch ($Action) {
    'init' {
        if (Test-Path $Inst) { throw 'synthetic instance already exists; refusing to reinitialize' }
        if ((Resolve-Path -LiteralPath (Split-Path $Inst)).Path -ne (Resolve-Path -LiteralPath (Join-Path $Root '.runtime')).Path) { throw 'unexpected location' }
        New-Item -ItemType Directory -Path $Inst | Out-Null
        $port = Get-Port
        $pw = -join ((1..48) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
        $template = Join-Path $Inst 'bootstrap.ini'
        [IO.File]::WriteAllText($template, "[mysqld]`nbind-address=127.0.0.1`nskip-name-resolve`n", (New-Object Text.UTF8Encoding $false))
        $dataFwd = $Data -replace '\\', '/'; $binFwd = (Join-Path $Root '.runtime\mariadb') -replace '\\', '/'
        $r = & (Join-Path $Bin 'mariadb-install-db.exe') "--datadir=$Data" "--password=$pw" "--port=$port" "--config=$template" 2>&1
        if ($LASTEXITCODE) { throw 'synthetic data directory initialization failed' }
        Remove-Item $template -Force
        [IO.File]::WriteAllText($My, "[mysqld]`nbasedir=$binFwd`ndatadir=$dataFwd`nport=$port`nbind-address=127.0.0.1`nskip-name-resolve`ncharacter-set-server=utf8mb4`ncollation-server=utf8mb4_unicode_ci`ninnodb-buffer-pool-size=64M`nlog-error=$($Log -replace '\\','/')`npid-file=$($PidFile -replace '\\','/')`ntmpdir=$($Inst -replace '\\','/')`n", (New-Object Text.UTF8Encoding $false))
        [IO.File]::WriteAllText($Client, "[client]`nuser=root`npassword=$pw`n", (New-Object Text.UTF8Encoding $false))
        [IO.File]::WriteAllText($PortFile, "$port", (New-Object Text.UTF8Encoding $false))
        $m = [ordered]@{ purpose = 'synthetic-isolated-test'; port = $port; host = '127.0.0.1'; data_dir = (Resolve-Path -LiteralPath $Data).Path; created_at = (Get-Date -Format 'o'); operational_data_dir_excluded = $Operational }
        [IO.File]::WriteAllText($Marker, ($m | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))
        Write-Output (@{ ok = $true; action = 'init'; port = $port; data_dir = $m.data_dir } | ConvertTo-Json)
    }
    'start' {
        $port = Get-Port-Value
        if (Test-Listening $port) { Write-Output (@{ ok = $true; action = 'start'; port = $port; note = 'already listening' } | ConvertTo-Json); break }
        $proc = Start-Process -FilePath (Join-Path $Bin 'mariadbd.exe') -ArgumentList "--defaults-file=$My", '--console' -WindowStyle Hidden -PassThru
        $deadline = (Get-Date).AddSeconds(60)
        while (-not (Test-Listening $port)) { if ($proc.HasExited -or (Get-Date) -gt $deadline) { throw 'synthetic instance did not start (see its private log)' }; Start-Sleep -Milliseconds 200 }
        Write-Output (@{ ok = $true; action = 'start'; port = $port } | ConvertTo-Json)
    }
    'stop' {
        $port = Get-Port-Value
        if (-not (Test-Listening $port)) { Write-Output (@{ ok = $true; action = 'stop'; note = 'not listening' } | ConvertTo-Json); break }
        & (Join-Path $Bin 'mariadb-admin.exe') "--defaults-file=$Client" --protocol=tcp --host=127.0.0.1 "--port=$port" --skip-ssl shutdown
        $deadline = (Get-Date).AddSeconds(60)
        while (Test-Listening $port) { if ((Get-Date) -gt $deadline) { throw 'synthetic instance did not stop' }; Start-Sleep -Milliseconds 200 }
        Write-Output (@{ ok = $true; action = 'stop'; port = $port } | ConvertTo-Json)
    }
    'status' {
        $port = Get-Port-Value
        Write-Output (@{ initialized = $true; port = $port; listening = (Test-Listening $port); operational_data_dir_used = $false } | ConvertTo-Json)
    }
}
