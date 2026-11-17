<?php
declare(strict_types=1);

/*
 * Migração 342 — Requisitos legais, etapa 2: a IA sugere a Severidade e a Probabilidade dos itens (aceitar, ajustar ou recusar).
 *
 * Universal: menu Módulo Legal › Sugestões de risco (IA) (MODULOLEGAL_RISCO_SUGESTOES, ao lado de Requisitos legais, replicado
 *            nas empresas) e a Ajuda (tela nova, Requisitos legais, Situação e cadastros dos pilares; trecho entre <!-- ml342 -->).
 * Cada empresa com a migração 341 (ml_risco_itens):
 *   1. colunas ORIGEM (MANUAL, IA, IA_AJUSTADA) e CODSUGESTAO em ml_risco_itens e no histórico;
 *   2. database/342_risco_sugestao_ia.sql — pedidos (ml_risco_lotes), sugestões (ml_risco_sugestoes), procedures, views e o
 *      gatilho que volta a origem para MANUAL quando o risco é alterado no cadastro;
 *   3. prompt ML_RISCO (Setup › Prompts de IA), só se ainda não existir;
 *   4. permissão do menu novo: a mesma de Requisitos legais em cada perfil.
 * Nada é enviado à IA aqui: os pedidos só são feitos por quem usa a tela.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-342-risco-sugestao-ia.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/RequisitoRiscoIa.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);

$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '342', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
$textos = require dirname(__DIR__) . '/database/342_ajuda_risco_ia.php';
$prompt = $textos['__prompt']; unset($textos['__prompt']);

// ---------------------------------------------------------------- universal: menu e ajuda
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $req = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'REQUISITOS_LEGAIS']);
    $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => RequisitoRiscoIa::MENU]);
    if ($existe) {
        if (!$simular) $cat->replicar(RequisitoRiscoIa::MENU, null, false);
        $rel['universal']['menu'] = 'já existia (codmenu ' . $existe['codmenu'] . ')';
    } elseif (!$req) { $rel['universal']['menu'] = 'ERRO: menu REQUISITOS_LEGAIS não encontrado no universal'; $rel['ok'] = false; }
    elseif ($simular) $rel['universal']['menu'] = 'será criado ao lado de Requisitos legais: Sugestões de risco (IA)';
    else {
        $req = array_change_key_case($req);
        $g = $cat->gravar(['codmenu_pai' => $req['codmenu_pai'] !== null ? (int)$req['codmenu_pai'] : null, 'modulo' => $req['modulo'] ?? null, 'codigo' => RequisitoRiscoIa::MENU,
            'label' => 'Sugestões de risco (IA)', 'label_pt' => 'Sugestões de risco (IA)', 'label_en' => 'Risk suggestions (AI)', 'label_es' => 'Sugerencias de riesgo (IA)',
            'url' => RequisitoRiscoIa::TELA, 'nivel' => (int)($req['nivel'] ?? 1), 'ordem' => (int)($req['ordem'] ?? 0) + 1, 'icone' => 'bi-stars', 'ativo' => 1]);
        $rel['universal']['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    $rel['universal']['ajuda'] = gravarAjuda342($u, $textos, $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'ml_risco_itens' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa com a migração 341 (ml_risco_itens): rode a 341 antes'];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/342_risco_sugestao_ia.sql');
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 1. Colunas de origem
        $novas = [];
        foreach (['ml_risco_itens', 'ml_risco_itens_historico'] as $t) {
            $cols = MigracaoCrud::colunas($pdo, $t);
            $add = [];
            if (!in_array('origem', $cols, true)) $add[] = "ADD COLUMN `ORIGEM` VARCHAR(12) NOT NULL DEFAULT 'MANUAL' COMMENT 'MANUAL, IA ou IA_AJUSTADA'";
            if (!in_array('codsugestao', $cols, true)) $add[] = 'ADD COLUMN `CODSUGESTAO` BIGINT NULL';
            if ($add) { $novas[] = $t; if (!$simular) $pdo->exec("ALTER TABLE `$t` " . implode(', ', $add)); }
        }
        $info['colunas_origem'] = $novas ? implode(', ', $novas) . ($simular ? ' (a criar)' : ' (criadas)') : 'já existiam';
        if ($simular) { $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue; }
        // 2. Blocos
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $pdo->exec($cmd); $n++;
        }
        $info['blocos'] = $n;
        $s = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_NAME LIKE 'sp\\_ml\\_risco\\_%'");
        $procs = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
        $faltam = array_values(array_diff(['sp_ml_risco_lote_criar', 'sp_ml_risco_sugestao_criar', 'sp_ml_risco_sugestao_job', 'sp_ml_risco_sugestao_resultado', 'sp_ml_risco_lote_verificar', 'sp_ml_risco_sugestao_decidir'], $procs));
        $info['procedures'] = $faltam ? ['faltam' => $faltam] : 'gravadas';
        if ($faltam) $rel['ok'] = false;
        // 3. Prompt ML_RISCO
        if (!MigracaoCrud::existe($pdo, 'ia_prompts')) $info['prompt'] = 'sem ia_prompts (migração 142): a sugestão não funciona nesta empresa';
        elseif (MigracaoCrud::primeiro($pdo, 'ia_prompts', ['codigo' => RequisitoRiscoIa::PROMPT])) $info['prompt'] = 'já existia (mantido)';
        else {
            MigracaoCrud::inserir($pdo, 'ia_prompts', ['codigo' => RequisitoRiscoIa::PROMPT, 'nome_pt' => $prompt['nome']['pt'], 'nome_en' => $prompt['nome']['en'], 'nome_es' => $prompt['nome']['es'],
                'prompt_pt' => $prompt['texto']['pt'], 'prompt_en' => $prompt['texto']['en'], 'prompt_es' => $prompt['texto']['es'], 'versao' => 1, 'ativo' => 1]);
            $info['prompt'] = 'cadastrado';
        }
        // 4. Permissão do menu novo = a de Requisitos legais
        $menuNovo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => RequisitoRiscoIa::MENU]);
        $menuReq = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'REQUISITOS_LEGAIS']);
        if (!$menuNovo || !$menuReq) $info['permissao'] = 'menu ainda não replicado neste banco';
        else {
            $cont = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $cols = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
                foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$menuReq['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if (!array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']])) continue;
                    MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $info['permissao'] = $cont . ' permissão(ões) criada(s) (as mesmas de Requisitos legais)';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml342 --> e <!-- /ml342 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda342(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml342 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml342 -->.*?<!-- \/ml342 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml342 -->\n" . $d['texto'][$lg] . "\n<!-- /ml342 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
