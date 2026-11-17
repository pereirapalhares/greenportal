<?php
declare(strict_types=1);

/*
 * Migração 353 — Vigia do orquestrador e tela Administração › Serviços (decisão do usuário de 11/11/2026).
 *
 * Motivo: de 28/10 a 11/11/2026 as 3 trilhas ficaram sem rodar (tarefas registradas sem o "--") e ninguém foi avisado.
 *
 * Universal:
 *   - orq_trilhas ganha a situação da tarefa do Windows (estado, último resultado, última e próxima execução, quando foi coletada);
 *   - orq_vigia: sinal de vida do vigia (scripts/orquestrador-vigia.php, tarefa própria, fora das trilhas) e da tarefa dele;
 *   - orq_avisos: problemas avisados aos ADMINISTRADORES (aberto, último aviso, resolvido), para não repetir o alerta a cada ciclo;
 *   - sp_orq_saude: problemas atuais (trilha sem sinal de vida há mais de 15 min, trilha em SIMULAR ou desligada, tarefa do
 *     Windows desativada ou não encontrada, serviço suspenso, empresa suspensa pelo disjuntor, vigia sem rodar);
 *   - sp_orq_aviso_abrir / sp_orq_aviso_resolver_ausentes / sp_orq_avisos_listar, sp_orq_tarefa_windows, sp_orq_vigia_sinal,
 *     sp_orq_trilhas_situacao, sp_orq_vigia_situacao, sp_orq_servico_liberar.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-353-orquestrador-vigia.php [--simular]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '353', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => []];

// Colunas novas em orq_trilhas (conferidas no information_schema: o MySQL 8 não tem ADD COLUMN IF NOT EXISTS)
$colunas = [
    'tarefa_estado' => "VARCHAR(20) NULL",
    'tarefa_resultado' => "BIGINT NULL",
    'tarefa_ultima' => "DATETIME NULL",
    'tarefa_proxima' => "DATETIME NULL",
    'tarefa_coletada_em' => "DATETIME NULL",
];

$ddl = [];
$ddl['orq_vigia'] = "CREATE TABLE IF NOT EXISTS orq_vigia (
 id TINYINT UNSIGNED NOT NULL, ultima_execucao DATETIME NULL, mensagem VARCHAR(500) NULL,
 tarefa_estado VARCHAR(20) NULL, tarefa_resultado BIGINT NULL, tarefa_ultima DATETIME NULL, tarefa_proxima DATETIME NULL, tarefa_coletada_em DATETIME NULL,
 PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$ddl['orq_vigia_linha'] = "INSERT IGNORE INTO orq_vigia (id) VALUES (1)";
$ddl['orq_avisos'] = "CREATE TABLE IF NOT EXISTS orq_avisos (
 codaviso BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, chave VARCHAR(120) NOT NULL, tipo VARCHAR(20) NOT NULL, gravidade VARCHAR(10) NOT NULL,
 banco VARCHAR(64) NULL, titulo VARCHAR(200) NOT NULL, mensagem VARCHAR(1500) NULL,
 aberto_em DATETIME NOT NULL, avisado_em DATETIME NOT NULL, visto_em DATETIME NOT NULL, resolvido_em DATETIME NULL,
 PRIMARY KEY(codaviso), KEY ix_orq_avisos_chave(chave, resolvido_em), KEY ix_orq_avisos_abertos(resolvido_em, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sp = [];
// Problemas atuais. Os de EMPRESA vêm do vigia (ele lê os arquivos do disjuntor); os outros são calculados aqui.
$sp['sp_orq_saude'] = "CREATE PROCEDURE sp_orq_saude() BEGIN
 SELECT x.chave, x.tipo, x.gravidade, x.banco, x.titulo, x.mensagem FROM (
  SELECT CONCAT('TRILHA_SEM_SINAL_', t.trilha) AS chave, 'TRILHA' AS tipo, 'ERRO' AS gravidade, CAST(NULL AS CHAR(64)) AS banco,
         CONCAT('Trilha ', t.trilha, ' — ', t.nome, ': sem sinal de vida') AS titulo,
         CONCAT(IF(t.batimento_em IS NULL, 'A trilha nunca rodou',
                   CONCAT('Último sinal em ', DATE_FORMAT(t.batimento_em, '%d/%m/%Y %H:%i'), ' (há ', TIMESTAMPDIFF(MINUTE, t.batimento_em, NOW()), ' min)')),
                '. Os serviços dessa trilha não estão rodando. Confira a tarefa GreenPortal-Orquestrador-T', t.trilha, ' no Agendador do Windows.') AS mensagem,
         1 AS ordem
    FROM orq_trilhas t WHERE t.ativo = 1 AND (t.batimento_em IS NULL OR t.batimento_em < NOW() - INTERVAL 15 MINUTE)
  UNION ALL
  SELECT CONCAT('TAREFA_', t.trilha), 'TAREFA', 'ERRO', NULL,
         CONCAT('Tarefa GreenPortal-Orquestrador-T', t.trilha, IF(t.tarefa_estado = 'DISABLED', ' desativada', ' não encontrada'), ' no Agendador'),
         CONCAT('Coletado em ', DATE_FORMAT(t.tarefa_coletada_em, '%d/%m/%Y %H:%i'), '. Enquanto a tarefa não rodar, os serviços da trilha ', t.trilha,
                ' ficam parados. Para recriar: scripts/criar-agendamento-orquestrador.ps1 (PowerShell como Administrador).'),
         2
    FROM orq_trilhas t WHERE t.ativo = 1 AND t.tarefa_coletada_em >= NOW() - INTERVAL 30 MINUTE AND t.tarefa_estado IN ('DISABLED', 'NAO_INSTALADO')
  UNION ALL
  SELECT CONCAT('SERVICO_SUSPENSO_', s.codigo), 'SERVICO', 'ERRO', NULL,
         CONCAT('Serviço suspenso: ', s.nome),
         CONCAT('Falhou ', s.falhas_seguidas, ' vezes seguidas e está suspenso até ', DATE_FORMAT(s.suspenso_ate, '%d/%m/%Y %H:%i'),
                '. Última situação: ', COALESCE(s.ultima_status, '-'), IF(COALESCE(s.ultima_mensagem, '') = '', '', CONCAT(' — ', LEFT(s.ultima_mensagem, 400)))),
         3
    FROM orq_servicos s JOIN orq_trilhas t ON t.trilha = s.trilha WHERE s.ativo = 1 AND t.ativo = 1 AND s.suspenso_ate > NOW()
  UNION ALL
  SELECT a.chave, a.tipo, a.gravidade, a.banco, a.titulo, a.mensagem, 4
    FROM orq_avisos a WHERE a.tipo = 'EMPRESA' AND a.resolvido_em IS NULL
  UNION ALL
  SELECT CONCAT('TRILHA_SIMULAR_', t.trilha), 'TRILHA', 'AVISO', NULL,
         CONCAT('Trilha ', t.trilha, ' — ', t.nome, ': em SIMULAR'),
         CONCAT('A trilha só registra o que rodaria: os serviços dela não estão rodando. Para ligar: php scripts/orquestrador.php --modo=EXECUTAR --trilha=', t.trilha, '.'),
         5
    FROM orq_trilhas t WHERE t.ativo = 1 AND t.modo <> 'EXECUTAR'
  UNION ALL
  SELECT CONCAT('TRILHA_DESLIGADA_', t.trilha), 'TRILHA', 'AVISO', NULL,
         CONCAT('Trilha ', t.trilha, ' — ', t.nome, ': desligada'),
         'A trilha está com ativo = 0 em orq_trilhas: nenhum serviço dela roda.', 6
    FROM orq_trilhas t WHERE t.ativo = 0
  UNION ALL
  SELECT 'VIGIA_PARADO', 'VIGIA', 'AVISO', NULL, 'Vigia dos serviços sem rodar',
         CONCAT(IF(v.ultima_execucao IS NULL, 'O vigia nunca rodou',
                   CONCAT('Última execução em ', DATE_FORMAT(v.ultima_execucao, '%d/%m/%Y %H:%i'))),
                '. Sem ele, os administradores não recebem alerta de serviço parado. Confira a tarefa GreenPortal-Orquestrador-Vigia.'),
         7
    FROM orq_vigia v WHERE v.id = 1 AND (v.ultima_execucao IS NULL OR v.ultima_execucao < NOW() - INTERVAL 30 MINUTE)
 ) x ORDER BY x.ordem, x.chave; END";

// Abre o aviso (NOVO), atualiza o texto e, se o último aviso tem mais de 24 h, pede para avisar de novo (LEMBRAR)
$sp['sp_orq_aviso_abrir'] = "CREATE PROCEDURE sp_orq_aviso_abrir(IN p_chave VARCHAR(120), IN p_tipo VARCHAR(20), IN p_gravidade VARCHAR(10), IN p_banco VARCHAR(64),
 IN p_titulo VARCHAR(200), IN p_mensagem VARCHAR(1500)) BEGIN
 DECLARE v_id BIGINT UNSIGNED DEFAULT NULL; DECLARE v_avisado DATETIME DEFAULT NULL; DECLARE v_acao VARCHAR(10) DEFAULT 'NADA';
 SELECT codaviso, avisado_em INTO v_id, v_avisado FROM orq_avisos WHERE chave = p_chave AND resolvido_em IS NULL ORDER BY codaviso DESC LIMIT 1;
 IF v_id IS NULL THEN
   INSERT INTO orq_avisos (chave, tipo, gravidade, banco, titulo, mensagem, aberto_em, avisado_em, visto_em)
   VALUES (p_chave, p_tipo, p_gravidade, NULLIF(p_banco, ''), LEFT(p_titulo, 200), LEFT(p_mensagem, 1500), NOW(), NOW(), NOW());
   SET v_id = LAST_INSERT_ID(); SET v_acao = 'NOVO';
 ELSE
   IF v_avisado < NOW() - INTERVAL 24 HOUR THEN SET v_acao = 'LEMBRAR'; END IF;
   UPDATE orq_avisos SET gravidade = p_gravidade, titulo = LEFT(p_titulo, 200), mensagem = LEFT(p_mensagem, 1500), visto_em = NOW(),
          avisado_em = IF(v_acao = 'LEMBRAR', NOW(), avisado_em) WHERE codaviso = v_id;
 END IF;
 SELECT v_id AS codaviso, v_acao AS acao; END";

// Resolve os avisos abertos que não estão mais na lista atual (chaves separadas por vírgula). Trilha e tarefa resolvem na hora;
// serviço e empresa só depois de 60 min sem aparecer, para uma suspensão que volta logo não gerar alerta a cada meia hora.
$sp['sp_orq_aviso_resolver_ausentes'] = "CREATE PROCEDURE sp_orq_aviso_resolver_ausentes(IN p_chaves TEXT) BEGIN
 DROP TEMPORARY TABLE IF EXISTS tmp_orq_resolvidos;
 CREATE TEMPORARY TABLE tmp_orq_resolvidos AS
  SELECT codaviso, chave, tipo, gravidade, banco, titulo, aberto_em FROM orq_avisos
   WHERE resolvido_em IS NULL AND tipo <> 'VIGIA' AND FIND_IN_SET(chave, COALESCE(p_chaves, '')) = 0
     AND (tipo IN ('TRILHA', 'TAREFA') OR visto_em < NOW() - INTERVAL 60 MINUTE);
 UPDATE orq_avisos a JOIN tmp_orq_resolvidos r ON r.codaviso = a.codaviso SET a.resolvido_em = NOW();
 SELECT codaviso, chave, tipo, gravidade, banco, titulo, aberto_em FROM tmp_orq_resolvidos ORDER BY codaviso;
 DROP TEMPORARY TABLE IF EXISTS tmp_orq_resolvidos; END";

$sp['sp_orq_avisos_listar'] = "CREATE PROCEDURE sp_orq_avisos_listar(IN p_limite INT) BEGIN
 SELECT codaviso, chave, tipo, gravidade, banco, titulo, mensagem, aberto_em, avisado_em, visto_em, resolvido_em
   FROM orq_avisos ORDER BY (resolvido_em IS NULL) DESC, codaviso DESC LIMIT p_limite; END";

// Situação da tarefa do Windows (trilha 0 = vigia), gravada pelo monitor e pelo vigia
$sp['sp_orq_tarefa_windows'] = "CREATE PROCEDURE sp_orq_tarefa_windows(IN p_trilha TINYINT, IN p_estado VARCHAR(20), IN p_resultado BIGINT,
 IN p_ultima DATETIME, IN p_proxima DATETIME) BEGIN
 IF p_trilha = 0 THEN
   UPDATE orq_vigia SET tarefa_estado = p_estado, tarefa_resultado = p_resultado, tarefa_ultima = p_ultima, tarefa_proxima = p_proxima, tarefa_coletada_em = NOW() WHERE id = 1;
 ELSE
   UPDATE orq_trilhas SET tarefa_estado = p_estado, tarefa_resultado = p_resultado, tarefa_ultima = p_ultima, tarefa_proxima = p_proxima, tarefa_coletada_em = NOW() WHERE trilha = p_trilha;
 END IF; END";

$sp['sp_orq_vigia_sinal'] = "CREATE PROCEDURE sp_orq_vigia_sinal(IN p_mensagem VARCHAR(500)) BEGIN
 UPDATE orq_vigia SET ultima_execucao = NOW(), mensagem = LEFT(p_mensagem, 500) WHERE id = 1; END";

$sp['sp_orq_trilhas_situacao'] = "CREATE PROCEDURE sp_orq_trilhas_situacao() BEGIN
 SELECT t.trilha, t.nome, t.ativo, t.modo, t.pid, t.ciclo_inicio, t.ciclo_fim, t.batimento_em, t.servico_atual,
        TIMESTAMPDIFF(SECOND, t.batimento_em, NOW()) AS segundos_sem_sinal,
        t.tarefa_estado, t.tarefa_resultado, t.tarefa_ultima, t.tarefa_proxima, t.tarefa_coletada_em,
        TIMESTAMPDIFF(SECOND, t.tarefa_coletada_em, NOW()) AS segundos_desde_coleta,
        (SELECT COUNT(*) FROM orq_servicos s WHERE s.trilha = t.trilha AND s.ativo = 1) AS servicos_ligados,
        (SELECT COUNT(*) FROM orq_servicos s WHERE s.trilha = t.trilha AND s.ativo = 1 AND s.suspenso_ate > NOW()) AS servicos_suspensos,
        (SELECT COUNT(*) FROM orq_execucoes e WHERE e.trilha = t.trilha AND e.inicio_em >= NOW() - INTERVAL 24 HOUR AND e.status IN ('ERRO', 'TEMPO_ESGOTADO', 'ABANDONADA')) AS falhas_24h,
        NOW() AS agora
   FROM orq_trilhas t ORDER BY t.trilha; END";

$sp['sp_orq_vigia_situacao'] = "CREATE PROCEDURE sp_orq_vigia_situacao() BEGIN
 SELECT v.ultima_execucao, TIMESTAMPDIFF(SECOND, v.ultima_execucao, NOW()) AS segundos_sem_rodar, v.mensagem,
        v.tarefa_estado, v.tarefa_resultado, v.tarefa_ultima, v.tarefa_proxima, v.tarefa_coletada_em,
        (SELECT COUNT(*) FROM orq_avisos a WHERE a.resolvido_em IS NULL) AS avisos_abertos
   FROM orq_vigia v WHERE v.id = 1; END";

// Botão "Liberar" da tela: tira a suspensão sem mudar o cadastro
$sp['sp_orq_servico_liberar'] = "CREATE PROCEDURE sp_orq_servico_liberar(IN p_codigo VARCHAR(40), IN p_usuario BIGINT) BEGIN
 IF NOT EXISTS (SELECT 1 FROM orq_servicos WHERE codigo = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Serviço não encontrado'; END IF;
 UPDATE orq_servicos SET suspenso_ate = NULL, falhas_seguidas = 0, atualizado_por = p_usuario WHERE codigo = p_codigo;
 SELECT * FROM vw_orq_servicos WHERE codigo = p_codigo; END";

try {
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->exec('SET NAMES utf8mb4');
    $tem = $u->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orq_trilhas'")->fetchColumn();
    if ((int)$tem === 0) throw new RuntimeException('A migração 352 (orquestrador) não foi aplicada neste banco.');

    $existentes = $u->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orq_trilhas'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($colunas as $col => $tipo) {
        if (in_array($col, $existentes, true)) { $rel['universal']["orq_trilhas.$col"] = 'já existe'; continue; }
        if ($simular) { $rel['universal']["orq_trilhas.$col"] = 'será incluída'; continue; }
        $u->exec("ALTER TABLE orq_trilhas ADD COLUMN `$col` $tipo");
        $rel['universal']["orq_trilhas.$col"] = 'incluída';
    }
    foreach ($ddl as $nome => $sql) {
        if ($simular) { $rel['universal'][$nome] = 'será gravado'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    foreach ($sp as $nome => $sql) {
        if ($simular) { $rel['universal'][$nome] = 'será gravada'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    if (!$simular) {
        $st = $u->query('CALL sp_orq_saude()');
        $rel['problemas_agora'] = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
