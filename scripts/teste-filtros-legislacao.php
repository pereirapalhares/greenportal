<?php
declare(strict_types=1);

/*
 * Teste (somente leitura) dos filtros Empresa, Unidade e Mês/Ano de atualização de Manutenção Legal › Legislação.
 * Compara o total que a tela mostra (sp_legislacao_count, a mesma chamada da tela) com o total esperado calculado
 * direto das tabelas: legislacaoxempresa do banco da empresa e legislacaoxalteracao / legislacao.data_atualizacao.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-filtros-legislacao.php [--banco=gcrown_dbp] [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
ini_set('memory_limit', '2048M');
@set_time_limit(0);

$bancos = [];
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $bancos[] = substr($a, 8);
if (!$bancos) $bancos = ['gcrown_dbp', 'gatlas_dbp'];
$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$call = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$todas = static function (PDO $pdo, string $tabela, array $f = []) use ($call): array {
    $out = [];
    for ($o = 0; ; $o += 1000) { $p = $call($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$tabela, json_encode($f ?: new stdClass()), $o]); $out = array_merge($out, $p); if (count($p) < 1000) break; }
    return $out;
};
$conta = static fn(?int $emp, ?int $uni, ?string $per): int => (int)($call($leg, 'CALL sp_legislacao_count(?, ?, ?, NULL, NULL, ?)', [$emp, $uni, $per, '{}'])[0]['total_registros'] ?? -1);

$rel = ['quando' => date('Y-m-d H:i:s')];
// como a tela faz desde a migração 383: lista de leis do banco da empresa -> sp_legislacao_count_ids
$contaIds = static function (?array $ids, ?string $per) use ($call, $leg): ?int {
    try { return (int)($call($leg, 'CALL sp_legislacao_count_ids(?, ?, NULL, NULL, ?)', [$ids === null ? null : json_encode(array_keys($ids)), $per, '{}'])[0]['total_registros'] ?? -1); }
    catch (Throwable) { return null; }
};

// 1) De onde a procedure lê o vínculo empresa/unidade
foreach (['sp_legislacao_select', 'sp_legislacao_count'] as $p) {
    $def = (string)$leg->query("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = " . $leg->quote($p))->fetchColumn();
    preg_match_all('/`?(g[a-z0-9_]*_dbp)`?\.`?legislacaoxempresa/i', $def, $m);
    $rel['procedure'][$p] = ['le_vinculo_de' => array_values(array_unique($m[1])), 'periodo_usa' => (str_contains($def, 'legislacaoxalteracao') ? 'legislacaoxalteracao.data_da_alteracao' : '') . (preg_match('/data_atualizacao`?\s*>=/', $def) ? ' + legislacao.data_atualizacao' : '')];
}

// 2) Leis: data_atualizacao por mês
// lê em páginas e guarda só código e data (o texto PADRAO é grande)
$porMesAtualizacao = []; $existe = [];
for ($o = 0; ; $o += 1000) {
    $p = $call($leg, 'CALL sp_crud_select(?, ?, NULL, 1000, ?)', ['legislacao', '{}', $o]);
    foreach ($p as $l) { $id = (int)$l['CODLEGISLACAO']; $existe[$id] = true; if (!empty($l['data_atualizacao'])) $porMesAtualizacao[substr((string)$l['data_atualizacao'], 0, 7)][$id] = true; }
    $n = count($p); unset($p);
    if ($n < 1000) break;
}
$alteracoes = $todas($leg, 'legislacaoxalteracao');
$porMesAlteracao = [];
foreach ($alteracoes as $a) if (!empty($a['data_da_alteracao']) && isset($existe[(int)$a['codlegislacao']])) $porMesAlteracao[substr((string)$a['data_da_alteracao'], 0, 7)][(int)$a['codlegislacao']] = true;
$periodosTela = array_map(static fn($r) => (string)$r['periodo'], $todas($leg, 'vw_legislacao_periodos'));
rsort($periodosTela);
$rel['periodo'] = ['opcoes_na_tela' => count($periodosTela), 'mais_recentes' => array_slice($periodosTela, 0, 6),
    'meses_com_data_atualizacao' => count($porMesAtualizacao), 'ultimo_mes_data_atualizacao' => $porMesAtualizacao ? max(array_keys($porMesAtualizacao)) : null];
$amostra = array_unique(array_merge(array_slice($periodosTela, 0, 4), array_slice(array_keys($porMesAtualizacao), -3)));
rsort($amostra);
foreach ($amostra as $per) {
    $rel['periodo']['meses'][$per] = ['tela' => $conta(null, null, $per), 'esperado_por_alteracao' => count($porMesAlteracao[$per] ?? []), 'esperado_por_data_atualizacao' => count($porMesAtualizacao[$per] ?? [])];
}

// 3) Empresa / Unidade em cada banco
foreach ($bancos as $banco) {
    $info = [];
    try {
        $t = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $vinc = $todas($t, 'legislacaoxempresa');
        $info['vinculos'] = count($vinc);
        $porEmp = []; $porUni = []; $porPar = []; $semLei = 0;
        foreach ($vinc as $v) {
            $lei = (int)$v['CODLEGISLACAO']; $e = (int)$v['CODEMPRESA']; $u = (int)($v['codunidade'] ?? 0);
            if (!isset($existe[$lei])) { $semLei++; continue; }
            $porEmp[$e][$lei] = true; $porUni[$u][$lei] = true; $porPar[$e . '/' . $u][$lei] = true;
        }
        $info['vinculos_com_lei_inexistente'] = $semLei;
        foreach ($porEmp as $e => $ls) $info['empresa'][$e] = ['antes' => $conta($e, null, null), 'tela' => $contaIds($ls, null), 'esperado' => count($ls)];
        $info['pares_empresa_unidade'] = count($porPar);
        uasort($porPar, static fn($a, $b) => count($b) <=> count($a));
        foreach (array_slice($porPar, 0, 15, true) as $k => $ls) {
            [$e, $u] = array_map('intval', explode('/', $k));
            $info['empresa_unidade'][$k] = ['antes' => $conta($e, $u, null), 'tela' => $contaIds($ls, null), 'esperado' => count($ls)];
        }
        // empresa + mês (o mês mais recente com alteração)
        $per = $periodosTela[0] ?? null;
        if ($per) foreach (array_slice(array_keys($porEmp), 0, 3) as $e) {
            $esp = count(array_intersect_key($porEmp[$e], $porMesAlteracao[$per] ?? []));
            $info['empresa_mais_periodo'][$e . ' / ' . $per] = ['antes' => $conta((int)$e, null, $per), 'tela' => $contaIds($porEmp[$e], $per), 'esperado' => $esp];
        }
        $dif = 0;
        foreach (['empresa', 'empresa_unidade', 'empresa_mais_periodo'] as $g) foreach ((array)($info[$g] ?? []) as $x) if ($x['tela'] !== $x['esperado']) $dif++;
        $info['divergencias'] = $dif;
    } catch (Throwable $e) { $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
