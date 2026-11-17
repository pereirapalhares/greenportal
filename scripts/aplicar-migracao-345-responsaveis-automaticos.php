<?php
declare(strict_types=1);

/*
 * Migração 345 — Gestão de tarefas, etapa 3: responsáveis automáticos pelo perfil de atribuições (migração 268).
 *
 * Cada empresa com o perfil de atribuições (perfil_atribuicoes) e a fila de tarefas (green_tasks):
 *   1. database/345_responsaveis_automaticos.sql — controle, registro das indicações, candidatos, ADM, procedures
 *      (pendentes, atribuir, tarefa, alerta e sp_tarefa_concluir);
 *   2. vw_resp_auto_itens (itens de cada pilar e se já têm responsável), montada conforme as colunas do banco;
 *   3. a marca inicial: os itens que já existem não recebem responsável automático (só os cadastrados daqui para a frente);
 *   4. relatório: perfis vigentes e, por pilar, quantas pessoas executam (pilar sem ninguém = as tarefas ficam com quem pediu
 *      e o ADM DA EMPRESA é avisado).
 * Universal: Ajuda da Agenda e dos Perfis de atribuições (trecho entre <!-- ml345 -->).
 * Funciona pelo serviço de IA (tarefas dos potenciais aprovados) e pelo de responsabilidades (itens novos sem responsável).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-345-responsaveis-automaticos.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez (a marca inicial não muda).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/ResponsavelAutomatico.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '345', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- universal: ajuda
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GPT');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['universal']['ajuda'] = gravarAjuda345($u, require dirname(__DIR__) . '/database/345_ajuda_responsaveis.php', $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('perfil_atribuicoes', 'green_tasks', 'alertas') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa com o perfil de atribuições (migração 268), a fila de tarefas e os alertas'];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/345_responsaveis_automaticos.sql');
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!MigracaoCrud::existe($pdo, 'responsabilidade_atribuicoes') || !MigracaoCrud::existe($pdo, 'responsabilidade_modulos'))
            $info['aviso'] = 'sem a camada de responsabilidades (migração 093): só as tarefas da IA usam o perfil; os itens sem responsável não são atribuídos';
        // Pilares: quantos executam (perfil vigente)
        $executam = [];
        if (MigracaoCrud::existe($pdo, 'vw_perfil_atribuicoes_itens_vigentes')) {
            $s = $pdo->query("SELECT CONCAT(modulo, '.', pilar) AS P, COUNT(DISTINCT codusuario) AS N FROM vw_perfil_atribuicoes_itens_vigentes WHERE tipo = 'PILAR' AND papel = 'EXECUTA' GROUP BY 1");
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $executam[$r['P']] = (int)$r['N']; $s->closeCursor();
            $s = $pdo->query('SELECT COUNT(DISTINCT codusuario) FROM vw_perfil_atribuicoes_itens_vigentes'); $info['perfis_vigentes'] = (int)$s->fetchColumn() . ' usuário(s) com perfil de atribuições aprovado'; $s->closeCursor();
        }
        $pil = [];
        foreach (array_merge(array_values(array_map(static fn($a) => [$a[0], $a[1]], ResponsavelAutomatico::AREAS)), [['HOMOLOGACAO_FORNECEDORES', 'HOMOLOGACAO'], ['MODULO_LEGAL', 'REQUISITOS']]) as [$m, $p]) {
            $n = $executam[$m . '.' . $p] ?? 0;
            $pil[$m . '.' . $p] = $n > 0 ? "$n pessoa(s) executam" : 'ninguém executa: as tarefas ficam com quem pediu e o ADM é avisado';
        }
        $info['pilares'] = $pil;
        if ($simular) { $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue; }
        // 1. Parte fixa
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $pdo->exec($cmd); $n++;
        }
        $info['blocos'] = $n;
        if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
        // 2. Itens dos pilares
        [$ddl, $areas] = ResponsavelAutomatico::viewItens($pdo);
        $pdo->exec($ddl);
        $info['areas'] = $areas;
        // 3. Marca inicial (só na primeira vez de cada área): o que já existe não recebe responsável automático
        $marcas = [];
        $s = $pdo->query('SELECT AREA, MAX(ITEM_ID) AS ULTIMO, SUM(TEM_RESPONSAVEL = 0) AS SEM FROM vw_resp_auto_itens GROUP BY AREA');
        $atual = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        foreach ($atual as $r) {
            $ja = MigracaoCrud::primeiro($pdo, 'resp_auto_controle', ['AREA' => (string)$r['AREA']]);
            if (!$ja) MigracaoCrud::inserir($pdo, 'resp_auto_controle', ['AREA' => (string)$r['AREA'], 'ULTIMO_ID' => (int)$r['ULTIMO']]);
            $marcas[$r['AREA']] = ($ja ? 'marca mantida' : 'marca gravada') . ': a partir do nº ' . ((int)($ja['ULTIMO_ID'] ?? $r['ULTIMO']) + 1) . ' (hoje há ' . (int)$r['SEM'] . ' item(ns) sem responsável, que ficam como estão)';
        }
        foreach (array_keys(ResponsavelAutomatico::AREAS) as $a) if (!isset($marcas[$a]) && ($areas[$a] ?? '') !== '' && str_starts_with($areas[$a], 'ok') && !MigracaoCrud::primeiro($pdo, 'resp_auto_controle', ['AREA' => $a])) {
            MigracaoCrud::inserir($pdo, 'resp_auto_controle', ['AREA' => $a, 'ULTIMO_ID' => 0]); $marcas[$a] = 'marca gravada: área vazia, todos os itens novos';
        }
        $info['marca'] = $marcas;
        $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_resp_auto_pendentes', 'sp_resp_auto_atribuir', 'sp_resp_auto_tarefa', 'sp_resp_auto_alerta', 'sp_tarefa_concluir')");
        $procs = (int)$s->fetchColumn(); $s->closeCursor();
        $info['procedures'] = $procs === 5 ? 'gravadas' : "faltam " . (5 - $procs);
        if ($procs !== 5) $rel['ok'] = false;
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml345 --> e <!-- /ml345 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda345(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml345 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml345 -->.*?<!-- \/ml345 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml345 -->\n" . $d['texto'][$lg] . "\n<!-- /ml345 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
