<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) de uma transação do legado (hpr_configuracoes): configuração da tela
 * (configuracoes_transacoes, colunas do grid, filtros, abas, botões...) e, em cada cliente, a tabela da transação
 * (registros novo x legado, colunas, valores das colunas de texto curto, datas) e a pasta do GED com o nome da tabela.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-hpr-config-transacao.php --transacao=foFuncionariosxTreinamentos
 * Saída: tela + storage/logs/diagnostico_hpr_config_<transacao>.json
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$transacao = 'foFuncionariosxTreinamentos';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--transacao=')) $transacao = trim(substr($a, 12));
if (!preg_match('/^[A-Za-z0-9_]+$/', $transacao)) { fwrite(STDERR, "Transação inválida\n"); exit(1); }
$env = GreenPortalDatabase::env();
$saida = ['transacao' => $transacao, 'data' => date('c')];
$limpa = static fn(array $r, int $n = 3000): array => array_map(static fn($v) => is_string($v) && mb_strlen($v) > $n ? mb_substr($v, 0, $n) . '…' : $v, array_filter($r, static fn($v) => $v !== null && $v !== ''));

$tabela = '';
try {
    $h = null;
    foreach (['LEGADO_SEGURANCA', 'GPT'] as $prefixo) {
        if (empty($env[$prefixo . '_DB_HOST'])) continue;
        try { $h = GreenPortalDatabase::connect($env, 'hpr_configuracoes', $prefixo); break; } catch (Throwable) {}
    }
    if (!$h) throw new RuntimeException('não conectou em hpr_configuracoes');
    $h->exec('SET NAMES utf8mb4');
    $s = $h->prepare('SELECT * FROM configuracoes_transacoes WHERE LOWER(dsctransacao) = LOWER(?)'); $s->execute([$transacao]);
    $tr = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$tr) throw new RuntimeException("transação $transacao não encontrada");
    $saida['legado']['transacao'] = array_map($limpa, $tr);
    $tabela = strtolower((string)($tr[0]['tabela'] ?? ''));
    foreach ($h->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(COLUMN_NAME) = 'codtransacao' AND TABLE_NAME <> 'configuracoes_transacoes'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $s = $h->prepare("SELECT * FROM `$t` WHERE codtransacao = ? LIMIT 200"); $s->execute([$tr[0]['codtransacao']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;
        if ($t === 'configuracoes_grids') {
            usort($rows, static fn($a, $b) => (int)($a['orderdocampo'] ?? 0) <=> (int)($b['orderdocampo'] ?? 0));
            $saida['legado']['grid'] = array_map(static fn($r) => ($r['orderdocampo'] ?? '') . ' ' . ($r['campo'] ?? '') . ' = "' . ($r['label'] ?? '') . '" tam ' . ($r['tamanho'] ?? '') . ' tipo ' . ($r['tipo'] ?? '') . ' ' . ($r['busca'] ?? ''), $rows);
        } else $saida['legado']['configuracao'][$t] = array_map(static fn($r) => $limpa($r, 600), $rows);
    }
} catch (Throwable $e) { $saida['legado']['erro'] = $e->getMessage(); }

foreach (['gatlas_dbp' => 'green_atlas', 'gmodelo_dbp' => 'green_modelo'] as $banco => $legado) {
    $r = [];
    try {
        if ($tabela === '' || !preg_match('/^[a-z0-9_]+$/', $tabela)) throw new RuntimeException('tabela da transação desconhecida');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };
        $existe = static fn(string $b, string $t): bool => (bool)$q('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t])[0]['n'];
        $r['registros'] = ['novo' => $existe($banco, $tabela) ? (int)$q("SELECT COUNT(*) n FROM `$banco`.`$tabela`")[0]['n'] : 'NÃO EXISTE', 'legado' => $existe($legado, $tabela) ? (int)$q("SELECT COUNT(*) n FROM `$legado`.`$tabela`")[0]['n'] : 'NÃO EXISTE'];
        if ($existe($banco, $tabela)) {
            $cols = array_column($q('SELECT COLUMN_NAME c, COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$banco, $tabela]), 't', 'c');
            $r['colunas'] = $cols;
            foreach ($cols as $c => $tipo) {
                if (preg_match('/^cod/i', $c)) continue;
                if (preg_match('/date|time/i', $tipo)) $r['datas'][$c] = $q("SELECT SUM(`$c` IS NULL OR `$c` < '1900-01-01') vazias, SUM(`$c` > '1900-01-01' AND `$c` < CURDATE()) vencidas, SUM(`$c` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY) ate_30_dias, SUM(`$c` > CURDATE() + INTERVAL 30 DAY) depois FROM `$banco`.`$tabela`")[0];
                elseif (preg_match('/char\((\d+)\)/i', $tipo, $m) && (int)$m[1] <= 120 || preg_match('/^(tinyint|int|enum)/i', $tipo)) $r['valores'][$c] = array_map(static fn($x) => ($x['v'] ?? '(vazio)') . ' = ' . $x['n'], $q("SELECT `$c` v, COUNT(*) n FROM `$banco`.`$tabela` GROUP BY `$c` ORDER BY n DESC LIMIT 12"));
            }
        }
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    if ($tabela !== '') {
        $dir = 'E:\\projetoGreen\\GED\\' . $legado . '\\' . $tabela;
        if (!is_dir($dir)) $r['ged'] = "$dir não existe";
        else { $sub = glob($dir . '\\*', GLOB_ONLYDIR) ?: []; $arq = 0; foreach (array_slice($sub, 0, 3000) as $s) $arq += count(glob($s . '\\*') ?: []); $r['ged'] = count($sub) . " pastas, $arq itens"; }
    }
    $saida['bancos'][$banco] = $r;
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_hpr_config_' . $transacao . '.json', $json);
echo $json . PHP_EOL;
