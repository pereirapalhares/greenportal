[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe',
    [string]$Pasta = '\GreenSolutions\',
    [switch]$ExecutarAgora
)

# Cria (ou atualiza) a tarefa do serviço do sincronismo legado -> greenportal (migração 305):
#   \GreenSolutions\GreenPortal-SincronismoWorker -> php scripts\sincronismo-worker.php a cada minuto, como SYSTEM, oculto.
# O serviço roda o sincronismo diário no horário configurado em Administração › Migração › Sincronismo e os pedidos
# feitos na tela (simular, aplicar, validar). Uma execução por vez; enquanto uma roda, o Agendador não abre outra.
# Desativa a tarefa antiga que rodava scripts\sincronizar-fo-legado.bat às 05:00 (o horário agora fica na tela).
# Requer a migração 305.
# Uso (PowerShell como Administrador):
#   powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\criar-agendamento-sincronismo.ps1

$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }
$script = Join-Path $Projeto 'scripts\sincronismo-worker.php'
if (-not (Test-Path -LiteralPath $script -PathType Leaf)) { throw "Worker não localizado: $script" }

$service = New-Object -ComObject 'Schedule.Service'
$service.Connect()
$root = $service.GetFolder('\')
try { $null = $root.GetFolder($Pasta.Trim('\')) } catch { $null = $root.CreateFolder($Pasta.Trim('\'), $null) }

$nome = 'GreenPortal-SincronismoWorker'
$action = New-ScheduledTaskAction -Execute $Php -Argument ('-f "{0}"' -f $script) -WorkingDirectory $Projeto
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
# O sincronismo de todas as empresas pode levar horas: limite de 12 horas por ciclo; IgnoreNew = um ciclo por vez.
$settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 12) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$definition = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "GreenSolutions PHP service: $nome (sincronismo legado -> greenportal)"
Register-ScheduledTask -TaskName $nome -TaskPath $Pasta -InputObject $definition -Force | Out-Null

# Tarefa antiga (sincronizar-fo-legado.bat às 05:00): desativada para não rodar duas vezes
$antigas = Get-ScheduledTask | Where-Object { $_.TaskName -ne $nome -and ($_.Actions | Where-Object { ("$($_.Execute) $($_.Arguments)") -match 'sincronizar-fo-legado' }) }
foreach ($t in $antigas) {
    Disable-ScheduledTask -TaskPath $t.TaskPath -TaskName $t.TaskName | Out-Null
    Write-Output "Tarefa antiga desativada: $($t.TaskPath)$($t.TaskName)"
}
if (-not $antigas) { Write-Output 'Nenhuma tarefa antiga do sincronizar-fo-legado.bat encontrada.' }

if ($ExecutarAgora) { Start-ScheduledTask -TaskPath $Pasta -TaskName $nome }
Get-ScheduledTask -TaskPath $Pasta -TaskName $nome | Select-Object TaskPath, TaskName, State | Format-Table -AutoSize
Write-Output "Tarefa $Pasta$nome registrada: a cada minuto. O horário do sincronismo diário fica em Administração › Migração › Sincronismo."
