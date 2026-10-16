param([Parameter(Mandatory=$true)][string]$Email)
$ErrorActionPreference = 'Stop'
if ($Email -notmatch '^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$') { throw 'Email invalido.' }
$root = Split-Path $PSScriptRoot -Parent
$secure = Read-Host 'Contrasena nueva (12 a 72 bytes UTF-8)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = Join-Path $root '.runtime/php/php.exe'
    $info.Arguments = '-n "' + (Join-Path $PSScriptRoot 'create_admin.php') + '" --email=' + $Email + ' --password-stdin'
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $process = [Diagnostics.Process]::Start($info)
    $process.StandardInput.WriteLine($password)
    $process.StandardInput.Close()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw 'No se pudo inicializar el administrador.' }
} finally {
    $password = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    $secure.Dispose()
}
