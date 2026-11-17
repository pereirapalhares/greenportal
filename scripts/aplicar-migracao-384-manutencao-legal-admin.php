<?php
declare(strict_types=1);

/*
 * Migração 384 — Manutenção Legal só para o perfil ADMINISTRADOR (17/11/2026).
 * Manutenção Legal › Legislação passa a administrar a legislação de TODAS as empresas migradas (vw_empresas_login_migradas,
 * MODELO incluída), por isso o grupo inteiro fica com o ADMINISTRADOR:
 *   - no código: PermissaoTransacao::MENUS_SO_ADMIN (nega a tela) e UsuarioSessao (some do menu), valendo mesmo que alguém
 *     volte a liberar a permissão em Segurança › Permissões;
 *   - aqui: em cada banco de empresa, tira as permissões desses menus dos perfis que não são ADMINISTRADOR (tabelas
 *     permissoes e perfil_menus), para a tela de permissões ficar coerente.
 * Antes de apagar, grava as linhas em database/backups/384_permissoes_removidas_<banco>_<data>.json.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-384-manutencao-legal-admin.php [--simular] [--banco=gcrown_dbp]
 */

date_default_timezone_set('America/Sao_Paulo');
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$cat = new MenuCatalogoService($env);
$rel = ['ok' => true, 'migracao' => '384', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'menus' => PermissaoTransacao::MENUS_SO_ADMIN, 'bancos' => []];

foreach ($cat->empresas() as $banco => $pdo) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = [];
    try {
        $codmenus = [];
        foreach (MigracaoCrud::selecionar($pdo, 'menus') as $m) {
            $m = array_change_key_case($m);
            if (in_array(strtoupper((string)($m['codigo'] ?? '')), PermissaoTransacao::MENUS_SO_ADMIN, true)) $codmenus[(int)$m['codmenu']] = (string)$m['codigo'];
        }
        $admins = []; $nomes = [];
        foreach (MigracaoCrud::existe($pdo, 'perfis') ? MigracaoCrud::selecionar($pdo, 'perfis') : [] as $p) {
            $p = array_change_key_case($p);
            $nomes[(int)$p['codperfil']] = (string)($p['codigo'] ?? $p['nome'] ?? $p['codperfil']);
            if (strtoupper(trim((string)($p['codigo'] ?? ''))) === 'ADMINISTRADOR') $admins[(int)$p['codperfil']] = true;
        }
        $info['perfil_administrador'] = array_keys($admins) ?: 'NÃO ENCONTRADO';
        if (!$admins) { $info['aviso'] = 'banco sem perfil ADMINISTRADOR: nada removido'; $rel['bancos'][$banco] = $info; continue; }
        $remover = [];
        foreach (['permissoes', 'perfil_menus'] as $t) {
            if (!MigracaoCrud::existe($pdo, $t)) continue;
            foreach (array_keys($codmenus) as $cm) foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => $cm]) as $r) {
                $r = array_change_key_case($r);
                if (isset($admins[(int)$r['codperfil']])) continue;
                $remover[$t][] = $r;
            }
        }
        $resumo = [];
        foreach ($remover as $t => $linhas) foreach ($linhas as $r) $resumo[$nomes[(int)$r['codperfil']] ?? ('perfil ' . $r['codperfil'])][] = $codmenus[(int)$r['codmenu']];
        $info['remover'] = array_map(static fn($l) => count($l), $remover) ?: 'nada';
        $info['por_perfil'] = array_map(static fn($l) => implode(', ', array_unique($l)), $resumo);
        if (!$simular && $remover) {
            $dir = dirname(__DIR__) . '/database/backups';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $arq = $dir . '/384_permissoes_removidas_' . $banco . '_' . date('Ymd_His') . '.json';
            file_put_contents($arq, json_encode($remover, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $info['backup'] = $arq;
            $n = 0;
            foreach ($remover as $t => $linhas) foreach ($linhas as $r) {
                $chave = $t === 'permissoes' && isset($r['codpermissao']) ? ['codpermissao' => (int)$r['codpermissao']] : ['codperfil' => (int)$r['codperfil'], 'codmenu' => (int)$r['codmenu']];
                $s = $pdo->prepare('CALL sp_crud_delete(?, ?)'); $s->execute([$t, json_encode($chave)]); while ($s->nextRowset()) {} $s->closeCursor();
                $n++;
            }
            $info['removidas'] = $n;
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$rel['depois'] = 'os usuários já logados deixam de ver o menu ao atualizar a sessão (próxima tela aberta ou novo login)';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
