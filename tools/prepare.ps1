param([switch]$VerifyOnly, [switch]$RefreshInventory)
$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$workspace = Split-Path $project -Parent
$utf8 = New-Object System.Text.UTF8Encoding($false, $true)

function Save-Json($Path, $Value) {
    [IO.File]::WriteAllText($Path, ($Value | ConvertTo-Json -Depth 30), $utf8)
}
function Digest($Text) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($utf8.GetBytes($Text)))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}
function Entries($Text, $Pattern) {
    return @([regex]::Matches($Text, $Pattern) | ForEach-Object {
        [ordered]@{ name = $_.Groups[1].Value; line = 1 + [regex]::Matches($Text.Substring(0, $_.Index), "`n").Count }
    })
}

$names = @('centro-operaciones.html', 'centro-operaciones-DEMO.html', 'README.md')
$manifestPath = Join-Path $project 'preparation/baseline.json'
if (-not $VerifyOnly) {
    $hasBaseline = Test-Path -LiteralPath $manifestPath
    if ($hasBaseline -and -not $RefreshInventory) { throw 'La referencia ya existe. Use -VerifyOnly o -RefreshInventory; no se sobrescriben referencias.' }
    if ($hasBaseline) {
        $existing = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($item in $existing.files) {
            if ((Get-FileHash -LiteralPath (Join-Path $workspace $item.file)).Hash.ToLowerInvariant() -ne $item.sha256) { throw "Original modificado: $($item.file)" }
        }
    }
    foreach ($dir in @('reference', 'preparation', 'public/assets/css', 'public/assets/js', 'app', 'config', 'database/migrations', 'storage/private', 'storage/logs', 'tests')) {
        New-Item -ItemType Directory -Path (Join-Path $project $dir) -Force | Out-Null
    }
    $baseline = @()
    $inventories = @()
    foreach ($name in $names) {
        $sourcePath = Join-Path $workspace $name
        $bytes = [IO.File]::ReadAllBytes($sourcePath)
        $text = $utf8.GetString($bytes)
        $baseline += [ordered]@{ file = $name; bytes = $bytes.Length; lines = [regex]::Matches($text, "`n").Count + 1; sha256 = (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256).Hash.ToLowerInvariant() }
        if ($name -eq 'README.md') { continue } # No crear archivos Markdown adicionales.
        if (-not $hasBaseline) { [IO.File]::WriteAllBytes((Join-Path $project "reference/$name"), $bytes) }
        $css = [regex]::Match($text, '(?s)<style>(.*?)</style>').Groups[1].Value
        $js = [regex]::Match($text, '(?s)<script>(.*?)</script>').Groups[1].Value
        $forms = @([regex]::Matches($text, '<form\b[^>]*>') | ForEach-Object { $_.Value } | Sort-Object -Unique)
        $attrs = @([regex]::Matches($text, 'data-([\w-]+)(?:="([^"\r\n]*)")?') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
        $reportSection = [regex]::Match($text, '(?s)function REPORTS\(\).*?(?=const fmtCell)').Value
        $inventory = [ordered]@{
            file = $name
            method = 'Lectura UTF-8 completa; inventario estatico por expresiones regulares, no un parser JavaScript ni prueba de ejecucion.'
            css_sha256 = Digest $css
            javascript_sha256 = Digest $js
            screens = Entries $text '(?m)^VIEWS\.([\w]+)\s*='
            named_functions = Entries $text '\bfunction\s+([\w$]+)\s*\('
            top_level_constants = Entries $text '(?m)^const\s+([\w$]+)\s*='
            top_level_binding_source = @([regex]::Matches($text, '(?m)^(?:const|let)\s+[^\r\n]+') | ForEach-Object { $_.Value })
            actions = Entries $text '(?m)^ACT\.([\w]+)\s*='
            change_handlers = Entries $text '(?m)^CHG\.([\w]+)\s*='
            input_handlers = Entries $text '(?m)^INP\.([\w]+)\s*='
            action_references = @([regex]::Matches($text, 'data-act="([A-Za-z]\w*)"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
            report_keys = @([regex]::Matches($reportSection, '(?m)^    (\w+): \{ label:') | ForEach-Object { $_.Groups[1].Value })
            forms = $forms
            field_names = @([regex]::Matches($text, '\bname="([A-Za-z_]\w*)"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
            data_attributes = $attrs
            media_queries = @([regex]::Matches($css, '@media\s*([^\{]+)') | ForEach-Object { $_.Groups[1].Value.Trim() })
            animations = @([regex]::Matches($css, '@keyframes\s+(\w+)') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
            storage_sites = @()
            mutation_sites = @()
            section_markers = @()
        }
        $lines = $text -split "`n"
        for ($i = 0; $i -lt $lines.Length; $i++) {
            $line = $lines[$i].TrimEnd("`r")
            if ($line -match 'indexedDB|localStorage|DataStore\.(load|save)|transact\(|restoreBackup|normalizeState') {
                $inventory.storage_sites += [ordered]@{ line = $i + 1; source = $line }
            }
            if ($line -match '\bst\.\w+\.(push|splice)|\bst\.\w+\s*=|Object\.assign\(') {
                $inventory.mutation_sites += [ordered]@{ line = $i + 1; source = $line }
            }
            if ($line -match '^\s*(?:/\*|\*)?\s*[A-Z\u00c0-\u00dd][A-Z\u00c0-\u00dd :(),/0-9_\u2014-]{5,}') {
                $inventory.section_markers += [ordered]@{ line = $i + 1; source = $line }
            }
        }
        $inventories += $inventory
    }
    if (-not $hasBaseline) { Save-Json $manifestPath ([ordered]@{ created_utc = [DateTime]::UtcNow.ToString('o'); files = $baseline; scope = 'Preparacion exclusivamente; sin backend ni conexion remota'; git = 'No es un repositorio Git'; README_warning = 'Conservado sin modificar: contiene propuesta anterior Fly.io; reemplazada por instrucciones PHP/Hostinger del usuario.' }) }
    Save-Json (Join-Path $project 'preparation/source-inventory.json') $inventories
}

$baselineData = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
$checks = @()
foreach ($item in $baselineData.files) {
    $hash = (Get-FileHash -LiteralPath (Join-Path $workspace $item.file) -Algorithm SHA256).Hash.ToLowerInvariant()
    $checks += [ordered]@{ check = "Original intacto: $($item.file)"; passed = $hash -eq $item.sha256 }
    if ($item.file -like '*.html') {
        $copy = (Get-FileHash -LiteralPath (Join-Path $project "reference/$($item.file)") -Algorithm SHA256).Hash.ToLowerInvariant()
        $checks += [ordered]@{ check = "Copia exacta: $($item.file)"; passed = $copy -eq $item.sha256 }
    }
}
$inventoryData = Get-Content -LiteralPath (Join-Path $project 'preparation/source-inventory.json') -Raw -Encoding UTF8 | ConvertFrom-Json
foreach ($inv in $inventoryData) {
    $checks += [ordered]@{ check = "13 vistas: $($inv.file)"; passed = @($inv.screens).Count -eq 13 }
    $checks += [ordered]@{ check = "15 reportes: $($inv.file)"; passed = @($inv.report_keys).Count -eq 15 }
    $missing = @($inv.action_references | Where-Object { $_ -notin @($inv.actions.name) })
    $checks += [ordered]@{ check = "Referencias literales data-act resueltas: $($inv.file)"; passed = $missing.Count -eq 0; unresolved = $missing; limit = 'No incluye acciones construidas dinamicamente ni handlers locales de modales.' }
}
$checks += [ordered]@{ check = 'CSS identico entre original y demo'; passed = $inventoryData[0].css_sha256 -eq $inventoryData[1].css_sha256 }
$newMarkdown = @(Get-ChildItem -LiteralPath $project -Recurse -File -Filter '*.md')
$checks += [ordered]@{ check = 'Sin Markdown adicional'; passed = $newMarkdown.Count -eq 0 }
$phpFiles = @(Get-ChildItem -LiteralPath $project -Recurse -File -Filter '*.php')
$checks += [ordered]@{ check = 'Sin implementacion backend PHP'; passed = $phpFiles.Count -eq 0 }
foreach ($artifact in @('schema-design.json', 'migration-plan.json')) {
    $artifactPath = Join-Path $project "preparation/$artifact"
    if (Test-Path -LiteralPath $artifactPath) {
        $parsed = Get-Content -LiteralPath $artifactPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $checks += [ordered]@{ check = "JSON valido: $artifact"; passed = $null -ne $parsed }
    }
}
$schemaPath = Join-Path $project 'preparation/schema-design.json'
if (Test-Path -LiteralPath $schemaPath) {
    $schema = Get-Content -LiteralPath $schemaPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $stateArrays = @('rates','projects','plans','products','customers','sales','subs','expenses','resources','suppliers','events','treasury','integrations','review','timeLogs','log')
    $missingMappings = @($stateArrays | Where-Object { $_ -notin $schema.state_mapping.PSObject.Properties.Name })
    $checks += [ordered]@{ check = '16 colecciones raiz mapeadas al diseno'; passed = $missingMappings.Count -eq 0; missing = $missingMappings }
    $tableNames = @($schema.tables.name)
    $missingTables = @($schema.state_mapping.PSObject.Properties | ForEach-Object { $_.Value } | Where-Object { $_ -notin $tableNames })
    $checks += [ordered]@{ check = 'Mapeo apunta a tablas existentes en el diseno'; passed = $missingTables.Count -eq 0 }
    $duplicateTables = @($tableNames | Group-Object | Where-Object { $_.Count -gt 1 })
    $checks += [ordered]@{ check = 'Nombres de tablas unicos en el diseno'; passed = $duplicateTables.Count -eq 0 }
    $references = @($schema.tables | ForEach-Object { [regex]::Matches($_.fields, '\bFK\s+(\w+)') } | ForEach-Object { $_.Groups[1].Value })
    $missingReferences = @($references | Where-Object { $_ -notin $tableNames } | Sort-Object -Unique)
    $checks += [ordered]@{ check = 'Referencias FK conceptuales resueltas'; passed = $missingReferences.Count -eq 0; missing = $missingReferences; limit = 'No valida DDL ni constraints en motor SQL; esquema aun conceptual.' }
}
$result = [ordered]@{ checked_utc = [DateTime]::UtcNow.ToString('o'); checks = $checks; not_run = @('Pruebas funcionales de navegador y capturas PC/movil', 'Lint PHP: php no disponible en PATH', 'Ejecucion JS: node no disponible en PATH', 'Python: launcher presente pero sin interprete instalado', 'DDL y transacciones SQL: no hay servidor/cliente configurado', 'Compatibilidad efectiva de la cuenta Hostinger, sin acceso ni conexion'); external_connections = 'Ninguna conexion a Hostinger ni a bases de datos' }
Save-Json (Join-Path $project 'preparation/verification.json') $result
foreach ($inv in $inventoryData) {
    Write-Output "$($inv.file): $(@($inv.screens).Count) vistas, $(@($inv.named_functions).Count) funciones con nombre, $(@($inv.actions).Count) acciones, $(@($inv.change_handlers).Count) cambios, $(@($inv.input_handlers).Count) entradas, $(@($inv.report_keys).Count) reportes, $(@($inv.forms).Count) formularios."
}
$checks | ForEach-Object { Write-Output ("{0}: {1}" -f $(if ($_.passed) { 'PASS' } else { 'FAIL' }), $_.check) }
if (@($checks | Where-Object { -not $_.passed }).Count) { throw 'Hay verificaciones fallidas; consultar preparation/verification.json.' }
