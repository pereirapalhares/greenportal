<?php
declare(strict_types=1);

/*
 * Migração 402 — Manutenção Legal › Legislação › Importar planilha de alterações (pedido do usuário de 19/11/2026).
 * Substitui o script legado migracao_legislacaoxalteracoes_v3_2.php, no mesmo fluxo das importações 397/398 (conferir sem
 * gravar → autorizar → serviço LEGISLACAO_IMPORTACAO aplica e manda o alerta). Tipo do pedido: ALTERACAO.
 *   - legislacao_importacao_itens: alt_tipo, alt_descricao (até 2 mil caracteres na planilha de 11/2026) e alt_data;
 *     ações NOVA_LEI (lei criada), ATUALIZAR (situação/devido/data) e ALTERACAO (linha em legislacaoxalteracao);
 *   - views regravadas com as colunas novas; sp_legimp_itens_incluir aceita as ações e as colunas.
 * Uso: php scripts\aplicar-migracao-402-legislacao-alteracoes.php [--aplicar]   (pode rodar mais de uma vez)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '402', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'universal' => []];

$views = [];
$views['vw_legislacao_importacoes'] = "CREATE OR REPLACE VIEW vw_legislacao_importacoes AS
 SELECT i.codimportacao, i.tipo, i.arquivo_nome, i.arquivo_hash, i.referencia, i.status, i.codempresa_origem, i.banco_origem,
        i.enviado_por, i.enviado_por_nome, i.enviado_em, i.autorizado_por, i.autorizado_por_nome, i.autorizado_em,
        i.descartado_por, i.descartado_por_nome, i.descartado_em, i.aplicacao_inicio, i.aplicacao_fim,
        i.total_incluir, i.total_trocar, i.total_retirar, i.total_manter, i.total_padroes, i.total_problemas,
        i.total_aplicados, i.total_ignorados, i.total_erros, i.resumo, i.problemas, i.log,
        (SELECT COUNT(*) FROM legislacao_importacao_itens x WHERE x.codimportacao = i.codimportacao AND x.acao = 'RETIRAR' AND x.selecionado = 1) AS total_retirar_marcadas
   FROM legislacao_importacoes i";
$views['vw_legislacao_importacao_itens'] = "CREATE OR REPLACE VIEW vw_legislacao_importacao_itens AS
 SELECT x.coditem, x.codimportacao, x.acao, x.codempresa, x.empresa_nome, x.codunidade, x.unidade_nome, x.linha, x.coluna,
        x.codigo_lei, x.codlegislacao_base, x.codlegxemp, x.codlegislacao_atual, x.codigo_atual, x.tipo_atual, x.tipo_novo,
        x.codigo_novo, x.padrao_novo, x.alt_tipo, x.alt_descricao, x.alt_data, x.selecionado, x.status, x.mensagem, x.aplicado_em
   FROM legislacao_importacao_itens x";

$sp = [];
$sp['sp_legimp_itens_incluir'] = "CREATE PROCEDURE sp_legimp_itens_incluir(IN p_codimportacao INT, IN p_itens JSON)
SQL SECURITY INVOKER
BEGIN
  IF NOT EXISTS (SELECT 1 FROM legislacao_importacoes WHERE codimportacao = p_codimportacao AND status = 'CONFERIDA') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pedido de importação não encontrado ou já fechado';
  END IF;
  INSERT INTO legislacao_importacao_itens (codimportacao, acao, codempresa, empresa_nome, codunidade, unidade_nome, linha, coluna,
         codigo_lei, codlegislacao_base, codlegxemp, codlegislacao_atual, codigo_atual, tipo_atual, tipo_novo, codigo_novo, padrao_novo, alt_tipo, alt_descricao, alt_data, selecionado)
  SELECT p_codimportacao, j.acao, j.codempresa, j.empresa_nome, j.codunidade, j.unidade_nome, j.linha, j.coluna,
         j.codigo_lei, j.codlegislacao_base, j.codlegxemp, j.codlegislacao_atual, j.codigo_atual, j.tipo_atual, j.tipo_novo, j.codigo_novo,
         COALESCE(j.padrao_novo, 0), j.alt_tipo, j.alt_descricao, j.alt_data, IF(j.acao = 'RETIRAR', 0, 1)
    FROM JSON_TABLE(p_itens, '\$[*]' COLUMNS (
         acao VARCHAR(10) PATH '\$.acao', codempresa INT PATH '\$.codempresa', empresa_nome VARCHAR(150) PATH '\$.empresa_nome',
         codunidade INT PATH '\$.codunidade', unidade_nome VARCHAR(150) PATH '\$.unidade_nome', linha INT PATH '\$.linha', coluna VARCHAR(4) PATH '\$.coluna',
         codigo_lei VARCHAR(80) PATH '\$.codigo_lei', codlegislacao_base INT PATH '\$.codlegislacao_base', codlegxemp INT PATH '\$.codlegxemp',
         codlegislacao_atual INT PATH '\$.codlegislacao_atual', codigo_atual VARCHAR(90) PATH '\$.codigo_atual', tipo_atual VARCHAR(20) PATH '\$.tipo_atual',
         tipo_novo VARCHAR(20) PATH '\$.tipo_novo', codigo_novo VARCHAR(90) PATH '\$.codigo_novo', padrao_novo TINYINT PATH '\$.padrao_novo',
         alt_tipo VARCHAR(250) PATH '\$.alt_tipo', alt_descricao TEXT PATH '\$.alt_descricao', alt_data DATE PATH '\$.alt_data')) j
   WHERE j.acao IN ('INCLUIR', 'TROCAR', 'RETIRAR', 'NOVA_LEI', 'ATUALIZAR', 'ALTERACAO');
  SELECT ROW_COUNT() AS registros_afetados;
END";


try {
    $env = GreenPortalDatabase::env();
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->exec('SET NAMES utf8mb4');
    if (!MigracaoCrud::existe($u, 'legislacao_importacao_itens')) throw new RuntimeException('Rode antes as migrações 397 e 398.');
    $tem = static function (string $c) use ($u): bool { $s = $u->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacao_importacao_itens' AND COLUMN_NAME = ?"); $s->execute([$c]); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0; };
    foreach (['alt_tipo' => 'VARCHAR(250) NULL', 'alt_descricao' => 'TEXT NULL', 'alt_data' => 'DATE NULL'] as $c => $def) {
        if ($tem($c)) { $rel['universal']['legislacao_importacao_itens.' . $c] = 'já existe'; continue; }
        if (!$aplicar) { $rel['universal']['legislacao_importacao_itens.' . $c] = 'será criada'; continue; }
        $u->exec("ALTER TABLE legislacao_importacao_itens ADD COLUMN $c $def AFTER padrao_novo"); $rel['universal']['legislacao_importacao_itens.' . $c] = 'criada';
    }
    foreach ($views as $nome => $sql) {
        if (!$aplicar) { $rel['universal'][$nome] = 'será regravada'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
    foreach ($sp as $nome => $sql) {
        if (!$aplicar) { $rel['universal'][$nome] = 'será regravada'; continue; }
        $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); $rel['universal'][$nome] = 'ok';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
