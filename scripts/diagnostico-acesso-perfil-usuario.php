<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — o que o perfil do usuário libera x o que a aplicação pede dele (20/10/2026).
 * Motivo: adriano.justino (GESTORES, Crown) recebe na Agenda itens de Licença (é o responsável), mas ao abrir
 * manutencaolegal_licencas.php?minhas_pendencias=1 aparece "Seu perfil não tem permissão para acessar esta transação."
 * Mostra: perfis efetivos (principal + associados do legado), permissões efetivas por menu, módulos ativos da empresa,
 * responsabilidades do usuário em cada pilar (vw_minhas_*) e na Homologação, com o menu que cada uma exige e se ele tem;
 * e, no legado, os grupos do usuário e as permissões desses grupos (tabelas de <legado>_seguranca com codgrupo).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-acesso-perfil-usuario.php --banco=gcrown_dbp --userid=adriano.justino
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = ''; $userid = '';
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
    if (preg_match('/^--userid=([A-Za-z0-9._@\-]{1,80})$/', $a, $m)) $userid = $m[1];
}
if ($banco === '' || $userid === '') { fwrite(STDERR, "Informe --banco=g..._dbp e --userid=login\n"); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (PDO $c, string $sql, array $p = []): array { $s = $c->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 220)]; } };
$existe = static fn(string $db, string $t): bool => (bool)$q($pdo, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]);

$u = $q($pdo, 'SELECT u.codusuario, u.nome, u.ativo, u.codperfil, p.codigo AS perfil FROM usuarios u LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE u.userid = ?', [$userid])[0] ?? null;
if (!$u) { fwrite(STDERR, "Usuário $userid não encontrado em $banco\n"); exit(1); }
$cod = (int)$u['codusuario'];
$r = ['banco' => $banco, 'usuario' => $u];

// 1. Perfis efetivos — mesma regra de classes/UsuarioSessao.php
$perfis = (int)$u['codperfil'] > 0 ? [(int)$u['codperfil']] : [];
$assoc = $tenta(function () use ($pdo, $cod) {
    $ler = static function (int $ef) use ($pdo, $cod): array { $s = $pdo->prepare('CALL sp_usuario_perfis_legado(?, ?)'); $s->execute([$cod, $ef]); $x = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor(); return $x; };
    $um = array_values(array_filter($ler(1))); return ['efetivos' => $um, 'associados' => $um ? [] : array_values(array_filter($ler(0)))];
});
if (!isset($assoc['erro'])) $perfis = array_values(array_unique(array_merge($perfis, $assoc['efetivos'] ?: $assoc['associados'])));
$r['perfis_efetivos'] = $perfis ? $tenta(fn() => $q($pdo, 'SELECT * FROM perfis WHERE codperfil IN (' . implode(',', $perfis) . ')')) : [];

// 2. Permissões efetivas (união das flags por menu, como na sessão)
$flags = ['pode_visualizar' => 'V', 'pode_visualizar_total' => 'VT', 'pode_incluir' => 'I', 'pode_editar' => 'E', 'pode_excluir' => 'X', 'pode_exportar_excel' => 'XLS', 'pode_importar_arquivos' => 'UP', 'pode_visualizar_arquivos' => 'VA', 'pode_baixar_arquivos' => 'DL'];
$perm = [];
foreach ($perfis as $p) {
    $linhas = $tenta(fn() => $q($pdo, 'SELECT * FROM vw_permissoes_grid WHERE codperfil = ?', [$p]));
    if (isset($linhas['erro'])) { $perm['__erro'] = $linhas['erro']; continue; }
    foreach ($linhas as $l) {
        $m = strtoupper((string)($l['menu_codigo'] ?? ''));
        if ($m === '') continue;
        foreach ($flags as $f => $sigla) if (!empty($l[$f])) $perm[$m][$sigla] = true;
        $perm[$m] = $perm[$m] ?? [];
    }
}
ksort($perm);
$tem = static fn(string $menu): bool => !empty($perm[strtoupper($menu)]['V']) || !empty($perm[strtoupper($menu)]['VT']);
$r['permissoes_efetivas'] = array_map(static fn($f) => is_array($f) ? implode('/', array_keys($f)) : $f, $perm);

// 3. Módulos da empresa (guniversal) e módulo de cada menu
$uni = null; $modulos = [];
try {
    $uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $uni->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $todos = $q($uni, 'SELECT * FROM vw_empresa_modulos_situacao');
    $doBanco = array_values(array_filter($todos, static function ($l) use ($banco) { foreach ($l as $v) if (is_string($v) && strtolower($v) === $banco) return true; return false; }));
    $r['modulos_empresa'] = $doBanco ?: ['obs' => 'a view não traz o banco; todas as linhas', 'linhas' => $todos];
    foreach ($doBanco as $l) $modulos[strtoupper((string)($l['codigo_modulo'] ?? ''))] = (int)($l['ativo'] ?? 0);
    $arvore = $q($uni, 'SELECT codmenu, codmenu_pai, codigo FROM vw_menus_arvore');
} catch (Throwable $e) { $r['modulos_empresa'] = ['erro' => mb_substr($e->getMessage(), 0, 220)]; $arvore = []; }
$byId = []; $byCode = [];
foreach ($arvore as $l) { $byId[(int)$l['codmenu']] = $l; $byCode[strtoupper((string)$l['codigo'])] = $l; }
$moduloDoMenu = static function (string $c) use ($byId, $byCode): ?string {
    $cur = $byCode[strtoupper($c)] ?? null; $g = 0;
    if (!$cur) return 'MENU_NAO_EXISTE';
    while ($cur && $g++ < 50) {
        $root = strtoupper((string)$cur['codigo']);
        if ($root === 'MODULO_LEGAL' || $root === 'MANUTENCAO_LEGAL') return 'MODULO_LEGAL';
        if (in_array($root, ['HOMOLOGACAO_FORNECEDORES', 'NAO_CONFORMIDADES'], true)) return $root;
        $cur = $byId[(int)($cur['codmenu_pai'] ?? 0)] ?? null;
    }
    return null;
};

// 4. Responsabilidades x menu exigido (classes/MinhasPendencias.php e ResponsibilityAgendaService.php)
$pilares = [
    'Licenças' => ['vw_minhas_licencas_grid', 'LICENCAS', 'manutencaolegal_licencas.php'],
    'Medição e monitoramento' => ['vw_minhas_monitoramentos_grid', 'MM_PLANO_MEDICAO_MONITORAMENTO', 'medicaoemonitoramento_mm_monitoramento.php'],
    'Treinamentos' => ['vw_minhas_treinamentos_grid', 'MODULOLEGAL_TREINAMENTOS', 'modulolegal_treinamentos.php'],
    'Infraestrutura' => ['vw_minhas_infraestrutura_grid', 'MODULOLEGAL_INFRAESTRUTURA', 'modulolegal_InfraEstrutura.php'],
    'Controles' => ['vw_meus_controles_grid', 'CONTROLE_DOCUMENTOS', 'modulolegal_controle.php'],
];
$resp = [];
foreach ($pilares as $nome => [$view, $menu, $tela]) {
    $n = $tenta(fn() => (int)($q($pdo, "SELECT COUNT(*) AS n FROM `$view` WHERE CODUSUARIO = ?", [$cod])[0]['n'] ?? 0));
    $mod = $moduloDoMenu($menu);
    $resp[$nome] = ['responsavel_por' => $n, 'menu_exigido' => $menu, 'tela' => $tela, 'perfil_tem' => $tem($menu) ? ($r['permissoes_efetivas'][$menu] ?? '') : 'NÃO',
        'modulo' => $mod, 'empresa_tem_modulo' => $mod === null || $mod === 'MENU_NAO_EXISTE' ? '-' : (isset($modulos[$mod]) ? ($modulos[$mod] ? 'sim' : 'NÃO') : '?')];
}
$hom = [
    'Documentos de fornecedor (responsável interno)' => ['SELECT COUNT(*) AS n FROM fo_fornecedorxcriterios WHERE codusuario = ?', 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR'],
    'Documentos de fornecedor (responsável do fornecedor)' => ['SELECT COUNT(*) AS n FROM fo_fornecedorxcriterios WHERE codusuario_fornecedor = ?', 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR'],
    'Fornecedor x Usuários' => ['SELECT COUNT(*) AS n FROM fo_fornecedorxusuario WHERE CODUSUARIO = ?', 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR'],
];
foreach ($hom as $nome => [$sql, $menu]) {
    $mod = $moduloDoMenu($menu);
    $resp[$nome] = ['responsavel_por' => $tenta(fn() => (int)($q($pdo, $sql, [$cod])[0]['n'] ?? 0)), 'menu_exigido' => $menu, 'perfil_tem' => $tem($menu) ? ($r['permissoes_efetivas'][$menu] ?? '') : 'NÃO',
        'modulo' => $mod, 'empresa_tem_modulo' => isset($modulos[(string)$mod]) ? ($modulos[(string)$mod] ? 'sim' : 'NÃO') : '?'];
}
$r['responsabilidades'] = $resp;
$r['acesso_homologacao'] = $tenta(fn() => $q($pdo, 'SELECT PERFIL, MODO FROM vw_homologacao_usuario_acesso WHERE CODUSUARIO = ?', [$cod])[0] ?? null);
$r['unidades'] = $tenta(fn() => count($q($pdo, 'SELECT CODUNIDADE FROM vw_portaria_usuario_unidades WHERE CODUSUARIO = ?', [$cod])));

// 5. Legado: grupos e permissões dos grupos
$leg = HomologacaoBancos::legado($banco);
if ($leg) {
    $seg = $leg . '_seguranca';
    $grupos = $tenta(fn() => $q($pdo, "SELECT g.codgrupo, g.dscgrupo FROM `$seg`.usuariosxgrupos ug JOIN `$seg`.grupos g ON g.codgrupo = ug.codgrupo WHERE ug.codusuario = ?", [$cod]));
    $legado = ['grupos' => $grupos];
    if (!isset($grupos['erro']) && $grupos) {
        $ids = implode(',', array_map(static fn($g) => (int)$g['codgrupo'], $grupos));
        foreach ($q($pdo, "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND LOWER(COLUMN_NAME) = 'codgrupo' AND TABLE_NAME NOT IN ('usuariosxgrupos', 'grupos')", [$seg]) as $t) {
            $tab = (string)$t['TABLE_NAME'];
            $legado['tabelas'][$tab] = $tenta(fn() => $q($pdo, "SELECT * FROM `$seg`.`$tab` WHERE codgrupo IN ($ids) LIMIT 400"));
        }
    }
    // Nomes dos menus do legado: procura em todos os schemas as tabelas com codmenu + dscmenu (a view vgruposxmenus mostra de onde vem)
    if (!isset($grupos['erro']) && $grupos) {
        $legado['definicao_vgruposxmenus'] = $tenta(fn() => mb_substr((string)($q($pdo, "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vgruposxmenus'", [$seg])[0]['VIEW_DEFINITION'] ?? ''), 0, 1500));
        $candidatas = $q($pdo, "SELECT c.TABLE_SCHEMA AS s, c.TABLE_NAME AS t FROM information_schema.COLUMNS c JOIN information_schema.COLUMNS d ON d.TABLE_SCHEMA = c.TABLE_SCHEMA AND d.TABLE_NAME = c.TABLE_NAME AND LOWER(d.COLUMN_NAME) = 'codmenu'
             JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
             WHERE LOWER(c.COLUMN_NAME) = 'dscmenu' AND (c.TABLE_SCHEMA LIKE ? OR c.TABLE_SCHEMA LIKE 'hpr%' OR c.TABLE_SCHEMA LIKE 'green%seguranca' OR c.TABLE_SCHEMA = ?) ORDER BY c.TABLE_SCHEMA = ? DESC, c.TABLE_SCHEMA", [$leg . '%', $seg, $seg]);
        $legado['tabelas_de_menus'] = array_map(static fn($c) => $c['s'] . '.' . $c['t'], $candidatas);
        $ids = array_map(static fn($gm) => (int)($gm['CODMENU'] ?? $gm['codmenu'] ?? 0), (array)($legado['tabelas']['gruposxmenus'] ?? []));
        $lista = [];
        foreach ($candidatas as $c) {
            $cols = array_map(static fn($x) => strtolower((string)$x['COLUMN_NAME']), $q($pdo, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$c['s'], $c['t']]));
            $todos = [];
            foreach ($q($pdo, "SELECT * FROM `{$c['s']}`.`{$c['t']}`") as $l) { $l = array_change_key_case($l, CASE_LOWER); $todos[(int)$l['codmenu']] = $l; }
            $acha = 0; foreach ($ids as $id) if (isset($todos[$id])) $acha++;
            if ($acha === 0) continue;
            $pai = ''; foreach (['codmenupai', 'codmenu_pai', 'codpai', 'pai', 'codmenusuperior'] as $p) if (in_array($p, $cols, true)) { $pai = $p; break; }
            $link = ''; foreach (['link', 'url', 'pagina', 'arquivo', 'programa', 'href', 'endereco'] as $p) if (in_array($p, $cols, true)) { $link = $p; break; }
            $caminho = static function (int $id) use ($todos, $pai): string { $p = []; $g = 0; while ($id > 0 && isset($todos[$id]) && $g++ < 10) { array_unshift($p, trim((string)$todos[$id]['dscmenu'])); $prox = $pai !== '' ? (int)$todos[$id][$pai] : 0; $id = $prox === $id ? 0 : $prox; } return implode(' › ', $p); };
            foreach ($ids as $id) $lista[$c['s'] . '.' . $c['t']][] = $id . ' | ' . (isset($todos[$id]) ? $caminho($id) . ($link !== '' ? ' | ' . (string)$todos[$id][$link] : '') : '(não está nesta tabela)');
            $legado['colunas'][$c['s'] . '.' . $c['t']] = $cols;
            if ($acha >= count($ids) - 2) break;
        }
        $legado['menus_do_grupo'] = $lista ?: 'nenhuma tabela de menus com esses codmenu';
        unset($legado['tabelas']['vgruposxmenus'], $legado['tabelas']['gruposxmenus']);
    }
    $r['legado'] = $legado;
}
// 6. Catálogo de menus do greenportal (guniversal) e menus do legado que a migração não mapeou para este banco
if ($uni) {
    $r['catalogo_greenportal'] = $tenta(function () use ($uni, $q, $perm) {
        $cols = array_map(static fn($c) => strtolower((string)$c['COLUMN_NAME']), $q($uni, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus'"));
        $rot = in_array('label_pt', $cols, true) ? 'label_pt' : 'label';
        $m = [];
        foreach ($q($uni, 'SELECT * FROM menus' . (in_array('ativo', $cols, true) ? ' WHERE COALESCE(ativo, 1) = 1' : '')) as $l) { $l = array_change_key_case($l, CASE_LOWER); $m[(int)$l['codmenu']] = $l; }
        $cam = static function (int $id) use ($m, $rot): string { $p = []; $g = 0; while ($id > 0 && isset($m[$id]) && $g++ < 8) { array_unshift($p, trim((string)$m[$id][$rot])); $prox = (int)($m[$id]['codmenu_pai'] ?? 0); $id = $prox === $id ? 0 : $prox; } return implode(' › ', $p); };
        $saida = [];
        foreach ($m as $id => $l) $saida[] = $cam($id) . ' | ' . strtoupper((string)$l['codigo']) . ' | ' . basename((string)($l['url'] ?? '')) . (isset($perm[strtoupper((string)$l['codigo'])]) ? ' | GESTOR JÁ TEM' : '');
        sort($saida);
        return $saida;
    });
    $r['menus_nao_mapeados_na_migracao'] = $tenta(fn() => $q($uni, "SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%nao_mapead%'"));
}
$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_acesso_' . preg_replace('/[^a-z0-9_.-]/i', '_', $userid) . '.json', $json);
echo $json . PHP_EOL;
