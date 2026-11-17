[CmdletBinding()]
param([string]$TaskPath='\GreenSolutions\')

$ErrorActionPreference='Stop'
$expected = @{
  'GreenPortal-TaskWorker' = 'scripts\task-worker.php'
  'GreenPortal-AgendaWorker' = 'scripts\agenda-worker.php'
  'GreenPortal-ResponsibilityWorker' = 'scripts\responsibility-worker.php'
  'GreenPortal-WorkflowWorker' = 'scripts\workflow-worker.php'
  'GreenPortal-ContentWorker' = 'scripts\content-worker.php'
  'GreenPortal-GedWorker' = 'scripts\ged-worker.php'
  'GreenPortal-ExportWorker' = 'scripts\export-worker.php'
  'GreenPortal-RankingFornecedores' = 'scripts\fornecedor-ranking-worker.php'
  'GreenPortal-BuscaWorker' = 'scripts\busca-worker.php'
  'GreenPortal-OcrWorker' = 'scripts\ocr-worker.php'
  'GreenPortal-IaWorker' = 'scripts\ia-worker.php'
  'GreenPortal-NotificationWorker' = 'scripts\notification-worker.php'
  'GreenPortal-ServiceMonitor' = 'scripts\service-monitor.php'
}
$legacy = @('GreenPortalExportacaoGrid','GreenPortalInteligenciaArtificial')
$result = [ordered]@{ ok=$true; task_path=$TaskPath; workers=@(); legacy_found=@(); errors=@() }
foreach($name in $expected.Keys){
  try {
    $task = Get-ScheduledTask -TaskName $name -TaskPath $TaskPath -ErrorAction Stop
    $actions = @($task.Actions | ForEach-Object { "$(($_.Execute)) $(($_.Arguments))" }) -join ' | '
    $expectedScript = $expected[$name]
    $found = $actions -match [regex]::Escape($expectedScript)
    $result.workers += [pscustomobject]@{ name=$name; state=[string]$task.State; action=$actions; valid=$found }
    if(-not $found){ $result.ok=$false; $result.errors += "A tarefa $name não aponta para $expectedScript." }
  } catch { $result.ok=$false; $result.errors += "Tarefa ausente ou inacessível: $name ($($_.Exception.Message))" }
}
foreach($name in $legacy){
  foreach($path in @('\', $TaskPath)){
    try { $task=Get-ScheduledTask -TaskName $name -TaskPath $path -ErrorAction Stop; $result.legacy_found += "$path$name"; $result.ok=$false } catch {}
  }
}
$result | ConvertTo-Json -Depth 5
if(-not $result.ok){ exit 1 }
