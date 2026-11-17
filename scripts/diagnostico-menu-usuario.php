<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — por que um menu não aparece para um usuário.
 * Refaz o cálculo do portal (classes/UsuarioSessao.php + classes/MenuService.php + classes/ModuloEmpresaService.php):
 *   1. usuário e perfil principal; perfis associados do legado (sp_usuario_perfis_legado, efetivos e não efetivos);
 *   2. permissões de cada perfil (vw_permissoes_grid; o portal lê no máximo 1000 por perfil);
 *   3. módulo da empresa (vw_empresa_modulos_situacao) para os menus do Módulo Legal / Manutenção Legal;
 *   4. cada item da árvore do menu pedido: tem permissão? o módulo está liberado? aparece?
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-menu-usuario.php --banco=gcrown_dbp --usuario=nathalia.capacla [--menu=MANUTENCAO_LEGAL]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opc = static function (string $n) use ($argv): ?string { foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$n=")) return substr($a, strlen($n) + 3); return null; };
$banco = (string)$opc('banco'); $login = trim((string)$opc('usuario')); $menuRaiz = strtoupper((string)($opc('menu') ?? 'MANUTENCAO_LEGAL'));
if (!preg_match('/^g[a-z0-9_]+_dbp$/i', $banco) || $login === '') { fwrite(STDERR, "Uso: --banco=gcrown_dbp --usuario=login [--menu=MANUTENCAO_LEGAL]\n"); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (PDO $p, string $sql, array $a = []): array { $s = $p->prepare($sql); $s->execute($a); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$crud = static fn(string $tabela, array $filtros, int $limite = 1000): array => $q($pdo, 'CALL sp_crud_select(?, ?, NULL, ?, 0)', [$tabela, json_encode($filtros, JSON_UNESCAPED_UNICODE), $limite]);

$rel = ['diagnostico' => 'menu do usuário', 'banco' => $banco, 'usuario' => $login, 'menu' => $menuRaiz, 'gerado_em' => date('c')];
$usuario = $crud('vw_usuarios_grid', ['userid' => $login], 5)[0] ?? null;
if (!$usuario) {
    // tenta sem diferenciar maiúsculas/minúsculas e pelo e-mail
    foreach ($crud('vw_usuarios_grid', [], 5000) as $x) if (strcasecmp((string)$x['userid'], $login) === 0 || strcasecmp((string)($x['email'] ?? ''), $login) === 0) { $usuario = $x; break; }
}
if (!$usuario) { $rel['erro'] = 'usuário não encontrado em vw_usuarios_grid'; echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }
$rel['usuario_dados'] = array_intersect_key($usuario, array_flip(['codusuario', 'userid', 'nome', 'email', 'ativo', 'codperfil', 'perfil_codigo', 'perfil_nome']));
$perfilPrincipal = (int)($usuario['codperfil'] ?? 0);
$assoc = [];
foreach ([1, 0] as $efetivo) {
    try { $assoc[$efetivo] = array_map(static fn(array $r): int => (int)reset($r), $q($pdo, 'CALL sp_usuario_perfis_legado(?, ?)', [(int)$usuario['codusuario'], $efetivo])); }
    catch (Throwable $e) { $assoc[$efetivo] = 'erro: ' . $e->getMessage(); }
}
$rel['perfis_associados_legado'] = ['efetivos' => $assoc[1], 'nao_efetivos' => $assoc[0]];
$perfis = $perfilPrincipal > 0 ? [$perfilPrincipal] : [];
if (is_array($assoc[1]) && $assoc[1]) $perfis = array_values(array_unique(array_merge($perfis, $assoc[1])));
elseif (is_array($assoc[0])) foreach ($assoc[0] as $p) if ($p > 0 && !in_array($p, $perfis, true)) $perfis[] = $p;
$nomesPerfis = [];
foreach ($crud('vw_perfis_grid', [], 1000) as $p) $nomesPerfis[(int)$p['codperfil']] = (string)$p['codigo'] . ((int)($p['ativo'] ?? 1) ? '' : ' (INATIVO)');
$rel['perfis_usados_pelo_portal'] = array_map(static fn($p) => $p . ' ' . ($nomesPerfis[$p] ?? '?'), $perfis);

// permissões unidas (como UsuarioSessao), por código do menu
$porCodigo = []; $porPerfil = [];
foreach ($perfis as $p) {
    $lista = $crud('vw_permissoes_grid', ['codperfil' => $p], 1000);
    $total = $crud('vw_permissoes_grid', ['codperfil' => $p], 100000);
    $porPerfil[$p . ' ' . ($nomesPerfis[$p] ?? '?')] = ['lidas_pelo_portal' => count($lista), 'total_no_banco' => count($total), 'alerta' => count($total) > count($lista) ? 'MAIS DE 1000: o portal ignora o restante' : null];
    foreach ($lista as $perm) {
        $c = strtoupper((string)($perm['menu_codigo'] ?? ''));
        if ($c === '') { $porPerfil[$p . ' ' . ($nomesPerfis[$p] ?? '?')]['sem_menu_codigo'] = ($porPerfil[$p . ' ' . ($nomesPerfis[$p] ?? '?')]['sem_menu_codigo'] ?? 0) + 1; continue; }
        $porCodigo[$c] ??= ['pode_visualizar' => 0, 'pode_visualizar_total' => 0, 'perfis' => []];
        $porCodigo[$c]['pode_visualizar'] = max($porCodigo[$c]['pode_visualizar'], (int)$perm['pode_visualizar']);
        $porCodigo[$c]['pode_visualizar_total'] = max($porCodigo[$c]['pode_visualizar_total'], (int)$perm['pode_visualizar_total']);
        if ((int)$perm['pode_visualizar'] || (int)$perm['pode_visualizar_total']) $porCodigo[$c]['perfis'][] = $nomesPerfis[$p] ?? (string)$p;
    }
}
$rel['permissoes_por_perfil'] = $porPerfil;

// empresa e módulos
$empresa = $q($u, 'SELECT codempresa, codigo, nome FROM empresa WHERE banco_dados = ? LIMIT 1', [$banco])[0] ?? null;
$rel['empresa'] = $empresa;
$modulos = [];
if ($empresa) foreach ($q($u, 'SELECT codigo_modulo, ativo FROM vw_empresa_modulos_situacao WHERE codempresa = ?', [(int)$empresa['codempresa']]) as $m) $modulos[(string)$m['codigo_modulo']] = (int)$m['ativo'];
$rel['modulos_da_empresa'] = $modulos ?: 'nenhum registro em vw_empresa_modulos_situacao (o portal trata como desligado)';

// árvore do menu no universal
$menus = $q($u, 'SELECT codmenu, codmenu_pai, codigo, label_pt, url, ativo FROM menus');
$byId = []; foreach ($menus as $m) $byId[(int)$m['codmenu']] = $m;
$raiz = null; foreach ($menus as $m) if (strtoupper((string)$m['codigo']) === $menuRaiz) $raiz = $m;
if (!$raiz) { $rel['erro'] = "menu $menuRaiz não existe no guniversal_dbp"; echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }
$moduloDe = static function (array $m) use ($byId): ?string {
    for ($g = 0; $m && $g < 50; $g++) {
        $c = strtoupper((string)$m['codigo']);
        if ($c === 'MODULO_LEGAL' || $c === 'MANUTENCAO_LEGAL') return 'MODULO_LEGAL';
        if ($c === 'HOMOLOGACAO_FORNECEDORES') return 'HOMOLOGACAO_FORNECEDORES';
        if ($c === 'NAO_CONFORMIDADES') return 'NAO_CONFORMIDADES';
        $m = $byId[(int)($m['codmenu_pai'] ?? 0)] ?? null;
    }
    return null;
};
$itens = [];
$visita = static function (array $m, int $nivel) use (&$visita, &$itens, $menus, $porCodigo, $moduloDe, $modulos): bool {
    $c = strtoupper((string)$m['codigo']);
    $perm = $porCodigo[$c] ?? null;
    $mod = $moduloDe($m);
    $modOk = $mod === null || (int)($modulos[$mod] ?? 0) === 1;
    $filhoVisivel = false; $idx = count($itens);
    $itens[] = null;
    foreach ($menus as $f) if ((int)$f['codmenu_pai'] === (int)$m['codmenu']) $filhoVisivel = $visita($f, $nivel + 1) || $filhoVisivel;
    $temPerm = $perm && ($perm['pode_visualizar'] || $perm['pode_visualizar_total']);
    $aparece = (int)$m['ativo'] === 1 && $modOk && ($temPerm || $filhoVisivel);
    $motivo = (int)$m['ativo'] !== 1 ? 'menu inativo' : (!$modOk ? "módulo $mod desligado para a empresa" : (!$temPerm && !$filhoVisivel ? ($perm ? 'permissão existe, mas sem Visualizar' : 'nenhum perfil do usuário tem permissão para este menu') : 'ok'));
    $itens[$idx] = str_repeat('  ', $nivel) . $c . ' (' . $m['label_pt'] . ') => ' . ($aparece ? 'APARECE' : 'NÃO APARECE: ' . $motivo) . ($temPerm ? ' [perfil: ' . implode(', ', array_unique($perm['perfis'])) . ']' : '');
    return $aparece;
};
$rel['aparece_no_menu'] = $visita($raiz, 0);
$rel['arvore'] = $itens;
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
