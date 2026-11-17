[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe',
    [string]$Pasta = '\GreenSolutions\',
    [int]$Minutos = 10,
    [switch]$ExecutarAgora
)

# Cria (ou atualiza) só a tarefa do serviço de indexação da Pesquisa de documentos:
#   \GreenSolutions\GreenPortal-BuscaWorker -> php scripts\busca-worker.php a cada 10 minutos, como SYSTEM, oculto.
# Os bancos indexados são gatlas_dbp e gmodelo_dbp (ou a lista de BUSCA_WORKER_DATABASES no .env).
# Uso (PowerShell como Administrador):
#   powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\criar-agendamento-busca.ps1 -ExecutarAgora

$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }
$script = Join-Path $Projeto 'scripts\busca-worker.php'
if (-not (Test-Path -LiteralPath $script -PathType Leaf)) { throw "Worker não localizado: $script" }

$service = New-Object -ComObject 'Schedule.Service'
$service.Connect()
$root = $service.GetFolder('\')
try { $null = $root.GetFolder($Pasta.Trim('\')) } catch { $null = $root.CreateFolder($Pasta.Trim('\'), $null) }

$nome = 'GreenPortal-BuscaWorker'
$action = New-ScheduledTaskAction -Execute $Php -Argument ('-f "{0}"' -f $script) -WorkingDirectory $Projeto
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $Minutos) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$definition = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "GreenSolutions PHP service: $nome (indexação da Pesquisa de documentos)"
Register-ScheduledTask -TaskName $nome -TaskPath $Pasta -InputObject $definition -Force | Out-Null
if ($ExecutarAgora) { Start-ScheduledTask -TaskPath $Pasta -TaskName $nome }

Get-ScheduledTask -TaskPath $Pasta -TaskName $nome | Select-Object TaskPath, TaskName, State | Format-Table -AutoSize
Write-Output "Tarefa $Pasta$nome registrada: a cada $Minutos minutos."
