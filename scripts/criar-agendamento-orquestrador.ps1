[CmdletBinding()]
param(
    [switch]$Ativar,
    [switch]$Voltar,
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [string]$Php = 'E:\wamp\bin\php\php8.4.15\php.exe'
)
<#
  Orquestrador dos serviços (migração 352): 3 tarefas no Agendador, uma por trilha.
    sem opção : cria ou atualiza as tarefas GreenPortal-Orquestrador-T1/T2/T3 e o vigia (GreenPortal-Orquestrador-Vigia, migração 353,
                a cada 10 min: avisa os ADMINISTRADORES quando uma trilha para, um serviço ou uma empresa é suspensa). As trilhas ficam
                no modo em que estão (começam em SIMULAR) e as tarefas antigas continuam funcionando normalmente.
    -Ativar   : guarda uma cópia (XML) das tarefas antigas, desativa-as e passa as trilhas para EXECUTAR.
    -Voltar   : passa as trilhas para SIMULAR e reativa as tarefas antigas.
  Uso (PowerShell como Administrador):
    powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\criar-agendamento-orquestrador.ps1 [-Ativar | -Voltar]
#>
$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw "PHP não localizado: $Php" }
$orq = Join-Path $Projeto 'scripts\orquestrador.php'
if (-not (Test-Path -LiteralPath $orq -PathType Leaf)) { throw "Orquestrador não localizado: $orq" }
$vigia = Join-Path $Projeto 'scripts\orquestrador-vigia.php'

$pasta = '\GreenSolutions\'
# Tarefas que o orquestrador substitui (o Backup continua desativado; no orquestrador ele também começa desligado)
$antigas = @('GreenPortal-AgendaWorker','GreenPortal-BuscaWorker','GreenPortal-ContentWorker','GreenPortal-DocumentosHomologacao',
    'GreenPortal-EmailWorker','GreenPortal-ExportWorker','GreenPortal-GedWorker','GreenPortal-IaConsumo','GreenPortal-IaWorker',
    'GreenPortal-Migracao-Worker','GreenPortal-NcLembretes','GreenPortal-NotificationWorker','GreenPortal-OcrWorker',
    'GreenPortal-ProtocoloHomologacao','GreenPortal-RankingFornecedores','GreenPortal-RelatoriosHomologacao',
    'GreenPortal-ResponsibilityWorker','GreenPortal-ResumoHomologacao','GreenPortal-ServiceMonitor','GreenPortal-SincronismoWorker',
    'GreenPortal-VisualizadorWorker','GreenPortal-WorkflowWorker')

function Definir-Modo([string]$modo) {
    $saida = & $Php -f $orq -- "--modo=$modo" 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Não foi possível mudar o modo das trilhas: $saida" }
    "Trilhas em $modo."
}

if ($Ativar -and $Voltar) { throw 'Use -Ativar ou -Voltar, não os dois.' }

if ($Voltar) {
    Definir-Modo 'SIMULAR'
    foreach ($n in $antigas) {
        $t = Get-ScheduledTask | Where-Object { $_.TaskName -eq $n } | Select-Object -First 1
        if ($t) { Enable-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-Null; "reativada: $n" } else { "não encontrada: $n" }
    }
    'Pronto: as tarefas antigas voltaram a rodar; o orquestrador só registra (SIMULAR).'
    return
}

if ($Ativar) {
    $copia = Join-Path $Projeto ('storage\runtime\agendamentos_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
    New-Item -ItemType Directory -Path $copia -Force | Out-Null
    foreach ($n in 1..3) {
        if (-not (Get-ScheduledTask | Where-Object { $_.TaskName -eq "GreenPortal-Orquestrador-T$n" })) { throw "A tarefa GreenPortal-Orquestrador-T$n não existe: rode este script primeiro sem -Ativar." }
    }
    foreach ($n in $antigas) {
        $t = Get-ScheduledTask | Where-Object { $_.TaskName -eq $n } | Select-Object -First 1
        if (-not $t) { "não encontrada: $n"; continue }
        Export-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-File -FilePath (Join-Path $copia ($t.TaskName + '.xml')) -Encoding Unicode
        Disable-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-Null
        "desativada: $n"
    }
    Definir-Modo 'EXECUTAR'
    "Cópia das tarefas antigas: $copia"
    'Pronto: o orquestrador passou a rodar os serviços. Para voltar: -Voltar.'
    return
}

# Cria ou atualiza as 3 tarefas do orquestrador (o "--" é obrigatório: sem ele o php.exe trata --trilha como opção dele e não roda o script)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$trilhas = @(
    @{ N = 1; Minutos = 1; Limite = (New-TimeSpan -Hours 1);  Nome = 'rápida: Exportação Excel e Visualizador' },
    @{ N = 2; Minutos = 1; Limite = (New-TimeSpan -Hours 3);  Nome = 'filas e regras: OCR, IA, Agenda, Responsabilidades, Notificações, E-mail...' },
    @{ N = 3; Minutos = 5; Limite = (New-TimeSpan -Days 3);   Nome = 'pesada: Sincronismo, Busca, Ranking, Migração, Backup' }
)
foreach ($t in $trilhas) {
    $nome = "GreenPortal-Orquestrador-T$($t.N)"
    $acao = New-ScheduledTaskAction -Execute $Php -Argument ('-f "{0}" -- --trilha={1}' -f $orq, $t.N) -WorkingDirectory $Projeto
    $gatilho = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes $t.Minutos) -RepetitionDuration (New-TimeSpan -Days 3650)
    $config = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit $t.Limite -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    $def = New-ScheduledTask -Action $acao -Trigger $gatilho -Principal $principal -Settings $config -Description "GreenPortal orquestrador, trilha $($t.N) ($($t.Nome))"
    Register-ScheduledTask -TaskName $nome -TaskPath $pasta -InputObject $def -Force | Out-Null
    "registrada: $pasta$nome (a cada $($t.Minutos) min)"
}
# Vigia (migração 353): tarefa própria, fora das trilhas, para continuar avisando quando uma trilha parar
if (Test-Path -LiteralPath $vigia -PathType Leaf) {
    $acao = New-ScheduledTaskAction -Execute $Php -Argument ('-f "{0}"' -f $vigia) -WorkingDirectory $Projeto
    $gatilho = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(2)) -RepetitionInterval (New-TimeSpan -Minutes 10) -RepetitionDuration (New-TimeSpan -Days 3650)
    $config = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    $def = New-ScheduledTask -Action $acao -Trigger $gatilho -Principal $principal -Settings $config -Description 'GreenPortal orquestrador: vigia das trilhas (avisa os administradores quando um serviço para)'
    Register-ScheduledTask -TaskName 'GreenPortal-Orquestrador-Vigia' -TaskPath $pasta -InputObject $def -Force | Out-Null
    "registrada: $($pasta)GreenPortal-Orquestrador-Vigia (a cada 10 min)"
} else { "vigia não encontrado ($vigia): tarefa do vigia não criada" }
'As trilhas continuam no modo em que estão (começam em SIMULAR). Confira: php scripts\orquestrador.php --situacao'
'Quando estiver tudo certo: rode de novo com -Ativar.'
