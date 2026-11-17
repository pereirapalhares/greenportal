[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe',
    [string]$Pasta = '\GreenSolutions\',
    [string]$Database = '',
    [switch]$ExecutarAgora
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }
if (-not (Test-Path -LiteralPath $Projeto -PathType Container)) { throw "Projeto não localizado: $Projeto" }

$service = New-Object -ComObject 'Schedule.Service'
$service.Connect()
$root = $service.GetFolder('\')
$folderName = $Pasta.Trim('\')
try { $folder = $root.GetFolder($folderName) }
catch { $folder = $root.CreateFolder($folderName, $null) }

# Remove somente os dois nomes legados conhecidos, que criavam processos residentes.
foreach ($legacy in @('GreenPortalExportacaoGrid', 'GreenPortalInteligenciaArtificial')) {
    Unregister-ScheduledTask -TaskName $legacy -TaskPath '\' -Confirm:$false -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $legacy -TaskPath $Pasta -Confirm:$false -ErrorAction SilentlyContinue
}

$tasks = @(
    @{ Name = 'GreenPortal-TaskWorker'; Script = 'scripts\task-worker.php'; Minutes = 1 },
    @{ Name = 'GreenPortal-AgendaWorker'; Script = 'scripts\agenda-worker.php'; Minutes = 5 },
    @{ Name = 'GreenPortal-ResponsibilityWorker'; Script = 'scripts\responsibility-worker.php'; Minutes = 5 },
    @{ Name = 'GreenPortal-WorkflowWorker'; Script = 'scripts\workflow-worker.php'; Minutes = 10 },
    @{ Name = 'GreenPortal-ContentWorker'; Script = 'scripts\content-worker.php'; Minutes = 10 },
    @{ Name = 'GreenPortal-GedWorker'; Script = 'scripts\ged-worker.php'; Minutes = 10 },
    @{ Name = 'GreenPortal-IaWorker'; Script = 'scripts\ia-worker.php'; Minutes = 1 },
    @{ Name = 'GreenPortal-OcrWorker'; Script = 'scripts\ocr-worker.php'; Minutes = 1 },
    @{ Name = 'GreenPortal-NotificationWorker'; Script = 'scripts\notification-worker.php'; Minutes = 1 },
    @{ Name = 'GreenPortal-ExportWorker'; Script = 'scripts\export-worker.php'; Minutes = 1 },
    @{ Name = 'GreenPortal-ServiceMonitor'; Script = 'scripts\service-monitor.php'; Minutes = 5 },
    @{ Name = 'GreenPortal-BuscaWorker'; Script = 'scripts\busca-worker.php'; Minutes = 10 },
    @{ Name = 'GreenPortal-RankingFornecedores'; Script = 'scripts\fornecedor-ranking-worker.php'; Minutes = 15 }
)

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$results = @()
foreach ($item in $tasks) {
    $script = Join-Path $Projeto $item.Script
    if (-not (Test-Path -LiteralPath $script -PathType Leaf)) { throw "Worker não localizado: $script" }

    $argument = '-f "{0}"' -f $script
    if ($Database.Trim() -ne '') { $argument += ' --database={0}' -f $Database.Trim() }
    $action = New-ScheduledTaskAction -Execute $Php -Argument $argument -WorkingDirectory $Projeto
    $start = (Get-Date).AddMinutes(1)
    $trigger = New-ScheduledTaskTrigger -Once -At $start -RepetitionInterval (New-TimeSpan -Minutes ([int]$item.Minutes)) -RepetitionDuration (New-TimeSpan -Days 3650)
    # O monitor faz uma coleta pontual e deve encerrar antes do proximo disparo.
    $limitMinutes = if ($item.Name -eq 'GreenPortal-ServiceMonitor') { 2 } else { 30 }
    if ($item.Name -eq 'GreenPortal-ServiceMonitor') {
        # Sem RestartCount/RestartInterval: o XML do Agendador nao aceita contagem zero.
        $settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes $limitMinutes) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    } else {
        $settings = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes $limitMinutes) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    }
    $definition = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "GreenSolutions PHP service: $($item.Name)"
    if ($item.Name -eq 'GreenPortal-ServiceMonitor') {
        # Permite atualizar uma instância antiga que tenha ficado presa.
        Stop-ScheduledTask -TaskName $item.Name -TaskPath $Pasta -ErrorAction SilentlyContinue
        for ($wait = 0; $wait -lt 20; $wait++) {
            $running = $false
            try { $running = (Get-ScheduledTask -TaskName $item.Name -TaskPath $Pasta -ErrorAction Stop).State -eq 'Running' } catch {}
            if (-not $running) { break }
            Start-Sleep -Milliseconds 250
        }
    }
    Register-ScheduledTask -TaskName $item.Name -TaskPath $Pasta -InputObject $definition -Force | Out-Null
    if ($ExecutarAgora) { Start-ScheduledTask -TaskPath $Pasta -TaskName $item.Name }
    $results += [pscustomobject]@{ Name = $item.Name; Path = "$Pasta$($item.Name)"; Script = $script; EveryMinutes = $item.Minutes; ExecutionLimitMinutes = $limitMinutes; Status = 'REGISTERED' }
}

$results | Format-Table -AutoSize
Write-Output "Pasta: $Pasta"
Write-Output "Total de tarefas: $($results.Count)"
Write-Output 'Todas as tarefas usam o prefixo GreenPortal e executam PHP CLI oculto como SYSTEM, incluindo Excel, IA e monitoramento.'
