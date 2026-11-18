<?php
declare(strict_types=1);

/*
 * Migração 397 — Manutenção Legal › Legislação x Empresas: importação da planilha mensal (pedido do usuário de 18/11/2026).
 *
 * Substitui o script legado legislacaoxempresax_v2.php. Fluxo em duas etapas, nada é gravado sem autorização:
 *   1. o ADMINISTRADOR envia a planilha; o portal confere e grava o PEDIDO (status CONFERIDA) com a lista do que mudaria;
 *   2. o ADMINISTRADOR autoriza (AUTORIZADA) ou descarta (DESCARTADA); o serviço LEGISLACAO_IMPORTACAO (trilha 1 do
 *      orquestrador, a cada 1 min) aplica (APLICANDO → APLICADA / APLICADA_COM_ERROS) e manda o alerta com o resultado.
 *
 * Universal (guniversal_dbp):
 *   - legislacao_importacoes       pedido: arquivo, quem enviou/autorizou/descartou e quando, resumo, problemas, log;
 *   - legislacao_importacao_itens  cada mudança: INCLUIR, TROCAR (tipo) ou RETIRAR (só se o usuário marcar), com o
 *                                  resultado da aplicação (PENDENTE, APLICADO, IGNORADO, ERRO);
 *   - views vw_legislacao_importacoes e vw_legislacao_importacao_itens (leitura pela sp_crud_select);
 *   - procedures sp_legimp_itens_incluir (itens em lote), sp_legimp_marcar_retiradas, sp_legimp_autorizar,
 *     sp_legimp_descartar e sp_legimp_reservar (o worker pega um pedido por vez);
 *   - serviço LEGISLACAO_IMPORTACAO no orq_servicos (scripts/legislacao-importacao-worker.php).
 *
 * Uso: php scripts\aplicar-migracao-397-legislacao-importacao.php            (simulação)
 *      php scripts\aplicar-migracao-397-legislacao-importacao.php --aplicar  (grava)
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '397', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'universal' => []];

$ddl = [];
$ddl['legislacao_importacoes'] = "CREATE TABLE IF NOT EXISTS legislacao_importacoes (
 codimportacao INT UNSIGNED NOT NULL AUTO_INCREMENT,
 arquivo_nome VARCHAR(255) NOT NULL, arquivo_hash CHAR(64) NULL, referencia VARCHAR(7) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'CONFERIDA',
 codempresa_origem INT NULL, banco_origem VARCHAR(64) NULL,
 enviado_por INT NULL, enviado_por_nome VARCHAR(150) NULL, enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 autorizado_por INT NULL, autorizado_por_nome VARCHAR(150) NULL, autorizado_em DATETIME NULL,
 descartado_por INT NULL, descartado_por_nome VARCHAR(150) NULL, descartado_em DATETIME NULL,
 aplicacao_inicio DATETIME NULL, aplicacao_fim DATETIME NULL,
 total_incluir INT NOT NULL DEFAULT 0, total_trocar INT NOT NULL DEFAULT 0, total_retirar INT NOT NULL DEFAULT 0,
 total_manter INT NOT NULL DEFAULT 0, total_padroes INT NOT NULL DEFAULT 0, total_problemas INT NOT NULL DEFAULT 0,
 total_aplicados INT NULL, total_ignorados INT NULL, total_erros INT NULL,
 resumo LONGTEXT NULL, problemas LONGTEXT NULL, log LONGTEXT NULL,
 PRIMARY KEY (codimportacao), KEY ix_legimp_status (status, codimportacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$ddl['legislacao_importacao_itens'] = "CREATE TABLE IF NOT EXISTS legislacao_importacao_itens (
 coditem INT UNSIGNED NOT NULL AUTO_INCREMENT,
 codimportacao INT UNSIGNED NOT NULL,
 acao VARCHAR(10) NOT NULL,
 codempresa INT NOT NULL, empresa_nome VARCHAR(150) NULL,
 codunidade INT NOT NULL, unidade_nome VARCHAR(150) NULL,
 linha INT NULL, coluna VARCHAR(4) NULL,
 codigo_lei VARCHAR(80) NOT NULL, codlegislacao_base INT NULL,
 codlegxemp INT NULL, codlegislacao_atual INT NULL, codigo_atual VARCHAR(90) NULL, tipo_atual VARCHAR(10) NULL,
 tipo_novo VARCHAR(10) NULL, codigo_novo VARCHAR(90) NULL, padrao_novo TINYINT(1) NOT NULL DEFAULT 0,
 selecionado TINYINT(1) NOT NULL DEFAULT 1,
 status VARCHAR(12) NOT NULL DEFAULT 'PENDENTE', mensagem VARCHAR(500) NULL, aplicado_em DATETIME NULL,
 PRIMARY KEY (coditem), KEY ix_legimp_itens (codimportacao, acao, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$views = [];
$views['vw_legislacao_importacoes'] = "CREATE OR REPLACE VIEW vw_legislacao_importacoes AS
 SELECT i.codimportacao, i.arquivo_nome, i.arquivo_hash, i.referencia, i.status, i.codempresa_origem, i.banco_origem,
        i.enviado_por, i.enviado_por_nome, i.enviado_em, i.autorizado_por, i.autorizado_por_nome, i.autorizado_em,
        i.descartado_por, i.descartado_por_nome, i.descartado_em, i.aplicacao_inicio, i.aplicacao_fim,
        i.total_incluir, i.total_trocar, i.total_retirar, i.total_manter, i.total_padroes, i.total_problemas,
        i.total_aplicados, i.total_ignorados, i.total_erros, i.resumo, i.problemas, i.log,
        (SELECT COUNT(*) FROM legislacao_importacao_itens x WHERE x.codimportacao = i.codimportacao AND x.acao = 'RETIRAR' AND x.selecionado = 1) AS total_retirar_marcadas
   FROM legislacao_importacoes i";
$views['vw_legislacao_importacao_itens'] = "CREATE OR REPLACE VIEW vw_legislacao_importacao_itens AS
 SELECT x.coditem, x.codimportacao, x.acao, x.codempresa, x.empresa_nome, x.codunidade, x.unidade_nome, x.linha, x.coluna,
        x.codigo_lei, x.codlegislacao_base, x.codlegxemp, x.codlegislacao_atual, x.codigo_atual, x.tipo_atual, x.tipo_novo,
        x.codigo_novo, x.padrao_novo, x.selecionado, x.status, x.mensagem, x.aplicado_em
   FROM legislacao_importacao_itens x";

$sp = [];
$sp['sp_legimp_itens_incluir'] = "CREATE PROCEDURE sp_legimp_itens_incluir(IN p_codimportacao INT, IN p_itens JSON)
SQL SECURITY INVOKER
BEGIN
  IF NOT EXISTS (SELECT 1 FROM legislacao_importacoes WHERE codimportacao = p_codimportacao AND status = 'CONFERIDA') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido de importação não encontrado ou já fechado';
  END IF;
  INSERT INTO legislacao_importacao_itens (codimportacao, acao, codempresa, empresa_nome, codunidade, unidade_nome, linha, coluna,
         codigo_lei, codlegislacao_base, codlegxemp, codlegislacao_atual, codigo_atual, tipo_atual, tipo_novo, codigo_novo, padrao_novo, selecionado)
  SELECT p_codimportacao, j.acao, j.codempresa, j.empresa_nome, j.codunidade, j.unidade_nome, j.linha, j.coluna,
         j.codigo_lei, j.codlegislacao_base, j.codlegxemp, j.codlegislacao_atual, j.codigo_atual, j.tipo_atual, j.tipo_novo, j.codigo_novo,
         COALESCE(j.padrao_novo, 0), IF(j.acao = 'RETIRAR', 0, 1)
    FROM JSON_TABLE(p_itens, '\$[*]' COLUMNS (
         acao VARCHAR(10) PATH '\$.acao', codempresa INT PATH '\$.codempresa', empresa_nome VARCHAR(150) PATH '\$.empresa_nome',
         codunidade INT PATH '\$.codunidade', unidade_nome VARCHAR(150) PATH '\$.unidade_nome', linha INT PATH '\$.linha', coluna VARCHAR(4) PATH '\$.coluna',
         codigo_lei VARCHAR(80) PATH '\$.codigo_lei', codlegislacao_base INT PATH '\$.codlegislacao_base', codlegxemp INT PATH '\$.codlegxemp',
         codlegislacao_atual INT PATH '\$.codlegislacao_atual', codigo_atual VARCHAR(90) PATH '\$.codigo_atual', tipo_atual VARCHAR(10) PATH '\$.tipo_atual',
         tipo_novo VARCHAR(10) PATH '\$.tipo_novo', codigo_novo VARCHAR(90) PATH '\$.codigo_novo', padrao_novo TINYINT PATH '\$.padrao_novo')) j
   WHERE j.acao IN ('INCLUIR', 'TROCAR', 'RETIRAR');
  SELECT ROW_COUNT() AS registros_afetados;
END";
$sp['sp_legimp_marcar_retiradas'] = "CREATE PROCEDURE sp_legimp_marcar_retiradas(IN p_codimportacao INT, IN p_itens JSON)
SQL SECURITY INVOKER
BEGIN
  IF NOT EXISTS (SELECT 1 FROM legislacao_importacoes WHERE codimportacao = p_codimportacao AND status = 'CONFERIDA') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O pedido já foi autorizado ou descartado';
  END IF;
  UPDATE legislacao_importacao_itens SET selecionado = 0 WHERE codimportacao = p_codimportacao AND acao = 'RETIRAR';
  UPDATE legislacao_importacao_itens x
    JOIN JSON_TABLE(COALESCE(p_itens, JSON_ARRAY()), '\$[*]' COLUMNS (id INT PATH '\$')) j ON j.id = x.coditem
     SET x.selecionado = 1
   WHERE x.codimportacao = p_codimportacao AND x.acao = 'RETIRAR';
  SELECT COUNT(*) AS marcadas FROM legislacao_importacao_itens WHERE codimportacao = p_codimportacao AND acao = 'RETIRAR' AND selecionado = 1;
END";
$sp['sp_legimp_autorizar'] = "CREATE PROCEDURE sp_legimp_autorizar(IN p_codimportacao INT, IN p_usuario INT, IN p_nome VARCHAR(150))
SQL SECURITY INVOKER
BEGIN
  UPDATE legislacao_importacoes SET status = 'AUTORIZADA', autorizado_por = p_usuario, autorizado_por_nome = p_nome, autorizado_em = NOW()
   WHERE codimportacao = p_codimportacao AND status = 'CONFERIDA';
  IF ROW_COUNT() = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O pedido já foi autorizado ou descartado'; END IF;
  SELECT codimportacao, status, autorizado_em FROM legislacao_importacoes WHERE codimportacao = p_codimportacao;
END";
$sp['sp_legimp_descartar'] = "CREATE PROCEDURE sp_legimp_descartar(IN p_codimportacao INT, IN p_usuario INT, IN p_nome VARCHAR(150))
SQL SECURITY INVOKER
BEGIN
  UPDATE legislacao_importacoes SET status = 'DESCARTADA', descartado_por = p_usuario, descartado_por_nome = p_nome, descartado_em = NOW()
   WHERE codimportacao = p_codimportacao AND status = 'CONFERIDA';
  IF ROW_COUNT() = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O pedido já foi autorizado ou descartado'; END IF;
  SELECT codimportacao, status FROM legislacao_importacoes WHERE codimportacao = p_codimportacao;
END";
// Um pedido por vez: o AUTORIZADA mais antigo, ou um APLICANDO parado há mais de 2 h (processo encerrado no meio;
// os itens já aplicados não são refeitos, porque a aplicação só pega os PENDENTE).
$sp['sp_legimp_reservar'] = "CREATE PROCEDURE sp_legimp_reservar()
SQL SECURITY INVOKER
BEGIN
  DECLARE v_id INT DEFAULT NULL;
  IF EXISTS (SELECT 1 FROM legislacao_importacoes WHERE status = 'APLICANDO' AND aplicacao_inicio >= DATE_SUB(NOW(), INTERVAL 2 HOUR)) THEN
    SELECT NULL AS codimportacao LIMIT 0;
  ELSE
    SELECT codimportacao INTO v_id FROM legislacao_importacoes
     WHERE status = 'AUTORIZADA' OR (status = 'APLICANDO' AND aplicacao_inicio < DATE_SUB(NOW(), INTERVAL 2 HOUR))
     ORDER BY codimportacao LIMIT 1;
    IF v_id IS NOT NULL THEN
      UPDATE legislacao_importacoes SET status = 'APLICANDO', aplicacao_inicio = NOW()
       WHERE codimportacao = v_id AND (status = 'AUTORIZADA' OR (status = 'APLICANDO' AND aplicacao_inicio < DATE_SUB(NOW(), INTERVAL 2 HOUR)));
      IF ROW_COUNT() = 1 THEN SELECT * FROM vw_legislacao_importacoes WHERE codimportacao = v_id;
      ELSE SELECT NULL AS codimportacao LIMIT 0; END IF;
    ELSE
      SELECT NULL AS codimportacao LIMIT 0;
    END IF;
  END IF;
END";

$servico = ['LEGISLACAO_IMPORTACAO', 'Importação Legislação x Empresas', 1, 30, 1, 'scripts/legislacao-importacao-worker.php', null, 1, null, null, 1800,
    'Aplica as planilhas de Legislação x Empresas autorizadas pelo ADMINISTRADOR.'];

try {
    $env = GreenPortalDatabase::env();
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->exec('SET NAMES utf8mb4');
    if ($aplicar) { $criadas = MigracaoCrud::garantir($u); if ($criadas) $rel['universal']['sp_crud'] = 'criadas: ' . implode(', ', $criadas); }
    foreach ($ddl as $nome => $sql) {
        $existe = MigracaoCrud::existe($u, $nome);
        if (!$aplicar) { $rel['universal'][$nome] = $existe ? 'já existe' : 'será criada'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = $existe ? 'já existia' : 'criada';
    }
    foreach ($views as $nome => $sql) {
        if (!$aplicar) { $rel['universal'][$nome] = 'será gravada'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    foreach ($sp as $nome => $sql) {
        if (!$aplicar) { $rel['universal'][$nome] = 'será gravada'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    // Serviço no orquestrador (trilha 1, a cada 1 min)
    if (!MigracaoCrud::existe($u, 'orq_servicos')) {
        $rel['avisos'][] = 'orq_servicos não existe (migração 352): o serviço LEGISLACAO_IMPORTACAO não foi cadastrado.';
    } elseif (!is_file(dirname(__DIR__) . '/scripts/legislacao-importacao-worker.php')) {
        $rel['avisos'][] = 'scripts/legislacao-importacao-worker.php não encontrado.';
    } else {
        $ja = MigracaoCrud::primeiro($u, 'orq_servicos', ['codigo' => $servico[0]]);
        if ($ja) $rel['universal']['orq_servicos'] = 'LEGISLACAO_IMPORTACAO já cadastrado (mantido)';
        elseif (!$aplicar) $rel['universal']['orq_servicos'] = 'LEGISLACAO_IMPORTACAO será cadastrado (trilha 1, ordem 30, a cada 1 min)';
        else {
            MigracaoCrud::inserir($u, 'orq_servicos', array_combine(['codigo', 'nome', 'trilha', 'ordem', 'ativo', 'script', 'argumentos', 'intervalo_min', 'horario', 'dias_semana', 'limite_seg', 'descricao'], $servico));
            $rel['universal']['orq_servicos'] = 'LEGISLACAO_IMPORTACAO cadastrado (trilha 1, ordem 30, a cada 1 min)';
        }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
