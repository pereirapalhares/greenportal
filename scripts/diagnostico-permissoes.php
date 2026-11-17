<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) das permissões de Segurança › Permissões em cada empresa.
 * Verifica se o que está gravado para o perfil é o que o usuário recebe no login:
 *   1. Perfis compostos da migração (atlas_usuarios_perfis_legado, efetivo = 1): quando existem, o login usa SÓ esses
 *      perfis e ignora o perfil principal do usuário — alterar a permissão do perfil principal na tela não muda nada.
 *   2. Menu da empresa x menu universal: o menu lateral usa o catálogo universal e as permissões usam o código do menu
 *      da empresa. Mesmo código com codmenu diferente = item aparece/some errado no menu.
 *   3. Permissões só com "Visualizar Total" (sem "Visualizar Parcial"): hoje não abrem a tela nem mostram o menu.
 *   4. Permissões de menus que não existem mais e menus duplicados.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-permissoes.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$somente = [];
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $somente[] = substr($a, 8);
$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $universalDb && !str_contains($b, '_seguranca')));
if ($somente) $bancos = array_values(array_intersect($bancos, $somente));

$u = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
$universal = [];
foreach (MigracaoCrud::selecionar($u, 'menus') as $m) $universal[strtoupper((string)$m['codigo'])] = (int)$m['codmenu'];

$rel = ['ok' => true, 'menus_universais' => count($universal), 'bancos' => []];
foreach ($bancos as $db) {
    $r = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $db, 'GPT');
        $perfis = [];
        foreach (MigracaoCrud::selecionar($pdo, 'perfis') as $p) $perfis[(int)$p['codperfil']] = (string)$p['codigo'];
        $menus = []; $porCodigo = [];
        foreach (MigracaoCrud::selecionar($pdo, 'menus') as $m) { $menus[(int)$m['codmenu']] = strtoupper((string)$m['codigo']); $porCodigo[strtoupper((string)$m['codigo'])][] = (int)$m['codmenu']; }

        // 2. menu da empresa x universal
        $dif = [];
        foreach ($porCodigo as $cod => $ids) {
            if (count($ids) > 1) $dif[] = "$cod duplicado na empresa (" . implode(', ', $ids) . ')';
            elseif (isset($universal[$cod]) && $universal[$cod] !== $ids[0]) $dif[] = "$cod: empresa {$ids[0]} x universal {$universal[$cod]}";
        }
        $r['menus'] = ['na_empresa' => count($menus), 'divergentes_do_universal' => count($dif), 'exemplos' => array_slice($dif, 0, 15)];

        // 3 e 4. permissões
        $soTotal = []; $orfas = 0; $porPerfil = [];
        foreach (MigracaoCrud::selecionar($pdo, 'permissoes') as $pm) {
            $cp = (int)$pm['codperfil']; $cm = (int)$pm['codmenu'];
            if (!isset($menus[$cm])) { $orfas++; continue; }
            if ((int)($pm['pode_visualizar'] ?? 0) === 1) $porPerfil[$perfis[$cp] ?? "perfil $cp"] = ($porPerfil[$perfis[$cp] ?? "perfil $cp"] ?? 0) + 1;
            if ((int)($pm['pode_visualizar_total'] ?? 0) === 1 && (int)($pm['pode_visualizar'] ?? 0) !== 1) $soTotal[] = ($perfis[$cp] ?? $cp) . ' / ' . $menus[$cm];
        }
        $r['permissoes'] = ['transacoes_visiveis_por_perfil' => $porPerfil, 'so_visualizar_total' => count($soTotal), 'exemplos_so_total' => array_slice($soTotal, 0, 15), 'de_menus_inexistentes' => $orfas];

        // 1. perfis compostos
        $usuarios = [];
        foreach (MigracaoCrud::selecionar($pdo, 'usuarios') as $us) $usuarios[(int)$us['codusuario']] = ['userid' => (string)($us['userid'] ?? ''), 'perfil' => $perfis[(int)($us['codperfil'] ?? 0)] ?? '', 'ativo' => (int)($us['ativo'] ?? 0)];
        if (MigracaoCrud::existe($pdo, 'atlas_usuarios_perfis_legado')) {
            $efetivos = [];
            foreach (MigracaoCrud::selecionar($pdo, 'atlas_usuarios_perfis_legado', ['efetivo' => 1]) as $l) $efetivos[(int)$l['codusuario']][] = $perfis[(int)$l['codperfil']] ?? ('perfil ' . (int)$l['codperfil']);
            $ignorado = []; $porPrincipal = [];
            foreach ($efetivos as $cu => $lista) {
                $us = $usuarios[$cu] ?? null;
                if (!$us || !$us['ativo']) continue;
                if (!in_array($us['perfil'], $lista, true)) {
                    $porPrincipal[$us['perfil']] = ($porPrincipal[$us['perfil']] ?? 0) + 1;
                    if (count($ignorado) < 20) $ignorado[] = $us['userid'] . ' — perfil ' . $us['perfil'] . ' ignorado; usa ' . implode(' + ', array_unique($lista));
                }
            }
            $r['perfis_compostos'] = ['usuarios_ativos_com_perfil_composto' => count(array_filter(array_keys($efetivos), static fn($cu) => !empty($usuarios[$cu]['ativo']))),
                'usuarios_cujo_perfil_principal_e_ignorado' => array_sum($porPrincipal), 'por_perfil_principal' => $porPrincipal, 'exemplos' => $ignorado];
        } else $r['perfis_compostos'] = 'não há (tabela atlas_usuarios_perfis_legado ausente)';
        $r['usuarios_ativos'] = count(array_filter($usuarios, static fn($x) => $x['ativo']));
    } catch (Throwable $e) { $rel['ok'] = false; $r['erro'] = $e->getMessage(); }
    $rel['bancos'][$db] = $r;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
