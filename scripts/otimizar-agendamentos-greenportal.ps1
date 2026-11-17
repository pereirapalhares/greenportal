[CmdletBinding()]
param([switch]$Aplicar)
<#
  Otimização das tarefas do GreenPortal no Agendador do Windows (análise de 28/10/2026).
  Sem -Aplicar: só mostra o que mudaria (nada é alterado).
  Com -Aplicar: guarda antes uma cópia (XML) de cada tarefa em storage\runtime\agendamentos_backup_<data>\ e então
  desativa as tarefas repetidas e ajusta a frequência e o limite de tempo das demais.
  Voltar uma tarefa: Register-ScheduledTask -TaskName <nome> -TaskPath <pasta> -Xml (Get-Content <arquivo.xml> -Raw) -User SYSTEM -Force
  Uso (PowerShell como Administrador):
    powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\otimizar-agendamentos-greenportal.ps1
    powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\otimizar-agendamentos-greenportal.ps1 -Aplicar
#>
$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}

# Nome = tarefa; Desativar = tarefa repetida; Intervalo = nova repetição; Limite = novo tempo máximo de uma execução
$mudancas = @(
    @{ Nome = 'GreenPortalTarefas';               Desativar = $true; Motivo = 'repete a GreenPortal-TaskWorker; o .vbs inicia o PHP sem esperar o fim, então o Agendador não controla as cópias (empilharam 15)' },
    @{ Nome = 'GreenPortal-TaskWorker';           Desativar = $true; Motivo = 'repete o que Agenda, Responsibility e Notification já fazem, a cada minuto e sem trava' },
    @{ Nome = 'GreenPortal-Migracao-Worker';      Intervalo = 'PT5M';  Motivo = 'pedidos de migração são raros; hoje abre cmd + 3 PHP por minuto' },
    @{ Nome = 'GreenPortal-SincronismoWorker';    Intervalo = 'PT5M';  Motivo = 'o ciclo só confere a fila; um pedido espera no máximo 5 min' },
    @{ Nome = 'GreenPortal-IaWorker';             Intervalo = 'PT2M';  Limite = 'PT15M'; Motivo = 'fila assíncrona; metade dos disparos' },
    @{ Nome = 'GreenPortal-OcrWorker';            Intervalo = 'PT2M';  Limite = 'PT15M'; Motivo = 'fila assíncrona; metade dos disparos' },
    @{ Nome = 'GreenPortal-NotificationWorker';   Intervalo = 'PT2M';  Limite = 'PT10M'; Motivo = 'alertas internos; 2 min é suficiente' },
    @{ Nome = 'GreenPortal-EmailWorker';          Intervalo = 'PT5M';  Limite = 'PT10M'; Motivo = 'SMTP ainda não configurado; voltar a 2 min quando ligar o envio' },
    @{ Nome = 'GreenPortal-AgendaWorker';                              Limite = 'PT10M'; Motivo = 'execução normal leva segundos' },
    @{ Nome = 'GreenPortal-ResponsibilityWorker';                      Limite = 'PT10M'; Motivo = 'execução normal leva segundos' },
    @{ Nome = 'GreenPortal-WorkflowWorker';       Intervalo = 'PT1H';  Limite = 'PT5M';  Motivo = 'só conta registros para o painel de serviços' },
    @{ Nome = 'GreenPortal-ContentWorker';        Intervalo = 'PT1H';  Limite = 'PT5M';  Motivo = 'só conta registros para o painel de serviços' },
    @{ Nome = 'GreenPortal-GedWorker';            Intervalo = 'PT1H';  Limite = 'PT5M';  Motivo = 'só confere se a pasta do GED existe' }
)

$backup = $null
if ($Aplicar) {
    $backup = Join-Path 'E:\wamp\apps\greenportal\storage\runtime' ('agendamentos_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
    New-Item -ItemType Directory -Path $backup -Force | Out-Null
}
$saida = @()
foreach ($m in $mudancas) {
    $t = Get-ScheduledTask | Where-Object { $_.TaskName -eq $m.Nome } | Select-Object -First 1
    if (-not $t) { $saida += [pscustomobject]@{ Tarefa = $m.Nome; Antes = '-'; Depois = '-'; Resultado = 'NAO_ENCONTRADA' }; continue }
    $antes = 'estado=' + $t.State + ' repete=' + $t.Triggers[0].Repetition.Interval + ' limite=' + $t.Settings.ExecutionTimeLimit
    if ($m.Desativar) { $depois = 'DESATIVADA' }
    else {
        $depois = 'repete=' + $(if ($m.Intervalo) { $m.Intervalo } else { $t.Triggers[0].Repetition.Interval }) + ' limite=' + $(if ($m.Limite) { $m.Limite } else { $t.Settings.ExecutionTimeLimit })
    }
    $resultado = 'SIMULADO'
    if ($Aplicar) {
        try {
            Export-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-File -FilePath (Join-Path $backup ($t.TaskName + '.xml')) -Encoding Unicode
            if ($m.Desativar) {
                Disable-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-Null
            } else {
                if ($m.Intervalo) { foreach ($g in $t.Triggers) { if ($g.Repetition -and $g.Repetition.Interval) { $g.Repetition.Interval = $m.Intervalo } } }
                if ($m.Limite) { $t.Settings.ExecutionTimeLimit = $m.Limite }
                Set-ScheduledTask -InputObject $t | Out-Null
            }
            $resultado = 'APLICADO'
        } catch { $resultado = 'ERRO: ' + $_.Exception.Message }
    }
    $saida += [pscustomobject]@{ Tarefa = $m.Nome; Antes = $antes; Depois = $depois; Resultado = $resultado; Motivo = $m.Motivo }
}
$saida | Format-Table Tarefa, Antes, Depois, Resultado -AutoSize -Wrap
if ($Aplicar) { "Copia das tarefas antes da mudanca: $backup" } else { 'Nada foi alterado. Para aplicar, rode de novo com -Aplicar.' }
