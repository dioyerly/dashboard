param([int]$Port = 8080)
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$path = Join-Path $project '.runtime/mariadb/review.json'
if (-not (Test-Path -LiteralPath $path)) { throw 'Falta la configuracion privada de revision MariaDB.' }
$review = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json
if ($review.environment.CENTRO_DB_HOST -ne '127.0.0.1' -or $review.environment.CENTRO_ENV -ne 'local') { throw 'Este lanzador solo admite MariaDB local.' }
if ($Port -lt 1024 -or $Port -gt 65535) { throw 'Puerto local invalido.' }
# Process scope only: no persistent Windows environment changes.
foreach ($item in $review.environment.PSObject.Properties) { [Environment]::SetEnvironmentVariable($item.Name, [string]$item.Value, 'Process') }
$php = Join-Path $project '.runtime/php/php.exe'
$extensions = Join-Path $project '.runtime/php/ext'
Write-Host "Centro de Operaciones (MariaDB local): http://127.0.0.1:$Port"
& $php -n -d "extension_dir=$extensions" -d extension=pdo_mysql -S "127.0.0.1:$Port" -t (Join-Path $project 'public')
exit $LASTEXITCODE
