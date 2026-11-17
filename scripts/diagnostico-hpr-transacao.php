<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) de uma transação do sistema antigo configurada no banco hpr_configuracoes.
 * Parte de configuracoes_transacoes.dsctransacao e segue as chaves (colunas cod*) por todas as tabelas do banco,
 * até não achar mais nada ligado. Nada é alterado.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-hpr-transacao.php --transacao=foTiposTreinamentos
 * Opções: --banco=hpr_configuracoes (padrão)
 * Saída: storage\logs\hpr_<transacao>.json (completo) e um resumo na tela.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opcao = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return trim(substr($a, strlen($nome) + 3));
    return $padrao;
};
$transacao = $opcao('transacao', 'foTiposTreinamentos');
$banco = $opcao('banco', 'hpr_configuracoes');
if (!preg_match('/^[A-Za-z0-9_]+$/', $banco)) { fwrite(STDERR, "Banco inválido\n"); exit(1); }

$env = GreenPortalDatabase::env();
$pdo = null;
foreach (['LEGADO_SEGURANCA', 'GPT'] as $prefixo) {          // o banco antigo pode estar com outro usuário no .env
    if (empty($env[$prefixo . '_DB_HOST'])) continue;
    try { $pdo = GreenPortalDatabase::connect($env, $banco, $prefixo); break; } catch (Throwable $e) { $erro = $e->getMessage(); }
}
if (!$pdo) { fwrite(STDERR, "Não consegui conectar em $banco: " . ($erro ?? '') . "\n"); exit(1); }
$pdo->exec("SET NAMES utf8mb4");
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';

// Estrutura
$estrutura = [];
foreach ($pdo->query("SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $c = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $c->execute([$t['TABLE_NAME']]);
    $estrutura[$t['TABLE_NAME']] = ['tipo' => $t['TABLE_TYPE'], 'linhas_aprox' => (int)$t['TABLE_ROWS'],
        'colunas' => array_map(static fn($x) => $x['COLUMN_NAME'] . ' ' . $x['COLUMN_TYPE'] . ($x['COLUMN_KEY'] === 'PRI' ? ' PK' : ''), $c->fetchAll(PDO::FETCH_ASSOC))];
}
if (!isset($estrutura['configuracoes_transacoes'])) { fwrite(STDERR, "Tabela configuracoes_transacoes não encontrada em $banco\n"); exit(1); }

// Coleta: começa pela transação e segue as colunas cod* em comum
$colunasDe = static function (string $t) use ($estrutura): array {
    return array_map(static fn($c) => explode(' ', $c)[0], $estrutura[$t]['colunas']);
};
$linhas = [];          // tabela => [linhas]
$chaves = [];          // coluna (minúscula) => [valores]
$s = $pdo->prepare('SELECT * FROM configuracoes_transacoes WHERE dsctransacao = ?');
$s->execute([$transacao]);
$linhas['configuracoes_transacoes'] = $s->fetchAll(PDO::FETCH_ASSOC);
if (!$linhas['configuracoes_transacoes']) { fwrite(STDERR, "Transação $transacao não encontrada em configuracoes_transacoes\n"); exit(1); }

$adicionarChaves = static function (array $rows) use (&$chaves): bool {
    $novo = false;
    foreach ($rows as $r) foreach ($r as $col => $val) {
        $c = strtolower((string)$col);
        if (!str_starts_with($c, 'cod') || $val === null || $val === '' || !is_scalar($val)) continue;
        if (!isset($chaves[$c][(string)$val])) { $chaves[$c][(string)$val] = true; $novo = true; }
    }
    return $novo;
};
$adicionarChaves($linhas['configuracoes_transacoes']);

// Só segue chaves "estruturais" (evita puxar meio banco por colunas genéricas como codusuario)
$ignorar = ['codigo', 'cod', 'codusuario', 'codempresa', 'codunidade', 'codidioma'];
for ($volta = 0; $volta < 8; $volta++) {
    $houveNovo = false;
    foreach ($estrutura as $t => $info) {
        if ($info['tipo'] !== 'BASE TABLE') continue;
        $cols = array_map('strtolower', $colunasDe($t));
        $filtros = []; $params = [];
        foreach ($cols as $c) {
            if (in_array($c, $ignorar, true) || empty($chaves[$c])) continue;
            $vals = array_keys($chaves[$c]);
            $filtros[] = $q($c) . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            array_push($params, ...$vals);
        }
        if (!$filtros) continue;
        $s = $pdo->prepare('SELECT * FROM ' . $q($t) . ' WHERE ' . implode(' OR ', $filtros) . ' LIMIT 2000');
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;
        $existentes = array_map('serialize', $linhas[$t] ?? []);
        foreach ($rows as $r) if (!in_array(serialize($r), $existentes, true)) { $linhas[$t][] = $r; $houveNovo = true; }
        if ($adicionarChaves($rows)) $houveNovo = true;
    }
    if (!$houveNovo) break;
}

$resultado = [
    'banco' => $banco, 'transacao' => $transacao, 'gerado_em' => date('Y-m-d H:i:s'),
    'tabelas_do_banco' => $estrutura,
    'linhas_ligadas_a_transacao' => $linhas,
    'chaves_seguidas' => array_map(static fn($v) => array_keys($v), $chaves),
];
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$arquivo = $dir . DIRECTORY_SEPARATOR . 'hpr_' . preg_replace('/[^A-Za-z0-9_]/', '', $transacao) . '.json';
file_put_contents($arquivo, json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

echo json_encode(['ok' => true, 'arquivo' => $arquivo, 'tabelas_no_banco' => count($estrutura),
    'linhas_por_tabela' => array_map('count', $linhas)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
