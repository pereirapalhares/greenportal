<?php
declare(strict_types=1);

/*
 * Migração 344 — Gestão de taxas das licenças (Onda 1 da apresentação Atlas Schindler 2026).
 *
 * Universal: menu Manutenção legal › Taxas das licenças (LICENCAS_TAXAS, ao lado de Licenças, replicado nas empresas) e a
 *            Ajuda (tela nova e nota na Ajuda de Licenças; trecho entre <!-- ml344 -->).
 * Cada empresa com o cadastro de licenças (li_licencas e vw_licencas_grid):
 *   1. database/344_licencas_taxas.sql — tabela li_taxas, gatilhos de validação, sp_li_taxas_gerar_proximas (recorrência) e
 *      sp_li_taxas_avisar (alertas 30 dias antes e no dia do vencimento);
 *   2. views montadas conforme as colunas do banco (classes/LicencaTaxa.php): grid, licenças para escolher, responsáveis,
 *      pendências do usuário, avisos e dashboard;
 *   3. permissão do menu novo: a mesma de Licenças (ou, sem ela, a de Minhas licenças) em cada perfil;
 *   4. reaplica a migração 284 nesta empresa (licença com taxa vencida e não paga fica em Atenção na situação calculada, e o
 *      gatilho de li_taxas põe o requisito na fila de recálculo). A 284 reaplica a 341, quando já aplicada.
 * Os alertas são criados pelo serviço de responsabilidades (GreenPortal-Responsibility) e viram e-mail pelo serviço de e-mail
 * (migração 343), conforme a escolha de cada usuário.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-344-licencas-taxas.php [--simular] [--banco=gatlas_dbp] [--sem-284]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/LicencaTaxa.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$sem284 = in_array('--sem-284', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);

$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '344', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
$textos = require dirname(__DIR__) . '/database/344_ajuda_licencas_taxas.php';

// ---------------------------------------------------------------- universal: menu e ajuda
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $lic = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'LICENCAS']);
    $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => LicencaTaxa::MENU]);
    if ($existe) {
        if (!$simular) $cat->replicar(LicencaTaxa::MENU, null, false);
        $rel['universal']['menu'] = 'já existia (codmenu ' . $existe['codmenu'] . ')';
    } elseif (!$lic) { $rel['universal']['menu'] = 'ERRO: menu LICENCAS não encontrado no universal'; $rel['ok'] = false; }
    elseif ($simular) $rel['universal']['menu'] = 'será criado ao lado de Licenças: Taxas das licenças';
    else {
        $lic = array_change_key_case($lic);
        $g = $cat->gravar(['codmenu_pai' => $lic['codmenu_pai'] !== null ? (int)$lic['codmenu_pai'] : null, 'modulo' => $lic['modulo'] ?? null, 'codigo' => LicencaTaxa::MENU,
            'label' => 'Taxas das licenças', 'label_pt' => 'Taxas das licenças', 'label_en' => 'License fees', 'label_es' => 'Tasas de las licencias',
            'url' => LicencaTaxa::TELA, 'nivel' => (int)($lic['nivel'] ?? 1), 'ordem' => (int)($lic['ordem'] ?? 0) + 1, 'icone' => 'bi-cash-coin', 'ativo' => 1]);
        $rel['universal']['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    $rel['universal']['ajuda'] = gravarAjuda344($u, $textos, $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('li_licencas', 'vw_licencas_grid', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa com o cadastro de licenças (li_licencas e vw_licencas_grid)'];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/344_licencas_taxas.sql');
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tinha = MigracaoCrud::existe($pdo, 'li_taxas');
        $info['tabela'] = $tinha ? 'li_taxas já existia (' . count(MigracaoCrud::selecionar($pdo, 'li_taxas')) . ' taxa(s))' : ($simular ? 'li_taxas será criada' : 'li_taxas criada');
        if ($simular) {
            $info['alertas'] = MigracaoCrud::existe($pdo, 'alertas') ? 'ok' : 'sem a tabela alertas: os avisos não serão criados';
            $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue;
        }
        // 1. Parte fixa
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $pdo->exec($cmd); $n++;
        }
        $info['blocos'] = $n;
        // 2. Views
        foreach (LicencaTaxa::views($pdo) as $ddl) $pdo->exec($ddl);
        $info['views'] = count(LicencaTaxa::VIEWS) . ' gravadas';
        if (!MigracaoCrud::existe($pdo, 'alertas')) $info['alertas'] = 'sem a tabela alertas: os avisos não serão criados (rode a migração 343)';
        // 3. Permissão do menu novo = a de Licenças
        $menuNovo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => LicencaTaxa::MENU]);
        $menusOrigem = array_values(array_filter([MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'LICENCAS']), MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'MINHAS_LICENCAS'])]));
        if (!$menuNovo || !$menusOrigem) $info['permissao'] = 'menu ainda não replicado neste banco';
        else {
            $cont = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $cols = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
                foreach ($menusOrigem as $menuLic) foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$menuLic['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if (!array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']])) continue;
                    MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $info['permissao'] = $cont . ' permissão(ões) criada(s) (as mesmas de Licenças / Minhas licenças)';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); $rel['bancos'][$banco] = $info; continue; }
    // 4. Situação legal: a 284 nesta empresa (com a regra da taxa e o gatilho de li_taxas)
    if ($sem284) $info['situacao_legal'] = 'não reaplicada (--sem-284): rode a 284 depois';
    else {
        $saida = []; $cod = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-284-situacao-legal.php') . ' ' . escapeshellarg('--banco=' . $banco) . ' 2>&1', $saida, $cod);
        $r284 = json_decode(implode("\n", $saida), true);
        $b284 = is_array($r284) ? ($r284['bancos'][$banco] ?? []) : [];
        if (!is_array($r284)) { $info['situacao_legal'] = 'ERRO ao reaplicar a 284: ' . mb_substr(implode(' ', $saida), 0, 300); $rel['ok'] = false; }
        elseif (isset($b284['ignorado'])) $info['situacao_legal'] = 'sem módulo legal: ' . $b284['ignorado'];
        else {
            $info['situacao_legal'] = ($r284['ok'] ?? false) ? 'reaplicada (licença com taxa vencida = Atenção)' : 'reaplicada com aviso: veja a saída da 284';
            if (isset($b284['gatilhos'])) $info['gatilhos_284'] = $b284['gatilhos'];
            if (isset($b284['erro'])) { $info['erro_284'] = $b284['erro']; $rel['ok'] = false; }
        }
    }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml344 --> e <!-- /ml344 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda344(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml344 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml344 -->.*?<!-- \/ml344 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml344 -->\n" . $d['texto'][$lg] . "\n<!-- /ml344 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
