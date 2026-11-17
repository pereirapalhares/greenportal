<?php
declare(strict_types=1);

/*
 * Migração 326 — catálogo único de menus (21/10/2026).
 * Regra: todo menu existe no catálogo universal; não há menu específico de empresa.
 * Correção do relato "associo a permissão a um perfil e ela não é refletida na aplicação": o menu lateral vem do
 * universal (classes/MenuService.php) e casa as permissões pelo código; menu que só existe no banco da empresa
 * (ex.: "Nova ocorrência do fornecedor/de planejamento", criados pela migração 321, ou incluídos em Setup › Menu,
 * que gravava no banco da empresa) recebe permissão na tela de Permissões, mas nunca aparece.
 *
 *  1. garante sp_crud_* no universal e nas empresas e a view vw_menus_grid no universal (Setup › Menu passa a usar o universal);
 *  2. menu que só existe em banco de empresa → incluído no universal (pai pelo código; codmenu da empresa se estiver
 *     livre em todos os bancos, senão um livre);
 *  3. menu do universal que falta em banco de empresa → incluído na empresa, sem permissão (as permissões casam pelo código).
 * Menus que já existem não são alterados. Não mexe em permissões. Pode ser executada mais de uma vez.
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-326-menus-nc-universal.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-326-menus-nc-universal.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$simular = in_array('--simular', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '326', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try {
    $cat = new MenuCatalogoService();
    $u = $cat->universal();
    $empresas = $cat->empresas();
    $rel['bancos_empresas'] = array_keys($empresas);

    // 1. estrutura
    if (!$simular) {
        $rel['procedures_criadas'] = ['universal' => MigracaoCrud::garantir($u)];
        foreach ($empresas as $b => $p) $rel['procedures_criadas'][$b] = MigracaoCrud::garantir($p);
    }
    if (!MigracaoCrud::existe($u, 'vw_menus_grid')) {
        if (!$simular) $u->exec('CREATE OR REPLACE VIEW `vw_menus_grid` AS SELECT m.`codmenu`, m.`codmenu_pai`, p.`label_pt` AS `menu_pai`, m.`modulo`, m.`codigo`, m.`label`, m.`label_pt`, m.`label_en`, m.`label_es`, m.`url`, m.`nivel`, m.`ordem`, m.`ativo` FROM `menus` m LEFT JOIN `menus` p ON p.`codmenu` = m.`codmenu_pai`');
        $rel['vw_menus_grid_universal'] = $simular ? 'será criada' : 'criada';
    } else $rel['vw_menus_grid_universal'] = 'já existia';

    $carregar = static function (PDO $p): array { $c = []; $i = []; foreach (MigracaoCrud::selecionar($p, 'menus') as $m) { $m = array_change_key_case($m); $c[strtoupper((string)$m['codigo'])] = $m; $i[(int)$m['codmenu']] = $m; } return [$c, $i]; };
    [$uniCod, $uniId] = $carregar($u);
    $empCod = []; $empId = []; $usados = [];
    foreach ($uniId as $id => $m) $usados[$id][strtoupper((string)$m['codigo'])] = true;
    foreach ($empresas as $b => $p) { [$empCod[$b], $empId[$b]] = $carregar($p); foreach ($empId[$b] as $id => $m) $usados[$id][strtoupper((string)$m['codigo'])] = true; }

    // 2. só na empresa → universal (pais antes dos filhos)
    $novos = [];
    foreach ($empCod as $b => $lista) foreach ($lista as $cod => $m) if (!isset($uniCod[$cod]) && !isset($novos[$cod])) $novos[$cod] = ['banco' => $b, 'linha' => $m];
    uasort($novos, static fn($a, $c) => ((int)$a['linha']['nivel'] <=> (int)$c['linha']['nivel']) ?: ((int)$a['linha']['codmenu'] <=> (int)$c['linha']['codmenu']));
    $uniCols = MigracaoCrud::colunas($u, 'menus');
    $maior = max(array_keys($usados) ?: [0]);
    $rel['incluidos_no_universal'] = [];
    foreach ($novos as $cod => ['banco' => $b, 'linha' => $m]) {
        $codmenu = (int)$m['codmenu'];
        if (count($usados[$codmenu] ?? []) > 1 || isset($uniId[$codmenu])) $codmenu = ++$maior;
        $paiCod = !empty($m['codmenu_pai']) ? strtoupper((string)($empId[$b][(int)$m['codmenu_pai']]['codigo'] ?? '')) : '';
        $pai = $paiCod !== '' ? ($uniCod[$paiCod]['codmenu'] ?? null) : null;
        $dados = array_intersect_key(['codmenu' => $codmenu, 'codmenu_pai' => $pai !== null ? (int)$pai : null] + $m, array_flip($uniCols));
        foreach (['criado_em', 'atualizado_em', 'created_at', 'updated_at'] as $k) unset($dados[$k]);
        if (!$simular) MigracaoCrud::inserir($u, 'menus', $dados);
        $uniCod[$cod] = $dados; $uniId[$codmenu] = $dados; $usados[$codmenu][$cod] = true;
        $rel['incluidos_no_universal'][] = "$cod → codmenu $codmenu (copiado de $b" . ($paiCod !== '' ? ", pai $paiCod" . ($pai === null ? ' NÃO ENCONTRADO NO UNIVERSAL: fica no topo' : '') : '') . ')';
    }

    // 3. universal → empresas que não têm o menu (pais antes dos filhos)
    $ordem = array_values($uniCod);
    usort($ordem, static fn($a, $c) => ((int)$a['nivel'] <=> (int)$c['nivel']) ?: ((int)$a['codmenu'] <=> (int)$c['codmenu']));
    $rel['incluidos_nas_empresas'] = [];
    foreach ($ordem as $m) {
        $cod = strtoupper((string)$m['codigo']);
        $faltam = array_values(array_filter(array_keys($empresas), static fn($b) => !isset($empCod[$b][$cod])));
        if (!$faltam) continue;
        if ($simular) { $rel['incluidos_nas_empresas'][$cod] = 'será incluído em ' . implode(', ', $faltam); continue; }
        $res = $cat->replicar($cod, null, false);
        $rel['incluidos_nas_empresas'][$cod] = array_intersect_key($res, array_flip($faltam));
        foreach ($res as $r) if (str_starts_with((string)$r, 'ERRO')) $rel['ok'] = false;
    }
    if (!$rel['incluidos_no_universal']) $rel['incluidos_no_universal'] = 'nenhum: todo menu das empresas já está no universal';
    if (!$rel['incluidos_nas_empresas']) $rel['incluidos_nas_empresas'] = 'nenhum: todas as empresas têm todos os menus do universal';
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['erro'] = $e->getMessage();
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_326_menus_universal' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
