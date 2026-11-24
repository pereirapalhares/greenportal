<?php
declare(strict_types=1);

/*
 * Diagnóstico SOMENTE LEITURA (23/11/2026): definição de uma tela do legado (newlegislacao/cadastros/grid.php?transacao=X)
 * no banco hpr_configuracoes — linha de hpr_transacoes e todas as linhas ligadas a ela nas outras tabelas hpr_*.
 * Valores de colunas com cara de segredo (senha, password, pwd, token, secret) saem mascarados.
 * Uso: php scripts\diagnostico-transacao-legado.php mlUnidades storage\diag-transacao.json
 * Temporário: apague depois de usar.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$transacao = (string)($argv[1] ?? 'mlUnidades');
$saida = (string)($argv[2] ?? '');
$banco = (string)($argv[3] ?? 'hpr_configuracoes');
if (!preg_match('/^[A-Za-z0-9_]+$/', $transacao) || !preg_match('/^[A-Za-z0-9_]+$/', $banco)) exit("parâmetros inválidos\n");
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$mascarar = static function (array $linhas): array {
    foreach ($linhas as &$l) foreach ($l as $k => &$v) if (preg_match('/senha|password|passwd|pwd|token|secret/i', (string)$k) && $v !== null && $v !== '') $v = '***';
    return $linhas;
};

$out = ['banco' => $banco, 'transacao' => $transacao];
try {
    $tabelas = [];
    foreach ($q('SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$banco]) as $r) $tabelas[$r['t']][$r['c']] = $r['ty'];
    $out['tabelas'] = array_map(static fn($c) => array_keys($c), $tabelas);
    $tt = isset($tabelas['hpr_transacoes']) ? 'hpr_transacoes' : (isset($tabelas['configuracoes_transacoes']) ? 'configuracoes_transacoes' : null);
    if ($tt === null) foreach (array_keys($tabelas) as $nomeT) if (str_ends_with($nomeT, '_transacoes')) { $tt = $nomeT; break; }
    if ($tt === null) throw new RuntimeException('tabela de transações não encontrada em ' . $banco);
    $out['tabela_transacoes'] = $tt;

    // linha da transação: procura o nome em qualquer coluna de texto
    $colsTexto = array_keys(array_filter($tabelas[$tt], static fn($t) => preg_match('/char|text/i', $t)));
    $where = implode(' OR ', array_map(static fn($c) => "LOWER(TRIM(`$c`)) = LOWER(?)", $colsTexto));
    $trans = $q("SELECT * FROM `$banco`.`$tt` WHERE $where", array_fill(0, count($colsTexto), $transacao));
    if (!$trans) { // sem igualdade exata: parecidos (só para mostrar o nome certo)
        $c0 = array_key_first($tabelas[$tt]);
        $out['parecidas'] = $q("SELECT `$c0`, dsctransacao, tabela FROM `$banco`.`$tt` WHERE " . implode(' OR ', array_map(static fn($c) => "`$c` LIKE ?", $colsTexto)) . ' LIMIT 30', array_fill(0, count($colsTexto), '%' . substr($transacao, 2) . '%'));
    }
    $out['transacao_linha'] = $mascarar($trans);
    if (!$trans) throw new RuntimeException('transação não encontrada');

    // chave da transação (primeira coluna) e ligações: colunas das outras tabelas com o mesmo nome da chave ou que contenham "transac"
    $pk = array_key_first($tabelas[$tt]);
    $ids = array_values(array_unique(array_map(static fn($r) => $r[$pk], $trans)));
    $out['chave'] = [$pk => $ids];
    $ligadas = [];
    foreach ($tabelas as $t => $cols) {
        if ($t === $tt) continue;
        foreach ($cols as $c => $ty) {
            if (strcasecmp($c, $pk) !== 0 && stripos($c, 'transac') === false) continue;
            $vals = preg_match('/char|text/i', $ty) ? array_merge($ids, [$transacao]) : $ids;
            $marks = implode(',', array_fill(0, count($vals), '?'));
            $linhas = $q("SELECT * FROM `$banco`.`$t` WHERE `$c` IN ($marks) LIMIT 500", array_map('strval', $vals));
            if ($linhas) $ligadas["$t.$c"] = $mascarar($linhas);
        }
    }
    $out['ligadas'] = $ligadas;

    // segundo nível: tabelas ligadas às linhas encontradas (ex.: campos → listas/combos)
    $nivel2 = [];
    foreach ($ligadas as $origem => $linhas) {
        [$t] = explode('.', $origem);
        $pk2 = array_key_first($tabelas[$t]);
        $ids2 = array_values(array_unique(array_filter(array_map(static fn($r) => $r[$pk2] ?? null, $linhas), static fn($v) => $v !== null && $v !== '')));
        if (!$ids2 || count($ids2) > 300) continue;
        foreach ($tabelas as $t2 => $cols2) {
            if ($t2 === $t || $t2 === $tt || !isset($cols2[$pk2])) continue;
            $marks = implode(',', array_fill(0, count($ids2), '?'));
            $l2 = $q("SELECT * FROM `$banco`.`$t2` WHERE `$pk2` IN ($marks) LIMIT 500", array_map('strval', $ids2));
            if ($l2) $nivel2["$t2.$pk2"] = $mascarar($l2);
        }
    }
    $out['ligadas_nivel2'] = $nivel2;
} catch (Throwable $e) {
    $out['erro'] = $e->getMessage();
}
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if ($saida !== '') { file_put_contents($saida, $json); echo 'gravado em ' . $saida . (isset($out['erro']) ? ' (erro: ' . $out['erro'] . ')' : '') . PHP_EOL; } else echo $json;
