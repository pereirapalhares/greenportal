<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Homologação de Fornecedores: tabelas MANTIDAS NO GREENPORTAL (SINC_MESTRE_NOVO do
 * scripts/sincronizar-fo-legado.php: documentos do fornecedor, histórico, tipos/critérios de fornecedor e de funcionário,
 * treinamentos, fornecedor × usuário) nos bancos em que o sincronismo não as traz do legado (MODELO, Atlas, MODELO2).
 * Para cada tabela, pela chave primária: linhas em cada lado, quantas só no legado, só no greenportal e com valores
 * diferentes (colunas em comum), quais colunas mudam e alguns exemplos. Grava em storage/logs.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-homologacao-mestre-novo.php [--banco=gatlas_dbp]
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
$Q = static fn(string $n): string => '`' . str_replace('`', '', $n) . '`';
$cols = static function (string $db, string $t) use ($q): array { $o = []; foreach ($q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]) as $c) $o[strtolower($c['COLUMN_NAME'])] = $c['COLUMN_NAME']; return $o; };

// Mesma lista de SINC_MESTRE_NOVO (scripts/sincronizar-fo-legado.php).
$tabelas = ['fo_criteriofuncionarios', 'fo_tipotreinamentos', 'fo_tipofuncionarios', 'fo_tipofuncionariosxcriteriofuncionarios', 'fo_tipofuncionariosxtipotreinamentos',
    'fo_tipofuncionariosxpalavraschave', 'fo_tipofornecedores', 'fo_criteriofornecedores', 'fo_tipofornecedoresxcriteriofornecedores', 'fo_tipofornecedoresxpalavraschave',
    'fo_fornecedorxcriterios', 'fo_fornecedorxcriterios_historico', 'fo_fornecedoresxtipofornecedores', 'fo_fornecedorxusuario'];

$saida = ['banco' => $banco, 'legado' => $leg, 'gerado_em' => date('c'), 'tabelas' => []];
foreach ($tabelas as $t) {
    $cO = $cols($leg, $t); $cD = $cols($banco, $t);
    if (!$cO || !$cD) { $saida['tabelas'][$t] = ['situacao' => !$cO ? 'não existe no legado' : 'não existe no greenportal']; continue; }
    $pk = array_column($q("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$banco, $t]), 'COLUMN_NAME');
    $r = ['linhas_legado' => (int)$v('SELECT COUNT(*) FROM ' . $Q($leg) . '.' . $Q($t)), 'linhas_greenportal' => (int)$v('SELECT COUNT(*) FROM ' . $Q($banco) . '.' . $Q($t))];
    $pkComum = $pk && !array_diff(array_map('strtolower', $pk), array_keys($cO));
    if (!$pkComum) { $r['obs'] = 'chave do greenportal não existe no legado: comparada só a contagem'; $saida['tabelas'][$t] = $r; continue; }
    $S = $Q($leg) . '.' . $Q($t); $D = $Q($banco) . '.' . $Q($t);
    $join = implode(' AND ', array_map(static fn($c) => 'd.' . '`' . $c . '` <=> s.`' . $cO[strtolower($c)] . '`', $pk));
    $r['so_no_legado'] = (int)$v("SELECT COUNT(*) FROM $S s WHERE NOT EXISTS (SELECT 1 FROM $D d WHERE $join)");
    $r['so_no_greenportal'] = (int)$v("SELECT COUNT(*) FROM $D d WHERE NOT EXISTS (SELECT 1 FROM $S s WHERE $join)");
    if ($t === 'fo_fornecedorxcriterios_historico') { $saida['tabelas'][$t] = $r; continue; } // só inclusões; valores não mudam
    $comuns = array_values(array_diff(array_keys(array_intersect_key($cD, $cO)), array_map('strtolower', $pk)));
    if ($comuns) {
        $dif = implode(' OR ', array_map(static fn($c) => 'NOT (CAST(d.`' . $cD[$c] . '` AS CHAR) <=> CAST(s.`' . $cO[$c] . '` AS CHAR))', $comuns));
        $r['valores_diferentes'] = (int)$v("SELECT COUNT(*) FROM $D d JOIN $S s ON $join WHERE $dif");
        if ($r['valores_diferentes']) {
            $porColuna = [];
            foreach ($comuns as $c) {
                $n = (int)$v("SELECT COUNT(*) FROM $D d JOIN $S s ON $join WHERE NOT (CAST(d.`" . $cD[$c] . '` AS CHAR) <=> CAST(s.`' . $cO[$c] . '` AS CHAR))');
                if ($n) $porColuna[$cD[$c]] = $n;
            }
            arsort($porColuna);
            $r['colunas_que_mudam'] = $porColuna;
            $chave = implode(',', array_map(static fn($c) => 'd.`' . $c . '`', $pk));
            $mostrar = array_slice(array_keys($porColuna), 0, 4);
            $sel = implode(',', array_map(static fn($c) => 'd.`' . $c . '` AS `' . $c . '_greenportal`, s.`' . $cO[strtolower($c)] . '` AS `' . $c . '_legado`', $mostrar));
            $r['exemplos'] = $q("SELECT $chave, $sel FROM $D d JOIN $S s ON $join WHERE $dif ORDER BY $chave DESC LIMIT 5");
        }
    }
    if ($r['so_no_legado']) $r['exemplos_so_no_legado'] = array_column($q('SELECT ' . implode(',', array_map(static fn($c) => 's.`' . $cO[strtolower($c)] . '`', $pk)) . " AS k FROM $S s WHERE NOT EXISTS (SELECT 1 FROM $D d WHERE $join) LIMIT 10"), 'k');
    $saida['tabelas'][$t] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_homologacao_mestre_novo_' . $banco . '.json', $json);
echo $json . PHP_EOL;
