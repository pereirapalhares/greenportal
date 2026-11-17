<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — Licenças × condicionantes: legado × greenportal.
 * Legado (configuracoes/li_licencas.php + cadastros/li_licencas_c.php): aba "Condicionantes" da licença (li_condicionantes,
 * com CODLICENCA) e aba "Plano de Ação" da condicionante (li_planodeacao); "Situação das Condicionantes" (li_situacao,
 * licença × condicionante) e a correlação li_licencasxcondicionantes; modelo antigo: licencasxcondicionantes,
 * condicionantes, avaliacao_condicionante, planodeacao_condicionantes.
 * Greenportal: manutencaolegal_licencas_condicionantes.php (vw_licencas_condicionantes_grid sobre li_condicionantes) e
 * manutencaolegal_licencas_condicionantesplanodeacao.php (li_planodeacao).
 * Para cada empresa: tabelas e linhas dos dois lados, o que falta/sobra/difere por chave, órfãos e responsáveis.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-licencas-condicionantes.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => (static fn(array $r) => $r ? array_values($r[0])[0] : null)($q($sql, $p));
$n = static fn(string $s): string => '`' . str_replace('`', '``', $s) . '`';
$cols = static function (string $db, string $t) use ($q): array { $o = []; foreach ($q('SELECT COLUMN_NAME c FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]) as $r) $o[strtolower($r['c'])] = $r['c']; return $o; };
$tabExiste = static fn(string $db, string $t): bool => (bool)$v('SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]);

$tabelas = ['li_licencas', 'li_condicionantes', 'li_planodeacao', 'li_situacao', 'licencasxcondicionantes', 'condicionantes', 'avaliacao_condicionante', 'planodeacao_condicionantes'];
$chaves = ['li_licencas' => 'CODLICENCA', 'li_condicionantes' => 'CODCONDICIONANTE', 'li_planodeacao' => 'CODPLANOACAO', 'li_situacao' => 'CODSITUACAO'];
$rel = ['diagnostico' => 'licenças × condicionantes (legado × greenportal)', 'gerado_em' => date('c'), 'empresas' => []];

foreach (HomologacaoBancos::lista($argv ?? []) as $novo) {
    $leg = HomologacaoBancos::legado($novo);
    $e = ['legado' => $leg, 'tabelas' => [], 'comparacao' => [], 'alertas' => []];
    try {
        foreach ($tabelas as $t) {
            $e['tabelas'][$t] = [
                'legado' => $tabExiste($leg, $t) ? (int)$v('SELECT COUNT(*) FROM ' . $n($leg) . '.' . $n($t)) : 'não existe',
                'greenportal' => $tabExiste($novo, $t) ? (int)$v('SELECT COUNT(*) FROM ' . $n($novo) . '.' . $n($t)) : 'não existe',
            ];
        }
        // comparação por chave nas tabelas usadas pelas telas
        foreach ($chaves as $t => $pk) {
            if (!$tabExiste($leg, $t) || !$tabExiste($novo, $t)) continue;
            $cL = $cols($leg, $t); $cN = $cols($novo, $t);
            $comuns = array_values(array_intersect_key($cN, $cL));
            if (!isset($cL[strtolower($pk)], $cN[strtolower($pk)])) { $e['comparacao'][$t] = 'sem a chave ' . $pk . ' nos dois lados'; continue; }
            $L = $n($leg) . '.' . $n($t); $N = $n($novo) . '.' . $n($t);
            $dif = [];
            foreach ($comuns as $c) {
                if (strcasecmp($c, $pk) === 0) continue;
                $qtd = (int)$v("SELECT COUNT(*) FROM $L l JOIN $N g ON g.`$pk` = l.`$pk` WHERE NOT (CAST(g.`$c` AS CHAR) <=> CAST(l.`" . $cL[strtolower($c)] . "` AS CHAR))");
                if ($qtd) $dif[$c] = $qtd;
            }
            $e['comparacao'][$t] = [
                'faltam_no_greenportal' => (int)$v("SELECT COUNT(*) FROM $L l WHERE NOT EXISTS (SELECT 1 FROM $N g WHERE g.`$pk` = l.`$pk`)"),
                'so_no_greenportal' => (int)$v("SELECT COUNT(*) FROM $N g WHERE NOT EXISTS (SELECT 1 FROM $L l WHERE l.`$pk` = g.`$pk`)"),
                'colunas_com_valor_diferente' => $dif ?: 'nenhuma',
                'colunas_so_no_legado' => array_values(array_diff_key($cL, $cN)) ?: 'nenhuma',
                'colunas_so_no_greenportal' => array_values(array_diff_key($cN, $cL)) ?: 'nenhuma',
            ];
        }
        // órfãos e responsáveis no greenportal
        if ($tabExiste($novo, 'li_condicionantes')) {
            $C = $n($novo) . '.`li_condicionantes`';
            if ($tabExiste($novo, 'li_licencas')) $e['orfaos']['condicionantes_sem_licenca'] = (int)$v("SELECT COUNT(*) FROM $C c WHERE NOT EXISTS (SELECT 1 FROM " . $n($novo) . ".`li_licencas` l WHERE l.`CODLICENCA` = c.`CODLICENCA`)");
            if ($tabExiste($novo, 'li_planodeacao')) $e['orfaos']['planos_sem_condicionante'] = (int)$v('SELECT COUNT(*) FROM ' . $n($novo) . ".`li_planodeacao` p WHERE NOT EXISTS (SELECT 1 FROM $C c WHERE c.`CODCONDICIONANTE` = p.`CODCONDICIONANTE`)");
            if ($tabExiste($novo, 'usuarios')) {
                $e['responsaveis']['condicionantes_com_responsavel'] = (int)$v("SELECT COUNT(*) FROM $C WHERE COALESCE(`CODUSUARIO`, 0) > 0");
                $e['responsaveis']['responsavel_inexistente_no_greenportal'] = (int)$v("SELECT COUNT(*) FROM $C c WHERE COALESCE(c.`CODUSUARIO`, 0) > 0 AND NOT EXISTS (SELECT 1 FROM " . $n($novo) . ".`usuarios` u WHERE u.`codusuario` = c.`CODUSUARIO`)");
                $seg = $leg . '_seguranca';
                if ($tabExiste($seg, 'usuarios'))
                    $e['responsaveis']['nome_diferente_do_legado'] = (int)$v("SELECT COUNT(*) FROM $C c JOIN " . $n($novo) . ".`usuarios` u ON u.`codusuario` = c.`CODUSUARIO` JOIN " . $n($seg) . ".`usuarios` lu ON lu.`codusuario` = c.`CODUSUARIO` WHERE NOT (TRIM(u.`nome`) <=> TRIM(lu.`nome`))");
            }
            $e['valores_greenportal']['FREQUENCIA'] = array_column($q("SELECT COALESCE(`FREQUENCIA`, '(vazio)') v, COUNT(*) n FROM $C GROUP BY 1 ORDER BY 2 DESC LIMIT 20"), 'n', 'v');
            $e['valores_greenportal']['ATENDIDO'] = array_column($q("SELECT COALESCE(`ATENDIDO`, '(vazio)') v, COUNT(*) n FROM $C GROUP BY 1 ORDER BY 2 DESC LIMIT 20"), 'n', 'v');
        }
        if ($tabExiste($novo, 'li_planodeacao')) $e['valores_greenportal']['PLANO_STATUS'] = array_column($q('SELECT COALESCE(`STATUS`, \'(vazio)\') v, COUNT(*) n FROM ' . $n($novo) . '.`li_planodeacao` GROUP BY 1 ORDER BY 2 DESC LIMIT 20'), 'n', 'v');
        // views das telas
        foreach (['vw_licencas_condicionantes_grid', 'vw_licencas_condicionantes_planodeacao_grid'] as $vw)
            $e['views'][$vw] = $v('SELECT COUNT(*) FROM VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$novo, $vw]) ? (int)$v('SELECT COUNT(*) FROM ' . $n($novo) . '.' . $n($vw)) . ' linha(s)' : 'NÃO EXISTE';
        foreach ($e['tabelas'] as $t => $x) if (is_int($x['legado']) && $x['legado'] > 0 && !is_int($x['greenportal'])) $e['alertas'][] = "$t: {$x['legado']} linha(s) no legado e a tabela não existe no greenportal";
        foreach ($e['comparacao'] as $t => $x) if (is_array($x) && ($x['faltam_no_greenportal'] || $x['so_no_greenportal'] || is_array($x['colunas_com_valor_diferente']))) $e['alertas'][] = "$t: faltam {$x['faltam_no_greenportal']}, só no greenportal {$x['so_no_greenportal']}" . (is_array($x['colunas_com_valor_diferente']) ? ', valores diferentes em ' . implode(', ', array_keys($x['colunas_com_valor_diferente'])) : '');
    } catch (Throwable $ex) { $e['erro'] = $ex->getMessage(); }
    $rel['empresas'][$novo] = $e;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
