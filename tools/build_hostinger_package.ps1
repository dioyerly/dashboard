# Reproducible distribution builder for the Hostinger package (development tool only, never deployed).
# Whitelists the production files, refuses private/runtime/development content, writes a SHA-256 manifest and a ZIP.
# Output goes to .runtime/hostinger-packages/<stamp>/ (ignored by Git). Existing outputs are never overwritten.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File tools\build_hostinger_package.ps1

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$OutRoot = Join-Path $Root '.runtime\hostinger-packages'
$OutDir = Join-Path $OutRoot $Stamp
$Staging = Join-Path $OutDir 'package'
$ZipName = "centro-operaciones-hostinger-$Stamp.zip"
$ZipPath = Join-Path $OutDir $ZipName

if (Test-Path -LiteralPath $OutDir) { throw "Output already exists; refusing to overwrite: $OutDir" }
New-Item -ItemType Directory -Path $Staging -Force | Out-Null

# Whitelist: source relative path -> package relative path (directories copied recursively).
$Copy = @(
    @{ Src = 'public'; Dst = 'public_html'; Recurse = $true },
    @{ Src = 'app'; Dst = 'app'; Recurse = $true },
    @{ Src = 'config\settings.php'; Dst = 'config\settings.php'; File = $true },
    @{ Src = 'config\private.hostinger.example.php'; Dst = 'config\private.hostinger.example.php'; File = $true },
    @{ Src = 'tools\migrate.php'; Dst = 'tools\migrate.php'; File = $true },
    @{ Src = 'tools\create_admin.php'; Dst = 'tools\create_admin.php'; File = $true }
)
$Migrations = Get-ChildItem -LiteralPath (Join-Path $Root 'database\migrations') -Filter '*.sql' |
    Where-Object { $_.Name -match '^\d{3}_.+\.sql$' -and [int]$_.Name.Substring(0, 3) -ge 1 -and [int]$_.Name.Substring(0, 3) -le 17 } |
    Sort-Object Name

foreach ($item in $Copy) {
    $src = Join-Path $Root $item.Src
    $dst = Join-Path $Staging $item.Dst
    if (-not (Test-Path -LiteralPath $src)) { throw "Missing required source: $($item.Src)" }
    if ($item.File) {
        New-Item -ItemType Directory -Path (Split-Path -Parent $dst) -Force | Out-Null
        Copy-Item -LiteralPath $src -Destination $dst
    } else {
        Copy-Item -LiteralPath $src -Destination $dst -Recurse
    }
}
$migDst = Join-Path $Staging 'database\migrations'
New-Item -ItemType Directory -Path $migDst -Force | Out-Null
foreach ($m in $Migrations) { Copy-Item -LiteralPath $m.FullName -Destination (Join-Path $migDst $m.Name) }
if ($Migrations.Count -ne 17) { throw "Expected migrations 001-017, found $($Migrations.Count)" }

# Exclusion gate: nothing private, generated, versioned metadata or development material may reach the package.
$forbidden = '(?i)(\\|/)(\.runtime|\.git|storage|tests|reference|preparation|__pycache__|validation-private)(\\|/)|\.(zip|log|pyc|exe|dll|sqlite|bak)$|\.sql\.json$|(\\|/)private\.php$|(\\|/)(client|prep|local)\.(ini|json)$|\.env$'
$all = Get-ChildItem -LiteralPath $Staging -Recurse -File
$bad = @($all | Where-Object { $_.FullName.Substring($Staging.Length) -match $forbidden })
if ($bad.Count -gt 0) { throw "Forbidden files in package: $($bad.Count) e.g. $($bad[0].FullName)" }

# Secret gate: the distributed config must hold placeholders only.
$secretPattern = '(?i)(password|passwd|secret|token)\s*[''"]?\s*(=>|=|:)\s*[''"][^''"<][^''"]{5,}[''"]'
$configFiles = @(Get-ChildItem -LiteralPath (Join-Path $Staging 'config') -File)
foreach ($f in $configFiles) {
    if (Select-String -LiteralPath $f.FullName -Pattern $secretPattern -Quiet) { throw "Possible secret literal in $($f.Name)" }
}

# Manifest: every file of the package with its SHA-256 (relative paths with forward slashes).
$lines = foreach ($f in ($all | Sort-Object FullName)) {
    $rel = $f.FullName.Substring($Staging.Length + 1) -replace '\\', '/'
    $h = (Get-FileHash -LiteralPath $f.FullName -Algorithm SHA256).Hash
    "$h  $rel"
}
$manifestPath = Join-Path $Staging 'MANIFEST-SHA256.txt'
[IO.File]::WriteAllLines($manifestPath, [string[]]$lines, (New-Object Text.UTF8Encoding $false))

# ZIP of the staging directory without a top-level folder. Entry names use forward slashes explicitly:
# CreateFromDirectory on .NET Framework writes backslashes, which Linux hosts do not extract correctly.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipStream = [IO.File]::Open($ZipPath, [IO.FileMode]::CreateNew)
try {
    $archive = New-Object IO.Compression.ZipArchive($zipStream, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($f in ($all | Sort-Object FullName)) {
            $entryName = $f.FullName.Substring($Staging.Length + 1) -replace '\\', '/'
            [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $f.FullName, $entryName, [IO.Compression.CompressionLevel]::Optimal)
        }
        $mf = Get-Item -LiteralPath $manifestPath
        [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $mf.FullName, 'MANIFEST-SHA256.txt', [IO.Compression.CompressionLevel]::Optimal)
    } finally { $archive.Dispose() }
} finally { $zipStream.Dispose() }
$zipHash = (Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash
[IO.File]::WriteAllText((Join-Path $OutDir "$ZipName.sha256"), "$zipHash  $ZipName`r`n", (New-Object Text.UTF8Encoding $false))

[pscustomobject]@{
    ok = $true
    stamp = $Stamp
    files = $all.Count
    migrations = $Migrations.Count
    zip = $ZipPath
    zip_sha256 = $zipHash
    staging = $Staging
} | ConvertTo-Json -Depth 3
