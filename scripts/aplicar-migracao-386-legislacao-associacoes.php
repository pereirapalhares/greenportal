<?php
declare(strict_types=1);

/*
 * Migração 386 — Manutenção Legal › Legislação: Alterações na legislação, CheckList e Palavras-chave (17/11/2026).
 * Só código novo nas telas (principal/manutencaolegal_legislacao_alteracoes.php e manutencaolegal_legislacao_catalogo.php),
 * sem tabela nova: usa legislacaoxalteracao / tipo_de_alteracao, legislacaoxpergunta / perguntas (glegislacao_dbp) e
 * legislacaoxpalavraschave (glegislacao_dbp) / palavras_chaves (guniversal_dbp).
 * Aqui: Ajuda da tela (trecho <!-- ml386 -->) e o prompt LEG_PALAVRAS_CHAVE em cada empresa (só se não existir).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-386-legislacao-associacoes.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/LegislacaoAssociacoes.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);

$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$bancoLeg = (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp');
$rel = ['ok' => true, 'migracao' => '386', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
$textos = require dirname(__DIR__) . '/database/386_ajuda_legislacao_associacoes2.php';
$prompt = $textos['__prompt']; unset($textos['__prompt']);

// ---------------------------------------------------------------- 2) universal: ajuda
try {
    $cat = new MenuCatalogoService($env);
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['universal']['ajuda'] = gravarAjuda386($u, $textos, $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 3) empresas: prompt de IA
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'ia_prompts' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => !in_array($b, [$bancoUniversal, $bancoLeg], true) && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa com ia_prompts: a sugestão por IA não funciona até a migração 142'];
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (MigracaoCrud::primeiro($pdo, 'ia_prompts', ['codigo' => LegislacaoAssociacoes::PROMPT_PALAVRAS])) { $rel['bancos'][$banco] = 'prompt já existia (mantido)'; continue; }
        if ($simular) { $rel['bancos'][$banco] = 'prompt será cadastrado'; continue; }
        MigracaoCrud::inserir($pdo, 'ia_prompts', ['codigo' => LegislacaoAssociacoes::PROMPT_PALAVRAS, 'nome_pt' => $prompt['nome']['pt'], 'nome_en' => $prompt['nome']['en'], 'nome_es' => $prompt['nome']['es'],
            'prompt_pt' => $prompt['texto']['pt'], 'prompt_en' => $prompt['texto']['en'], 'prompt_es' => $prompt['texto']['es'], 'versao' => 1, 'ativo' => 1]);
        $rel['bancos'][$banco] = 'prompt cadastrado';
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml386 --> e <!-- /ml386 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda386(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml386 -->.*?<!-- \/ml386 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml386 -->\n" . $d['texto'][$lg] . "\n<!-- /ml386 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada (trecho ml386 no fim do texto atual)'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
