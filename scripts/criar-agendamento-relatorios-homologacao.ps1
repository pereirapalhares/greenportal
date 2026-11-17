[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe',
    [string]$Pasta = '\GreenSolutions\',
    [string]$Hora = '07:20',
    [switch]$ExecutarAgora
)

# Cria (ou atualiza) a tarefa do envio semanal de "Meus relatórios" (Relatórios de Homologação):
#   \GreenSolutions\GreenPortal-RelatoriosHomologacao -> php scripts\homologacao-relatorios-worker.php toda segunda-feira, como SYSTEM, oculto.
# Para cada relatório salvo com o envio semanal ligado cria um alerta em Home › Alertas com o resumo e o link (uma vez por semana).
# Requer a migração 314.
# Uso (PowerShell como Administrador):
#   powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\criar-agendamento-relatorios-homologacao.ps1

$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }
$script = Join-Path $Projeto 'scripts\homologacao-relatorios-worker.php'
if (-not (Test-Path -LiteralPath $script -PathType Leaf)) { throw "Worker não localizado: $script" }

$service = New-Object -ComObject 'Schedule.Service'
$service.Connect()
$root = $service.GetFolder('\')
try { $null = $root.GetFolder($Pasta.Trim('\')) } catch { $null = $root.CreateFolder($Pasta.Trim('\'), $null) }

$nome = 'GreenPortal-RelatoriosHomologacao'
$action = New-ScheduledTaskAction -Execute $Php -Argument ('-f "{0}"' -f $script) -WorkingDirectory $Projeto
$trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At $Hora
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$definition = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "GreenSolutions PHP service: $nome (envio semanal dos relatórios salvos da homologação)"
Register-ScheduledTask -TaskName $nome -TaskPath $Pasta -InputObject $definition -Force | Out-Null
if ($ExecutarAgora) { Start-ScheduledTask -TaskPath $Pasta -TaskName $nome }

Get-ScheduledTask -TaskPath $Pasta -TaskName $nome | Select-Object TaskPath, TaskName, State | Format-Table -AutoSize
Write-Output "Tarefa $Pasta$nome registrada: segundas-feiras às $Hora."
