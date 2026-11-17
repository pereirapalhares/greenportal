[CmdletBinding()]
param([switch]$SystemCleanup)

$ErrorActionPreference = 'Stop'
$project = 'E:\wamp\apps\greenportal'
$legacy = Join-Path $project 'services\ExportacaoGridService.php'
$target = Join-Path $project 'services\disabled\ExportacaoGridService.php.disabled'
$taskName = 'GreenPortal-Disable-Legacy-Export'
$taskPath = '\GreenSolutions\'

if ($SystemCleanup) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    if (-not [System.IO.File]::Exists($legacy)) {
        Write-Output 'Arquivo legado nao esta mais presente; servico HTTP legado desativado.'
        exit 0
    }
    if (Test-Path -LiteralPath $target) { throw "Destino ja existe: $target" }
    for ($attempt = 0; $attempt -lt 6; $attempt++) {
        $lines = @(netstat.exe -ano -p tcp 2>$null | Select-String -Pattern '127\.0\.0\.1:(8787|8788)\s+.*LISTENING\s+(\d+)')
        $pids = @($lines | ForEach-Object { if ($_.Line -match 'LISTENING\s+(\d+)') { [int]$Matches[1] } } | Where-Object { $_ -gt 0 } | Sort-Object -Unique)
        foreach ($processId in $pids) { taskkill.exe /PID $processId /T /F 2>$null | Out-Null }
        if ($pids.Count -eq 0) { break }
        Start-Sleep -Seconds 2
    }
    if (@(netstat.exe -ano -p tcp 2>$null | Select-String -Pattern '127\.0\.0\.1:(8787|8788)\s+.*LISTENING').Count -gt 0) { throw 'Os processos HTTP legados continuam ativos nas portas 8787/8788.' }
    [System.IO.File]::Move($legacy, $target)
    exit 0
}

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Execute este script em um PowerShell como Administrador.' }
if (-not [System.IO.File]::Exists($legacy)) { Write-Output 'Arquivo legado ja nao existe; servico HTTP legado desativado.'; exit 0 }
if (Test-Path -LiteralPath $target) { throw "Destino ja existe: $target" }

$argument = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -SystemCleanup' -f $PSCommandPath
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argument -WorkingDirectory $project
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

try {
    Register-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName -TaskPath $taskPath
    for ($i = 0; $i -lt 60; $i++) { Start-Sleep -Seconds 1; if (-not [System.IO.File]::Exists($legacy)) { break } }
} finally { Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false -ErrorAction SilentlyContinue }

if ([System.IO.File]::Exists($legacy)) { throw 'A tarefa SYSTEM nao conseguiu desativar o arquivo legado.' }
if (Test-Path -LiteralPath $target) { Write-Output "Arquivo legado movido para: $target" } else { Write-Output 'Arquivo legado nao esta mais presente; servico HTTP legado desativado.' }
