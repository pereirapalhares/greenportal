<?php
declare(strict_types=1);

/*
 * Migração 381 — Homes por módulo (GER-1 / GER-4 / GER-13), começando por Licenças (16/11/2026).
 * Modelo único: classes/HomeModulo.php (filtros, cartões, gráficos, calendário, grid padrão em sub-abas). Páginas: principal/home_<módulo>.php.
 * Nada muda nas tabelas das empresas (só leitura das views que já existem: vw_dash_licencas, vw_licencas_grid, ...).
 *
 * Universal: para cada Home de HomeModulo::MENUS, um menu no MESMO grupo da tela do módulo e como PRIMEIRO item dele
 *            (ex.: HOME_LICENCAS no grupo Módulo Legal › Licenças, antes de "Licenças"), replicado nas empresas;
 *            Ajuda da tela (ajuda_transacoes, trecho <!-- ml381 -->).
 * Cada empresa: permissões do menu novo = as da tela do módulo (ex.: LICENCAS), em cada perfil.
 * Pode ser executada mais de uma vez (as Homes novas entram ao rodar de novo). Depois: sair e entrar no portal (o menu fica na sessão).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-381-home-modulos.php [--simular] [--banco=gcrown_dbp] [--modulo=LICENCAS]
 *      ... --remover   tira os menus das Homes (catálogo e empresas) e as permissões deles
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/HomeModulo.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$remover = in_array('--remover', $args, true);
$soBanco = null; $soModulo = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
    if (str_starts_with($a, '--modulo=')) $soModulo = strtoupper(substr($a, 9));
}
$homes = array_filter(HomeModulo::MENUS, static fn($k) => $soModulo === null || $k === $soModulo, ARRAY_FILTER_USE_KEY);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '381', 'modo' => ($remover ? 'REMOVER' : 'APLICAR') . ($simular ? ' (SIMULACAO)' : ''), 'homes' => array_keys($homes), 'universal' => [], 'bancos' => []];
if (!$homes) { $rel['ok'] = false; $rel['universal']['erro'] = 'módulo desconhecido: ' . $soModulo; }

// ---------------------------------------------------------------- 1) universal: menu da Home como primeiro item do grupo do módulo
$cat = new MenuCatalogoService($env);
$codmenus = [];
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($homes as $mod => $h) {
        $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $h['codigo']]);
        if ($existe) $codmenus[$mod] = (int)$existe['codmenu'];
        if ($remover) { $rel['universal']['menus'][$mod] = !$existe ? 'não existe' : ($soBanco !== null ? 'mantido (--banco)' : ($simular ? 'será excluído' : 'a excluir')); continue; }
        $tela = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $h['menu']]);
        if (!$tela) { $rel['universal']['menus'][$mod] = 'ignorado: menu ' . $h['menu'] . ' não existe no catálogo'; continue; }
        $tela = array_change_key_case($tela);
        $pai = $tela['codmenu_pai'] !== null ? (int)$tela['codmenu_pai'] : null;
        // primeiro do grupo: ordem menor que a de todos os irmãos (na reaplicação mantém a ordem que alguém tenha mudado em Setup › Menu)
        $menor = null;
        foreach (MigracaoCrud::selecionar($u, 'menus', $pai === null ? [] : ['codmenu_pai' => $pai]) as $irmao) {
            $irmao = array_change_key_case($irmao);
            if (($pai === null && $irmao['codmenu_pai'] !== null) || strtoupper((string)$irmao['codigo']) === $h['codigo']) continue;
            $o = (int)($irmao['ordem'] ?? 0); $menor = $menor === null ? $o : min($menor, $o);
        }
        $dados = ['codmenu_pai' => $pai, 'modulo' => $tela['modulo'] ?? null, 'codigo' => $h['codigo'],
            'label' => $h['nome']['pt'], 'label_pt' => $h['nome']['pt'], 'label_en' => $h['nome']['en'], 'label_es' => $h['nome']['es'],
            'url' => $h['url'], 'nivel' => (int)($tela['nivel'] ?? 1), 'icone' => $h['icone'], 'ativo' => 1];
        if (!$existe) $dados['ordem'] = max(1, (int)($menor ?? 10) - 5);
        if ($simular) { $rel['universal']['menus'][$mod] = $existe ? 'já existe (codmenu ' . $existe['codmenu'] . ')' : 'será criado no grupo de ' . $h['menu'] . ' (codmenu_pai ' . ($pai ?? 'raiz') . ', ordem ' . $dados['ordem'] . ')'; continue; }
        $g = $cat->gravar($dados, $existe ? (int)$existe['codmenu'] : null);
        $codmenus[$mod] = (int)$g['codmenu'];
        $rel['universal']['menus'][$mod] = ($existe ? 'atualizado' : 'criado') . ' (codmenu ' . $g['codmenu'] . ', ' . count((array)$g['empresas']) . ' empresa(s))';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// Ajuda da tela, no universal (só o trecho <!-- ml381 --> é trocado ao rodar de novo)
if (!$remover) {
    try {
        $u = $cat->universal();
        if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
        $textos = require dirname(__DIR__) . '/database/381_ajuda_home_modulos.php';
        foreach ($homes as $mod => $h) {
            $transacao = $h['transacao'];
            if (!isset($textos[$transacao])) { $rel['universal']['ajuda'][$transacao] = 'sem texto'; continue; }
            $d = $textos[$transacao];
            $atual = null;
            foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
            $dados = [];
            foreach (['pt', 'en', 'es'] as $lg) {
                $velho = trim((string)preg_replace('/\s*<!-- ml381 -->.*?<!-- \/ml381 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
                $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml381 -->\n" . $d['texto'][$lg] . "\n<!-- /ml381 -->";
                if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
            }
            if ($atual !== null) {
                $igual = (int)($atual['ativo'] ?? 1) === 1;
                foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
                if ($igual) { $rel['universal']['ajuda'][$transacao] = 'igual'; continue; }
            }
            if ($simular) { $rel['universal']['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
            if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
            else {
                $chave = ['transacao' => (string)$atual['transacao']];
                foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
                MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
            }
            $rel['universal']['ajuda'][$transacao] = $atual === null ? 'incluída' : 'atualizada';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['ajuda_erro'] = $e->getMessage(); }
}

// ---------------------------------------------------------------- 2) empresas: permissões do menu novo = as da tela do módulo
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa encontrada' . ($soBanco ? " ($soBanco)" : '')];
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        foreach ($homes as $mod => $h) {
            $novo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $h['codigo']]);
            $orig = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $h['menu']]);
            if (!$orig) { $info[$mod] = 'sem o menu ' . $h['menu'] . ' (módulo não usado nesta empresa)'; continue; }
            if (!$novo) { $info[$mod] = $simular ? 'o menu será replicado ao aplicar' : 'menu ainda não replicado neste banco'; continue; }
            $cont = 0; $tirou = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $cols = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
                if ($remover) {
                    $tem = MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$novo['codmenu']]);
                    if (!$simular) foreach ($tem as $p) { $p = array_change_key_case($p); $s = $pdo->prepare('CALL sp_crud_delete(?, ?)'); $s->execute([$t, json_encode(['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])]); $s->closeCursor(); }
                    $tirou += count($tem);
                    continue;
                }
                foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$orig['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if (!array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])) continue;
                    if (!$simular) MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $info[$mod] = $remover ? $tirou . ' permissão(ões) ' . ($simular ? 'a tirar' : 'tirada(s)')
                : $cont . ' permissão(ões) ' . ($simular ? 'a criar' : 'criada(s)') . ' (as mesmas de ' . $h['menu'] . ')';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- 3) remover: menus do catálogo (depois das permissões)
if ($remover && !$simular && $soBanco === null) {
    foreach ($codmenus as $mod => $cod) {
        try { $x = $cat->excluir($cod); $rel['universal']['menus'][$mod] = 'excluído (' . count((array)$x['empresas']) . ' empresa(s))'; }
        catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['menus'][$mod] = 'ERRO ao excluir: ' . $e->getMessage(); }
    }
}

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_381_home_modulos' . ($simular ? '_simulacao' : '') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
