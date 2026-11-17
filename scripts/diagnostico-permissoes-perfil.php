<?php
declare(strict_types=1);

/*
 * Diagnóstico de Segurança › Permissões (21/10/2026). SOMENTE LEITURA.
 * Problema relatado: a permissão associada a um perfil na tela de Permissões não é refletida na aplicação.
 * Confere, para o banco: volume de permissões por perfil x limite de 1000 linhas da carga da sessão (UsuarioSessao),
 * menus duplicados / fora do catálogo universal, a definição de vw_permissoes_grid e a ordenação de sp_crud_select;
 * e, com --userid, simula a carga da sessão do usuário e o resultado de PermissaoTransacao::obter para --menu.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-permissoes-perfil.php --banco=gcrown_dbp [--userid=adriano.justino] [--menu=NAO_CONFORMIDADES_NOVA_OCORRENCIA]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = 'gcrown_dbp'; $userid = ''; $menuCod = '';
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
    if (preg_match('/^--userid=(.+)$/', $a, $m)) $userid = trim($m[1]);
    if (preg_match('/^--menu=([A-Za-z0-9_]+)$/', $a, $m)) $menuCod = strtoupper($m[1]);
}
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$uni = null;
try { $uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL'); $uni->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); } catch (Throwable $e) { $uni = null; }
$q = static function (PDO $c, string $sql, array $p = []): array { try { $s = $c->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 300)]; } };
$crud = static function (string $tabela, array $filtros, int $limit, int $offset = 0) use ($pdo): array {
    $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, ' . $limit . ', ' . $offset . ')');
    $s->execute(['t' => $tabela, 'f' => json_encode($filtros, JSON_UNESCAPED_UNICODE)]);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r;
};

$r = ['banco' => $banco, 'gerado_em' => date('c')];

// 1. Definições
$def = $q($pdo, 'SHOW CREATE VIEW vw_permissoes_grid');
$r['vw_permissoes_grid'] = isset($def['erro']) ? $def : (string)($def[0]['Create View'] ?? '');
$sp = $q($pdo, "SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_crud_select'");
$body = (string)($sp[0]['ROUTINE_DEFINITION'] ?? '');
preg_match_all('/.{0,160}ORDER BY.{0,160}/is', $body, $mm);
$r['sp_crud_select_order_by'] = array_map(static fn($x) => preg_replace('/\s+/', ' ', $x), $mm[0] ?? []);

// 2. Volume de permissões por perfil x limite 1000 da sessão
$r['menus_tenant'] = [
    'total' => $q($pdo, 'SELECT COUNT(*) n, SUM(COALESCE(url,\'\') <> \'\') com_url, SUM(ativo = 1) ativos FROM menus'),
    'codigos_duplicados' => $q($pdo, 'SELECT UPPER(codigo) codigo, COUNT(*) n, GROUP_CONCAT(codmenu ORDER BY codmenu) codmenus FROM menus GROUP BY UPPER(codigo) HAVING COUNT(*) > 1 LIMIT 50'),
];
$r['permissoes'] = [
    'por_perfil' => $q($pdo, 'SELECT p.codperfil, pf.codigo, COUNT(*) n, MIN(p.codpermissao) min_cod, MAX(p.codpermissao) max_cod FROM permissoes p LEFT JOIN perfis pf ON pf.codperfil = p.codperfil GROUP BY p.codperfil, pf.codigo ORDER BY n DESC'),
    'orfas_sem_menu' => $q($pdo, 'SELECT COUNT(*) n FROM permissoes p LEFT JOIN menus m ON m.codmenu = p.codmenu WHERE m.codmenu IS NULL'),
    'duplicadas_perfil_menu' => $q($pdo, 'SELECT codperfil, codmenu, COUNT(*) n FROM permissoes GROUP BY codperfil, codmenu HAVING COUNT(*) > 1 LIMIT 20'),
    'perfil_com_mesmo_codigo_em_2_menus' => $q($pdo, 'SELECT p.codperfil, UPPER(m.codigo) codigo, COUNT(*) n, GROUP_CONCAT(CONCAT(m.codmenu, \':vis=\', p.pode_visualizar, \'/tot=\', p.pode_visualizar_total) ORDER BY p.codpermissao) linhas FROM permissoes p JOIN menus m ON m.codmenu = p.codmenu GROUP BY p.codperfil, UPPER(m.codigo) HAVING COUNT(*) > 1 LIMIT 30'),
];
// O que a sessão carrega de fato (mesma chamada de UsuarioSessao: limite 1000, offset 0)
$cortes = [];
foreach ($q($pdo, 'SELECT p.codperfil, pf.codigo, COUNT(*) n FROM permissoes p JOIN perfis pf ON pf.codperfil = p.codperfil GROUP BY p.codperfil, pf.codigo') as $pp) {
    try {
        $carregadas = $crud('vw_permissoes_grid', ['codperfil' => (int)$pp['codperfil']], 1000);
        $total = (int)$pp['n'];
        $naGrid = (int)($q($pdo, 'SELECT COUNT(*) n FROM vw_permissoes_grid WHERE codperfil = ?', [(int)$pp['codperfil']])[0]['n'] ?? 0);
        $item = ['perfil' => $pp['codigo'], 'codperfil' => (int)$pp['codperfil'], 'permissoes' => $total, 'na_view' => $naGrid, 'carregadas_na_sessao' => count($carregadas)];
        if ($naGrid > count($carregadas)) {
            $ids = array_fill_keys(array_map(static fn($x) => (int)$x['codpermissao'], $carregadas), true);
            $fora = [];
            foreach ($q($pdo, 'SELECT codpermissao, codmenu, menu_codigo, pode_visualizar, pode_visualizar_total FROM vw_permissoes_grid WHERE codperfil = ?', [(int)$pp['codperfil']]) as $l) if (!isset($ids[(int)$l['codpermissao']])) $fora[] = $l;
            $item['ignoradas_pela_sessao'] = count($fora);
            $item['ignoradas_com_visualizar'] = count(array_filter($fora, static fn($l) => (int)$l['pode_visualizar'] || (int)$l['pode_visualizar_total']));
            $item['exemplos_ignoradas'] = array_map(static fn($l) => $l['menu_codigo'] . ' (codpermissao ' . $l['codpermissao'] . ', vis=' . $l['pode_visualizar'] . ', tot=' . $l['pode_visualizar_total'] . ')', array_slice(array_values(array_filter($fora, static fn($l) => (int)$l['pode_visualizar'] || (int)$l['pode_visualizar_total'])), 0, 15));
        }
        $cortes[] = $item;
    } catch (Throwable $e) { $cortes[] = ['perfil' => $pp['codigo'], 'erro' => mb_substr($e->getMessage(), 0, 200)]; }
}
usort($cortes, static fn($a, $b) => ($b['ignoradas_pela_sessao'] ?? 0) <=> ($a['ignoradas_pela_sessao'] ?? 0));
$r['carga_da_sessao_por_perfil'] = $cortes;

// 3. Catálogo universal (de onde vem o menu) x menus do banco (onde ficam as permissões): o casamento é pelo código
if ($uni) {
    $u = []; foreach ($q($uni, 'SELECT codmenu, codigo, url, ativo FROM menus') as $l) if (isset($l['codigo'])) $u[strtoupper((string)$l['codigo'])] = $l;
    $t = []; foreach ($q($pdo, 'SELECT codmenu, codigo, url, ativo FROM menus') as $l) if (isset($l['codigo'])) $t[strtoupper((string)$l['codigo'])] = $l;
    $r['catalogo'] = [
        'universal_total' => count($u), 'tenant_total' => count($t),
        'universal_ativos_com_url_sem_menu_no_banco' => array_values(array_keys(array_filter($u, static fn($l, $k) => (int)$l['ativo'] === 1 && trim((string)$l['url']) !== '' && !isset($t[$k]), ARRAY_FILTER_USE_BOTH))),
        'banco_sem_correspondente_no_universal' => array_slice(array_values(array_diff(array_keys($t), array_keys($u))), 0, 60),
    ];
}

// 4. Simulação da sessão de um usuário
if ($userid !== '') {
    $us = $q($pdo, 'SELECT u.codusuario, u.userid, u.ativo, u.codperfil, pf.codigo perfil FROM usuarios u LEFT JOIN perfis pf ON pf.codperfil = u.codperfil WHERE u.userid = ?', [$userid]);
    $sim = ['usuario' => $us];
    if ($us && !isset($us['erro'])) {
        $cod = (int)$us[0]['codusuario']; $principal = (int)$us[0]['codperfil'];
        $leg = static function (int $ef) use ($pdo, $cod): array { try { $s = $pdo->prepare('CALL sp_usuario_perfis_legado(?, ?)'); $s->execute([$cod, $ef]); $x = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor(); return $x; } catch (Throwable $e) { return []; } };
        $ef1 = $leg(1); $ef0 = $leg(0);
        $perfis = $principal > 0 ? [$principal] : [];
        $perfis = array_values(array_unique(array_merge($perfis, $ef1 ?: $ef0)));
        $sim['perfis_legado_efetivo_1'] = $ef1; $sim['perfis_legado_efetivo_0'] = $ef0; $sim['perfis_usados_na_sessao'] = $perfis;
        $porMenu = []; $linhasPorPerfil = [];
        foreach ($perfis as $pid) {
            $rows = $crud('vw_permissoes_grid', ['codperfil' => $pid], 1000);
            $linhasPorPerfil[$pid] = count($rows);
            foreach ($rows as $p) { $mid = (int)$p['codmenu']; if (!isset($porMenu[$mid])) $porMenu[$mid] = $p; else foreach (['pode_visualizar', 'pode_visualizar_total', 'pode_incluir', 'pode_editar', 'pode_excluir'] as $f) $porMenu[$mid][$f] = max((int)$porMenu[$mid][$f], (int)$p[$f]); }
        }
        $sim['linhas_carregadas_por_perfil'] = $linhasPorPerfil;
        if ($menuCod !== '') {
            $sim['menu'] = $menuCod;
            $sim['linhas_no_banco_para_o_menu'] = $q($pdo, 'SELECT p.codpermissao, p.codperfil, pf.codigo perfil, p.codmenu, m.codigo, m.ativo menu_ativo, p.pode_visualizar, p.pode_visualizar_total, p.pode_incluir, p.pode_editar, p.pode_excluir FROM permissoes p JOIN menus m ON m.codmenu = p.codmenu LEFT JOIN perfis pf ON pf.codperfil = p.codperfil WHERE UPPER(m.codigo) = ? AND p.codperfil IN (' . implode(',', $perfis ?: [0]) . ')', [$menuCod]);
            $achou = array_values(array_filter($porMenu, static fn($p) => strtoupper((string)($p['menu_codigo'] ?? '')) === $menuCod));
            $sim['carregado_na_sessao_para_o_menu'] = $achou ?: 'NÃO CARREGADO (a sessão não tem a permissão deste menu)';
            if ($uni) {
                $sim['menu_no_universal'] = $q($uni, 'SELECT codmenu, codmenu_pai, codigo, url, ativo FROM menus WHERE UPPER(codigo) = ?', [$menuCod]);
            }
        }
    }
    $r['simulacao_usuario'] = $sim;
}

$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_permissoes_' . $banco . '.json', $json);
echo $json . PHP_EOL;
