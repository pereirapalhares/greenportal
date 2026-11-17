<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Atlas, Homologação de Fornecedores (19/10/2026):
 *  1. documentos do fornecedor (fo_fornecedorxcriterios) com diferença REAL entre legado e greenportal — validade, usuário
 *     do fornecedor/empresa, observações, protocolo, cobrança, contrato, reação — com os valores dos dois lados, o fornecedor,
 *     o documento e a última alteração registrada no histórico de cada lado. Não conta "Enviado?" e "Situação" vazios no legado
 *     (o greenportal preenche pela regra da migração 301).
 *  2. histórico de alterações: o legado inteiro está guardado no greenportal (tabela ativa + backup da migração 217,
 *     fo_fornecedorxcriterios_historico_bkp217)? Conta por CODHISTORICO o que está em cada lugar e o que falta.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-atlas-documentos-historico.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gatlas_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$leg = (string)HomologacaoBancos::legado($banco);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$existe = static fn(string $db, string $t): bool => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$G = "`$banco`"; $L = "`$leg`";
$saida = ['banco' => $banco, 'legado' => $leg, 'gerado_em' => date('c')];

// ---------- 1. Documentos com diferença real ----------
$campos = ['datadevalidade', 'datadereacao', 'codusuario', 'codusuario_fornecedor', 'observacao', 'observacao_do_fornecedor', 'emprotocolo', 'datacobranca_emprotocolo', 'incluidocontrato'];
$dif = implode(' OR ', array_map(static fn($c) => "NOT (CAST(d.`$c` AS CHAR) <=> CAST(s.`$c` AS CHAR))", $campos));
// enviado/situação só contam quando os dois lados estão preenchidos e diferentes
$difTexto = "(COALESCE(s.enviadoodocumento, '') <> '' AND NOT (d.enviadoodocumento <=> s.enviadoodocumento)) OR (COALESCE(s.situacao, '') <> '' AND NOT (d.situacao <=> s.situacao))";
$sel = ['d.codfornecedorxcriterios AS codigo', "COALESCE(f.razaosocial, CONCAT('Fornecedor ', d.codfornecedor)) AS fornecedor", "CONCAT(COALESCE(t.DSCTIPO, ''), ' / ', COALESCE(c.DSCRITERIO, '')) AS documento"];
foreach (array_merge(['enviadoodocumento', 'situacao'], $campos) as $c) { $sel[] = "d.`$c` AS `{$c}_greenportal`"; $sel[] = "s.`$c` AS `{$c}_legado`"; }
$histG = $existe($banco, 'fo_fornecedorxcriterios_historico') ? "(SELECT MAX(h.DATAALTERACAO) FROM $G.fo_fornecedorxcriterios_historico h WHERE h.codfornecedorxcriterios = d.codfornecedorxcriterios)" : 'NULL';
$histL = "(SELECT MAX(h.DATAALTERACAO) FROM $L.fo_fornecedorxcriterios_historico h WHERE h.codfornecedorxcriterios = d.codfornecedorxcriterios)";
$sel[] = "$histG AS ultima_alteracao_greenportal";
$sel[] = "$histL AS ultima_alteracao_legado";
$linhas = $q('SELECT ' . implode(', ', $sel) . " FROM $G.fo_fornecedorxcriterios d JOIN $L.fo_fornecedorxcriterios s ON s.codfornecedorxcriterios = d.codfornecedorxcriterios
    LEFT JOIN $G.fo_fornecedores f ON f.codfornecedor = d.codfornecedor LEFT JOIN $G.fo_tipofornecedores t ON t.codtipofornecedor = d.codtipo LEFT JOIN $G.fo_criteriofornecedores c ON c.codcriteriofornecedor = d.codcriterio
    WHERE $dif OR $difTexto ORDER BY d.codfornecedorxcriterios");
$docs = [];
foreach ($linhas as $l) {
    $mudou = [];
    foreach (array_merge(['enviadoodocumento', 'situacao'], $campos) as $c)
        if ((string)$l[$c . '_greenportal'] !== (string)$l[$c . '_legado'] && !(in_array($c, ['enviadoodocumento', 'situacao'], true) && trim((string)$l[$c . '_legado']) === ''))
            $mudou[$c] = ['greenportal' => $l[$c . '_greenportal'], 'legado' => $l[$c . '_legado']];
    if (!$mudou) continue;
    $docs[] = ['codigo' => (int)$l['codigo'], 'fornecedor' => $l['fornecedor'], 'documento' => $l['documento'], 'diferencas' => $mudou,
        'ultima_alteracao_greenportal' => $l['ultima_alteracao_greenportal'], 'ultima_alteracao_legado' => $l['ultima_alteracao_legado']];
}
$saida['documentos_com_diferenca_real'] = ['quantidade' => count($docs), 'lista' => $docs,
    'nota' => 'última alteração = maior DATAALTERACAO do histórico de cada lado (indica quem mexeu por último)'];

// ---------- 2. Histórico: o legado inteiro está no greenportal? ----------
$bkp = 'fo_fornecedorxcriterios_historico_bkp217';
$h = ['legado' => (int)$v("SELECT COUNT(*) FROM $L.fo_fornecedorxcriterios_historico"),
    'greenportal_ativa' => (int)$v("SELECT COUNT(*) FROM $G.fo_fornecedorxcriterios_historico"),
    'greenportal_backup_217' => $existe($banco, $bkp) ? (int)$v("SELECT COUNT(*) FROM $G.`$bkp`") : 'tabela de backup não existe'];
$h['legado_maior_codigo'] = (int)$v("SELECT MAX(CODHISTORICO) FROM $L.fo_fornecedorxcriterios_historico");
$h['legado_ultima_alteracao'] = $v("SELECT MAX(DATAALTERACAO) FROM $L.fo_fornecedorxcriterios_historico");
if ($existe($banco, $bkp)) {
    $h['backup_maior_codigo'] = (int)$v("SELECT MAX(CODHISTORICO) FROM $G.`$bkp`");
    $h['backup_ultima_alteracao'] = $v("SELECT MAX(DATAALTERACAO) FROM $G.`$bkp`");
    $h['legado_que_nao_esta_em_lugar_nenhum'] = (int)$v("SELECT COUNT(*) FROM $L.fo_fornecedorxcriterios_historico s
        WHERE NOT EXISTS (SELECT 1 FROM $G.fo_fornecedorxcriterios_historico a WHERE a.CODHISTORICO = s.CODHISTORICO)
          AND NOT EXISTS (SELECT 1 FROM $G.`$bkp` b WHERE b.CODHISTORICO = s.CODHISTORICO)");
    if ($h['legado_que_nao_esta_em_lugar_nenhum'] > 0) {
        $h['faltando_por_situacao_do_fornecedor'] = $q("SELECT CASE WHEN f.codfornecedor IS NULL THEN 'fornecedor inexistente no greenportal' WHEN COALESCE(f.ativadofor, '') = 'Sim' THEN 'fornecedor ativo' ELSE 'fornecedor desativado' END AS motivo,
                COUNT(*) AS linhas, MIN(s.DATAALTERACAO) AS primeira, MAX(s.DATAALTERACAO) AS ultima
              FROM $L.fo_fornecedorxcriterios_historico s LEFT JOIN $G.fo_fornecedores f ON f.codfornecedor = s.codfornecedor
             WHERE NOT EXISTS (SELECT 1 FROM $G.fo_fornecedorxcriterios_historico a WHERE a.CODHISTORICO = s.CODHISTORICO)
               AND NOT EXISTS (SELECT 1 FROM $G.`$bkp` b WHERE b.CODHISTORICO = s.CODHISTORICO) GROUP BY 1");
    }
}
$h['ativa_so_no_greenportal'] = (int)$v("SELECT COUNT(*) FROM $G.fo_fornecedorxcriterios_historico a WHERE NOT EXISTS (SELECT 1 FROM $L.fo_fornecedorxcriterios_historico s WHERE s.CODHISTORICO = a.CODHISTORICO)");
$h['ativa_por_fornecedor'] = $q("SELECT CASE WHEN f.codfornecedor IS NULL THEN 'fornecedor inexistente' WHEN COALESCE(f.ativadofor, '') = 'Sim' THEN 'fornecedor ativo' ELSE 'fornecedor desativado' END AS motivo, COUNT(*) AS linhas
    FROM $G.fo_fornecedorxcriterios_historico a LEFT JOIN $G.fo_fornecedores f ON f.codfornecedor = a.codfornecedor GROUP BY 1");
$saida['historico'] = $h;

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_atlas_documentos_historico_' . $banco . '.json', $json);
echo $json . PHP_EOL;
