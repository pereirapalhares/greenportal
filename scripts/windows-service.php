<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';
require_once dirname(__DIR__) . '/classes/AgendaService.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityService.php';
require_once dirname(__DIR__) . '/classes/ResponsibilityAgendaService.php';
require_once dirname(__DIR__) . '/classes/OcrQueueService.php';
require_once dirname(__DIR__) . '/classes/DocumentAiQueueService.php';
require_once dirname(__DIR__) . '/classes/InteligenciaArtificialDocumento.php';
require_once dirname(__DIR__) . '/classes/AiApprovedTaskService.php';
require_once dirname(__DIR__) . '/classes/EmailFilaService.php';
require_once dirname(__DIR__) . '/classes/AlertaEmailService.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeEficaciaIa.php';
require_once dirname(__DIR__) . '/classes/IaTaximetro.php';

// Tempo máximo de uma execução (c584): no Windows o PHP conta o tempo real (inclusive esperando o banco ou a IA) e encerra sozinho
@set_time_limit(0);
$env = GreenPortalDatabase::env(); $service = ''; $database = null; $dry = false;
@set_time_limit(max(60, (int)($env['SERVICE_MAX_SECONDS'] ?? 1200)));
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--service=')) $service = strtolower(substr($arg, 10));
    if (str_starts_with($arg, '--database=')) $database = substr($arg, 11);
    if ($arg === '--dry-run') $dry = true;
}
$allowed = ['agenda','responsibility','workflow','content','ged','ia','ocr','notifications','email','nc','iaconsumo'];
if (!in_array($service, $allowed, true)) throw new InvalidArgumentException('Use --service=agenda|responsibility|workflow|content|ged|ia|ocr|notifications|email|nc|iaconsumo.');
$runtime = new WindowsServiceRuntime($env, $service, $dry);
$result = ['application'=>'GreenSolutions','version'=>'4.0.1','service'=>$service,'dry_run'=>$dry,'targets'=>[]];
// Migração 340: a cotação do dólar (PTAX do Banco Central) vale para todas as empresas: uma vez por execução, no universal
if ($service === 'iaconsumo' && !$dry) {
    try { $result['ptax'] = IaTaximetro::ptaxAtualizar(); } catch (Throwable $e) { $result['ptax'] = ['erro' => $e->getMessage()]; error_log('[iaconsumo] ' . $e->getMessage()); }
}
foreach ($runtime->databases($database) as $db) {
    $pdo = GreenPortalDatabase::connect($env, $db, 'GPT');
    $result['targets'][$db] = $runtime->run($pdo, $db, function(PDO $p) use ($service, $env, $db): int {
        if ($service === 'agenda') return (new AgendaService($p, new TaskService($p)))->materializeDueOccurrences(1);
        if ($service === 'responsibility') {
            $tasks = new TaskService($p);
            $agenda = new ResponsibilityAgendaService($p, new AgendaService($p, $tasks));
            $synchronized = $agenda->synchronizeFivePillars();
            $expiring = (new ResponsibilityService($p, $tasks))->enqueueExpiringTasks(30);
            // Migração 344: taxas das licenças — recorrentes pagas criam a próxima; alertas 30 dias antes e no dia do vencimento
            // Migração 361: avisos das regras de "Responsáveis e avisos" (atribuição, prazos e alteração por outra pessoa)
            return (int)$synchronized['criados_ou_atualizados'] + (int)$synchronized['cancelados'] + $expiring + licencaTaxas($p) + responsaveisAutomaticos($p) + responsaveisRegrasAvisos($p) + eficaciaAvisos($p) + situacaoLegalManutencao($p);
        }
        if ($service === 'workflow') return (int)$p->query("SELECT COUNT(*) FROM workflow_instancias WHERE status='ATIVA'")->fetchColumn();
        if ($service === 'content') return (int)$p->query("SELECT COUNT(*) FROM content_documents WHERE status='EM_REVISAO'")->fetchColumn();
        if ($service === 'ged') { $root=(string)($env['GGED_STORAGE_LOCAL_ROOT']??dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'); return is_dir($root)?1:0; }
        // A IA opera exclusivamente pela fila no Task Scheduler. O serviço HTTP legado não é mais iniciado.
        // Migração 338: pedidos de eficácia de NC que esperavam a leitura (OCR) das evidências entram na fila antes do processamento
        // Migração 347: documentos da homologação com modelo cadastrado — arquivos novos vão para a leitura (OCR) e, lidos, para a IA
        // Migração 349: análise de adulteração dos arquivos novos dos documentos da homologação (metadados, assinatura, imagem, conteúdo e IA)
        if ($service === 'ia') return NaoConformidadeEficaciaIa::prepararPendentes($p) + documentoModelos($p, $env, $db) + documentoAdulteracao($p, $env, $db) + (new DocumentAiQueueService($p))->processPending(3) + (new AiApprovedTaskService($p))->enqueueApproved(100);
        if ($service === 'ocr') return (new OcrQueueService($p, $env))->processPending(5);
        // Migração 333: fila de e-mails (avisos aos participantes externos das listas de NC); empresa sem a fila é ignorada
        // Migração 343: antes do envio, os alertas do portal viram e-mail (na hora ou no resumo das 7h, conforme a escolha de cada usuário)
        if ($service === 'email') { $gerados = 0; try { $gerados = (new AlertaEmailService($p, $env, $db))->gerar(); } catch (Throwable $e) { error_log('[alertas e-mail] ' . $db . ': ' . $e->getMessage()); } return $gerados + (new EmailFilaService($p, $env, $db))->processar(); }
        // Migração 339: lembretes de NC (regras de Administração › Configuração do módulo); empresa sem NC ou sem a 339 é ignorada
        if ($service === 'nc') return ncLembretes($p);
        // Migração 340: avisos do limite mensal de IA (80%, limite e bloqueio) aos ADM DA EMPRESA e ao ADMINISTRADOR; empresa sem a 340 é ignorada
        if ($service === 'iaconsumo') return iaLimiteAvisos($p);
        if ($service === 'notifications') {
            $tasks=new TaskService($p); $count=0;
            while ($count<20 && ($task=$tasks->claim('NOTIFICACAO'))!==null) {
                try {
                    $u=(int)($task['codusuario_destino']??0); if($u<=0) throw new RuntimeException('Tarefa sem usuário de destino.');
                    $q=$p->prepare("CALL sp_alerta_criar(:usuario,:titulo,:mensagem,:link,'LINK')");
                    $q->execute(['usuario'=>$u,'titulo'=>$task['titulo'],'mensagem'=>$task['descricao'],'link'=>'TASK:'.(int)$task['codtarefa']]); $q->closeCursor();
                    $tasks->complete((int)$task['codtarefa'],'Notificação processada pelo worker PHP.'); $count++;
                } catch(Throwable $e) { $tasks->fail((int)$task['codtarefa'],$e->getMessage()); }
            }
            return $count;
        }
        return 0;
    });
}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;

/**
 * Situação legal calculada (migração 284): uma vez por dia o cálculo completo; nas demais execuções, os requisitos que os
 * gatilhos das tabelas de gestão enfileiraram. Empresa sem a migração 284 é ignorada; erro aqui não interrompe as responsabilidades.
 */
function situacaoLegalManutencao(PDO $pdo): int
{
    try {
        $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_situacao_legal_manutencao'");
        $tem = (int)$s->fetchColumn() > 0;
        $s->closeCursor();
        if (!$tem) return 0;
        $st = $pdo->prepare('CALL sp_situacao_legal_manutencao(500)');
        $st->execute();
        $r = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return (int)($r[0]['requisitos'] ?? 0);
    } catch (Throwable $e) {
        error_log('[situacao-legal] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migração 344: taxas das licenças. Cria a próxima das recorrentes pagas (sp_li_taxas_gerar_proximas) e os alertas do vencimento
 * aos responsáveis da licença (sp_li_taxas_avisar: 30 dias antes e no dia; viram e-mail pelo serviço de e-mail). Empresa sem a
 * 344 é ignorada; erro aqui não interrompe as responsabilidades. Devolve quantas taxas e alertas foram criados.
 */
function licencaTaxas(PDO $p): int
{
    try {
        $s = $p->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_li_taxas_gerar_proximas', 'sp_li_taxas_avisar')");
        $tem = (int)$s->fetchColumn(); $s->closeCursor();
        if ($tem < 2) return 0;
        $n = 0;
        foreach (['CALL sp_li_taxas_gerar_proximas()' => 'GERADAS', 'CALL sp_li_taxas_avisar(0)' => 'ALERTAS'] as $sql => $col) {
            $st = $p->prepare($sql); $st->execute();
            do { try { foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $n += (int)($r[$col] ?? 0); } catch (Throwable) {} } while ($st->nextRowset());
            $st->closeCursor();
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[taxas das licenças] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migração 345: itens novos dos pilares sem responsável há mais de 1 hora recebem o responsável pelo perfil de atribuições
 * (quem executa o pilar na unidade, o menos carregado); sem ninguém no perfil, o ADM DA EMPRESA é avisado. Empresa sem a 345
 * é ignorada; erro aqui não interrompe as responsabilidades.
 */
function responsaveisAutomaticos(PDO $p): int
{
    try {
        require_once dirname(__DIR__) . '/classes/ResponsavelAutomatico.php';
        if (!ResponsavelAutomatico::disponivel($p)) return 0;
        return (new ResponsavelAutomatico($p))->processarItens();
    } catch (Throwable $e) {
        error_log('[responsável automático] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migração 362 (GER-5): alerta ao avaliador quando uma ação marcada "Avaliar eficácia?" chega a 100% (sp_eficacia_avisar;
 * um alerta por avaliador e módulo; o mesmo ciclo não é avisado de novo). Empresa sem a 362 é ignorada.
 */
function eficaciaAvisos(PDO $p): int
{
    try {
        $s = $p->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_eficacia_avisar'");
        $tem = (int)$s->fetchColumn() > 0; $s->closeCursor();
        if (!$tem) return 0;
        $st = $p->prepare('CALL sp_eficacia_avisar(0)'); $st->execute();
        $n = 0;
        do { try { foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $n += (int)($r['ALERTAS'] ?? 0); } catch (Throwable) {} } while ($st->nextRowset());
        $st->closeCursor();
        return $n;
    } catch (Throwable $e) {
        error_log('[eficácia dos planos] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migração 361: avisos das regras de Responsáveis e avisos (sp_resp_regras_avisar): um alerta por pessoa, módulo e tipo de aviso
 * (você passou a responder; vencimento em 30 dias; reação nos últimos 7 dias; medição prevista em 7 dias; alteração por outra
 * pessoa, pelo histórico da 359), sem repetir o mesmo aviso. Viram e-mail pelo serviço de e-mail (343). Empresa sem a 361 é
 * ignorada; erro aqui não interrompe as responsabilidades.
 */
function responsaveisRegrasAvisos(PDO $p): int
{
    try {
        $s = $p->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_resp_regras_avisar'");
        $tem = (int)$s->fetchColumn() > 0; $s->closeCursor();
        if (!$tem) return 0;
        $st = $p->prepare('CALL sp_resp_regras_avisar(0)'); $st->execute();
        $n = 0;
        do { try { foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $n += (int)($r['ALERTAS'] ?? 0); } catch (Throwable) {} } while ($st->nextRowset());
        $st->closeCursor();
        return $n;
    } catch (Throwable $e) {
        error_log('[responsáveis por regra] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migração 347: validação dos documentos da homologação pelo modelo do critério (varredura a cada 5 minutos dos documentos
 * "Pendente com a empresa" com arquivo novo; leitura pronta vai para a fila de IA). Empresa sem a 347 é ignorada; erro aqui não para a fila.
 */
function documentoModelos(PDO $p, array $env, string $db): int
{
    try {
        require_once dirname(__DIR__) . '/classes/HomologacaoDocumentoModelo.php';
        return HomologacaoDocumentoModelo::processar($p, $env, $db);
    } catch (Throwable $e) {
        error_log('[modelos de documento] ' . $db . ': ' . $e->getMessage());
        return 0;
    }
}

/**
 * Migração 349: análise de adulteração dos documentos da homologação (varredura a cada 10 minutos das pastas que mudaram; leitura
 * pronta passa pelas verificações e vai para a fila de IA). Empresa sem a 349 é ignorada; erro aqui não para a fila.
 */
function documentoAdulteracao(PDO $p, array $env, string $db): int
{
    try {
        require_once dirname(__DIR__) . '/classes/HomologacaoDocumentoAdulteracao.php';
        return HomologacaoDocumentoAdulteracao::processar($p, $env, $db);
    } catch (Throwable $e) {
        error_log('[adulteração] ' . $db . ': ' . $e->getMessage());
        return 0;
    }
}

/** Migração 339: aplica as regras de lembrete ligadas (sp_nc_lembretes_processar); devolve quantos lembretes foram enviados. */
function ncLembretes(PDO $p): int
{
    $tem = (int)$p->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_nc_lembretes_processar'")->fetchColumn();
    if (!$tem) return 0;
    $s = $p->prepare('CALL sp_nc_lembretes_processar(0)'); $s->execute();
    $n = 0; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $n += (int)($r['LEMBRETES'] ?? 0) + (int)($r['SUPERIORES'] ?? 0);
    while ($s->nextRowset()) {} $s->closeCursor();
    return $n;
}

/** Migração 340: envia o aviso do limite de IA do mês quando a empresa passa de uma faixa (sp_ia_limite_avisar); devolve quantos avisos. */
function iaLimiteAvisos(PDO $p): int
{
    $tem = (int)$p->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_ia_limite_avisar'")->fetchColumn();
    if (!$tem) return 0;
    $s = $p->prepare('CALL sp_ia_limite_avisar(0)'); $s->execute();
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    while ($s->nextRowset()) {} $s->closeCursor();
    return (int)($r['DESTINATARIOS'] ?? 0);
}
