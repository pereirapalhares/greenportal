<?php
declare(strict_types=1);

/*
 * Migração 362 — Avaliação da eficácia nos planos de ação (GER-5 do 01_Mudancas_Gerais).
 * Decisões do usuário (16/11/2026): marcação "Avaliar eficácia?" em cada ação (colunas no fim da grade); ação marcada a 100%
 * vira pendência com alerta; o avaliador (escolhido na ação; vazio = Responsável da condicionante / do fornecedor; sem ninguém,
 * o responsável da ação) registra Eficaz ou Não eficaz, data e observação; Não eficaz reabre a ação (volta para 75%).
 * A NC já tinha a sua ("Passar pela análise da eficácia?"); aqui entram li_planodeacao (Condicionantes) e fo_planodeacao (Fornecedores).
 *
 * Universal: Ajuda das duas telas de plano de ação (trecho <!-- ml362 --> no fim do texto atual).
 * Cada empresa:
 *   1. database/362_eficacia_planos.sql: plano_eficacia_historico, plano_eficacia_avisos e sp_eficacia_avisar;
 *   2. colunas novas nas tabelas dos planos (AVALIAR_EFICACIA, CODAVALIADOR, EFICACIA, EFICACIA_DATA, EFICACIA_OBS, EFICACIA_CODUSUARIO);
 *   3. gatilhos trg_eficacia_<tabela>_bi/_bu (regras; parados durante o sincronismo com o sistema antigo);
 *   4. vw_eficacia_planos / vw_eficacia_pendentes e as views das grades com as colunas novas (a anterior vira <view>_base362).
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-362-eficacia-planos.php [--simular] [--banco=gcrown_dbp]
 *      ... --remover   tira gatilhos, views e procedure e volta as grades; as colunas e o histórico ficam (sem efeito)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/EficaciaPlanos.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$remover = in_array('--remover', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '362', 'modo' => ($remover ? 'REMOVER' : 'APLICAR') . ($simular ? ' (SIMULACAO)' : ''), 'universal' => [], 'bancos' => []];
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

// ---------------------------------------------------------------- 1) universal: Ajuda
if (!$remover) {
    try {
        $u = (new MenuCatalogoService($env))->universal();
        $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rel['universal']['ajuda'] = gravarAjuda362($u, require dirname(__DIR__) . '/database/362_ajuda_eficacia_planos.php', $simular);
    } catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['ajuda'] = 'ERRO: ' . $e->getMessage(); }
}

// ---------------------------------------------------------------- 2) empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa encontrada' . ($soBanco ? " ($soBanco)" : '')];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/362_eficacia_planos.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        EficaciaPlanos::limparCache();
        $planos = EficaciaPlanos::planos($pdo);
        $ok = array_keys(array_filter($planos, static fn($s) => $s === 'ok'));
        $info['planos'] = $planos;
        if (!$ok) { $info['ignorado'] = 'sem planos de ação de condicionantes nem de fornecedores'; $rel['bancos'][$banco] = $info; continue; }

        if ($remover) {
            if ($simular) { $info['modo'] = 'simulação: nada removido'; $rel['bancos'][$banco] = $info; continue; }
            foreach ($ok as $m) {
                foreach (EficaciaPlanos::gatilhosRemover($m) as $c) $pdo->exec($c);
                $info['grades'][$m] = EficaciaPlanos::gradeRestaurar($pdo, $m);
            }
            foreach (['vw_eficacia_pendentes', 'vw_eficacia_planos'] as $v) $pdo->exec("DROP VIEW IF EXISTS `$v`");
            $pdo->exec('DROP PROCEDURE IF EXISTS `sp_eficacia_avisar`');
            $info['estrutura'] = 'gatilhos, views e procedure removidos; colunas e histórico mantidos (sem efeito)';
            $rel['bancos'][$banco] = $info;
            continue;
        }

        foreach ($ok as $m) {
            $p = EficaciaPlanos::PLANOS[$m];
            $t = $p['tabela'];
            $q = $pdo->query("SELECT COUNT(*) AS n, SUM(CAST(COALESCE(`STATUS`, 0) AS SIGNED) >= 100) AS concl FROM `$t`")->fetch(PDO::FETCH_ASSOC);
            $info['acoes'][$m] = sprintf('%d ação(ões), %d a 100%%', (int)$q['n'], (int)$q['concl']);
            $s = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME NOT LIKE ?');
            $s->execute([$t, 'trg_eficacia_%']);
            $outros = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
            if ($outros) $info['outros_gatilhos'][$m] = implode(', ', $outros) . ' (mantidos)';
            $alt = EficaciaPlanos::alterTabela($pdo, $m);
            $info['colunas'][$m] = $alt === null ? 'já existem' : ($simular ? 'serão criadas' : 'criadas');
            if ($simular) {
                $info['grades'][$m] = EficaciaPlanos::existe($pdo, $p['grid'] . '_base362') ? 'será refeita' : (EficaciaPlanos::existe($pdo, $p['grid']) ? 'receberá as colunas (anterior em ' . $p['grid'] . '_base362)' : 'sem a view ' . $p['grid']);
                continue;
            }
            if ($alt !== null) $pdo->exec($alt);
            EficaciaPlanos::limparCache();
        }
        if ($simular) {
            $info['sincronismo'] = 'os gatilhos ficam parados durante o sincronismo com o sistema antigo (@gp_sinc_legado = 1)';
            $info['modo'] = 'simulação: nada gravado';
            $rel['bancos'][$banco] = $info;
            continue;
        }
        // parte fixa (o histórico precisa existir antes dos gatilhos)
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            if (str_contains($cmd, 'sp_eficacia_avisar') && str_starts_with($cmd, 'CREATE PROCEDURE')) continue; // depois das views
            $pdo->exec($cmd); $n++;
        }
        EficaciaPlanos::limparCache();
        foreach ($ok as $m) {
            foreach (EficaciaPlanos::gatilhos($pdo, $m) as $c) $pdo->exec($c);
            // avaliador 0 (gravado fora do portal) vira vazio: a grade barra a referência 0 ao salvar a tela
            $zerados = $pdo->exec('UPDATE `' . EficaciaPlanos::PLANOS[$m]['tabela'] . '` SET `CODAVALIADOR` = NULL WHERE `CODAVALIADOR` = 0');
            $info['gatilhos'][$m] = 'ok' . ($zerados ? " ($zerados avaliador(es) 0 limpo(s))" : '');
        }
        foreach (EficaciaPlanos::views($pdo, $ok) as $nome => $ddl) { $pdo->exec($ddl); $info['views'][$nome] = 'ok'; }
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if (str_starts_with($cmd, 'CREATE PROCEDURE')) { $pdo->exec($cmd); $n++; }
        }
        $info['fixa'] = $n . ' bloco(s): histórico, avisos e sp_eficacia_avisar';
        foreach ($ok as $m) {
            try {
                [$cmds, $sit] = EficaciaPlanos::viewGrade($pdo, $m, $logDir, $banco);
                if ($cmds !== null) EficaciaPlanos::aplicarGrade($pdo, $cmds);
                $info['grades'][$m] = $sit;
            } catch (Throwable $e) { $rel['ok'] = false; $info['grades'][$m] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 220); }
        }
        $t0 = microtime(true);
        $info['conferencia'] = sprintf('%d ação(ões) marcada(s), %d a avaliar (%.2fs)',
            (int)$pdo->query('SELECT COUNT(*) FROM `vw_eficacia_planos`')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM `vw_eficacia_pendentes`')->fetchColumn(), microtime(true) - $t0);
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

/** Ajuda: trecho entre <!-- ml362 --> e <!-- /ml362 --> no fim do texto atual da transação (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda362(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml362 -->.*?<!-- \/ml362 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml362 -->\n" . $d['texto'][$lg] . "\n<!-- /ml362 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_362_eficacia_planos' . ($remover ? '_remover' : '') . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
