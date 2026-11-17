<?php
declare(strict_types=1);

/*
 * Diagnóstico: tipos de não conformidade do legado × greenportal (22/10/2026). SOMENTE LEITURA — não grava em nenhum banco.
 * Levanta no legado:
 *  1. todos os tipos de nc_tipo_de_naoconformidade, com volume de ocorrências (total, abertas, última, desde 2025) e campos configurados;
 *  2. tabelas de módulos "irmãos" fora de nc_indicacao_ocorrencia (near miss / quase-acidente, acidentes, incidentes) com linhas e última data;
 *  3. itens do menu do legado que apontam para telas de NC, near miss ou acidentes;
 *  4. ocorrências com tipo inexistente ou vazio.
 * Tipos que o greenportal grava hoje: 3 Empresa, 1 Fornecedor, 5 Planejamento, 2 Cliente, 6 Assistência Técnica.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-tipos-legado.php [--origem=green_crown]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$origem = 'green_crown';
foreach ($argv ?? [] as $a) if (preg_match('/^--origem=([A-Za-z0-9_]+)$/', $a, $m)) $origem = $m[1];
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4');
$q = static function (string $sql, array $p = []) use ($pdo): array { try { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(); $s->closeCursor(); return $r; } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 250)]; } };
$v = static function (string $sql, array $p = []) use ($q) { $r = $q($sql, $p); return isset($r['erro']) ? $r['erro'] : ($r ? reset($r[0]) : null); };
$id = static fn(string $s): string => '`' . str_replace('`', '', $s) . '`';
$O = $id($origem);
$GP = ['1' => 'Fornecedor', '2' => 'Cliente', '3' => 'Empresa', '5' => 'Planejamento', '6' => 'Assistência Técnica'];

$colunas = static function (string $banco, string $tabela) use ($q): array {
    $r = $q('SELECT COLUMN_NAME c, DATA_TYPE t FROM COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$banco, $tabela]);
    return isset($r['erro']) ? [] : array_column($r, 't', 'c');
};

$rel = ['diagnostico' => 'tipos de NC do legado', 'origem' => $origem, 'gerado_em' => date('c')];

// 1. tipos
$colTipo = $colunas($origem, 'nc_tipo_de_naoconformidade');
$rel['colunas_nc_tipo_de_naoconformidade'] = array_keys($colTipo);
$tipos = $q("SELECT * FROM $O.nc_tipo_de_naoconformidade ORDER BY 1");
$colOc = $colunas($origem, 'nc_indicacao_ocorrencia');
$dataCriacao = isset($colOc['data_de_criacao']) ? 'data_de_criacao' : (isset($colOc['DATA_DE_CRIACAO']) ? 'DATA_DE_CRIACAO' : null);
$rel['tipos'] = [];
if (!isset($tipos['erro'])) foreach ($tipos as $t) {
    $cod = (string)reset($t);
    $W = 'o.CODTIPONAOCONFORMIDADE = ' . (int)$cod;
    $rel['tipos'][$cod] = [
        'cadastro' => $t,
        'no_greenportal' => $GP[$cod] ?? 'NÃO CONTEMPLADO',
        'ocorrencias' => (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o WHERE $W"),
        'abertas' => (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o WHERE $W AND COALESCE(o.ETAPA,'') <> 'Finalizado'"),
        'ultima_criada' => $dataCriacao ? $v("SELECT MAX(o.$dataCriacao) FROM $O.nc_indicacao_ocorrencia o WHERE $W") : null,
        'desde_2025' => $dataCriacao ? (int)$v("SELECT COUNT(*) FROM $O.nc_indicacao_ocorrencia o WHERE $W AND o.$dataCriacao >= '2025-01-01'") : null,
        'etapas_abertas' => $q("SELECT o.ETAPA, COUNT(*) n FROM $O.nc_indicacao_ocorrencia o WHERE $W AND COALESCE(o.ETAPA,'') <> 'Finalizado' GROUP BY 1 ORDER BY n DESC LIMIT 8"),
        'campos_configurados' => (int)$v("SELECT COUNT(*) FROM $O.nc_tipo_de_naoconformidadexcampos x WHERE x.CODTIPONAOCONFORMIDADE = " . (int)$cod),
    ];
} else $rel['tipos'] = $tipos;
$rel['ocorrencias_sem_tipo_valido'] = $q("SELECT COALESCE(o.CODTIPONAOCONFORMIDADE, 'NULL') tipo, COUNT(*) n FROM $O.nc_indicacao_ocorrencia o
    WHERE o.CODTIPONAOCONFORMIDADE IS NULL OR NOT EXISTS (SELECT 1 FROM $O.nc_tipo_de_naoconformidade t WHERE t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE) GROUP BY 1");

// 2. tabelas de módulos irmãos (near miss, acidentes, incidentes)
$rel['tabelas_modulos_irmaos'] = [];
$tabs = $q("SELECT TABLE_NAME n FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
    AND (TABLE_NAME LIKE '%nearmiss%' OR TABLE_NAME LIKE '%near\\_miss%' OR TABLE_NAME LIKE '%acidente%' OR TABLE_NAME LIKE '%incidente%' OR TABLE_NAME LIKE '%quase%' OR TABLE_NAME LIKE 'ac\\_%' OR TABLE_NAME LIKE 'ga\\_%') ORDER BY 1", [$origem]);
if (!isset($tabs['erro'])) foreach ($tabs as $t) {
    $n = (string)$t['n']; $cols = $colunas($origem, $n);
    $datas = array_keys(array_filter($cols, static fn($tp) => in_array($tp, ['date', 'datetime', 'timestamp'], true)));
    $item = ['linhas' => (int)$v('SELECT COUNT(*) FROM ' . $O . '.' . $id($n))];
    if ($datas && $item['linhas'] > 0) {
        $d = $datas[0];
        $item['coluna_data'] = $d;
        $item['ultima_data'] = $v('SELECT MAX(' . $id($d) . ') FROM ' . $O . '.' . $id($n));
        $item['desde_2025'] = (int)$v('SELECT COUNT(*) FROM ' . $O . '.' . $id($n) . ' WHERE ' . $id($d) . " >= '2025-01-01'");
    }
    if (isset($cols['ETAPA']) && $item['linhas'] > 0) $item['etapas'] = $q('SELECT ETAPA, COUNT(*) n FROM ' . $O . '.' . $id($n) . ' GROUP BY 1 ORDER BY n DESC LIMIT 8');
    $rel['tabelas_modulos_irmaos'][$n] = $item;
} else $rel['tabelas_modulos_irmaos'] = $tabs;

// 3. menu do legado: tabelas com "menu" no nome, no banco da empresa, no de segurança e no universal
$alvo = '/(nc_|naoconform|n[aã]o[ _-]?conform|nearmiss|near miss|quase|acidente|incidente)/iu';
$rel['menu_legado'] = [];
foreach ([$origem, $origem . '_seguranca', 'green_universal'] as $banco) {
    $mt = $q("SELECT TABLE_NAME n FROM TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE '%menu%'", [$banco]);
    if (isset($mt['erro'])) { $rel['menu_legado'][$banco] = $mt; continue; }
    foreach ($mt as $t) {
        $n = (string)$t['n'];
        $linhas = $q('SELECT * FROM ' . $id($banco) . '.' . $id($n) . ' LIMIT 20000');
        if (isset($linhas['erro'])) { $rel['menu_legado']["$banco.$n"] = $linhas; continue; }
        $achados = [];
        foreach ($linhas as $l) {
            $texto = implode(' | ', array_map(static fn($x) => (string)$x, $l));
            if (preg_match($alvo, $texto)) $achados[] = array_map(static fn($x) => is_string($x) ? mb_substr($x, 0, 160) : $x, $l);
        }
        $rel['menu_legado']["$banco.$n"] = ['linhas_tabela' => count($linhas), 'itens_nc_nearmiss_acidente' => array_slice($achados, 0, 80)];
    }
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_tipos_' . $origem . '.json', $json);
echo $json . PHP_EOL;
