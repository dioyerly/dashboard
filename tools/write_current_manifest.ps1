# Writes MANIFEST-SHA256-CURRENT.txt: SHA-256 of every Git-tracked file of this repository (working tree),
# grouped by category. Read-only for the repository except the manifest file itself.
# The historical MANIFEST-SHA256.txt is never read, rewritten or deleted.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File tools\write_current_manifest.ps1

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Out = Join-Path $Root 'MANIFEST-SHA256-CURRENT.txt'
$gitArgs = @('-c', "safe.directory=$Root", '-C', $Root)
$head = (& git @gitArgs rev-parse HEAD).Trim()
$tracked = @(& git @gitArgs ls-files) | Where-Object { $_ -ne 'MANIFEST-SHA256-CURRENT.txt' -and $_ -ne 'MANIFEST-SHA256.txt' }

function Get-Category([string]$p) {
    $n = $p -replace '\\', '/'
    if ($n -like 'tests/*') { return 'pruebas' }
    if ($n -like 'public/assets/*' -or $n -like 'public/*.php' -or $n -like 'public/.htaccess') { if ($n -like 'public/assets/*') { return 'frontend' } else { return 'codigo' } }
    if ($n -like 'app/*' -or $n -like 'config/*') { return 'codigo' }
    if ($n -like 'database/*') { return 'migraciones' }
    if ($n -like 'reference/*') { return 'referencia' }
    if ($n -like 'preparation/*') { return 'evidencia-preparacion' }
    if ($n -like '*.md' -or $n -like '*.txt') { return 'documentacion' }
    if ($n -like 'config/*' -or $n -like '.git*' -or $n -like '*.example.php') { return 'configuracion' }
    if ($n -like 'tools/*') { return 'distribucion-y-herramientas' }
    return 'documentacion-y-configuracion'
}

$lines = New-Object System.Collections.Generic.List[string]
$missing = @()
$counts = @{}
foreach ($p in ($tracked | Sort-Object)) {
    $full = Join-Path $Root ($p -replace '/', '\')
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { $missing += $p; continue }
    $h = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash
    $cat = Get-Category $p
    $counts[$cat] = 1 + [int]$counts[$cat]
    $lines.Add("$h  $cat  $($p -replace '\\','/')")
}
if ($missing.Count -gt 0) { throw "Tracked entries missing from working tree: $($missing -join ', ')" }

$header = @(
    '# MANIFEST ACTUAL (generado). No es el manifiesto historico MANIFEST-SHA256.txt.',
    "# Base Git: $head",
    "# Generado: $(Get-Date -Format 'yyyy-MM-ddTHH:mm:ss')",
    '# Alcance: archivos rastreados por Git en el arbol de trabajo. Excluye .runtime, storage e ignorados.',
    '# Formato: SHA256  categoria  ruta-relativa'
)
$summary = $counts.GetEnumerator() | Sort-Object Name | ForEach-Object { "# $($_.Key): $($_.Value)" }
[IO.File]::WriteAllLines($Out, [string[]]($header + $summary + $lines), (New-Object Text.UTF8Encoding $false))
Write-Output ("ok entries={0} out={1}" -f $lines.Count, $Out)
