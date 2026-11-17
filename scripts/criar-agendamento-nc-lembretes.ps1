[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe',
    [string]$Pasta = '\GreenSolutions\',
    [switch]$ExecutarAgora
)

# Cria (ou atualiza) a tarefa dos lembretes de Não Conformidades (migração 339):
#   \GreenSolutions\GreenPortal-NcLembretes -> php scripts\nc-lembretes-worker.php a cada 60 minutos, como SYSTEM, oculto.
# O serviço aplica as regras ligadas em Não Conformidades › Administração › Configuração › Configuração do módulo (aba Lembretes):
# grava os alertas (Home › Alertas) e, com e-mail ligado na regra, põe os e-mails na fila (enviada pelo serviço GreenPortal-EmailWorker).
# Uso (PowerShell como Administrador):
#   powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\criar-agendamento-nc-lembretes.ps1

$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }
$script = Join-Path $Projeto 'scripts\nc-lembretes-worker.php'
if (-not (Test-Path -LiteralPath $script -PathType Leaf)) { throw "Worker não localizado: $script" }

$service = New-Object -ComObject 'Schedule.Service'
$service.Connect()
$root = $service.GetFolder('\')
try { $null = $root.GetFolder($Pasta.Trim('\')) } catch { $null = $root.CreateFolder($Pasta.Trim('\'), $null) }

$nome = 'GreenPortal-NcLembretes'
$action = New-ScheduledTaskAction -Execute $Php -Argument ('-f "{0}"' -f $script) -WorkingDirectory $Projeto
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 60) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
# Um ciclo por vez (IgnoreNew); limite de 30 minutos por ciclo.
$settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$definition = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "GreenSolutions PHP service: $nome (lembretes de não conformidades)"
Register-ScheduledTask -TaskName $nome -TaskPath $Pasta -InputObject $definition -Force | Out-Null

if ($ExecutarAgora) { Start-ScheduledTask -TaskPath $Pasta -TaskName $nome }
Get-ScheduledTask -TaskPath $Pasta -TaskName $nome | Select-Object TaskPath, TaskName, State | Format-Table -AutoSize
Write-Output "Tarefa $Pasta$nome registrada: a cada 60 minutos."
