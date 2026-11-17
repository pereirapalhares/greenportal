[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe',
    [string]$Pasta = '\GreenSolutions\',
    [switch]$Corrigir,
    [switch]$ExecutarMonitor
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Projeto -PathType Container)) { throw "Projeto não localizado: $Projeto" }
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }

$creator = Join-Path $Projeto 'scripts\criar-agendamentos-greenportal.ps1'
if (-not (Test-Path -LiteralPath $creator -PathType Leaf)) { throw "Script de criação não localizado: $creator" }

$tasks = @(
    @{ Name = 'GreenPortal-TaskWorker'; Script = 'scripts\task-worker.php' },
    @{ Name = 'GreenPortal-AgendaWorker'; Script = 'scripts\agenda-worker.php' },
    @{ Name = 'GreenPortal-ResponsibilityWorker'; Script = 'scripts\responsibility-worker.php' },
    @{ Name = 'GreenPortal-WorkflowWorker'; Script = 'scripts\workflow-worker.php' },
    @{ Name = 'GreenPortal-ContentWorker'; Script = 'scripts\content-worker.php' },
    @{ Name = 'GreenPortal-GedWorker'; Script = 'scripts\ged-worker.php' },
    @{ Name = 'GreenPortal-IaWorker'; Script = 'scripts\ia-worker.php' },
    @{ Name = 'GreenPortal-OcrWorker'; Script = 'scripts\ocr-worker.php' },
    @{ Name = 'GreenPortal-NotificationWorker'; Script = 'scripts\notification-worker.php' },
    @{ Name = 'GreenPortal-ExportWorker'; Script = 'scripts\export-worker.php' },
    @{ Name = 'GreenPortal-ServiceMonitor'; Script = 'scripts\service-monitor.php' }
)

function Get-TaskCheck {
    param([hashtable]$Definition)
    $issues = [System.Collections.Generic.List[string]]::new()
    $task = $null; $info = $null
    try {
        $task = Get-ScheduledTask -TaskPath $Pasta -TaskName $Definition.Name -ErrorAction Stop
        $info = Get-ScheduledTaskInfo -TaskPath $Pasta -TaskName $Definition.Name -ErrorAction Stop
    } catch {
        $issues.Add('TAREFA_NAO_LOCALIZADA')
    }

    if ($null -ne $task) {
        $principal = [string]$task.Principal.UserId
        $logonType = [string]$task.Principal.LogonType
        $runLevel = [string]$task.Principal.RunLevel
        if ($principal -notin @('SYSTEM', 'S-1-5-18')) { $issues.Add("USUARIO=$principal") }
        if ($logonType -ne 'ServiceAccount') { $issues.Add("LOGON_TYPE=$logonType") }
        if ($runLevel -ne 'Highest') { $issues.Add("RUN_LEVEL=$runLevel") }
        $action = @($task.Actions) | Select-Object -First 1
        if ($null -eq $action -or [string]$action.Execute -ne $Php) { $issues.Add('PHP_ACAO_INCORRETO') }
        if ($null -eq $action -or [string]$action.Arguments -notlike ('*' + $Definition.Script + '*')) { $issues.Add('SCRIPT_ACAO_INCORRETO') }
    }

    [pscustomobject]@{
        Name = $Definition.Name
        Path = "$Pasta$($Definition.Name)"
        State = if ($task) { [string]$task.State } else { 'N/A' }
        UserId = if ($task) { [string]$task.Principal.UserId } else { 'N/A' }
        LogonType = if ($task) { [string]$task.Principal.LogonType } else { 'N/A' }
        RunLevel = if ($task) { [string]$task.Principal.RunLevel } else { 'N/A' }
        LastTaskResult = if ($info) { [int64]$info.LastTaskResult } else { -1 }
        LastRunTime = if ($info) { $info.LastRunTime } else { $null }
        NextRunTime = if ($info) { $info.NextRunTime } else { $null }
        Status = if ($issues.Count -eq 0) { 'OK' } else { 'CORRIGIR' }
        Issues = if ($issues.Count -eq 0) { '' } else { $issues -join '; ' }
    }
}

function Invoke-Checks {
    $items = foreach ($definition in $tasks) { Get-TaskCheck -Definition $definition }
    return @($items)
}

$serviceStatus = Get-Service -Name Schedule -ErrorAction Stop
if ($serviceStatus.Status -ne 'Running') {
    if ($Corrigir) { Start-Service -Name Schedule; $serviceStatus = Get-Service -Name Schedule }
    else { throw 'O serviço Agendador de Tarefas (Schedule) não está em execução.' }
}

$before = Invoke-Checks
$problems = @($before | Where-Object Status -ne 'OK')
if ($Corrigir -and $problems.Count -gt 0) {
    Write-Output 'Problemas encontrados. Recriando as tarefas com SYSTEM...'
    & powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $creator -Projeto $Projeto -Php $Php
    if ($LASTEXITCODE -ne 0) { throw "O script de criação terminou com código $LASTEXITCODE." }
    Start-Sleep -Seconds 2
}

$after = Invoke-Checks
if ($ExecutarMonitor) {
    Start-ScheduledTask -TaskPath $Pasta -TaskName 'GreenPortal-ServiceMonitor'
    Start-Sleep -Seconds 5
    $after = Invoke-Checks
}

$after | Format-Table Name,State,UserId,LogonType,RunLevel,LastTaskResult,Status,Issues -AutoSize
if (@($after | Where-Object Status -ne 'OK').Count -gt 0) {
    Write-Output 'Há tarefas que ainda precisam de correção.'
    exit 1
}
Write-Output 'Todas as tarefas GreenPortal estão configuradas para executar como SYSTEM.'
