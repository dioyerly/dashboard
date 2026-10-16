param([int]$Port = 8080, [string]$PhpPath = '')
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
if (-not $PhpPath) {
    $portable = Join-Path $project '.runtime/php/php.exe'
    if (Test-Path -LiteralPath $portable) { $PhpPath = $portable }
    else {
        $installed = Get-Command php -ErrorAction SilentlyContinue
        if (-not $installed) { throw 'PHP >= 8.3 requerido: use -PhpPath con el ejecutable portable.' }
        $PhpPath = $installed.Source
    }
}
$version = & $PhpPath -n -r 'echo PHP_VERSION_ID;'
if ($LASTEXITCODE -ne 0 -or [int]$version -lt 80300) { throw 'Se requiere PHP 8.3 o superior.' }
if ($Port -lt 1024 -or $Port -gt 65535) { throw 'Puerto local invalido.' }
Write-Host "Centro de Operaciones: http://127.0.0.1:$Port (Ctrl+C para detener)"
& $PhpPath -n -S "127.0.0.1:$Port" -t (Join-Path $project 'public')
exit $LASTEXITCODE
