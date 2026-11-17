[CmdletBinding()]
param(
    [switch]$Persistir,
    [switch]$Validar
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$requiredTasks = @('GreenPortal-TaskWorker','GreenPortal-ExportWorker','GreenPortal-IaWorker','GreenPortal-OcrWorker')

if ($Persistir) {
    if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Execute este script em PowerShell elevado para persistir variáveis do computador.' }
    $secure = Read-Host 'Informe a senha Crown atual (não será exibida)' -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { $crownPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
    if ([string]::IsNullOrWhiteSpace($crownPassword)) { throw 'A senha Crown não foi informada.' }
    [Environment]::SetEnvironmentVariable('GREEN_CROWN_TEST_USER','admin','Machine')
    [Environment]::SetEnvironmentVariable('GREEN_CROWN_TEST_PASSWORD',$crownPassword,'Machine')
    [Environment]::SetEnvironmentVariable('GREEN_TEST_PASSWORD',$crownPassword,'Machine')
    Write-Output 'Variáveis persistidas no escopo Machine. A senha não foi impressa.'
}

if ($Validar -or $Persistir) {
    $missing = @('GREEN_CROWN_TEST_USER','GREEN_CROWN_TEST_PASSWORD','GREEN_TEST_PASSWORD') | Where-Object { [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($_,'Machine')) }
    $tasks = Get-ScheduledTask -TaskPath '\GreenSolutions\' -ErrorAction SilentlyContinue | Where-Object TaskName -like 'GreenPortal-*'
    $missingTasks = $requiredTasks | Where-Object { $_ -notin @($tasks.TaskName) }
    [pscustomobject]@{ ok = ($missing.Count -eq 0 -and $missingTasks.Count -eq 0); missing_variables = @($missing); missing_tasks = @($missingTasks); task_count = @($tasks).Count; checked_at = (Get-Date).ToUniversalTime().ToString('o') } | ConvertTo-Json -Depth 4
}
