<?php
declare(strict_types=1);

/*
 * Permissões do perfil ADM DA EMPRESA em transações que ele deve administrar na própria empresa
 * (o perfil ADMINISTRADOR passa sempre; o ADM DA EMPRESA precisa da permissão gravada):
 *   MODULOLEGAL_POTENCIAIS  Potenciais IA: abrir o resultado da análise por IA e aprovar/rejeitar as sugestões
 *   SETUP_LAYOUT            Configurações › Layout: tema de cores da empresa
 *
 * Mostra a permissão atual de cada perfil e libera Visualizar + Editar + Incluir + Exportar para ADM DA EMPRESA.
 * Com --gestor-consulta, também dá Visualizar ao GESTOR que ainda não tem (só em Potenciais IA).
 * Opcional: --transacao=CODIGO (pode repetir) para tratar só algumas.
 *
 * Uso (PowerShell):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\corrigir-permissao-adm-empresa.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\corrigir-permissao-adm-empresa.php [--banco=gatlas_dbp] [--gestor-consulta]
 * Depois: o usuário precisa sair e entrar de novo (as permissões são carregadas no login).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$transacoes = ['MODULOLEGAL_POTENCIAIS', 'SETUP_LAYOUT'];
$simular = in_array('--simular', $argv ?? [], true);
$gestor = in_array('--gestor-consulta', $argv ?? [], true);
$somente = [];
$escolhidas = [];
foreach ($argv ?? [] as $a) { if (str_starts_with($a, '--banco=')) $somente[] = substr($a, 8); if (str_starts_with($a, '--transacao=')) $escolhidas[] = strtoupper(substr($a, 12)); }
if ($escolhidas) $transacoes = array_values(array_intersect($transacoes, $escolhidas));

$env = GreenPortalDatabase::env();
$universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $universal && !str_contains($b, '_seguranca')));
if ($somente) $bancos = array_values(array_intersect($bancos, $somente));

$norm = static fn(string $c): string => trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[_-]+/', ' ', strtoupper(trim($c)))));
$flagsVer = ['pode_visualizar', 'pode_visualizar_arquivos'];
$flagsAdm = ['pode_visualizar', 'pode_editar', 'pode_incluir', 'pode_exportar_excel'];

$rel = ['ok' => true, 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'transacoes' => $transacoes, 'bancos' => []];
foreach ($bancos as $db) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $db, 'GPT');
        foreach ($transacoes as $tx) {
        $ti = [];
        $menu = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $tx]);
        if (!$menu) { $info[$tx] = 'não existe no menu desta empresa (só o perfil ADMINISTRADOR acessa)'; continue; }
        $codmenu = (int)$menu['codmenu'];
        $ti['menu'] = 'codmenu ' . $codmenu . (isset($menu['ativo']) && (int)$menu['ativo'] !== 1 ? ' (fora do menu)' : '');
        foreach (['permissoes', 'perfil_menus'] as $t) {
            $tc = MigracaoCrud::colunas($pdo, $t);
            if (!$tc) continue;
            foreach (MigracaoCrud::selecionar($pdo, 'perfis') as $pf) {
                $cod = (string)($pf['codigo'] ?? ''); $n = $norm($cod);
                $atual = MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$pf['codperfil'], 'codmenu' => $codmenu]) ?? [];
                $tem = array_values(array_filter(['pode_visualizar', 'pode_editar', 'pode_incluir'], static fn($f) => (int)($atual[$f] ?? 0) === 1));
                $alvo = [];
                if (in_array($n, ['ADM DA EMPRESA', 'ADM EMPRESA'], true)) $alvo = $flagsAdm;
                elseif ($gestor && $n === 'GESTOR' && $tx === 'MODULOLEGAL_POTENCIAIS') $alvo = $flagsVer;
                $alvo = array_values(array_intersect($alvo, $tc));
                $falta = array_values(array_filter($alvo, static fn($f) => (int)($atual[$f] ?? 0) !== 1));
                if ($t === 'permissoes' && ($falta || in_array($n, ['ADM DA EMPRESA', 'ADM EMPRESA', 'GESTOR'], true))) $ti['perfis'][$cod] = ($tem ? implode(', ', $tem) : 'sem permissão') . ($falta ? ' → liberar: ' . implode(', ', $falta) : '');
                if ($falta && !$simular) MigracaoCrud::salvar($pdo, $t, ['codperfil' => (int)$pf['codperfil'], 'codmenu' => $codmenu], array_fill_keys($falta, 1));
            }
        }
        $info[$tx] = $ti;
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$db] = $info;
}
$rel['depois'] = 'Os usuários afetados precisam sair e entrar de novo no portal.';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
