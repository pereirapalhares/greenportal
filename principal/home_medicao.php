<?php
declare(strict_types=1);

/*
 * Home de Medição e Monitoramento (24/11/2026, Homes por módulo — item 66 da Revisão G), no modelo de classes/HomeModulo.php.
 *  - Situação de cada plano de medição pelo cronograma (a mesma regra do cálculo da situação legal, migração 284):
 *    mês previsto anterior ao atual sem medição = Atrasada; mês atual previsto e ainda não medido = Prevista neste mês;
 *    sem nenhum mês marcado = Sem cronograma; demais = Em dia. Data de reação passada e sem responsável aparecem em cartões próprios.
 *  - Sub-abas: Gráficos (situação, tipo de medição, unidade, responsável, previstas por mês), Lista, Calendário (medições previstas
 *    no mês, datas de reação e medições feitas) e Requisitos ligados.
 * Dados (só leitura, sp_crud_select): mm_monitoramento, mm_medicao, mm_equipamento, vw_areas_grid, vw_unidades_grid,
 * vw_minhas_monitoramentos_grid (responsáveis), mm_monitoramentoxlegislacao, vw_sl_ev_medicao, vw_ml_risco_itens e
 * vw_modulolegal_requisitos_situacao_grid.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit('<div style="font:13px sans-serif;padding:16px;color:#5f7268">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</div>'); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomeModulo.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
$idioma = IdiomaService::idioma();
$T = static fn(string $c, string $pt, string $en, string $es): string => HomeModulo::t('mm.' . $c, $pt, $en, $es);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);

$pHome = PermissaoTransacao::obter('HOME_MEDICAO'); $pTela = PermissaoTransacao::obter('MM_PLANO_MEDICAO_MONITORAMENTO');
if (empty($pHome['pode_visualizar']) && empty($pTela['pode_visualizar']))
    $sair(403, $T('negado', 'Você não tem acesso à Medição e monitoramento.', 'You do not have access to Measurement and monitoring.', 'No tiene acceso a Medición y monitoreo.'));
$total = !empty($pTela['pode_visualizar']); // a tela do plano mostra todos a quem tem acesso a ela

// ---------------------------------------------------------------- dados
$planos = HomeModulo::ler($pdo, 'mm_monitoramento');
if ($planos === null) $sair(503, $T('sem_tabela', 'O plano de medição e monitoramento não existe nesta empresa.', 'The measurement plan does not exist for this company.', 'El plan de medición no existe en esta empresa.'));
$planos = array_map(static fn($p) => array_change_key_case($p, CASE_UPPER), $planos);
$nomeU = []; foreach (HomeModulo::ler($pdo, 'vw_unidades_grid') ?? [] as $u) $nomeU[(int)$u['CODUNIDADE']] = trim((string)$u['DSCUNIDADE']);
$nomeTipo = []; foreach (HomeModulo::ler($pdo, 'mm_medicao') ?? [] as $m) { $m = array_change_key_case($m, CASE_UPPER); $nomeTipo[(int)$m['CODMEDICAO']] = trim((string)$m['DSCMEDICAO']); }
$nomeEq = []; foreach (HomeModulo::ler($pdo, 'mm_equipamento') ?? [] as $m) { $m = array_change_key_case($m, CASE_UPPER); $nomeEq[(int)$m['CODEQUIPAMENTO']] = trim((string)$m['DSCEQUIPAMENTO']); }
$nomeArea = []; foreach (HomeModulo::ler($pdo, 'vw_areas_grid') ?? [] as $m) $nomeArea[(int)$m['CODAREA']] = trim((string)$m['DSCAREA']);
$nomesP = HomeModulo::nomesUsuarios($pdo);
$resp = [];
foreach ($planos as $p) if ((int)($p['CODUSUARIO'] ?? 0) > 0) $resp[(int)$p['CODMONITORAMENTO']][(int)$p['CODUSUARIO']] = true;
foreach (HomeModulo::ler($pdo, 'vw_minhas_monitoramentos_grid', [], 200000) ?? [] as $m) if ((int)($m['CODUSUARIO'] ?? 0) > 0) $resp[(int)$m['CODMONITORAMENTO']][(int)$m['CODUSUARIO']] = true;
$vinc = [];
foreach (HomeModulo::ler($pdo, 'mm_monitoramentoxlegislacao', [], 200000) ?? [] as $v) { $v = array_change_key_case($v, CASE_UPPER); $vinc[(int)$v['CODMONITORAMENTO']][(int)$v['CODLEGISLACAO']] = true; }
$sl = [];
foreach (HomeModulo::ler($pdo, 'vw_sl_ev_medicao', [], 200000) ?? [] as $s) $sl[(int)$s['ITEM_ID']][(int)$s['CODLEGISLACAO'] . '|' . (int)$s['CODUNIDADE']] = [(string)$s['STATUS'], (string)$s['MOTIVO']];
$risco = [];
foreach (HomeModulo::ler($pdo, 'vw_ml_risco_itens', ['AREA' => ['value' => 'MEDICAO', 'exact' => true]]) ?? [] as $r) if ((string)$r['AREA'] === 'MEDICAO') $risco[(int)$r['ITEM_ID']] = (string)$r['CLASSE'];
$reqs = [];
if ($vinc) foreach (HomeModulo::consultar($pdo, 'vw_modulolegal_requisitos_situacao_grid', ['CODLEGISLACAO', 'CODUNIDADE', 'UNIDADE', 'CODIGO', 'DSCTIPO', 'NR', 'ANO', 'LEI_REVOGADA', 'SITUACAO_CONSOLIDADA']) ?? [] as $l) $reqs[(int)$l['CODLEGISLACAO'] . '|' . (int)$l['CODUNIDADE']] = $l;

$aviso = '';
if (!$total) {
    $planos = array_values(array_filter($planos, static fn($p) => isset($resp[(int)$p['CODMONITORAMENTO']][$uid])));
    $aviso = $T('so_minhas', 'Mostrando só as medições de que você é responsável.', 'Showing only the measurements you are responsible for.', 'Mostrando solo las mediciones de las que usted es responsable.');
}

// ---------------------------------------------------------------- filtros
$hoje = date('Y-m-d'); $anoAtual = (int)date('Y'); $mesAtual = (int)date('n');
$f = ['u' => (string)($_GET['u'] ?? ''), 'r' => (string)($_GET['r'] ?? ''), 't' => (string)($_GET['t'] ?? '')];
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$url = static function (array $mudar = []) use ($f, $mes): string {
    $q = array_filter($mudar + $f + ['mes' => $mes !== date('Y-m') ? $mes : ''], static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
$unidades = []; $responsaveis = []; $tipos = [];
foreach ($planos as $p) {
    $id = (int)$p['CODMONITORAMENTO'];
    $unidades[(string)(int)$p['CODUNIDADE']] = $nomeU[(int)$p['CODUNIDADE']] ?? '#' . (int)$p['CODUNIDADE'];
    $tipos[(string)(int)$p['CODMEDICAO']] = $nomeTipo[(int)$p['CODMEDICAO']] ?? '#' . (int)$p['CODMEDICAO'];
    foreach (array_keys($resp[$id] ?? []) as $u) $responsaveis[(string)$u] = $nomesP[$u] ?? '#' . $u;
}
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($responsaveis, SORT_NATURAL | SORT_FLAG_CASE); asort($tipos, SORT_NATURAL | SORT_FLAG_CASE);
$responsaveis = ['0' => $T('sem_resp', '(sem responsável)', '(no owner)', '(sin responsable)')] + $responsaveis;
$lista = array_values(array_filter($planos, static function ($p) use ($f, $resp) {
    if ($f['u'] !== '' && (string)(int)$p['CODUNIDADE'] !== $f['u']) return false;
    if ($f['t'] !== '' && (string)(int)$p['CODMEDICAO'] !== $f['t']) return false;
    if ($f['r'] !== '') { $rs = array_map('strval', array_keys($resp[(int)$p['CODMONITORAMENTO']] ?? [])); if ($f['r'] === '0' ? $rs !== [] : !in_array($f['r'], $rs, true)) return false; }
    return true;
}));

// ---------------------------------------------------------------- situação pelo cronograma
$MESES = ['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$marcado = static fn($v): bool => !in_array(mb_strtoupper(trim((string)$v)), ['', 'N', '0', 'NAO', 'NÃO', 'FALSE'], true);
$SIT = ['ATRASADA' => [$T('s.atrasada', 'Atrasada', 'Overdue', 'Atrasada'), 'red', 'bi-calendar-x'], 'PREVISTA' => [$T('s.prevista', 'Prevista neste mês', 'Due this month', 'Prevista este mes'), 'orange', 'bi-alarm'],
    'EM_DIA' => [$T('s.em_dia', 'Em dia', 'On schedule', 'Al día'), 'green', 'bi-check-circle'], 'SEM_CRONO' => [$T('s.sem_crono', 'Sem cronograma', 'No schedule', 'Sin cronograma'), 'gray', 'bi-calendar']];
$RISCO = ['CRITICO' => [$T('r.critico', 'Crítico', 'Critical', 'Crítico'), 'red'], 'ALTO' => [$T('r.alto', 'Alto', 'High', 'Alto'), 'orange'],
    'MODERADO' => [$T('r.moderado', 'Moderado', 'Moderate', 'Moderado'), 'yellow'], 'BAIXO' => [$T('r.baixo', 'Baixo', 'Low', 'Bajo'), 'green'], 'NA' => [$T('r.na', 'Risco não avaliado', 'Risk not assessed', 'Riesgo no evaluado'), 'gray']];
$abrir = static fn(int $id, string $nome): array => ['url' => 'medicaoemonitoramento_mm_monitoramento.php?' . http_build_query(['filtros' => json_encode(['CODMONITORAMENTO' => $id])]),
    'title' => $T('titulo', 'Plano de medição e monitoramento', 'Measurement and monitoring plan', 'Plan de medición y monitoreo') . ' - ' . mb_strimwidth($nome, 0, 60, '…')];

$cont = array_fill_keys(array_keys($SIT), 0); $contR = array_fill_keys(array_keys($RISCO), 0);
$medidasMes = 0; $reacaoVencida = 0; $semResp = 0; $ligadas = 0; $prevMes = array_fill(1, 12, 0);
$porTipo = []; $porUn = []; $porResp = []; $linhas = []; $eventos = []; $rowsCal = []; $rowsReq = [];
foreach ($lista as $p) {
    $id = (int)$p['CODMONITORAMENTO']; $un = (int)$p['CODUNIDADE']; $tp = (int)$p['CODMEDICAO'];
    $nome = trim(($nomeTipo[$tp] ?? $T('sem_tipo', '(sem tipo)', '(no type)', '(sin tipo)')) . ' · ' . ($nomeU[$un] ?? '#' . $un) . (trim((string)$p['PARAMETROS']) !== '' ? ' · ' . trim((string)$p['PARAMETROS']) : ''));
    $med = HomeModulo::data($p['DATA_MEDICAO'] ?? null); $rea = HomeModulo::data($p['DATA_REACAO'] ?? null);
    $planej = [];
    foreach ($MESES as $i => $m) if ($marcado($p['CRONOGRAMA_' . strtoupper($m)] ?? '')) { $planej[] = $i + 1; $prevMes[$i + 1]++; }
    $anteriores = array_filter($planej, static fn($m) => $m < $mesAtual);
    $ultimoPrevisto = $anteriores ? sprintf('%04d-%02d-01', $anoAtual, max($anteriores)) : null;
    if (!$planej) $s = 'SEM_CRONO';
    elseif ($ultimoPrevisto !== null && ($med === null || $med < $ultimoPrevisto)) $s = 'ATRASADA';
    elseif (in_array($mesAtual, $planej, true) && ($med === null || substr($med, 0, 7) !== date('Y-m'))) $s = 'PREVISTA';
    else $s = 'EM_DIA';
    $cont[$s]++;
    $rk = $risco[$id] ?? 'NA'; if (!isset($RISCO[$rk])) $rk = 'NA'; $contR[$rk]++;
    $rs = array_keys($resp[$id] ?? []);
    $g = [HomeModulo::grupo('S', $s), HomeModulo::grupo('K', $rk), HomeModulo::grupo('U', $un), HomeModulo::grupo('T', $tp)];
    if (in_array($rk, ['CRITICO', 'ALTO'], true)) $g[] = 'K_CA';
    if ($med !== null && substr($med, 0, 7) === date('Y-m')) { $g[] = 'MEDIDA_MES'; $medidasMes++; }
    if ($rea !== null && $rea < $hoje && ($med === null || $med < $rea)) { $g[] = 'REACAO'; $reacaoVencida++; }
    foreach ($planej as $m) $g[] = 'PM_' . $m;
    foreach ($rs ?: [0] as $u) { $g[] = HomeModulo::grupo('R', $u); $porResp[$u]['n'] = ($porResp[$u]['n'] ?? 0) + 1; $porResp[$u][$s] = ($porResp[$u][$s] ?? 0) + 1; }
    if (!$rs) $semResp++;
    $porTipo[$tp]['n'] = ($porTipo[$tp]['n'] ?? 0) + 1; $porTipo[$tp][$s] = ($porTipo[$tp][$s] ?? 0) + 1;
    $porUn[$un]['n'] = ($porUn[$un]['n'] ?? 0) + 1; $porUn[$un][$s] = ($porUn[$un][$s] ?? 0) + 1;
    $leis = array_keys($vinc[$id] ?? []);
    if ($leis) { $ligadas++; $g[] = 'LIG'; } else $g[] = 'NLIG';
    foreach ($leis as $lei) {
        $k = $lei . '|' . $un; $req = $reqs[$k] ?? null; [$st, $mt] = $sl[$id][$k] ?? ['', ''];
        if ($req) $rowsReq[] = ['cells' => ['ITEM' => ['text' => $nome, 'title' => $nome], 'CODIGO' => (string)$req['CODIGO'], 'NORMA' => trim($req['DSCTIPO'] . ' ' . $req['NR'] . '/' . $req['ANO']),
            'STATUS' => $st, 'MOTIVO' => ['text' => $mt, 'title' => $mt], 'SITREQ' => (string)$req['SITUACAO_CONSOLIDADA']],
            'open' => ['url' => 'modulolegal_requisitoslegais.php?' . http_build_query(['filtros' => json_encode(['CODLEGISLACAO' => $lei, 'CODUNIDADE' => $un])]), 'title' => $T('titulo_req', 'Requisitos legais', 'Legal requirements', 'Requisitos legales') . ' - ' . $req['CODIGO']]];
    }
    $nomesResp = implode(', ', array_map(static fn($u) => $nomesP[$u] ?? '#' . $u, $rs));
    $cron = implode(' ', array_map(static fn($m) => HomeModulo::mesCurto(sprintf('%04d-%02d', $anoAtual, $m)), $planej));
    $linhas[] = ['cells' => [
        'UNIDADE' => $nomeU[$un] ?? '#' . $un, 'TIPO' => $nomeTipo[$tp] ?? '', 'PARAMETROS' => ['text' => trim((string)$p['PARAMETROS']), 'title' => trim((string)$p['PARAMETROS'])],
        'AREA' => $nomeArea[(int)($p['CODAREA'] ?? 0)] ?? '', 'EQUIPAMENTO' => $nomeEq[(int)($p['CODEQUIPAMENTO'] ?? 0)] ?? '',
        'SITUACAO' => ['text' => $SIT[$s][0], 'html' => '<span class="hm-sit tom-' . $SIT[$s][1] . '">' . $e($SIT[$s][0]) . '</span>', 'sort' => $SIT[$s][0]],
        'CRONOGRAMA' => ['text' => $cron, 'title' => $cron], 'MEDICAO' => ['text' => HomeModulo::dataTexto($med), 'sort' => $med ?? ''], 'REACAO' => DashboardGrid::prazo($rea ?? '', $s !== 'EM_DIA'),
        'RISCO' => ['text' => $RISCO[$rk][0], 'html' => '<span class="hm-sit tom-' . $RISCO[$rk][1] . '">' . $e($RISCO[$rk][0]) . '</span>', 'sort' => $RISCO[$rk][0]],
        'RESPONSAVEIS' => ['text' => $nomesResp, 'title' => $nomesResp],
    ], 'open' => $abrir($id, $nome), 'grupos' => implode(' ', $g)];
    // calendário: medições previstas (1º dia do mês), data de reação e medição feita
    [$ca, $cm] = array_map('intval', explode('-', $mes));
    if (in_array($cm, $planej, true)) $eventos[] = ['data' => sprintf('%04d-%02d-01', $ca, $cm), 'tom' => $s === 'ATRASADA' || ($ca === $anoAtual && $cm < $mesAtual) ? 'red' : 'orange', 'rotulo' => $T('ev.prevista', 'Prevista', 'Due', 'Prevista') . ': ' . $nome, 'tipo' => $T('ev.prevista_l', 'Medição prevista no cronograma', 'Scheduled measurement', 'Medición prevista'), 'item' => $nome, 'open' => $abrir($id, $nome), 'resp' => $nomesResp];
    if ($rea !== null) $eventos[] = ['data' => $rea, 'tom' => 'blue', 'rotulo' => $T('ev.reacao', 'Reação', 'Reaction', 'Reacción') . ': ' . $nome, 'tipo' => $T('ev.reacao_l', 'Data de reação', 'Reaction date', 'Fecha de reacción'), 'item' => $nome, 'open' => $abrir($id, $nome), 'resp' => $nomesResp];
    if ($med !== null) $eventos[] = ['data' => $med, 'tom' => 'green', 'rotulo' => $T('ev.medido', 'Medido', 'Measured', 'Medido') . ': ' . $nome, 'tipo' => $T('ev.medido_l', 'Medição realizada', 'Measurement taken', 'Medición realizada'), 'item' => $nome, 'open' => $abrir($id, $nome), 'resp' => $nomesResp];
}
$G = 'hmMed';
$gridLista = HomeModulo::grid($G, [
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
    'TIPO' => ['label' => $T('col.tipo', 'Tipo de medição', 'Measurement type', 'Tipo de medición'), 'filter' => 'select', 'width' => '180px'],
    'PARAMETROS' => ['label' => $T('col.parametros', 'Parâmetros', 'Parameters', 'Parámetros'), 'width' => '200px'],
    'AREA' => ['label' => $T('col.area', 'Área', 'Area', 'Área'), 'filter' => 'select', 'width' => '130px'],
    'EQUIPAMENTO' => ['label' => $T('col.equipamento', 'Equipamento', 'Equipment', 'Equipo'), 'filter' => 'select', 'width' => '150px'],
    'SITUACAO' => ['label' => $T('col.situacao', 'Situação', 'Status', 'Situación'), 'filter' => 'select', 'width' => '140px'],
    'CRONOGRAMA' => ['label' => $T('col.cronograma', 'Cronograma', 'Schedule', 'Cronograma'), 'width' => '170px'],
    'MEDICAO' => ['label' => $T('col.medicao', 'Última medição', 'Last measurement', 'Última medición'), 'width' => '110px'],
    'REACAO' => ['label' => $T('col.reacao', 'Data de reação', 'Reaction date', 'Fecha de reacción'), 'width' => '110px'],
    'RISCO' => ['label' => $T('col.risco', 'Risco', 'Risk', 'Riesgo'), 'filter' => 'select', 'width' => '120px'],
    'RESPONSAVEIS' => ['label' => $T('col.responsaveis', 'Responsáveis', 'Owners', 'Responsables'), 'width' => '200px'],
], $linhas, ['transacao' => 'home_medicao', 'ordem' => ['SITUACAO', 'asc'], 'por_pagina' => 50, 'vazio' => $T('lista.vazia', 'Nenhuma medição com estes filtros.', 'No measurement with these filters.', 'Ninguna medición con estos filtros.')]);

usort($eventos, static fn($a, $b) => strcmp($a['data'], $b['data']));
foreach ($eventos as $ev) if (substr($ev['data'], 0, 7) === $mes)
    $rowsCal[] = ['cells' => ['DATA' => DashboardGrid::prazo($ev['data'], false), 'EVENTO' => ['text' => $ev['tipo'], 'html' => '<span class="hm-sit tom-' . $ev['tom'] . '">' . $e($ev['tipo']) . '</span>', 'sort' => $ev['tipo']],
        'ITEM' => ['text' => $ev['item'], 'title' => $ev['item']], 'RESPONSAVEL' => $ev['resp']], 'open' => $ev['open'], 'grupos' => 'D_' . $ev['data']];
$ant = date('Y-m', strtotime($mes . '-01 -1 month')); $prox = date('Y-m', strtotime($mes . '-01 +1 month'));
$calendario = HomeModulo::calendario($mes, $eventos, 'hmCal', ['ant' => $url(['mes' => $ant]), 'prox' => $url(['mes' => $prox]), 'hoje' => $url(['mes' => ''])])
    . HomeModulo::legenda(['orange' => $T('lg.prevista', 'Medição prevista (cronograma)', 'Scheduled measurement', 'Medición prevista'), 'red' => $T('lg.atrasada', 'Prevista e já passada', 'Scheduled, past', 'Prevista y pasada'),
        'blue' => $T('lg.reacao', 'Data de reação', 'Reaction date', 'Fecha de reacción'), 'green' => $T('lg.medido', 'Medição realizada', 'Measurement taken', 'Medición realizada')])
    . '<div class="hm-nota">' . $e($T('cal.nota', 'O cronograma marca o mês: a medição prevista aparece no dia 1º.', 'The schedule marks the month: the scheduled measurement shows on day 1.', 'El cronograma marca el mes: la medición prevista aparece el día 1.')) . '</div>'
    . '<div class="mt-2">' . HomeModulo::grid('hmCal', ['DATA' => ['label' => $T('col.data', 'Data', 'Date', 'Fecha'), 'width' => '100px'], 'EVENTO' => ['label' => $T('col.evento', 'Prazo', 'Deadline', 'Plazo'), 'filter' => 'select', 'width' => '220px'],
        'ITEM' => ['label' => $T('col.item', 'Medição', 'Measurement', 'Medición'), 'width' => '380px'], 'RESPONSAVEL' => ['label' => $T('col.responsaveis', 'Responsáveis', 'Owners', 'Responsables'), 'width' => '200px']], $rowsCal,
        ['transacao' => 'home_medicao', 'ordem' => ['DATA', 'asc'], 'por_pagina' => 50, 'vazio' => $T('cal.vazio', 'Nada previsto neste mês.', 'Nothing scheduled this month.', 'Nada previsto este mes.')]) . '</div>';
$gridReq = HomeModulo::grid('hmReq', ['ITEM' => ['label' => $T('col.item', 'Medição', 'Measurement', 'Medición'), 'width' => '300px'], 'CODIGO' => ['label' => $T('col.codigo', 'Código', 'Code', 'Código'), 'width' => '90px'],
    'NORMA' => ['label' => $T('col.norma', 'Norma', 'Regulation', 'Norma'), 'width' => '220px'], 'STATUS' => ['label' => $T('col.sit_item', 'Situação do item', 'Item status', 'Situación del ítem'), 'filter' => 'select', 'width' => '120px'],
    'MOTIVO' => ['label' => $T('col.motivo', 'Motivo', 'Reason', 'Motivo'), 'width' => '260px'], 'SITREQ' => ['label' => $T('col.sit_req', 'Situação do requisito', 'Requirement status', 'Situación del requisito'), 'filter' => 'select', 'width' => '160px']],
    $rowsReq, ['transacao' => 'home_medicao', 'por_pagina' => 50, 'vazio' => $T('req.vazio', 'Nenhuma medição ligada a requisitos legais.', 'No measurement linked to legal requirements.', 'Ninguna medición vinculada a requisitos legales.')]);

// ---------------------------------------------------------------- gráficos
$partes = static function (array $c) use ($SIT): array { $o = []; foreach ($SIT as $k => [$rot, $tom]) if (($c[$k] ?? 0) > 0) $o[] = ['n' => $c[$k], 'tom' => $tom, 'rotulo' => $rot]; return $o; };
$barSit = []; foreach ($SIT as $k => [$rot, $tom]) $barSit[] = ['rotulo' => $rot, 'n' => $cont[$k], 'grupo' => 'S_' . $k, 'tom' => $tom];
$barRisco = []; foreach ($RISCO as $k => [$rot, $tom]) $barRisco[] = ['rotulo' => $rot, 'n' => $contR[$k], 'grupo' => 'K_' . $k, 'tom' => $tom];
uasort($porTipo, static fn($a, $b) => $b['n'] <=> $a['n']);
$barTipo = []; foreach ($porTipo as $k => $c) $barTipo[] = ['rotulo' => $nomeTipo[(int)$k] ?? $T('sem_tipo', '(sem tipo)', '(no type)', '(sin tipo)'), 'n' => $c['n'], 'grupo' => HomeModulo::grupo('T', $k), 'partes' => $partes($c)];
uasort($porUn, static fn($a, $b) => $b['n'] <=> $a['n']);
$barUn = []; foreach ($porUn as $k => $c) $barUn[] = ['rotulo' => $nomeU[(int)$k] ?? '#' . $k, 'n' => $c['n'], 'grupo' => HomeModulo::grupo('U', $k), 'partes' => $partes($c)];
uasort($porResp, static fn($a, $b) => $b['n'] <=> $a['n']);
$barResp = []; foreach ($porResp as $k => $c) $barResp[] = ['rotulo' => (int)$k === 0 ? $responsaveis['0'] : ($nomesP[(int)$k] ?? '#' . $k), 'n' => $c['n'], 'grupo' => HomeModulo::grupo('R', $k), 'partes' => $partes($c)];
$col = []; foreach (range(1, 12) as $m) $col[] = ['rotulo' => HomeModulo::mesCurto(sprintf('%04d-%02d', $anoAtual, $m)), 'n' => $prevMes[$m], 'grupo' => 'PM_' . $m, 'tom' => $m === $mesAtual ? 'orange' : ($m < $mesAtual ? 'gray' : 'teal'), 'dica' => HomeModulo::mesLongo(sprintf('%04d-%02d', $anoAtual, $m))];
$leg = HomeModulo::legenda(array_combine(array_column($SIT, 1), array_column($SIT, 0)));
$graficos = '<div class="hm-graficos">'
    . HomeModulo::grafico($T('g.situacao', 'Situação pelo cronograma', 'Status by schedule', 'Situación por cronograma'), 'bi-calendar-check', HomeModulo::barras($G, $barSit),
        $T('g.sit_nota', 'Atrasada: mês previsto anterior ao atual sem medição registrada depois dele.', 'Overdue: a scheduled month before the current one with no measurement recorded after it.', 'Atrasada: mes previsto anterior al actual sin medición registrada.'))
    . HomeModulo::grafico($T('g.meses', 'Medições previstas por mês', 'Scheduled measurements per month', 'Mediciones previstas por mes') . ' (' . $anoAtual . ')', 'bi-calendar3', HomeModulo::colunas($G, $col))
    . HomeModulo::grafico($T('g.tipo', 'Por tipo de medição', 'By measurement type', 'Por tipo de medición'), 'bi-rulers', HomeModulo::barras($G, $barTipo) . $leg)
    . HomeModulo::grafico($T('g.unidade', 'Por unidade', 'By unit', 'Por unidad'), 'bi-buildings', HomeModulo::barras($G, $barUn, 15) . $leg)
    . HomeModulo::grafico($T('g.responsavel', 'Por responsável', 'By owner', 'Por responsable'), 'bi-person', HomeModulo::barras($G, $barResp, 12) . $leg)
    . HomeModulo::grafico($T('g.risco', 'Risco', 'Risk', 'Riesgo'), 'bi-exclamation-triangle', HomeModulo::barras($G, $barRisco))
    . '</div>';

$cards = [['rotulo' => $T('c.total', 'Medições e monitoramentos', 'Measurements and monitoring', 'Mediciones y monitoreos'), 'valor' => count($lista), 'icone' => 'bi-speedometer2', 'tom' => 'blue', 'grupo' => '',
    'extra' => $ligadas . ' ' . $T('c.ligadas', 'ligada(s) a requisitos', 'linked to requirements', 'vinculada(s) a requisitos')]];
foreach ($SIT as $k => [$rot, $tom, $ic]) $cards[] = ['rotulo' => $rot, 'valor' => $cont[$k], 'icone' => $ic, 'tom' => $k === 'ATRASADA' && !$cont[$k] ? 'green' : $tom, 'grupo' => 'S_' . $k];
$cards[] = ['rotulo' => $T('c.medidas', 'Medidas neste mês', 'Measured this month', 'Medidas este mes'), 'valor' => $medidasMes, 'icone' => 'bi-clipboard-check', 'tom' => 'teal', 'grupo' => 'MEDIDA_MES'];
$cards[] = ['rotulo' => $T('c.reacao', 'Data de reação vencida', 'Reaction date passed', 'Fecha de reacción vencida'), 'valor' => $reacaoVencida, 'icone' => 'bi-hourglass-bottom', 'tom' => $reacaoVencida ? 'orange' : 'green', 'grupo' => 'REACAO'];
$cards[] = ['rotulo' => $T('c.sem_resp', 'Sem responsável', 'No owner', 'Sin responsable'), 'valor' => $semResp, 'icone' => 'bi-person-x', 'tom' => $semResp ? 'orange' : 'green', 'grupo' => 'R_0'];
$cards[] = ['rotulo' => $T('c.risco_ca', 'Risco crítico ou alto', 'Critical or high risk', 'Riesgo crítico o alto'), 'valor' => $contR['CRITICO'] + $contR['ALTO'], 'icone' => 'bi-exclamation-octagon', 'tom' => 'red', 'grupo' => 'K_CA'];
$cards[] = ['rotulo' => $T('c.sem_vinculo', 'Sem vínculo com requisito', 'Not linked to a requirement', 'Sin vínculo con requisito'), 'valor' => count($lista) - $ligadas, 'icone' => 'bi-link-45deg', 'tom' => 'gray', 'grupo' => 'NLIG'];

$filtrosHtml = HomeModulo::filtros([
    'u' => ['rotulo' => $T('f.unidade', 'Unidade', 'Unit', 'Unidad'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $unidades],
    'r' => ['rotulo' => $T('f.responsavel', 'Responsável', 'Owner', 'Responsable'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $responsaveis],
    't' => ['rotulo' => $T('f.tipo', 'Tipo de medição', 'Measurement type', 'Tipo de medición'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $tipos],
], $f, ['mes' => $mes !== date('Y-m') ? $mes : '']);
$abas = DashboardGrid::subabas('hmAbas', [
    ['id' => 'graficos', 'icone' => 'bi-bar-chart', 'rotulo' => $T('sub.graficos', 'Gráficos', 'Charts', 'Gráficos'), 'html' => $graficos],
    ['id' => 'lista', 'icone' => 'bi-table', 'rotulo' => $T('sub.lista', 'Lista', 'List', 'Lista'), 'contagem' => count($lista), 'html' => $gridLista],
    ['id' => 'calendario', 'icone' => 'bi-calendar3', 'rotulo' => $T('sub.calendario', 'Calendário', 'Calendar', 'Calendario'), 'contagem' => count($rowsCal), 'html' => $calendario],
    ['id' => 'requisitos', 'icone' => 'bi-journal-text', 'rotulo' => $T('sub.requisitos', 'Requisitos ligados', 'Linked requirements', 'Requisitos vinculados'), 'contagem' => count($rowsReq), 'html' => $gridReq],
], true);

$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$titulo = HomeModulo::MENUS['MEDICAO']['nome'][$idioma] ?? HomeModulo::MENUS['MEDICAO']['nome']['pt'];
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php HomeModulo::head(); ?>
</head>
<body class="theme-<?= $e($theme) ?>" data-transacao="home_medicao"><main class="hm">
<?= HomeModulo::cabecalho('bi-speedometer2', $titulo,
    $e($T('desc', 'Situação das medições pelo cronograma, datas de reação, risco e requisitos ligados. Os filtros do topo valem para a página toda; clique num cartão, numa barra do gráfico ou num dia do calendário para listar. Duplo clique na linha abre o plano.',
        'Measurement status by schedule, reaction dates, risk and linked requirements. The filters at the top apply to the whole page; click a card, a chart bar or a calendar day to list. Double-click a row to open the plan.',
        'Situación de las mediciones por cronograma, fechas de reacción, riesgo y requisitos vinculados. Los filtros valen para toda la página; haga clic en una tarjeta, una barra o un día para listar. Doble clic abre el plan.')), $aviso) ?>
<?= $filtrosHtml ?>
<?= HomeModulo::cartoes($G, $cards) ?>
<?= $abas ?>
</main>
<?php HomeModulo::scripts(); ?>
</body></html>
