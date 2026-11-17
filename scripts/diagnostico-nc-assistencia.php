<?php
declare(strict_types=1);

/*
 * Diagnóstico das NCs de Assistência Técnica (CODTIPONAOCONFORMIDADE = 6) — etapa 3d da reconstrução (22/10/2026). SOMENTE LEITURA.
 * O legado (nc_assistenciatecnica/nc_formulario.php e nc_s.php) tem abas Ocorrência, Plano de Ação e Avaliação da Eficácia:
 * o emitente cria a NC e monta as ações (com responsável) ainda Em criação; "Enviar plano de ação" leva ao Plano de Ação e avisa
 * os responsáveis das ações; a eficácia é do gestor (EFICAZ/ENCERRADO finaliza; NÃO EFICAZ → Recusado pela Avaliacao da Eficacia).
 * Este diagnóstico confere nos dados como o fluxo é usado de fato (quem monta as ações, avanço com as ações a 100%, recusas).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-nc-assistencia.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = 'gcrown_dbp';
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (string $sql) use ($pdo): array { try { return $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 200)]; } };
$v = static function (string $sql) use ($q) { $r = $q($sql); return isset($r['erro']) ? $r['erro'] : ($r ? reset($r[0]) : null); };
$W = 'o.CODTIPONAOCONFORMIDADE = 6';
$AB = " AND COALESCE(o.ETAPA,'') <> 'Finalizado'";

$r = ['banco' => $banco];
$r['volume'] = [
    'total' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W"),
    'abertas' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$AB"),
    'por_ano' => $q("SELECT YEAR(o.data_de_criacao) AS ano, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1 ORDER BY 1 DESC LIMIT 6"),
    'ultima_criada' => $v("SELECT MAX(o.data_de_criacao) FROM nc_indicacao_ocorrencia o WHERE $W"),
    'etapas_abertas' => $q("SELECT o.ETAPA, COUNT(*) AS n, MAX(o.data_de_criacao) AS mais_recente FROM nc_indicacao_ocorrencia o WHERE $W$AB GROUP BY 1 ORDER BY n DESC"),
    'etapas_criadas_2025_2026' => $q("SELECT o.ETAPA, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W AND o.data_de_criacao >= '2025-01-01' GROUP BY 1 ORDER BY n DESC"),
];
$r['eficacia'] = [
    'pass_pela_nanalise_eficacia' => $q("SELECT o.pass_pela_nanalise_eficacia AS valor, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W GROUP BY 1"),
    'finalizadas_por_situacao' => $q("SELECT o.SITUACAO, LEFT(COALESCE(o.VERIFICACAO_DA_EFICACIA,''), 40) AS verificacao, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W AND o.ETAPA = 'Finalizado' GROUP BY 1, 2 ORDER BY n DESC LIMIT 10"),
    'com_data_envio_eficacia' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.data_de_envia_analise_eficacia IS NOT NULL"),
];
$r['papeis'] = [
    'com_gestor' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.CODUSUARIO_GESTOR > 0"),
    'com_responsavel' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.CODUSUARIO_RESPONSAVEL > 0"),
    'emitente_igual_gestor' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.CODUSUARIO_GESTOR > 0 AND o.CODUSUARIO_GESTOR = o.CODUSUARIO_EMISSOR"),
    'responsavel_igual_gestor' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.CODUSUARIO_RESPONSAVEL > 0 AND o.CODUSUARIO_RESPONSAVEL = o.CODUSUARIO_GESTOR"),
    'perfis_gestor_abertas' => $q("SELECT COALESCE(p.codigo,'(sem perfil)') AS perfil, COUNT(*) AS n FROM nc_indicacao_ocorrencia o LEFT JOIN usuarios u ON u.codusuario = o.CODUSUARIO_GESTOR LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE $W$AB GROUP BY 1 ORDER BY n DESC"),
    'perfis_emitente_abertas' => $q("SELECT COALESCE(p.codigo,'(sem perfil)') AS perfil, COUNT(*) AS n FROM nc_indicacao_ocorrencia o LEFT JOIN usuarios u ON u.codusuario = o.CODUSUARIO_EMISSOR LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE $W$AB GROUP BY 1 ORDER BY n DESC"),
];
$r['causas_e_acoes'] = [
    'ncs_com_causa' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND EXISTS (SELECT 1 FROM nc_causas c WHERE c.CODOCORRENCIA = o.CODOCORRENCIA)"),
    'ncs_com_acao' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA)"),
    'acoes_total' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W"),
    'acoes_sem_causa' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W AND COALESCE(p.CODCAUSA,0) = 0"),
    'acoes_sem_responsavel' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W AND COALESCE(p.CODUSUARIO,0) = 0"),
    'status_acoes_abertas' => $q("SELECT p.STATUS, COUNT(*) AS n FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W$AB GROUP BY 1 ORDER BY 1"),
    'abertas_com_todas_acoes_100' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$AB AND EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) AND NOT EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA AND COALESCE(p.STATUS,0) < 100)"),
    'abertas_com_todas_acoes_100_por_etapa' => $q("SELECT o.ETAPA, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W$AB AND EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) AND NOT EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA AND COALESCE(p.STATUS,0) < 100) GROUP BY 1"),
    'abertas_sem_acao_por_etapa' => $q("SELECT o.ETAPA, COUNT(*) AS n FROM nc_indicacao_ocorrencia o WHERE $W$AB AND NOT EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) GROUP BY 1"),
    'acoes_por_nc' => $q("SELECT n_acoes, COUNT(*) AS ncs FROM (SELECT o.CODOCORRENCIA, (SELECT COUNT(*) FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) AS n_acoes FROM nc_indicacao_ocorrencia o WHERE $W$AB) x GROUP BY n_acoes ORDER BY n_acoes LIMIT 12"),
    'responsaveis_de_acao_abertas_perfil' => $q("SELECT COALESCE(pf.codigo,'(sem perfil)') AS perfil, COUNT(*) AS acoes FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA LEFT JOIN usuarios u ON u.codusuario = p.CODUSUARIO LEFT JOIN perfis pf ON pf.codperfil = u.codperfil WHERE $W$AB GROUP BY 1 ORDER BY acoes DESC"),
];
$r['acoes_extra'] = [
    'acoes_pela_ocorrencia' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W"),
    'acoes_pela_causa_sem_codocorrencia' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = c.CODOCORRENCIA WHERE $W AND p.CODOCORRENCIA IS NULL"),
    'acao_responsavel_e_emitente' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W AND p.CODUSUARIO = o.CODUSUARIO_EMISSOR"),
    'acao_responsavel_e_gestor' => (int)$v("SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = p.CODOCORRENCIA WHERE $W AND p.CODUSUARIO = o.CODUSUARIO_GESTOR"),
    'em_criacao_com_acoes' => (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W AND o.ETAPA = 'Em criação' AND EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA)"),
    'recusadas_eficacia_abertas' => $q("SELECT o.CODOCORRENCIA, o.data_de_criacao, (SELECT COUNT(*) FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) acoes, (SELECT MIN(p.STATUS) FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) menor_status FROM nc_indicacao_ocorrencia o WHERE $W AND o.ETAPA LIKE 'Recusado%'"),
    'historico_greenportal_e_legado' => $q("SELECT h.ACAO, COUNT(*) n FROM nc_workflow_historico h JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = h.CODOCORRENCIA WHERE $W GROUP BY 1 ORDER BY n DESC LIMIT 15"),
    'situacao_finalizadas' => $q("SELECT o.SITUACAO, o.pass_pela_nanalise_eficacia, COUNT(*) n FROM nc_indicacao_ocorrencia o WHERE $W AND o.ETAPA = 'Finalizado' GROUP BY 1, 2 ORDER BY n DESC LIMIT 10"),
    'outros_bancos' => [],
];
foreach (['gmodelo_dbp', 'gmodelo2_dbp', 'gatlas_dbp', 'ggdamericana_dbp', 'gganchieta_dbp'] as $b) {
    try { $p = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $b, 'GPT'); $s2 = $p->query("SELECT COUNT(*) total, SUM(COALESCE(ETAPA,'') <> 'Finalizado') abertas, MAX(data_de_criacao) ultima FROM nc_indicacao_ocorrencia WHERE CODTIPONAOCONFORMIDADE = 6"); $r['acoes_extra']['outros_bancos'][$b] = $s2->fetch(PDO::FETCH_ASSOC); }
    catch (Throwable) { $r['acoes_extra']['outros_bancos'][$b] = 'sem acesso'; }
}
$r['campos_preenchidos_abertas'] = [];
foreach (['codunidade' => 'o.codunidade > 0', 'CODAREA' => 'o.CODAREA > 0', 'CODTIPOOCORRENCIA' => 'o.CODTIPOOCORRENCIA > 0', 'CODTIPOACAO' => 'o.CODTIPOACAO > 0',
    'CODORIGEMACAO' => 'o.CODORIGEMACAO > 0', 'CODSIGLAGESTAO' => 'o.CODSIGLAGESTAO > 0', 'LISTADEDISTRIBUICAO' => "o.listadedistribuicao REGEXP '^[1-9]'", 'PROVIDENCIA' => "TRIM(COALESCE(o.PROVIDENCIA,'')) <> ''",
    'CODFORNECEDOR' => 'o.CODFORNECEDOR > 0', 'DSCCLIENTE' => "TRIM(COALESCE(o.DSCCLIENTE,'')) <> ''", 'ANALISE_DA_CAUSA' => "TRIM(COALESCE(o.ANALISE_DA_CAUSA,'')) <> ''",
    'ANALISE_DA_PROVIDENCIA' => "TRIM(COALESCE(o.ANALISE_DA_PROVIDENCIA,'')) <> ''", 'data_de_conhecimento_fornecedor' => 'o.data_de_conhecimento_fornecedor IS NOT NULL'] as $c => $cond)
    $r['campos_preenchidos_abertas'][$c] = (int)$v("SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE $W$AB AND $cond");
$r['campos_configurados_tipo_6'] = $q("SELECT CAMPO FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = 6");
$r['amostra_recentes'] = $q("SELECT o.CODOCORRENCIA, o.ETAPA, o.data_de_criacao, o.pass_pela_nanalise_eficacia AS eficacia, o.CODUSUARIO_EMISSOR = o.CODUSUARIO_GESTOR AS emitente_e_gestor,
    (SELECT COUNT(*) FROM nc_causas c WHERE c.CODOCORRENCIA = o.CODOCORRENCIA) AS causas, (SELECT COUNT(*) FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) AS acoes,
    (SELECT MIN(p.STATUS) FROM nc_planodeacao p WHERE p.CODOCORRENCIA = o.CODOCORRENCIA) AS menor_status
    FROM nc_indicacao_ocorrencia o WHERE $W ORDER BY o.CODOCORRENCIA DESC LIMIT 12");

$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_nc_assistencia.json', $json);
echo $json . PHP_EOL;
