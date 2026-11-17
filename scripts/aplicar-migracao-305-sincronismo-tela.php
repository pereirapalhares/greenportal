<?php
declare(strict_types=1);

/*
 * Migração 305 — Administração › Migração (aprovada pelo usuário em 18/10/2026).
 *
 *   Administração
 *     Migração                    (grupo novo, sem tela)
 *       Migração geral            ADMINISTRACAO_EMPRESAS_MIGRACAO  (antes "Empresas e migração")
 *       Sincronismo               ADMINISTRACAO_SINCRONISMO        (tela nova: principal/administracao_sincronismo.php)
 *       Menus de migração         ADMINISTRACAO_MIGRACAO_MENUS
 *
 * No guniversal_dbp cria:
 *   sincronismo_config      horário único do sincronismo diário (todas as empresas) e se está ligado;
 *   sincronismo_execucoes   fila e histórico das execuções (agendadas e pedidas na tela: simular, aplicar, validar);
 *   views e procedures sp_sinc_* usadas pela tela e pelo serviço scripts/sincronismo-worker.php
 *   (\GreenSolutions\GreenPortal-SincronismoWorker no Agendador do Windows, a cada minuto: scripts/criar-agendamento-sincronismo.ps1).
 * Regra do usuário: "Rodar agora › Aplicar" só a partir de uma simulação concluída nas últimas 24 horas e ainda não aplicada.
 * Revisão c566 (decisões do usuário de 24/10/2026):
 *   - empresa com empresa_migracao_config.sincronismo_ativo = 0 fica fora do sincronismo (ex.: Goodyear Americana, legado congelado);
 *     sincronismo_config.respeitar_ativo = 1 liga a regra (0 volta ao comportamento anterior). --simular lista quem sai.
 *     --manter=codigo1,codigo2 marca sincronismo_ativo = 1 nessas empresas antes de ligar a regra;
 *   - o agendamento diário simula primeiro (agendado_simula = 1); terminada a simulação, o serviço aplica se o total de exclusões
 *     ficar até limite_exclusoes (padrão 500); acima disso para e avisa o ADMINISTRADOR (alerta), que revisa e aplica pela tela.
 * O menu é gravado no universal e em todo banco de cliente que tem o item ADMINISTRACAO_EMPRESAS_MIGRACAO (mesmo codmenu).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-305-sincronismo-tela.php [--simular] [--manter=crown,atlas]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

const M305_RAIZ = 'ADMINISTRACAO';
const M305_GRUPO = 'ADMINISTRACAO_MIGRACAO';
// código => [ordem, pt, en, es, ícone, url (null = mantém a atual)]
const M305_ITENS = [
    'ADMINISTRACAO_EMPRESAS_MIGRACAO' => [10, 'Migração geral', 'General migration', 'Migración general', 'bi-database-add', null],
    'ADMINISTRACAO_SINCRONISMO' => [20, 'Sincronismo', 'Synchronization', 'Sincronización', 'bi-arrow-repeat', '/principal/administracao_sincronismo.php'],
    'ADMINISTRACAO_MIGRACAO_MENUS' => [30, 'Menus de migração', 'Migration menus', 'Menús de migración', 'bi-diagram-3', null],
];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$relatorio = ['ok' => true, 'migracao' => '305', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'menus' => []];

$u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$u->exec('SET NAMES utf8mb4');

// ------------------------------------------------------------------ 1. estrutura no universal
$ddl = [];
$ddl['sincronismo_config'] = "CREATE TABLE IF NOT EXISTS sincronismo_config (
 codconfig TINYINT UNSIGNED NOT NULL DEFAULT 1, ativo TINYINT(1) NOT NULL DEFAULT 1, horario TIME NOT NULL DEFAULT '05:00:00',
 ultima_agendada DATE NULL, worker_ultimo_ciclo_em DATETIME NULL, atualizado_por BIGINT UNSIGNED NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(codconfig)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
$ddl['sincronismo_execucoes'] = "CREATE TABLE IF NOT EXISTS sincronismo_execucoes (
 codexecucao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, origem VARCHAR(10) NOT NULL DEFAULT 'MANUAL', modo VARCHAR(10) NOT NULL,
 empresa VARCHAR(60) NULL, codusuario BIGINT UNSIGNED NULL, usuario_nome VARCHAR(200) NULL, codsimulacao BIGINT UNSIGNED NULL,
 status VARCHAR(25) NOT NULL DEFAULT 'NA_FILA', solicitado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, iniciado_em DATETIME NULL,
 finalizado_em DATETIME NULL, relatorio VARCHAR(500) NULL, resumo JSON NULL, erro TEXT NULL,
 PRIMARY KEY(codexecucao), KEY ix_sinc_exec_status(status, solicitado_em), KEY ix_sinc_exec_simulacao(codsimulacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
// Revisão c566: colunas novas da configuração (a tabela pode ter sido criada antes da revisão)
$ddl['config_colunas'] = '';
// Primeira gravação: 05:00 (horário do agendamento antigo). Se hoje já passou das 05:00, a próxima é amanhã
// (o agendamento antigo já rodou hoje).
$ddl['config_inicial'] = "INSERT IGNORE INTO sincronismo_config (codconfig, ativo, horario, ultima_agendada) VALUES (1, 1, '05:00:00', IF(CURTIME() >= '05:00:00', CURDATE(), NULL))";
// Empresas que entram no sincronismo (mesma regra de scripts/sincronizar-fo-legado.php::paresMigrados: ativa, banco novo
// existente e última solicitação de migração finalizada com sucesso, ou nenhuma). O código é o valor de --empresa=.
$ddl['vw_sincronismo_empresas'] = "CREATE OR REPLACE VIEW vw_sincronismo_empresas AS
 SELECT e.codempresa, LOWER(e.codigo) AS codigo, e.nome, e.banco_dados
   FROM empresa e
  WHERE e.status = 'ATIVA' AND e.banco_dados IS NOT NULL
    AND EXISTS (SELECT 1 FROM information_schema.SCHEMATA s WHERE s.SCHEMA_NAME = e.banco_dados)
    AND COALESCE((SELECT m.status FROM migracao_solicitacoes m WHERE m.banco_destino = e.banco_dados ORDER BY m.codsolicitacao DESC LIMIT 1), 'FINALIZADO_COM_SUCESSO') = 'FINALIZADO_COM_SUCESSO'
    AND NOT (COALESCE((SELECT c.respeitar_ativo FROM sincronismo_config c WHERE c.codconfig = 1), 0) = 1
             AND EXISTS (SELECT 1 FROM empresa_migracao_config x WHERE x.codempresa = e.codempresa AND x.sincronismo_ativo = 0))";
$ddl['vw_sincronismo_execucoes'] = "CREATE OR REPLACE VIEW vw_sincronismo_execucoes AS
 SELECT x.codexecucao, x.origem, x.modo, x.empresa, x.codusuario, x.usuario_nome, x.codsimulacao, x.status, x.solicitado_em,
        x.iniciado_em, x.finalizado_em, x.relatorio, x.resumo, x.erro,
        (SELECT MIN(e.nome) FROM empresa e WHERE LOWER(e.codigo) = x.empresa) AS empresa_nome,
        IF(x.iniciado_em IS NULL, NULL, TIMESTAMPDIFF(SECOND, x.iniciado_em, COALESCE(x.finalizado_em, NOW()))) AS duracao_segundos,
        (SELECT MIN(a.codexecucao) FROM sincronismo_execucoes a WHERE a.codsimulacao = x.codexecucao AND a.status <> 'ERRO' AND a.status <> 'CANCELADA') AS aplicada_por,
        (x.modo = 'SIMULAR' AND x.status IN ('CONCLUIDA', 'CONCLUIDA_COM_ERRO') AND x.finalizado_em >= NOW() - INTERVAL 24 HOUR
         AND NOT EXISTS (SELECT 1 FROM sincronismo_execucoes a WHERE a.codsimulacao = x.codexecucao AND a.status <> 'ERRO' AND a.status <> 'CANCELADA')) AS pode_aplicar
   FROM sincronismo_execucoes x";

$sp = [];
$sp['sp_sinc_config_obter'] = "CREATE PROCEDURE sp_sinc_config_obter() BEGIN
 SELECT c.ativo, TIME_FORMAT(c.horario, '%H:%i') AS horario, c.ultima_agendada, c.worker_ultimo_ciclo_em, c.atualizado_em,
        TIMESTAMPDIFF(SECOND, c.worker_ultimo_ciclo_em, NOW()) AS worker_segundos,
        CASE WHEN c.ativo = 0 THEN NULL
             WHEN COALESCE(c.ultima_agendada, '1000-01-01') < CURDATE() AND CURTIME() < c.horario THEN TIMESTAMP(CURDATE(), c.horario)
             WHEN COALESCE(c.ultima_agendada, '1000-01-01') < CURDATE() THEN NOW()
             ELSE TIMESTAMP(CURDATE() + INTERVAL 1 DAY, c.horario) END AS proxima_execucao
   FROM sincronismo_config c WHERE c.codconfig = 1; END";
// Horário depois de agora: roda hoje nesse horário; já passou: a próxima é amanhã.
$sp['sp_sinc_config_salvar'] = "CREATE PROCEDURE sp_sinc_config_salvar(IN p_ativo TINYINT, IN p_horario VARCHAR(5), IN p_usuario BIGINT) BEGIN
 DECLARE v_h TIME;
 IF p_horario IS NULL OR p_horario NOT REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Horário inválido (use HH:MM)'; END IF;
 SET v_h = CAST(CONCAT(p_horario, ':00') AS TIME);
 INSERT IGNORE INTO sincronismo_config (codconfig) VALUES (1);
 UPDATE sincronismo_config SET ativo = IF(p_ativo = 1, 1, 0), horario = v_h, atualizado_por = p_usuario,
        ultima_agendada = IF(v_h > CURTIME(), CURDATE() - INTERVAL 1 DAY, CURDATE()) WHERE codconfig = 1;
 CALL sp_sinc_config_obter(); END";
$sp['sp_sinc_empresas_listar'] = "CREATE PROCEDURE sp_sinc_empresas_listar() BEGIN SELECT codempresa, codigo, nome, banco_dados FROM vw_sincronismo_empresas ORDER BY nome; END";
$sp['sp_sinc_execucoes_listar'] = "CREATE PROCEDURE sp_sinc_execucoes_listar(IN p_limite INT) BEGIN SELECT * FROM vw_sincronismo_execucoes ORDER BY codexecucao DESC LIMIT p_limite; END";
$sp['sp_sinc_execucao_obter'] = "CREATE PROCEDURE sp_sinc_execucao_obter(IN p_id BIGINT) BEGIN SELECT * FROM vw_sincronismo_execucoes WHERE codexecucao = p_id; END";
$sp['sp_sinc_execucao_solicitar'] = "CREATE PROCEDURE sp_sinc_execucao_solicitar(IN p_modo VARCHAR(10), IN p_empresa VARCHAR(60), IN p_usuario BIGINT, IN p_usuario_nome VARCHAR(200), IN p_simulacao BIGINT) BEGIN
 DECLARE v_empresa VARCHAR(60) DEFAULT NULLIF(LOWER(TRIM(p_empresa)), '');
 DECLARE v_sim BIGINT DEFAULT NULL;
 IF p_modo NOT IN ('SIMULAR', 'VALIDAR', 'APLICAR') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo inválido'; END IF;
 IF p_modo = 'APLICAR' THEN
   IF NOT EXISTS (SELECT 1 FROM vw_sincronismo_execucoes WHERE codexecucao = p_simulacao AND pode_aplicar = 1) THEN
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para aplicar, selecione uma simulação concluída nas últimas 24 horas e ainda não aplicada';
   END IF;
   SELECT empresa INTO v_empresa FROM sincronismo_execucoes WHERE codexecucao = p_simulacao;
   SET v_sim = p_simulacao;
 ELSEIF v_empresa IS NOT NULL AND NOT EXISTS (SELECT 1 FROM vw_sincronismo_empresas WHERE codigo = v_empresa) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Empresa fora do sincronismo (sem migração concluída)';
 END IF;
 IF EXISTS (SELECT 1 FROM sincronismo_execucoes WHERE status = 'NA_FILA' AND modo = p_modo AND empresa <=> v_empresa) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um pedido igual na fila';
 END IF;
 INSERT INTO sincronismo_execucoes (origem, modo, empresa, codusuario, usuario_nome, codsimulacao) VALUES ('MANUAL', p_modo, v_empresa, p_usuario, p_usuario_nome, v_sim);
 SELECT LAST_INSERT_ID() AS codexecucao; END";
$sp['sp_sinc_execucao_cancelar'] = "CREATE PROCEDURE sp_sinc_execucao_cancelar(IN p_id BIGINT, IN p_usuario BIGINT) BEGIN
 UPDATE sincronismo_execucoes SET status = 'CANCELADA', finalizado_em = NOW(), erro = 'Cancelado pelo usuário antes de começar' WHERE codexecucao = p_id AND status = 'NA_FILA';
 IF ROW_COUNT() = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só é possível cancelar pedido que ainda está na fila'; END IF;
 SELECT p_id AS codexecucao; END";
// Serviço: marca o ciclo (a tela avisa se o serviço parou) e, na hora agendada, coloca o sincronismo do dia na fila.
$sp['sp_sinc_worker_agendar'] = "CREATE PROCEDURE sp_sinc_worker_agendar() BEGIN
 INSERT IGNORE INTO sincronismo_config (codconfig) VALUES (1);
 UPDATE sincronismo_config SET worker_ultimo_ciclo_em = NOW(), atualizado_em = atualizado_em WHERE codconfig = 1;
 IF EXISTS (SELECT 1 FROM sincronismo_config WHERE codconfig = 1 AND ativo = 1 AND CURTIME() >= horario AND COALESCE(ultima_agendada, '1000-01-01') < CURDATE()) THEN
   UPDATE sincronismo_config SET ultima_agendada = CURDATE(), atualizado_em = atualizado_em WHERE codconfig = 1;
   INSERT INTO sincronismo_execucoes (origem, modo, empresa, usuario_nome)
   SELECT 'AGENDADO', IF(COALESCE(c.agendado_simula, 0) = 1, 'SIMULAR', 'APLICAR'), NULL, 'Agendamento diário' FROM sincronismo_config c WHERE c.codconfig = 1;
   SELECT LAST_INSERT_ID() AS codexecucao;
 END IF; END";
// Só é chamada com o bloqueio do serviço (GET_LOCK): execução que ficou "em execução" é de um serviço que parou.
$sp['sp_sinc_worker_reivindicar'] = "CREATE PROCEDURE sp_sinc_worker_reivindicar() BEGIN
 DECLARE v_id BIGINT DEFAULT 0;
 UPDATE sincronismo_execucoes SET status = 'ERRO', finalizado_em = NOW(), erro = 'Interrompida: o serviço parou durante a execução' WHERE status = 'EM_EXECUCAO';
 SELECT COALESCE(MIN(codexecucao), 0) INTO v_id FROM sincronismo_execucoes WHERE status = 'NA_FILA';
 IF v_id > 0 THEN
   UPDATE sincronismo_execucoes SET status = 'EM_EXECUCAO', iniciado_em = NOW() WHERE codexecucao = v_id;
   SELECT * FROM sincronismo_execucoes WHERE codexecucao = v_id;
 END IF; END";
// Revisão c566: fim da simulação agendada — aplica se as exclusões ficaram até o limite; senão para (o serviço avisa o ADMINISTRADOR)
$sp['sp_sinc_agendado_continuar'] = "CREATE PROCEDURE sp_sinc_agendado_continuar(IN p_id BIGINT, IN p_exclusoes INT) BEGIN
 DECLARE v_lim INT DEFAULT 500;
 SELECT COALESCE(limite_exclusoes, 500) INTO v_lim FROM sincronismo_config WHERE codconfig = 1;
 IF NOT EXISTS (SELECT 1 FROM sincronismo_execucoes WHERE codexecucao = p_id AND origem = 'AGENDADO' AND modo = 'SIMULAR' AND status IN ('CONCLUIDA', 'CONCLUIDA_COM_ERRO')) THEN
   SELECT 'IGNORADO' AS acao, v_lim AS limite, NULL AS codexecucao;
 ELSEIF COALESCE(p_exclusoes, 0) <= v_lim THEN
   INSERT INTO sincronismo_execucoes (origem, modo, empresa, usuario_nome, codsimulacao) VALUES ('AGENDADO', 'APLICAR', NULL, CONCAT('Agendamento diário (simulação #', p_id, ')'), p_id);
   SELECT 'APLICAR' AS acao, v_lim AS limite, LAST_INSERT_ID() AS codexecucao;
 ELSE
   UPDATE sincronismo_execucoes SET erro = CONCAT(COALESCE(CONCAT(erro, ' | '), ''), 'Agendamento parado: ', p_exclusoes, ' exclusões, acima do limite de ', v_lim, '. Revise o relatório e aplique pela tela.') WHERE codexecucao = p_id;
   SELECT 'PARADO' AS acao, v_lim AS limite, NULL AS codexecucao;
 END IF; END";
$sp['sp_sinc_worker_finalizar'] = "CREATE PROCEDURE sp_sinc_worker_finalizar(IN p_id BIGINT, IN p_status VARCHAR(25), IN p_relatorio VARCHAR(500), IN p_resumo JSON, IN p_erro TEXT, IN p_bancos JSON) BEGIN
 UPDATE sincronismo_execucoes SET status = p_status, finalizado_em = NOW(), relatorio = p_relatorio, resumo = p_resumo, erro = p_erro WHERE codexecucao = p_id;
 IF p_bancos IS NOT NULL AND JSON_LENGTH(p_bancos) > 0 AND EXISTS (SELECT 1 FROM sincronismo_execucoes WHERE codexecucao = p_id AND modo = 'APLICAR') THEN
   UPDATE empresa_migracao_config SET ultimo_sincronismo_em = NOW() WHERE JSON_CONTAINS(p_bancos, JSON_QUOTE(banco_novo));
 END IF; END";

// Revisão c566: colunas que faltam em sincronismo_config (antes das views e procedures, que as usam)
$colsCfg = static fn(): array => array_map('strtolower', array_column($linhas($u, "SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sincronismo_config'"), 'c'));
$novasColunas = ['respeitar_ativo' => 'TINYINT(1) NOT NULL DEFAULT 1', 'agendado_simula' => 'TINYINT(1) NOT NULL DEFAULT 1', 'limite_exclusoes' => 'INT NOT NULL DEFAULT 500'];
// Quem sai do sincronismo com a regra do sincronismo_ativo (decisão de 24/10/2026)
$manter = [];
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--manter=')) $manter = array_values(array_filter(array_map(static fn($x) => strtolower(trim($x)), explode(',', substr($a, 9)))));
try {
    if ((int)$valor($u, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_migracao_config'") > 0)
        $relatorio['saem_do_sincronismo'] = array_map(static fn($r) => $r['codigo'] . ' — ' . $r['nome'] . (in_array(strtolower((string)$r['codigo']), $manter, true) ? ' (mantida: --manter)' : ''),
            $linhas($u, "SELECT e.codigo, e.nome FROM empresa e JOIN empresa_migracao_config x ON x.codempresa = e.codempresa
                WHERE e.status = 'ATIVA' AND e.banco_dados IS NOT NULL AND x.sincronismo_ativo = 0 ORDER BY e.nome"));
} catch (Throwable $e) { $relatorio['saem_do_sincronismo'] = 'aviso: ' . $e->getMessage(); }
foreach ($ddl as $nome => $sql) {
    if ($nome === 'config_colunas') {
        if ($simular) { $relatorio['universal'][$nome] = 'faltam: ' . (implode(', ', array_diff(array_keys($novasColunas), (int)$valor($u, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sincronismo_config'") ? $colsCfg() : [])) ?: 'nenhuma'); continue; }
        try {
            $tem = $colsCfg(); $feitas = [];
            foreach ($novasColunas as $c => $def) if (!in_array($c, $tem, true)) { $u->exec("ALTER TABLE sincronismo_config ADD COLUMN `$c` $def"); $feitas[] = $c; }
            if ($manter) { $q = $u->prepare('UPDATE empresa_migracao_config x JOIN empresa e ON e.codempresa = x.codempresa SET x.sincronismo_ativo = 1 WHERE LOWER(e.codigo) = ?'); foreach ($manter as $m) $q->execute([$m]); }
            $relatorio['universal'][$nome] = $feitas ? 'incluídas: ' . implode(', ', $feitas) : 'já existiam';
        } catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal'][$nome] = 'ERRO: ' . $e->getMessage(); }
        continue;
    }
    if ($simular) { $relatorio['universal'][$nome] = 'será criado'; continue; }
    try { $u->exec($sql); $relatorio['universal'][$nome] = 'ok'; }
    catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal'][$nome] = 'ERRO: ' . $e->getMessage(); }
}
foreach ($sp as $nome => $sql) {
    if ($simular) { $relatorio['universal'][$nome] = 'será criada'; continue; }
    try { $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $relatorio['universal'][$nome] = 'ok'; }
    catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal'][$nome] = 'ERRO: ' . $e->getMessage(); }
}

// Serviço na tela Administração › Serviços (o monitor lê o estado da tarefa do Windows)
try {
    if ((int)$valor($u, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'") > 0) {
        $cols = array_map('strtolower', array_column($linhas($u, "SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'"), 'c'));
        $dados = array_intersect_key(['nome_pt' => 'Sincronismo legado → greenportal', 'nome_en' => 'Legacy → greenportal synchronization', 'nome_es' => 'Sincronización legado → greenportal',
            'descricao_pt' => 'Executa o sincronismo diário no horário de Administração › Migração › Sincronismo e os pedidos feitos na tela (simular, aplicar, validar).',
            'descricao_en' => 'Runs the daily synchronization at the time set in Administration › Migration › Synchronization and the requests made on that screen.',
            'descricao_es' => 'Ejecuta la sincronización diaria en el horario configurado y los pedidos hechos en la pantalla.',
            'host' => 'TASK_SCHEDULER', 'script' => 'scripts/sincronismo-worker.php', 'ativo' => 1], array_flip($cols));
        $existe = (int)$valor($u, "SELECT COUNT(*) FROM servicos WHERE codigo = 'SINCRONISMO_WORKER'");
        if ($simular) $relatorio['universal']['servico'] = $existe ? 'será atualizado' : 'será incluído';
        elseif ($existe) {
            $u->prepare('UPDATE servicos SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($dados))) . " WHERE codigo = 'SINCRONISMO_WORKER'")->execute(array_values($dados));
            $relatorio['universal']['servico'] = 'atualizado';
        } else {
            $extra = array_intersect_key(['porta' => 0, 'ordem' => 14], array_flip($cols));
            $todos = ['codigo' => 'SINCRONISMO_WORKER'] + $dados + $extra;
            $u->prepare('INSERT INTO servicos (`' . implode('`, `', array_keys($todos)) . '`) VALUES (' . implode(', ', array_fill(0, count($todos), '?')) . ')')->execute(array_values($todos));
            $relatorio['universal']['servico'] = 'incluído';
        }
    }
} catch (Throwable $e) { $relatorio['universal']['servico'] = 'aviso: ' . $e->getMessage(); }

// ------------------------------------------------------------------ 2. menus (universal + clientes com o item de migração)
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$geral->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancos = [$bancoUniversal => $u];
foreach ($linhas($geral, "SELECT TABLE_SCHEMA AS b FROM information_schema.COLUMNS WHERE TABLE_NAME = 'menus' AND TABLE_SCHEMA LIKE 'g%\\_dbp' AND TABLE_SCHEMA <> ?
        AND COLUMN_NAME IN ('codigo', 'codmenu_pai', 'nivel', 'ordem') GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT COLUMN_NAME) = 4 ORDER BY 1", [$bancoUniversal]) as $l) {
    try {
        $pdo = GreenPortalDatabase::connect($env, (string)$l['b'], 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ((int)$valor($pdo, "SELECT COUNT(*) FROM menus WHERE codigo = 'ADMINISTRACAO_EMPRESAS_MIGRACAO'") > 0) { $pdo->exec('SET NAMES utf8mb4'); $bancos[(string)$l['b']] = $pdo; }
    } catch (Throwable $e) { $relatorio['menus'][(string)$l['b']] = ['aviso' => $e->getMessage()]; }
}
// Mesmo codmenu em todos os bancos para os itens novos (como nas migrações 289 e 299)
$novos = [];
foreach ([M305_GRUPO, 'ADMINISTRACAO_SINCRONISMO'] as $codigo) {
    $cod = (int)$valor($u, 'SELECT codmenu FROM menus WHERE codigo = ?', [$codigo]);
    if ($cod < 1) { foreach ($bancos as $pdo) $cod = max($cod, (int)$valor($pdo, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'), ...array_values($novos)); $cod++; }
    $novos[$codigo] = $cod;
}
$relatorio['codmenu_novos'] = $novos;
foreach ($bancos as $banco => $pdo) {
    $info = [];
    try {
        $cols = array_flip(array_map('strtolower', array_column($linhas($pdo, "SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus'"), 'c')));
        $raiz = $linhas($pdo, 'SELECT codmenu, nivel FROM menus WHERE codigo = ?', [M305_RAIZ])[0] ?? null;
        $atual = $linhas($pdo, "SELECT codmenu, codmenu_pai, ordem FROM menus WHERE codigo = 'ADMINISTRACAO_EMPRESAS_MIGRACAO'")[0] ?? null;
        if (!$raiz || !$atual) { $info['ignorado'] = 'sem o menu Administração ou o item de migração'; $relatorio['menus'][$banco] = $info; continue; }
        $nivelGrupo = (int)$raiz['nivel'] + 1;
        foreach ($linhas($pdo, 'SELECT m.codigo, p.codigo AS pai, m.ordem, m.label_pt FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai WHERE m.codigo IN (?, ?, ?, ?)', [M305_GRUPO, ...array_keys(M305_ITENS)]) as $l)
            $info['antes'][$l['codigo']] = ($l['pai'] ?? '-') . ' / ' . $l['ordem'] . ' / ' . $l['label_pt'];
        if ($simular) { $info['depois'] = 'será reorganizado'; $relatorio['menus'][$banco] = $info; continue; }
        $pdo->beginTransaction();
        // grupo "Migração" no lugar do item atual
        foreach ($novos as $codigo => $codmenu) {
            $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$codmenu]);
            if ($outro !== null && strtoupper((string)$outro) !== $codigo) throw new RuntimeException("codmenu $codmenu já usado por $outro");
        }
        $grupo = array_intersect_key(['codmenu_pai' => (int)$raiz['codmenu'], 'modulo' => 'ADMINISTRACAO', 'label' => 'Migração', 'label_pt' => 'Migração', 'label_en' => 'Migration', 'label_es' => 'Migración',
            'url' => null, 'nivel' => $nivelGrupo, 'ordem' => (int)$atual['ordem'], 'icone' => 'bi-database-gear', 'ativo' => 1], $cols);
        if ((int)$valor($pdo, 'SELECT COUNT(*) FROM menus WHERE codigo = ?', [M305_GRUPO]) > 0)
            $pdo->prepare('UPDATE menus SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($grupo))) . ' WHERE codigo = ?')->execute([...array_values($grupo), M305_GRUPO]);
        else {
            $d = ['codmenu' => $novos[M305_GRUPO], 'codigo' => M305_GRUPO] + $grupo;
            $pdo->prepare('INSERT INTO menus (`' . implode('`, `', array_keys($d)) . '`) VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
        }
        $codGrupo = (int)$valor($pdo, 'SELECT codmenu FROM menus WHERE codigo = ?', [M305_GRUPO]);
        foreach (M305_ITENS as $codigo => [$ordem, $pt, $en, $es, $icone, $url]) {
            $d = array_intersect_key(['codmenu_pai' => $codGrupo, 'modulo' => 'ADMINISTRACAO', 'label' => $pt, 'label_pt' => $pt, 'label_en' => $en, 'label_es' => $es,
                'nivel' => $nivelGrupo + 1, 'ordem' => $ordem, 'icone' => $icone, 'ativo' => 1] + ($url !== null ? ['url' => $url] : []), $cols);
            if ((int)$valor($pdo, 'SELECT COUNT(*) FROM menus WHERE codigo = ?', [$codigo]) > 0)
                $pdo->prepare('UPDATE menus SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($d))) . ' WHERE codigo = ?')->execute([...array_values($d), $codigo]);
            elseif (isset($novos[$codigo])) {
                $d = ['codmenu' => $novos[$codigo], 'codigo' => $codigo] + $d;
                $pdo->prepare('INSERT INTO menus (`' . implode('`, `', array_keys($d)) . '`) VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            } else $info['sem_o_menu_neste_banco'][] = $codigo;
        }
        $pdo->commit();
        foreach ($linhas($pdo, 'SELECT m.codigo, p.codigo AS pai, m.ordem, m.label_pt FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai WHERE m.codigo IN (?, ?, ?, ?) ORDER BY m.nivel, m.ordem', [M305_GRUPO, ...array_keys(M305_ITENS)]) as $l)
            $info['depois'][$l['codigo']] = ($l['pai'] ?? '-') . ' / ' . $l['ordem'] . ' / ' . $l['label_pt'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['menus'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_305_sincronismo_tela' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
echo $relatorio['ok'] ? "\nOs usuários veem o menu novo no próximo login. Depois instale o serviço: scripts\\criar-agendamento-sincronismo.ps1\n" : '';
exit($relatorio['ok'] ? 0 : 1);
