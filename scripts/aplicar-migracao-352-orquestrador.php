<?php
declare(strict_types=1);

/*
 * Migração 352 — Orquestrador dos serviços (decisão do usuário de 28/10/2026).
 *
 * Em vez de ~20 tarefas soltas no Agendador do Windows, 3 tarefas (uma por trilha) rodam scripts/orquestrador.php, que chama
 * os serviços na ordem e com controle contra travamento:
 *   Trilha 1 — rápida (o usuário está esperando): Exportação Excel → Visualizador
 *   Trilha 2 — filas e regras: OCR → IA → Agenda → Responsabilidades → Notificações → E-mail → lembretes, consumo de IA,
 *              documentos e protocolo da homologação, monitor
 *   Trilha 3 — pesada, um por vez: Sincronismo → Busca → Ranking → Migração de empresas → Backup
 *
 * Universal:
 *   - orq_trilhas (modo SIMULAR/EXECUTAR, sinal de vida), orq_servicos (cadastro: trilha, ordem, frequência, horário, dias,
 *     janela, tempo máximo; situação da última execução; falhas seguidas e suspensão) e orq_execucoes (histórico, 30 dias);
 *   - procedures sp_orq_* e a view vw_orq_servicos.
 * As trilhas começam em SIMULAR: o orquestrador só registra o que rodaria. scripts\criar-agendamento-orquestrador.ps1 -Ativar
 * passa para EXECUTAR e desativa as tarefas antigas (com cópia para voltar).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-352-orquestrador.php [--simular]
 * Pode ser executada mais de uma vez: o cadastro gravado (frequências e ordem ajustadas) não é sobrescrito.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '352', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => []];

$ddl = [];
$ddl['orq_trilhas'] = "CREATE TABLE IF NOT EXISTS orq_trilhas (
 trilha TINYINT UNSIGNED NOT NULL, nome VARCHAR(80) NOT NULL, ativo TINYINT(1) NOT NULL DEFAULT 1, modo VARCHAR(10) NOT NULL DEFAULT 'SIMULAR',
 pid INT UNSIGNED NULL, ciclo_inicio DATETIME NULL, ciclo_fim DATETIME NULL, batimento_em DATETIME NULL, servico_atual VARCHAR(40) NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(trilha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$ddl['orq_servicos'] = "CREATE TABLE IF NOT EXISTS orq_servicos (
 codigo VARCHAR(40) NOT NULL, nome VARCHAR(120) NOT NULL, trilha TINYINT UNSIGNED NOT NULL, ordem INT NOT NULL DEFAULT 100,
 ativo TINYINT(1) NOT NULL DEFAULT 1, script VARCHAR(200) NOT NULL, argumentos VARCHAR(300) NULL,
 intervalo_min INT NOT NULL DEFAULT 5, horario TIME NULL, dias_semana VARCHAR(20) NULL, janela_inicio TIME NULL, janela_fim TIME NULL,
 limite_seg INT NOT NULL DEFAULT 600, descricao VARCHAR(500) NULL,
 ultima_inicio DATETIME NULL, ultima_fim DATETIME NULL, ultima_status VARCHAR(20) NULL, ultima_duracao_seg INT NULL, ultima_mensagem VARCHAR(1000) NULL,
 falhas_seguidas INT NOT NULL DEFAULT 0, suspenso_ate DATETIME NULL, codexecucao_atual BIGINT UNSIGNED NULL, pid_atual INT UNSIGNED NULL,
 atualizado_por BIGINT UNSIGNED NULL, atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(codigo), KEY ix_orq_servicos_trilha(trilha, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$ddl['orq_execucoes'] = "CREATE TABLE IF NOT EXISTS orq_execucoes (
 codexecucao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo VARCHAR(40) NOT NULL, trilha TINYINT UNSIGNED NOT NULL,
 inicio_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, fim_em DATETIME NULL, duracao_seg INT NULL, status VARCHAR(20) NOT NULL,
 codigo_saida INT NULL, pid INT UNSIGNED NULL, mensagem VARCHAR(2000) NULL,
 PRIMARY KEY(codexecucao), KEY ix_orq_exec_servico(codigo, inicio_em), KEY ix_orq_exec_status(trilha, status), KEY ix_orq_exec_inicio(inicio_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$ddl['trilhas_iniciais'] = "INSERT IGNORE INTO orq_trilhas (trilha, nome, modo) VALUES
 (1, 'Rápida (usuário esperando)', 'SIMULAR'), (2, 'Filas e regras', 'SIMULAR'), (3, 'Pesada (um por vez)', 'SIMULAR')";

// codigo, nome, trilha, ordem, ativo, script, argumentos, intervalo (min), horário, dias (1=segunda … 7=domingo), limite (s), descrição
$servicos = [
    ['EXPORTACAO_GRID', 'Exportação Excel', 1, 10, 1, 'scripts/export-worker.php', null, 1, null, null, 1800, 'Gera as planilhas pedidas nos grids.'],
    ['VISUALIZADOR', 'Visualizador de arquivos', 1, 20, 1, 'scripts/visualizador-worker.php', null, 1, null, null, 300, 'Converte arquivos para a prévia (atende a fila por ~50 s).'],
    ['OCR_WORKER', 'Leitura de documentos (OCR)', 2, 10, 1, 'scripts/ocr-worker.php', null, 2, null, null, 600, 'Fila de OCR; roda antes da IA, que usa o texto lido.'],
    ['INTELIGENCIA_ARTIFICIAL', 'Inteligência Artificial', 2, 20, 1, 'scripts/ia-worker.php', null, 2, null, null, 900, 'Eficácia de NC, modelos e adulteração de documentos, fila de IA.'],
    ['AGENDA_WORKER', 'Agenda', 2, 30, 1, 'scripts/agenda-worker.php', null, 5, null, null, 300, 'Materializa as ocorrências recorrentes.'],
    ['RESPONSIBILITY_WORKER', 'Responsabilidades', 2, 40, 1, 'scripts/responsibility-worker.php', null, 5, null, null, 600, 'Cinco pilares, vencimentos, taxas, responsáveis automáticos, situação legal.'],
    ['NOTIFICATION_WORKER', 'Notificações', 2, 50, 1, 'scripts/notification-worker.php', null, 2, null, null, 300, 'Entrega as notificações (alertas) geradas acima.'],
    ['EMAIL_WORKER', 'E-mails', 2, 60, 1, 'scripts/email-worker.php', null, 5, null, null, 600, 'Transforma alertas em e-mail e envia a fila (SMTP do .env).'],
    ['NC_LEMBRETES', 'Lembretes de NC', 2, 70, 1, 'scripts/nc-lembretes-worker.php', null, 60, null, null, 600, 'Regras de lembrete das Não Conformidades.'],
    ['IA_CONSUMO', 'Taxímetro de IA', 2, 80, 1, 'scripts/ia-consumo-worker.php', null, 30, null, null, 600, 'PTAX do dia e avisos do limite mensal de IA.'],
    ['DOCUMENTOS_HOMOLOGACAO', 'Documentos da homologação', 2, 90, 1, 'scripts/homologacao-documentos-worker.php', null, 60, null, null, 1200, 'Regras automáticas dos documentos de fornecedores.'],
    ['PROTOCOLO_HOMOLOGACAO', 'Protocolo da homologação', 2, 100, 1, 'scripts/homologacao-protocolo-worker.php', null, 1440, '06:40:00', null, 1200, 'Documentos com protocolo vencido voltam para o fornecedor (diário).'],
    ['RESUMO_HOMOLOGACAO', 'Resumo semanal da homologação', 2, 110, 1, 'scripts/homologacao-resumo-worker.php', null, 1440, '07:10:00', '1', 1200, 'Resumo semanal aos responsáveis (segunda-feira).'],
    ['RELATORIOS_HOMOLOGACAO', 'Relatórios da homologação', 2, 120, 1, 'scripts/homologacao-relatorios-worker.php', null, 1440, '07:20:00', '1', 1800, 'Relatórios semanais (segunda-feira).'],
    ['SERVICE_MONITOR', 'Monitor de serviços', 2, 130, 1, 'scripts/service-monitor.php', null, 5, null, null, 120, 'Situação das tarefas do Windows para a tela Serviços.'],
    ['WORKFLOW_WORKER', 'Workflow', 2, 140, 1, 'scripts/workflow-worker.php', null, 60, null, null, 120, 'Conta as instâncias ativas (painel).'],
    ['CONTENT_WORKER', 'Conteúdo', 2, 150, 1, 'scripts/content-worker.php', null, 60, null, null, 120, 'Conta os conteúdos em revisão (painel).'],
    ['GED_WORKER', 'GED', 2, 160, 1, 'scripts/ged-worker.php', null, 60, null, null, 120, 'Confere a pasta do GED (painel).'],
    ['SINCRONISMO_WORKER', 'Sincronismo legado → greenportal', 3, 10, 1, 'scripts/sincronismo-worker.php', null, 5, null, null, 43200, 'Fila do sincronismo e o agendamento diário; pode levar horas.'],
    ['BUSCA_WORKER', 'Indexação da pesquisa', 3, 20, 1, 'scripts/busca-worker.php', null, 10, null, null, 3600, 'Índice da Pesquisa de documentos e busca pelo sentido; depois do sincronismo.'],
    ['FORNECEDOR_RANKING', 'Ranking de fornecedores', 3, 30, 1, 'scripts/fornecedor-ranking-worker.php', null, 15, null, null, 1800, 'Recalcula as notas quando pedido e uma vez por dia.'],
    ['MIGRACAO_PROMPTS', 'Migração: prompts de IA', 3, 40, 1, 'scripts/migracao-prompts-worker.php', null, 5, null, null, 1800, 'Parte da migração de empresas.'],
    ['MIGRACAO_EMPRESAS', 'Migração de empresas', 3, 41, 1, 'scripts/migracao-worker.php', null, 5, null, null, 259200, 'Pedidos de Administração › Migração de empresas.'],
    ['MIGRACAO_RELATORIO', 'Migração: relatório de prompts', 3, 42, 1, 'scripts/migracao-relatorio-prompts.php', null, 5, null, null, 1800, 'Parte da migração de empresas.'],
    ['BACKUP_WORKER', 'Backup e retenção', 3, 90, 0, 'scripts/backup-worker.php', null, 15, null, null, 82800, 'Backup no horário de Administração › Backup (desligado até definir o destino).'],
];

$sp = [];
$sp['sp_orq_ciclo_iniciar'] = "CREATE PROCEDURE sp_orq_ciclo_iniciar(IN p_trilha TINYINT, IN p_pid INT) BEGIN
 UPDATE orq_trilhas SET pid = p_pid, ciclo_inicio = NOW(), batimento_em = NOW(), servico_atual = NULL WHERE trilha = p_trilha;
 SELECT trilha, nome, ativo, modo FROM orq_trilhas WHERE trilha = p_trilha; END";
$sp['sp_orq_ciclo_fim'] = "CREATE PROCEDURE sp_orq_ciclo_fim(IN p_trilha TINYINT) BEGIN
 UPDATE orq_trilhas SET ciclo_fim = NOW(), batimento_em = NOW(), servico_atual = NULL WHERE trilha = p_trilha; END";
$sp['sp_orq_batimento'] = "CREATE PROCEDURE sp_orq_batimento(IN p_trilha TINYINT, IN p_servico VARCHAR(40)) BEGIN
 UPDATE orq_trilhas SET batimento_em = NOW(), servico_atual = p_servico WHERE trilha = p_trilha; END";
// Só é chamada com a trava da trilha: o que ficou EXECUTANDO é de um ciclo que morreu (o processo filho pode estar vivo)
$sp['sp_orq_orfaos'] = "CREATE PROCEDURE sp_orq_orfaos(IN p_trilha TINYINT) BEGIN
 SELECT codexecucao, codigo, pid, inicio_em FROM orq_execucoes WHERE trilha = p_trilha AND status = 'EXECUTANDO' ORDER BY codexecucao; END";
$sp['sp_orq_devidos'] = "CREATE PROCEDURE sp_orq_devidos(IN p_trilha TINYINT) BEGIN
 SELECT s.codigo, s.nome, s.script, s.argumentos, s.limite_seg, s.intervalo_min, s.horario FROM orq_servicos s
  WHERE s.trilha = p_trilha AND s.ativo = 1 AND (s.suspenso_ate IS NULL OR s.suspenso_ate <= NOW())
    AND (s.dias_semana IS NULL OR s.dias_semana = '' OR FIND_IN_SET(WEEKDAY(NOW()) + 1, s.dias_semana) > 0)
    AND (s.janela_inicio IS NULL OR s.janela_fim IS NULL
         OR (s.janela_inicio <= s.janela_fim AND CURTIME() BETWEEN s.janela_inicio AND s.janela_fim)
         OR (s.janela_inicio > s.janela_fim AND (CURTIME() >= s.janela_inicio OR CURTIME() <= s.janela_fim)))
    AND ((s.horario IS NULL AND (s.ultima_inicio IS NULL OR s.ultima_inicio + INTERVAL s.intervalo_min MINUTE <= NOW() + INTERVAL 20 SECOND))
      OR (s.horario IS NOT NULL AND CURTIME() >= s.horario AND (s.ultima_inicio IS NULL OR DATE(s.ultima_inicio) < CURDATE())))
  ORDER BY s.ordem, s.codigo; END";
$sp['sp_orq_execucao_iniciar'] = "CREATE PROCEDURE sp_orq_execucao_iniciar(IN p_codigo VARCHAR(40), IN p_trilha TINYINT, IN p_simulada TINYINT) BEGIN
 DECLARE v_id BIGINT UNSIGNED;
 INSERT INTO orq_execucoes (codigo, trilha, status) VALUES (p_codigo, p_trilha, IF(p_simulada = 1, 'SIMULADA', 'EXECUTANDO'));
 SET v_id = LAST_INSERT_ID();
 UPDATE orq_servicos SET ultima_inicio = NOW(), codexecucao_atual = IF(p_simulada = 1, NULL, v_id), pid_atual = NULL WHERE codigo = p_codigo;
 UPDATE orq_trilhas SET batimento_em = NOW(), servico_atual = p_codigo WHERE trilha = p_trilha;
 SELECT v_id AS codexecucao; END";
$sp['sp_orq_execucao_pid'] = "CREATE PROCEDURE sp_orq_execucao_pid(IN p_id BIGINT UNSIGNED, IN p_pid INT) BEGIN
 UPDATE orq_execucoes SET pid = p_pid WHERE codexecucao = p_id;
 UPDATE orq_servicos SET pid_atual = p_pid WHERE codexecucao_atual = p_id; END";
// Falha (erro, tempo esgotado ou abandonada) 3 vezes seguidas: o serviço fica suspenso por 30 minutos; um sucesso zera a contagem.
// (No UPDATE de uma tabela o MySQL avalia as atribuições em ordem: suspenso_ate já vê falhas_seguidas atualizado.)
$sp['sp_orq_execucao_finalizar'] = "CREATE PROCEDURE sp_orq_execucao_finalizar(IN p_id BIGINT UNSIGNED, IN p_status VARCHAR(20), IN p_saida INT, IN p_mensagem VARCHAR(2000)) BEGIN
 DECLARE v_cod VARCHAR(40) DEFAULT NULL; DECLARE v_falha TINYINT;
 SET v_falha = IF(p_status IN ('ERRO', 'TEMPO_ESGOTADO', 'ABANDONADA'), 1, 0);
 UPDATE orq_execucoes SET fim_em = NOW(), duracao_seg = TIMESTAMPDIFF(SECOND, inicio_em, NOW()), status = p_status, codigo_saida = p_saida,
        mensagem = LEFT(p_mensagem, 2000) WHERE codexecucao = p_id;
 SELECT codigo INTO v_cod FROM orq_execucoes WHERE codexecucao = p_id;
 UPDATE orq_servicos SET ultima_fim = NOW(), ultima_status = p_status,
        ultima_duracao_seg = (SELECT duracao_seg FROM orq_execucoes WHERE codexecucao = p_id), ultima_mensagem = LEFT(p_mensagem, 1000),
        codexecucao_atual = IF(codexecucao_atual = p_id, NULL, codexecucao_atual), pid_atual = IF(codexecucao_atual IS NULL, NULL, pid_atual),
        falhas_seguidas = IF(v_falha = 1, falhas_seguidas + 1, IF(p_status IN ('CONCLUIDA', 'SIMULADA'), 0, falhas_seguidas)),
        suspenso_ate = IF(v_falha = 1 AND falhas_seguidas >= 3, NOW() + INTERVAL 30 MINUTE, suspenso_ate)
  WHERE codigo = v_cod;
 SELECT codigo, falhas_seguidas, suspenso_ate FROM orq_servicos WHERE codigo = v_cod; END";
$sp['sp_orq_modo'] = "CREATE PROCEDURE sp_orq_modo(IN p_trilha TINYINT, IN p_modo VARCHAR(10)) BEGIN
 IF p_modo NOT IN ('SIMULAR', 'EXECUTAR') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo inválido (SIMULAR ou EXECUTAR)'; END IF;
 UPDATE orq_trilhas SET modo = p_modo WHERE p_trilha = 0 OR trilha = p_trilha;
 SELECT trilha, nome, ativo, modo FROM orq_trilhas ORDER BY trilha; END";
$sp['sp_orq_limpar'] = "CREATE PROCEDURE sp_orq_limpar(IN p_dias INT) BEGIN
 DELETE FROM orq_execucoes WHERE inicio_em < NOW() - INTERVAL GREATEST(p_dias, 7) DAY LIMIT 20000;
 SELECT ROW_COUNT() AS removidas; END";
$sp['sp_orq_situacao'] = "CREATE PROCEDURE sp_orq_situacao() BEGIN
 SELECT * FROM vw_orq_servicos ORDER BY trilha, ordem, codigo; END";
$sp['sp_orq_execucoes_listar'] = "CREATE PROCEDURE sp_orq_execucoes_listar(IN p_codigo VARCHAR(40), IN p_limite INT) BEGIN
 SELECT codexecucao, codigo, trilha, inicio_em, fim_em, duracao_seg, status, codigo_saida, pid, mensagem FROM orq_execucoes
  WHERE (p_codigo IS NULL OR p_codigo = '' OR codigo = p_codigo) ORDER BY codexecucao DESC LIMIT p_limite; END";
// Para a tela Administração › Serviços: só o que pode ser ajustado (script e argumentos ficam com a migração)
$sp['sp_orq_servico_alterar'] = "CREATE PROCEDURE sp_orq_servico_alterar(IN p_codigo VARCHAR(40), IN p_ativo TINYINT, IN p_trilha TINYINT, IN p_ordem INT,
 IN p_intervalo INT, IN p_horario VARCHAR(5), IN p_dias VARCHAR(20), IN p_limite INT, IN p_usuario BIGINT) BEGIN
 IF NOT EXISTS (SELECT 1 FROM orq_servicos WHERE codigo = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Serviço não encontrado'; END IF;
 IF p_trilha NOT IN (1, 2, 3) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trilha: 1, 2 ou 3'; END IF;
 IF p_intervalo IS NULL OR p_intervalo < 1 OR p_intervalo > 10080 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Frequência: de 1 a 10080 minutos'; END IF;
 IF p_limite IS NULL OR p_limite < 30 OR p_limite > 259200 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tempo máximo: de 30 segundos a 72 horas'; END IF;
 IF p_horario IS NOT NULL AND p_horario <> '' AND p_horario NOT REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Horário inválido (use HH:MM)'; END IF;
 IF p_dias IS NOT NULL AND p_dias <> '' AND p_dias NOT REGEXP '^[1-7](,[1-7])*$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dias: números de 1 (segunda) a 7 (domingo) separados por vírgula'; END IF;
 UPDATE orq_servicos SET ativo = IF(p_ativo = 1, 1, 0), trilha = p_trilha, ordem = COALESCE(p_ordem, ordem), intervalo_min = p_intervalo,
        horario = IF(p_horario IS NULL OR p_horario = '', NULL, CAST(CONCAT(p_horario, ':00') AS TIME)), dias_semana = NULLIF(p_dias, ''),
        limite_seg = p_limite, suspenso_ate = NULL, falhas_seguidas = 0, atualizado_por = p_usuario WHERE codigo = p_codigo;
 SELECT * FROM vw_orq_servicos WHERE codigo = p_codigo; END";

$view = "CREATE OR REPLACE VIEW vw_orq_servicos AS
 SELECT s.codigo, s.nome, s.trilha, t.nome AS trilha_nome, t.modo AS trilha_modo, s.ordem, s.ativo, s.script, s.argumentos, s.intervalo_min,
        TIME_FORMAT(s.horario, '%H:%i') AS horario, s.dias_semana, s.janela_inicio, s.janela_fim, s.limite_seg, s.descricao,
        s.ultima_inicio, s.ultima_fim, s.ultima_status, s.ultima_duracao_seg, s.ultima_mensagem, s.falhas_seguidas, s.suspenso_ate,
        s.codexecucao_atual, s.pid_atual, t.batimento_em AS trilha_batimento_em, t.servico_atual AS trilha_servico_atual,
        TIMESTAMPDIFF(SECOND, t.batimento_em, NOW()) AS trilha_segundos_sem_sinal
   FROM orq_servicos s JOIN orq_trilhas t ON t.trilha = s.trilha";

try {
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->exec('SET NAMES utf8mb4');
    foreach ($ddl as $nome => $sql) {
        if ($simular) { $rel['universal'][$nome] = 'será gravado'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    $ins = $simular ? null : $u->prepare('INSERT IGNORE INTO orq_servicos (codigo, nome, trilha, ordem, ativo, script, argumentos, intervalo_min, horario, dias_semana, limite_seg, descricao)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $novos = 0;
    foreach ($servicos as $s) {
        if (!is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $s[5]))) $rel['avisos'][] = "script não encontrado: {$s[5]} ({$s[0]})";
        if ($ins) { $ins->execute($s); $novos += $ins->rowCount(); }
    }
    $rel['universal']['servicos'] = $simular ? count($servicos) . ' serviços no cadastro' : "$novos incluído(s); " . (count($servicos) - $novos) . ' já existiam (mantidos)';
    if ($simular) $rel['universal']['vw_orq_servicos'] = 'será gravada'; else { $u->exec($view); $rel['universal']['vw_orq_servicos'] = 'ok'; }
    foreach ($sp as $nome => $sql) {
        if ($simular) { $rel['universal'][$nome] = 'será gravada'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    if (!$simular) {
        $st = $u->query('SELECT trilha, nome, modo FROM orq_trilhas ORDER BY trilha');
        $rel['trilhas'] = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
