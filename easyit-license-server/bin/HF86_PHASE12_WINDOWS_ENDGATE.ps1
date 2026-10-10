param(
    [string]$PhpExe = "D:\xampp\php\php.exe",
    [Parameter(Mandatory=$true)][string]$MariaDbDsn,
    [string]$MariaDbUser = "root",
    [string]$MariaDbPassword = "",
    [Parameter(Mandatory=$true)][string]$PgsqlDsn,
    [string]$PgsqlUser = "postgres",
    [string]$PgsqlPassword = ""
)
$ErrorActionPreference = "Stop"
if (-not (Test-Path $PhpExe)) { $PhpExe = "php" }
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$env:HF86_MARIADB_DSN = $MariaDbDsn
$env:HF86_MARIADB_USER = $MariaDbUser
$env:HF86_MARIADB_PASSWORD = $MariaDbPassword
$env:HF86_PGSQL_DSN = $PgsqlDsn
$env:HF86_PGSQL_USER = $PgsqlUser
$env:HF86_PGSQL_PASSWORD = $PgsqlPassword
& $PhpExe (Join-Path $root "bin\hf86-final-gate.php")
if ($LASTEXITCODE -ne 0) { throw "HF86 Phase 12 Endgate failed with exit code $LASTEXITCODE" }
Write-Host "HF86 PHASE 12 / FINAL GATE PASSED" -ForegroundColor Green
