[CmdletBinding()]
param([string]$Pasta='\GreenSolutions\',[string]$Projeto='E:\wamp\apps\greenportal',[string]$Php='E:\wamp\bin\php\php8.4.15\php.exe')
$ErrorActionPreference='Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Execute como Administrador.' }
$items = @(Get-ScheduledTask -TaskPath $Pasta -ErrorAction Stop | Where-Object TaskName -like 'GreenPortal-*')
$rows = foreach ($task in $items) { $info = Get-ScheduledTaskInfo -TaskPath $Pasta -TaskName $task.TaskName; [pscustomobject]@{ Name=$task.TaskName; State=$task.State; LastRun=$info.LastRunTime; LastResult=$info.LastTaskResult; NextRun=$info.NextRunTime; Ok=([int]$info.LastTaskResult -eq 0) } }
$rows | Sort-Object Name | Format-Table -AutoSize
if ($rows.Count -ne 11) { throw "Esperadas 11 tarefas GreenPortal, encontradas $($rows.Count)." }
if (@($rows | Where-Object { -not $_.Ok }).Count -gt 0) { throw 'Há tarefas com LastTaskResult diferente de 0.' }
$json = & $Php (Join-Path $Projeto 'scripts\validar-servicos-windows.php'); $json
if ($LASTEXITCODE -ne 0) { throw 'Validação de banco reprovada.' }
Write-Output 'Homologação do Agendador e dos três tenants aprovada.'
