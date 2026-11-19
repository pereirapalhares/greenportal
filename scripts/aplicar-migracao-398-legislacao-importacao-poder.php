<?php
declare(strict_types=1);

/*
 * Migração 398 — importação da planilha mensal de Legislação x Controle (pedido do usuário de 19/11/2026).
 * Usa a mesma estrutura da 397 (pedido + itens + serviço LEGISLACAO_IMPORTACAO), agora com o TIPO do pedido:
 *   EMPRESA = Legislação x Empresas (397)   PODER = Legislação x Controle (Conhecimento / Controle / Influência)
 *   - legislacao_importacoes.tipo (padrão EMPRESA para os pedidos que já existem);
 *   - legislacao_importacao_itens.tipo_atual / tipo_novo passam a VARCHAR(20) ("Conhecimento", "Influência");
 *   - views com a coluna tipo e sp_legimp_itens_incluir regravada para os textos maiores.
 * Uso: php scripts\aplicar-migracao-398-legislacao-importacao-poder.php [--aplicar]   (pode rodar mais de uma vez)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '398', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'universal' => []];

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
         codlegislacao_atual INT PATH '\$.codlegislacao_atual', codigo_atual VARCHAR(90) PATH '\$.codigo_atual', tipo_atual VARCHAR(20) PATH '\$.tipo_atual',
         tipo_novo VARCHAR(20) PATH '\$.tipo_novo', codigo_novo VARCHAR(90) PATH '\$.codigo_novo', padrao_novo TINYINT PATH '\$.padrao_novo')) j
   WHERE j.acao IN ('INCLUIR', 'TROCAR', 'RETIRAR');
  SELECT ROW_COUNT() AS registros_afetados;
END";

try {
    $env = GreenPortalDatabase::env();
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->exec('SET NAMES utf8mb4');
    if (!MigracaoCrud::existe($u, 'legislacao_importacoes')) throw new RuntimeException('Rode antes a migração 397 (legislacao_importacoes não existe).');
    $col = static function (string $tabela, string $coluna) use ($u): ?array {
        $s = $u->prepare('SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$tabela, $coluna]); $r = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor(); return $r ?: null;
    };
    if ($col('legislacao_importacoes', 'tipo')) $rel['universal']['legislacao_importacoes.tipo'] = 'já existe';
    elseif (!$aplicar) $rel['universal']['legislacao_importacoes.tipo'] = 'será criada (EMPRESA para os pedidos atuais)';
    else { $u->exec("ALTER TABLE legislacao_importacoes ADD COLUMN tipo VARCHAR(10) NOT NULL DEFAULT 'EMPRESA' AFTER codimportacao, ADD KEY ix_legimp_tipo (tipo, codimportacao)"); $rel['universal']['legislacao_importacoes.tipo'] = 'criada'; }
    foreach (['tipo_atual', 'tipo_novo'] as $c) {
        $info = $col('legislacao_importacao_itens', $c);
        if ((int)($info['tamanho'] ?? 0) >= 20) { $rel['universal']['legislacao_importacao_itens.' . $c] = 'já é VARCHAR(20)'; continue; }
        if (!$aplicar) { $rel['universal']['legislacao_importacao_itens.' . $c] = 'passará a VARCHAR(20)'; continue; }
        $u->exec("ALTER TABLE legislacao_importacao_itens MODIFY $c VARCHAR(20) NULL"); $rel['universal']['legislacao_importacao_itens.' . $c] = 'VARCHAR(20)';
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
