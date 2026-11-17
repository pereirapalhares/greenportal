<?php
declare(strict_types=1);

/*
 * Migração 382 — Manutenção Legal › Legislação: URL de pesquisa e Legislações filhas (17/11/2026).
 *
 * glegislacao_dbp (compartilhado por todas as empresas):
 *   1. database/382_legislacao_associacoes.sql — tabela legislacaoxurl e as views vw_legislacao_filhas, vw_legislacao_busca_simples
 *      e vw_legislacao_assoc_contagem;
 *   2. índice único (CODLEGISLACAO_PAI, CODLEGISLACAO_FILHA) em legislacaopaixlegislacaofilha, só se não houver vínculos repetidos
 *      (os repetidos e os de uma lei com ela mesma são listados, nada é apagado).
 * Universal: Ajuda da tela (ajuda_transacoes, transação manutencaolegal_legislacao, trecho <!-- ml382 -->).
 * Cada empresa com ia_prompts (migração 142): prompt LEG_URL_PESQUISA (Setup › Prompts de IA), só se ainda não existir.
 * Nada é enviado à IA aqui. Telas: principal/manutencaolegal_legislacao_urls.php e principal/manutencaolegal_legislacao_filhas.php.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-382-legislacao-associacoes.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
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
$rel = ['ok' => true, 'migracao' => '382', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'legislacao' => [], 'universal' => [], 'bancos' => []];
$textos = require dirname(__DIR__) . '/database/382_ajuda_legislacao_associacoes.php';
$prompt = $textos['__prompt']; unset($textos['__prompt']);

// ---------------------------------------------------------------- 1) glegislacao_dbp
try {
    $leg = GreenPortalDatabase::connect($env, $bancoLeg, 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $info = ['banco' => $bancoLeg];
    $existe = static fn(string $t): bool => (bool)$leg->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $leg->quote($t))->fetchColumn();
    $info['legislacaoxurl'] = $existe('legislacaoxurl') ? 'já existia' : ($simular ? 'será criada' : 'a criar');
    foreach (['vw_legislacao_filhas', 'vw_legislacao_busca_simples', 'vw_legislacao_assoc_contagem'] as $v) $info['views'][$v] = $existe($v) ? 'já existia (será recriada)' : ($simular ? 'será criada' : 'a criar');
    $info['leis'] = (int)$leg->query('SELECT COUNT(*) FROM legislacao')->fetchColumn();
    $info['vinculos_pai_filha'] = (int)$leg->query('SELECT COUNT(*) FROM legislacaopaixlegislacaofilha')->fetchColumn();
    $repetidos = $leg->query('SELECT CODLEGISLACAO_PAI, CODLEGISLACAO_FILHA, COUNT(*) QTD FROM legislacaopaixlegislacaofilha GROUP BY CODLEGISLACAO_PAI, CODLEGISLACAO_FILHA HAVING COUNT(*) > 1 LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $proprios = $leg->query('SELECT codlegislacaopai_filha, CODLEGISLACAO_PAI FROM legislacaopaixlegislacaofilha WHERE CODLEGISLACAO_PAI = CODLEGISLACAO_FILHA LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $orfaos = (int)$leg->query('SELECT COUNT(*) FROM legislacaopaixlegislacaofilha r WHERE NOT EXISTS (SELECT 1 FROM legislacao l WHERE l.CODLEGISLACAO = r.CODLEGISLACAO_PAI) OR NOT EXISTS (SELECT 1 FROM legislacao l WHERE l.CODLEGISLACAO = r.CODLEGISLACAO_FILHA)')->fetchColumn();
    $info['vinculos_repetidos'] = $repetidos ?: 'nenhum';
    $info['vinculos_lei_com_ela_mesma'] = $proprios ?: 'nenhum';
    $info['vinculos_com_lei_inexistente'] = $orfaos;
    $temUnico = (bool)$leg->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaopaixlegislacaofilha' AND INDEX_NAME = 'ux_lplf_pai_filha'")->fetchColumn();
    if ($temUnico) $info['indice_unico'] = 'já existia';
    elseif ($repetidos) $info['indice_unico'] = 'NÃO criado: há vínculos repetidos (a tela já impede novos repetidos)';
    else $info['indice_unico'] = $simular ? 'será criado' : 'a criar';
    if (!$simular) {
        $sql = (string)file_get_contents(dirname(__DIR__) . '/database/382_legislacao_associacoes.sql');
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $leg->exec($cmd); $n++;
        }
        $info['blocos'] = $n;
        if (!$temUnico && !$repetidos) { $leg->exec('ALTER TABLE `legislacaopaixlegislacaofilha` ADD UNIQUE KEY `ux_lplf_pai_filha` (`CODLEGISLACAO_PAI`, `CODLEGISLACAO_FILHA`)'); $info['indice_unico'] = 'criado'; }
        foreach (['legislacaoxurl', 'vw_legislacao_filhas', 'vw_legislacao_busca_simples', 'vw_legislacao_assoc_contagem'] as $t) if (!$existe($t)) { $rel['ok'] = false; $info['faltam'][] = $t; }
        if (empty($info['faltam'])) {
            $info['legislacaoxurl'] = 'ok (' . (int)$leg->query('SELECT COUNT(*) FROM legislacaoxurl')->fetchColumn() . ' link(s))';
            $amostra = $leg->query('SELECT * FROM vw_legislacao_assoc_contagem WHERE QTD_FILHAS > 0 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $info['teste_contagem'] = $amostra ?: 'sem lei com filhas';
        }
    }
    $rel['legislacao'] = $info;
} catch (Throwable $e) { $rel['ok'] = false; $rel['legislacao']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 2) universal: ajuda
try {
    $cat = new MenuCatalogoService($env);
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['universal']['ajuda'] = gravarAjuda382($u, $textos, $simular);
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
        if (MigracaoCrud::primeiro($pdo, 'ia_prompts', ['codigo' => LegislacaoAssociacoes::PROMPT])) { $rel['bancos'][$banco] = 'prompt já existia (mantido)'; continue; }
        if ($simular) { $rel['bancos'][$banco] = 'prompt será cadastrado'; continue; }
        MigracaoCrud::inserir($pdo, 'ia_prompts', ['codigo' => LegislacaoAssociacoes::PROMPT, 'nome_pt' => $prompt['nome']['pt'], 'nome_en' => $prompt['nome']['en'], 'nome_es' => $prompt['nome']['es'],
            'prompt_pt' => $prompt['texto']['pt'], 'prompt_en' => $prompt['texto']['en'], 'prompt_es' => $prompt['texto']['es'], 'versao' => 1, 'ativo' => 1]);
        $rel['bancos'][$banco] = 'prompt cadastrado';
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml382 --> e <!-- /ml382 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda382(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml382 -->.*?<!-- \/ml382 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml382 -->\n" . $d['texto'][$lg] . "\n<!-- /ml382 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada (trecho ml382 no fim do texto atual)'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
