<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AgendadorWindows.php';

// Execucao unica; o intervalo de cinco minutos pertence ao Agendador.
set_time_limit(150);

$root = dirname(__DIR__); $env = GreenPortalDatabase::env(); $taskPath = '\\GreenSolutions\\';
$runtimeDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0770, true);
$lock = @fopen($runtimeDir . DIRECTORY_SEPARATOR . 'green-service-monitor.lock', 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
$started = date('Y-m-d H:i:s'); $central = null; $status = 'CONCLUIDA'; $message = ''; $taskCount = 0; $queueCount = 0;

function monitorTenant(array $env, string $database): PDO { if (!preg_match('/^g[a-z0-9]+_dbp$/i', $database)) throw new InvalidArgumentException('Tenant inválido.'); return GreenPortalDatabase::connect($env, $database, 'GPT'); }
function monitorCall(PDO $pdo, string $sql, array $params = []): array { $s=$pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();return $rows; }
function monitorValue(mixed $value): ?string { $value=trim((string)$value);return $value===''?null:$value; }

try {
    $central = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
    // Migração 353: com o orquestrador, as tarefas do Windows são as 3 trilhas e o vigia (as 22 antigas ficam desativadas).
    // A situação vai para orq_trilhas / orq_vigia, que a tela Administração › Serviços mostra.
    try {
        $taskRows = AgendadorWindows::situacao(array_keys(AgendadorWindows::TAREFAS_ORQUESTRADOR), $taskPath);
        $orqTarefa = $central->prepare('CALL sp_orq_tarefa_windows(?, ?, ?, ?, ?)');
        foreach (AgendadorWindows::TAREFAS_ORQUESTRADOR as $taskName => $trilha) {
            $row = $taskRows[$taskName] ?? null;
            $orqTarefa->execute([$trilha, $row ? (string)$row['State'] : 'NAO_INSTALADO', $row ? (int)$row['LastTaskResult'] : null, $row['LastRunTime'] ?? null, $row['NextRunTime'] ?? null]);
            $orqTarefa->closeCursor();
            $taskCount++;
        }
    } catch (Throwable $taskError) {
        // Sem acesso ao Agendador as filas continuam sendo coletadas; o erro fica no histórico do monitor
        $message = 'Agendador: ' . $taskError->getMessage();
    }
    $central->exec('DELETE FROM servicos_fila_status WHERE coletado_em < DATE_SUB(NOW(), INTERVAL 2 DAY)');
    $central->exec('DELETE FROM servicos_exportacoes_cache WHERE coletado_em < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $companies = $central->query("SELECT nome,banco_dados FROM empresa WHERE status='ATIVA' AND banco_dados IS NOT NULL AND banco_dados<>'' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $queueUpsert = $central->prepare("INSERT INTO servicos_fila_status (codigo_servico,banco_dados,tipo,pendentes,processando,concluidas,erros,coletado_em) VALUES (:codigo,:db,:tipo,:pending,:processing,:done,:errors,NOW()) ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),pendentes=VALUES(pendentes),processando=VALUES(processando),concluidas=VALUES(concluidas),erros=VALUES(erros),coletado_em=VALUES(coletado_em)");
    $exportUpsert = $central->prepare("INSERT INTO servicos_exportacoes_cache (banco_dados,codexportacaogrid,empresa_nome,solicitado_em,concluido_em,usuario_nome,nome_planilha,status,coletado_em) VALUES (:db,:id,:empresa,:requested,:completed,:user,:name,:status,NOW()) ON DUPLICATE KEY UPDATE empresa_nome=VALUES(empresa_nome),solicitado_em=VALUES(solicitado_em),concluido_em=VALUES(concluido_em),usuario_nome=VALUES(usuario_nome),nome_planilha=VALUES(nome_planilha),status=VALUES(status),coletado_em=VALUES(coletado_em)");
    foreach ($companies as $company) {
        $db = (string)$company['banco_dados']; try { $tenant = monitorTenant($env, $db); } catch (Throwable) { continue; }
        foreach ([['codigo'=>'EXPORTACAO_GRID','tipo'=>'Exportação Excel','table'=>'exportacoes_grid'],['codigo'=>'INTELIGENCIA_ARTIFICIAL','tipo'=>'Inteligência Artificial','table'=>'ml_potenciais_solicitacoes'],['codigo'=>'EMAIL_WORKER','tipo'=>'E-mails','table'=>'email_fila']] as $queue) {
            try { $row = $tenant->query("SELECT SUM(status IN ('SOLICITADA','PENDENTE')) pendentes,SUM(status='PROCESSANDO') processando,SUM(status IN ('CONCLUIDA','CONCLUIDO')) concluidas,SUM(status IN ('ERRO','FALHA')) erros FROM `{$queue['table']}`")->fetch(PDO::FETCH_ASSOC) ?: []; $queueUpsert->execute(['codigo'=>$queue['codigo'],'db'=>$db,'tipo'=>$queue['tipo'],'pending'=>(int)($row['pendentes']??0),'processing'=>(int)($row['processando']??0),'done'=>(int)($row['concluidas']??0),'errors'=>(int)($row['erros']??0)]); $queueCount++; } catch (Throwable) {}
        }
        try { $rows = $tenant->query("SELECT e.codexportacaogrid,e.solicitado_em,e.concluido_em,e.arquivo,e.status,COALESCE(NULLIF(u.nome,''),CONCAT('Usuário #',e.codusuario)) usuario_nome,COALESCE(NULLIF(e.arquivo,''),e.transacao) nome_planilha FROM exportacoes_grid e LEFT JOIN usuarios u ON u.codusuario=e.codusuario ORDER BY e.solicitado_em DESC,e.codexportacaogrid DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC); foreach ($rows as $row) $exportUpsert->execute(['db'=>$db,'id'=>(int)$row['codexportacaogrid'],'empresa'=>$company['nome'],'requested'=>monitorValue($row['solicitado_em']??null),'completed'=>monitorValue($row['concluido_em']??null),'user'=>$row['usuario_nome']??null,'name'=>$row['nome_planilha']??null,'status'=>$row['status']??null]); } catch (Throwable) {}
    }
} catch (Throwable $error) {
    $status = 'ERRO'; $message = $error->getMessage();
}
finally {
    if ($central instanceof PDO) { $q=$central->prepare('INSERT INTO servicos_monitoramento_execucoes (inicio_em,fim_em,status,tarefas,filas,mensagem) VALUES (:inicio,NOW(),:status,:tarefas,:filas,:mensagem)'); $q->execute(['inicio'=>$started,'status'=>$status,'tarefas'=>$taskCount,'filas'=>$queueCount,'mensagem'=>$message ?: null]); }
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
}
echo json_encode(['ok'=>$status === 'CONCLUIDA','status'=>$status,'tarefas'=>$taskCount,'filas'=>$queueCount,'mensagem'=>$message], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($status === 'CONCLUIDA' ? 0 : 1);
