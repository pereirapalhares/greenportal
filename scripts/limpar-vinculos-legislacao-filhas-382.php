<?php
declare(strict_types=1);

/*
 * Migração 382 (complemento, 17/11/2026) — limpeza dos vínculos pai/filha herdados do legado em glegislacao_dbp.legislacaopaixlegislacaofilha:
 *   1. vínculo de uma lei com ela mesma: apagado;
 *   2. vínculo com lei pai ou filha que não existe mais em legislacao: apagado;
 *   3. pares pai/filha repetidos: fica o de menor codlegislacaopai_filha, os outros são apagados;
 *   4. depois disso, cria o índice único ux_lplf_pai_filha (CODLEGISLACAO_PAI, CODLEGISLACAO_FILHA).
 * Antes de apagar, grava as linhas em database/backups/382_vinculos_apagados_<data>.json (para restaurar, se preciso).
 * Leitura por sp_crud_select e exclusão por sp_crud_delete.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\limpar-vinculos-legislacao-filhas-382.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$call = static function (string $sql, array $p) use ($pdo): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$ler = static fn(string $t, array $f, int $lim = 1000, int $off = 0): array => $call('CALL sp_crud_select(?, ?, NULL, ?, ?)', [$t, json_encode($f ?: new stdClass()), $lim, $off]);

// todos os vínculos
$vinculos = [];
for ($off = 0; ; $off += 1000) { $p = $ler('legislacaopaixlegislacaofilha', [], 1000, $off); $vinculos = array_merge($vinculos, $p); if (count($p) < 1000) break; }
usort($vinculos, static fn($a, $b) => (int)$a['codlegislacaopai_filha'] <=> (int)$b['codlegislacaopai_filha']);

// leis que existem
$existe = [];
foreach ($vinculos as $v) foreach (['CODLEGISLACAO_PAI', 'CODLEGISLACAO_FILHA'] as $c) {
    $id = (int)$v[$c];
    if (!array_key_exists($id, $existe)) $existe[$id] = $id > 0 && (bool)$ler('legislacao', ['CODLEGISLACAO' => $id], 1);
}

$apagar = ['lei_com_ela_mesma' => [], 'lei_inexistente' => [], 'repetido' => []];
$vistos = [];
foreach ($vinculos as $v) {
    $pai = (int)$v['CODLEGISLACAO_PAI']; $filha = (int)$v['CODLEGISLACAO_FILHA'];
    if ($pai === $filha) { $apagar['lei_com_ela_mesma'][] = $v; continue; }
    if (!$existe[$pai] || !$existe[$filha]) { $apagar['lei_inexistente'][] = $v; continue; }
    $k = $pai . '-' . $filha;
    if (isset($vistos[$k])) { $apagar['repetido'][] = $v; continue; }
    $vistos[$k] = true;
}

$rel = ['modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'vinculos_antes' => count($vinculos)];
foreach ($apagar as $motivo => $linhas) $rel['apagar'][$motivo] = count($linhas);
$total = array_sum(array_map('count', $apagar));
$rel['vinculos_depois'] = count($vinculos) - $total;

if (!$simular) {
    if ($total > 0) {
        $dir = dirname(__DIR__) . '/database/backups';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $arq = $dir . '/382_vinculos_apagados_' . date('Ymd_His') . '.json';
        file_put_contents($arq, json_encode($apagar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $rel['backup'] = $arq;
        $n = 0;
        foreach ($apagar as $linhas) foreach ($linhas as $v) { $call('CALL sp_crud_delete(?, ?)', ['legislacaopaixlegislacaofilha', json_encode(['codlegislacaopai_filha' => (int)$v['codlegislacaopai_filha']])]); $n++; }
        $rel['apagados'] = $n;
    }
    $temUnico = (bool)$pdo->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaopaixlegislacaofilha' AND INDEX_NAME = 'ux_lplf_pai_filha'")->fetchColumn();
    if ($temUnico) $rel['indice_unico'] = 'já existia';
    else {
        try { $pdo->exec('ALTER TABLE `legislacaopaixlegislacaofilha` ADD UNIQUE KEY `ux_lplf_pai_filha` (`CODLEGISLACAO_PAI`, `CODLEGISLACAO_FILHA`)'); $rel['indice_unico'] = 'criado'; }
        catch (Throwable $e) { $rel['indice_unico'] = 'ERRO: ' . $e->getMessage(); }
    }
    $rel['vinculos_agora'] = count($ler('legislacaopaixlegislacaofilha', [], 1000));
} else {
    $rel['exemplos'] = array_map(static fn($l) => array_slice(array_map(static fn($v) => (int)$v['codlegislacaopai_filha'] . ': ' . $v['CODLEGISLACAO_PAI'] . ' → ' . $v['CODLEGISLACAO_FILHA'], $l), 0, 8), $apagar);
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
