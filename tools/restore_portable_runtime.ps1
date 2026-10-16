param()
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $project '.runtime'
$manifest = Get-Content (Join-Path $project 'preparation/runtime-distributions.json') -Raw | ConvertFrom-Json
# Explicit invocation only; refuse to replace an existing local runtime.
foreach ($package in $manifest.packages) {
    $destination = Join-Path $runtime $package.destination
    if (Test-Path -LiteralPath $destination) { throw "Existing runtime preserved: $destination" }
}
New-Item -ItemType Directory -Path $runtime -Force | Out-Null
foreach ($package in $manifest.packages) {
    $archive = Join-Path $runtime $package.filename
    if (-not (Test-Path -LiteralPath $archive)) {
        Invoke-WebRequest -Uri $package.url -OutFile $archive -UseBasicParsing
    }
    $digest = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($digest -ne $package.sha256) { throw 'Official distribution SHA-256 mismatch; extraction refused.' }
    $destination = Join-Path $runtime $package.destination
    if ($package.archive_root) {
        $unpack = Join-Path $runtime ('unpack-' + [guid]::NewGuid().ToString('N'))
        Expand-Archive -LiteralPath $archive -DestinationPath $unpack
        $source = Join-Path $unpack $package.archive_root
        if (-not (Test-Path -LiteralPath $source)) { throw 'Unexpected official archive structure.' }
        New-Item -ItemType Directory -Path $destination | Out-Null
        Get-ChildItem -LiteralPath $source -Force | Copy-Item -Destination $destination -Recurse
    } else {
        Expand-Archive -LiteralPath $archive -DestinationPath $destination
    }
    Write-Host ($package.name + ': pinned official SHA-256 verified; extracted locally.')
}
Write-Host 'No service, global configuration or PATH changed. See REPRODUCIR.txt for private initialization.'
