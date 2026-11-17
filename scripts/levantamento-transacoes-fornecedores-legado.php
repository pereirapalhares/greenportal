<?php
declare(strict_types=1);

/*
 * Levantamento (SOMENTE LEITURA) das transações do legado usadas pelo módulo Homologação de Fornecedores
 * (links do menu ../newlegislacao/cadastros/grid_tabulacao.php?transacao=...).
 * Banco de configuração: hpr_configuracoes (configuracoes_transacoes, configuracoes_grids e demais tabelas com codtransacao).
 * Não grava nada. Resultado completo em scripts/saida/levantamento-transacoes-fornecedores-legado.json.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\levantamento-transacoes-fornecedores-legado.php [--banco=hpr_configuracoes] [--prefixo=fo]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$arg = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return substr($a, strlen($nome) + 3);
    return $padrao;
};
$prefixo = $arg('prefixo', 'fo');
$extras = ['gdeFornecedores', 'mlTreinamentos'];
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($is): array { $s = $is->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };

$banco = $arg('banco', '');
if ($banco === '') {
    foreach (['hpr_configuracoes', 'hrp_configuracoes'] as $b) if ($q('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$b])) { $banco = $b; break; }
}
$rel = ['ok' => true, 'gerado_em' => date('c'), 'banco' => $banco, 'erros' => []];
try {
    if ($banco === '') throw new RuntimeException('banco hpr_configuracoes/hrp_configuracoes não encontrado');
    $tabelas = $q("SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME", [$banco]);
    $rel['tabelas'] = $tabelas;
    $cols = [];
    foreach ($q("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION", [$banco]) as $c)
        $cols[$c['TABLE_NAME']][$c['COLUMN_NAME']] = $c['COLUMN_TYPE'];
    $rel['colunas'] = $cols;

    $c = GreenPortalDatabase::connect($env, $banco, 'GPT');
    $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $s = $c->prepare("SELECT * FROM configuracoes_transacoes WHERE dsctransacao LIKE ? OR dsctransacao IN ('" . implode("','", $extras) . "') ORDER BY dsctransacao");
    $s->execute([$prefixo . '%']);
    $trans = $s->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_values(array_filter(array_map(static fn($t) => (int)($t['codtransacao'] ?? $t['CODTRANSACAO'] ?? 0), $trans)));
    $rel['transacoes'] = [];
    foreach ($trans as $t) $rel['transacoes'][(string)($t['dsctransacao'] ?? $t['DSCTRANSACAO'])] = ['transacao' => $t];
    if ($ids) {
        $in = implode(',', $ids);
        foreach ($cols as $tab => $cs) {
            if ($tab === 'configuracoes_transacoes') continue;
            $col = null;
            foreach (array_keys($cs) as $n) if (strtolower($n) === 'codtransacao') $col = $n;
            if (!$col) continue;
            try {
                foreach ($c->query("SELECT * FROM `$tab` WHERE `$col` IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    foreach ($rel['transacoes'] as $nome => &$info) {
                        if ((int)($info['transacao']['codtransacao'] ?? $info['transacao']['CODTRANSACAO'] ?? 0) === (int)$r[$col]) { $info[$tab][] = $r; break; }
                    }
                    unset($info);
                }
            } catch (Throwable $e) { $rel['erros'][] = "$tab: " . $e->getMessage(); }
        }
    }
    // Tabelas filhas sem codtransacao mas ligadas por grid (ex.: combos, regras) — só contagem para saber o que existe
    foreach ($tabelas as $t) if ($t['TABLE_TYPE'] === 'BASE TABLE') {
        try { $rel['contagem'][$t['TABLE_NAME']] = (int)$c->query("SELECT COUNT(*) FROM `{$t['TABLE_NAME']}`")->fetchColumn(); } catch (Throwable $e) {}
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erros'][] = $e->getMessage(); }

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'saida';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$arq = $dir . DIRECTORY_SEPARATOR . 'levantamento-transacoes-fornecedores-legado.json';
$json = (string)json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
file_put_contents($arq, $json);
$resumo = ['arquivo' => $arq, 'bytes' => strlen($json), 'banco' => $banco, 'erros' => $rel['erros'], 'transacoes' => []];
foreach ($rel['transacoes'] ?? [] as $n => $i) $resumo['transacoes'][$n] = array_map(static fn($v) => is_array($v) && array_is_list($v) ? count($v) : 1, $i);
echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
