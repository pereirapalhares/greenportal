$taskName = 'GreenPortal-Migracao-Worker'
$taskPath = '\GreenPortal\'
$portalRoot = Split-Path -Parent $PSScriptRoot
$bat = Join-Path $portalRoot 'scripts\migracao-worker.bat'
if (-not (Test-Path -LiteralPath $bat)) { throw "Worker nao encontrado: $bat" }
$ErrorActionPreference = 'Stop'
$schedule = New-Object -ComObject 'Schedule.Service'
$schedule.Connect()
$rootFolder = $schedule.GetFolder('\')
try { $rootFolder.GetFolder('GreenPortal') | Out-Null } catch { $rootFolder.CreateFolder('GreenPortal', $null) | Out-Null }
$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"$bat`""
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Action $action -Trigger $trigger -Principal $principal -Description 'Processa solicitacoes de migracao do GreenPortal.' -Force | Out-Null
Write-Output "Tarefa instalada: ${taskPath}${taskName}"
