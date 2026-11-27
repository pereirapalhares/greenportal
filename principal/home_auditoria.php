<?php
declare(strict_types=1);

/*
 * Home de Auditoria (migração 423 — Revisão G item 166), no modelo de classes/HomeModulo.php.
 * Cartões: auditorias em planejamento, em avaliação, em execução, encerradas, canceladas e recusadas; novas no mês; com período
 * vencido; RNCs de auditoria por etapa (criação, com o gestor, recusada pelo gestor, análise da causa, plano de ação, eficácia,
 * recusada na eficácia, finalizada). Gráficos por situação, unidade, tipo, auditor, mês e etapa das RNCs. Sub-abas: Gráficos,
 * Auditorias, RNCs de auditoria e Calendário. Filtros: unidade, auditor (responsável), tipo e período (data de início).
 * Quem tem a Gestão de Auditorias vê todas; quem só tem Minhas Auditorias vê as de que participa.
 * Dados (só leitura, sp_crud_select): vw_aud_auditorias_grid e vw_aud_rnc.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit('<div style="font:13px sans-serif;padding:16px;color:#5f7268">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</div>'); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/HomeModulo.php';
require_once dirname(__DIR__) . '/classes/Auditoria.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';

use PaginaSimples as P;
use Auditoria as A;

$pHome = PermissaoTransacao::obter('HOME_AUDITORIA'); $pGestao = PermissaoTransacao::obter('AUDITORIA_GESTAO'); $pMinhas = PermissaoTransacao::obter('MINHAS_AUDITORIAS');
if (empty($pHome['pode_visualizar']) && empty($pGestao['pode_visualizar']) && empty($pMinhas['pode_visualizar']))
    $sair(403, IdiomaService::t('home_modulo.aud.negado', ['pt' => 'Você não tem acesso à Auditoria.', 'en' => 'You do not have access to Audit.', 'es' => 'No tiene acceso a Auditoría.']));
P::abrir('HOME_AUDITORIA', false, true);
$pdo = P::$pdo; $uid = P::$uid; $idioma = P::$idioma;
$T = static fn(string $c, string $pt, string $en, string $es): string => HomeModulo::t('aud.' . $c, $pt, $en, $es);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$todas = !empty($pGestao['pode_visualizar']);
$tela = $todas ? 'auditoria_gestao.php' : 'minhas_auditorias.php';

$auds = HomeModulo::ler($pdo, 'vw_aud_auditorias_grid', [], 20000);
if ($auds === null) $sair(503, $T('sem_migracao', 'O módulo de Auditoria ainda não existe nesta empresa (migração 423).', 'The Audit module is not available for this company (migration 423).', 'El módulo de Auditoría aún no existe en esta empresa (migración 423).'));
$auds = array_map(static fn($a) => array_change_key_case($a, CASE_UPPER), $auds);
$aviso = '';
if (!$todas) { $auds = array_values(array_filter($auds, static fn($a) => A::participa($a, $uid))); $aviso = $T('so_minhas', 'Mostrando só as auditorias de que você participa.', 'Showing only the audits you take part in.', 'Mostrando solo las auditorías en que usted participa.'); }
$rncs = array_map(static fn($a) => array_change_key_case($a, CASE_UPPER), HomeModulo::ler($pdo, 'vw_aud_rnc', [], 20000) ?? []);
$visiveis = array_fill_keys(array_map(static fn($a) => (int)$a['CODAUDITORIA'], $auds), true);
$rncs = array_values(array_filter($rncs, static fn($r) => isset($visiveis[(int)$r['CODAUDITORIA']])));

$usuarios = HomeModulo::nomesUsuarios($pdo);
$unNome = [];
foreach (HomeModulo::ler($pdo, 'vw_unidades_grid', [], 2000) ?? [] as $u) { $u = array_change_key_case($u, CASE_UPPER); $unNome[(int)$u['CODUNIDADE']] = trim((string)$u['DSCUNIDADE']); }
$tipos = array_map(static fn($t) => A::rotulo($t), A::TIPOS);

// ---------------------------------------------------------------- filtros
$hoje = date('Y-m-d');
$f = ['u' => (string)($_GET['u'] ?? ''), 'r' => (string)($_GET['r'] ?? ''), 't' => (string)($_GET['t'] ?? ''), 'p' => (string)($_GET['p'] ?? ''), 'de' => (string)($_GET['de'] ?? ''), 'ate' => (string)($_GET['ate'] ?? '')];
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$url = static function (array $mudar = []) use ($f, $mes): string { return '?' . http_build_query(array_filter($mudar + $f + ['mes' => $mes !== date('Y-m') ? $mes : ''], static fn($v) => $v !== '' && $v !== null)); };
$auditorDe = static fn(array $a): int => (int)$a['CODUSUARIO_AUDITOR'];
$unidades = []; $auditores = [];
foreach ($auds as $a) {
    if ((int)$a['CODUNIDADE']) $unidades[(string)(int)$a['CODUNIDADE']] = $unNome[(int)$a['CODUNIDADE']] ?? '#' . $a['CODUNIDADE'];
    if ($auditorDe($a)) $auditores[(string)$auditorDe($a)] = $usuarios[$auditorDe($a)] ?? '#' . $auditorDe($a);
}
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($auditores, SORT_NATURAL | SORT_FLAG_CASE);
$per = ['' => $T('p.todos', 'Todos os períodos', 'All periods', 'Todos los períodos'), 'ano' => $T('p.ano', 'Este ano', 'This year', 'Este año'),
    '12m' => $T('p.12m', 'Próximos 12 meses', 'Next 12 months', 'Próximos 12 meses'), 'vencidos' => $T('p.passadas', 'Já iniciadas', 'Already started', 'Ya iniciadas'), 'datas' => $T('p.datas', 'Entre as datas…', 'Between dates…', 'Entre las fechas…')];
$lista = array_values(array_filter($auds, static fn($a) => ($f['u'] === '' || (string)(int)$a['CODUNIDADE'] === $f['u']) && ($f['r'] === '' || (string)$auditorDe($a) === $f['r'])
    && ($f['t'] === '' || (string)$a['TIPO'] === $f['t']) && HomeModulo::noPeriodo(HomeModulo::data($a['DATADEINICIO'] ?? null), $f['p'], $f['de'] ?: null, $f['ate'] ?: null)));
$ids = array_fill_keys(array_map(static fn($a) => (int)$a['CODAUDITORIA'], $lista), true);
$rncsL = array_values(array_filter($rncs, static fn($r) => isset($ids[(int)$r['CODAUDITORIA']])));

// ---------------------------------------------------------------- números e lista
$TOM = ['P' => 'gray', 'A' => 'orange', 'R' => 'red', 'X' => 'blue', 'E' => 'green', 'C' => 'teal'];
$cont = array_fill_keys(array_keys(A::STATUS), 0); $novas = 0; $vencidas = 0;
$porUn = []; $porTipo = []; $porAud = []; $porMes = []; $linhas = []; $eventos = [];
$abrir = static fn(array $a): array => ['url' => $tela . '?id=' . (int)$a['CODAUDITORIA'], 'title' => $T('titulo', 'Auditoria', 'Audit', 'Auditoría') . ' #' . (int)$a['CODAUDITORIA']];
foreach ($lista as $a) {
    $st = (string)$a['STATUS']; $cont[$st] = ($cont[$st] ?? 0) + 1; $id = (int)$a['CODAUDITORIA'];
    $ini = HomeModulo::data($a['DATADEINICIO'] ?? null); $fim = HomeModulo::data($a['DATADEFIM'] ?? null); $cri = HomeModulo::data($a['DATADECRIACAO'] ?? null);
    $g = ['S_' . $st, HomeModulo::grupo('U', (int)$a['CODUNIDADE']), HomeModulo::grupo('T', $a['TIPO']), HomeModulo::grupo('A', $auditorDe($a))];
    if ($cri && substr($cri, 0, 7) === date('Y-m')) { $g[] = 'NOVA'; $novas++; }
    if (in_array($st, ['P', 'A', 'X'], true) && $fim && $fim < $hoje) { $g[] = 'VENCIDA'; $vencidas++; }
    if ((int)$a['QTD_RNC_ABERTAS']) $g[] = 'RNCABERTA';
    foreach ([['porUn', (int)$a['CODUNIDADE']], ['porTipo', (string)$a['TIPO']], ['porAud', $auditorDe($a)]] as [$v, $k]) { ${$v}[$k]['n'] = (${$v}[$k]['n'] ?? 0) + 1; ${$v}[$k][$st] = (${$v}[$k][$st] ?? 0) + 1; }
    $m = substr((string)($cri ?? $ini ?? ''), 0, 7); if ($m) $porMes[$m] = ($porMes[$m] ?? 0) + 1;
    $q = (int)$a['QTD_PERGUNTAS'];
    $linhas[] = ['cells' => [
        'NUMERO' => ['text' => (string)$id, 'sort' => sprintf('%09d', $id)],
        'AUDITORIA' => ['text' => mb_strimwidth((string)$a['DSCAUDITORIA'], 0, 120, '…'), 'title' => (string)$a['DSCAUDITORIA']],
        'TIPO' => $tipos[$a['TIPO']] ?? (string)$a['TIPO'], 'NORMA' => (string)($a['NORMA'] ?? ''), 'UNIDADE' => $unNome[(int)$a['CODUNIDADE']] ?? '',
        'AUDITOR' => $usuarios[$auditorDe($a)] ?? '', 'SITUACAO' => ['text' => A::situacao($st), 'html' => '<span class="hm-sit tom-' . $TOM[$st] . '">' . $e(A::situacao($st)) . '</span>', 'sort' => A::situacao($st)],
        'INICIO' => ['text' => HomeModulo::dataTexto($ini), 'sort' => $ini ?? ''], 'FIM' => DashboardGrid::prazo($fim ?? '', in_array($st, ['P', 'A', 'X'], true)),
        'CHECKLIST' => ['text' => $q ? (int)$a['QTD_RESPONDIDAS'] . '/' . $q : '–', 'sort' => sprintf('%05d', $q ? (int)round((int)$a['QTD_RESPONDIDAS'] * 100 / $q) : 0)],
        'NC' => ['text' => (string)(int)$a['QTD_NC'], 'sort' => sprintf('%05d', (int)$a['QTD_NC'])], 'RNC' => ['text' => (int)$a['QTD_RNC_ABERTAS'] . '/' . (int)$a['QTD_RNC'], 'sort' => sprintf('%05d', (int)$a['QTD_RNC_ABERTAS'])],
    ], 'open' => $abrir($a), 'grupos' => implode(' ', $g)];
    if ($ini) $eventos[] = ['data' => $ini, 'tom' => in_array($st, ['E', 'C'], true) ? 'gray' : 'blue', 'rotulo' => '#' . $id, 'tipo' => $T('ev.inicio', 'Início da auditoria', 'Audit start', 'Inicio de la auditoría'),
        'item' => '#' . $id . ' — ' . $a['DSCAUDITORIA'], 'resp' => $usuarios[$auditorDe($a)] ?? '', 'open' => $abrir($a)];
    if ($fim && $fim !== $ini) $eventos[] = ['data' => $fim, 'tom' => in_array($st, ['P', 'A', 'X'], true) && $fim < $hoje ? 'red' : (in_array($st, ['E', 'C'], true) ? 'gray' : 'orange'), 'rotulo' => '#' . $id,
        'tipo' => $T('ev.fim', 'Fim previsto da auditoria', 'Planned audit end', 'Fin previsto de la auditoría'), 'item' => '#' . $id . ' — ' . $a['DSCAUDITORIA'], 'resp' => $usuarios[$auditorDe($a)] ?? '', 'open' => $abrir($a)];
}
$G = 'haAud';
$gridAud = HomeModulo::grid($G, [
    'NUMERO' => ['label' => 'Nº', 'width' => '60px'], 'AUDITORIA' => ['label' => $T('col.auditoria', 'Auditoria', 'Audit', 'Auditoría'), 'width' => '280px'],
    'TIPO' => ['label' => $T('col.tipo', 'Tipo', 'Type', 'Tipo'), 'filter' => 'select', 'width' => '130px'], 'NORMA' => ['label' => $T('col.norma', 'Norma', 'Standard', 'Norma'), 'filter' => 'select', 'width' => '140px'],
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'], 'AUDITOR' => ['label' => $T('col.auditor', 'Auditor', 'Auditor', 'Auditor'), 'filter' => 'select', 'width' => '160px'],
    'SITUACAO' => ['label' => $T('col.situacao', 'Situação', 'Status', 'Situación'), 'filter' => 'select', 'width' => '130px'], 'INICIO' => ['label' => $T('col.inicio', 'Início', 'Start', 'Inicio'), 'width' => '95px'],
    'FIM' => ['label' => $T('col.fim', 'Fim', 'End', 'Fin'), 'width' => '110px'], 'CHECKLIST' => ['label' => 'Checklist', 'width' => '80px'], 'NC' => ['label' => 'NC', 'width' => '55px'], 'RNC' => ['label' => $T('col.rnc', 'RNC abertas/total', 'Open/total NCRs', 'RNC abiertas/total'), 'width' => '110px'],
], $linhas, ['transacao' => 'home_auditoria', 'ordem' => ['NUMERO', 'desc'], 'por_pagina' => 50, 'vazio' => $T('lista.vazia', 'Nenhuma auditoria com estes filtros.', 'No audits with these filters.', 'Ninguna auditoría con estos filtros.')]);

// RNCs de auditoria por etapa (item 166)
$ETAPAS = ['Em criação' => [$T('e.criacao', 'Em criação', 'Being created', 'En creación'), 'gray'], 'Com o Gestor' => [$T('e.gestor', 'Com o gestor', 'With the manager', 'Con el gestor'), 'orange'],
    'Recusado pelo Gestor' => [$T('e.recg', 'Recusada pelo gestor', 'Rejected by the manager', 'Rechazada por el gestor'), 'red'], 'Em Analise da Causa Raiz' => [$T('e.causa', 'Análise da causa raiz', 'Root cause analysis', 'Análisis de causa raíz'), 'blue'],
    'Plano de Ação' => [$T('e.plano', 'Plano de ação', 'Action plan', 'Plan de acción'), 'teal'], 'Avaliação da Eficacia' => [$T('e.eficacia', 'Avaliação da eficácia', 'Effectiveness review', 'Evaluación de la eficacia'), 'purple'],
    'Recusado pela Avaliacao da Eficacia' => [$T('e.rece', 'Recusada na eficácia', 'Rejected in effectiveness', 'Rechazada en la eficacia'), 'red'], 'Finalizado' => [$T('e.fim', 'Finalizada', 'Finished', 'Finalizada'), 'green']];
$etapaDe = static function (string $e) use ($ETAPAS): string { foreach (array_keys($ETAPAS) as $k) if (strcasecmp($k, $e) === 0) return $k; return str_starts_with($e, 'Plano de Ac') ? 'Plano de Ação' : $e; };
$contE = array_fill_keys(array_keys($ETAPAS), 0); $rowsR = [];
foreach ($rncsL as $r) {
    $et = $etapaDe((string)$r['ETAPA']); $contE[$et] = ($contE[$et] ?? 0) + 1;
    $rowsR[] = ['cells' => ['NUMERO' => (string)($r['NRANO'] ?: '#' . $r['CODOCORRENCIA']), 'AUDITORIA' => '#' . (int)$r['CODAUDITORIA'], 'SUMARIO' => ['text' => mb_strimwidth((string)$r['IDENTIFICACAO'], 0, 140, '…'), 'title' => (string)$r['IDENTIFICACAO']],
        'ETAPA' => ['text' => $ETAPAS[$et][0] ?? $et, 'html' => '<span class="hm-sit tom-' . ($ETAPAS[$et][1] ?? 'gray') . '">' . $e($ETAPAS[$et][0] ?? $et) . '</span>', 'sort' => $ETAPAS[$et][0] ?? $et],
        'ABERTA' => ['text' => HomeModulo::dataTexto(HomeModulo::data($r['DATA_CRIACAO'] ?? null)), 'sort' => (string)$r['DATA_CRIACAO']]],
        'open' => ['url' => 'naoconformidades_ocorrencia.php?codigo=' . (int)$r['CODOCORRENCIA'], 'title' => 'RNC ' . ($r['NRANO'] ?: $r['CODOCORRENCIA'])], 'grupos' => 'E_' . md5($et)];
}
$gridRnc = HomeModulo::grid('haRnc', ['NUMERO' => ['label' => 'RNC', 'width' => '95px'], 'AUDITORIA' => ['label' => $T('col.auditoria', 'Auditoria', 'Audit', 'Auditoría'), 'filter' => 'select', 'width' => '90px'],
    'SUMARIO' => ['label' => $T('col.sumario', 'Sumário', 'Summary', 'Resumen'), 'width' => '360px'], 'ETAPA' => ['label' => $T('col.etapa', 'Etapa', 'Stage', 'Etapa'), 'filter' => 'select', 'width' => '170px'],
    'ABERTA' => ['label' => $T('col.aberta', 'Aberta em', 'Opened on', 'Abierta el'), 'width' => '100px']], $rowsR,
    ['transacao' => 'home_auditoria', 'ordem' => ['ABERTA', 'desc'], 'por_pagina' => 50, 'vazio' => $T('rnc.vazio', 'Nenhuma RNC de auditoria.', 'No audit NCRs.', 'Ninguna RNC de auditoría.')]);

usort($eventos, static fn($a, $b) => strcmp($a['data'], $b['data']));
$rowsCal = [];
foreach ($eventos as $ev) if (substr($ev['data'], 0, 7) === $mes)
    $rowsCal[] = ['cells' => ['DATA' => DashboardGrid::prazo($ev['data'], false), 'EVENTO' => ['text' => $ev['tipo'], 'html' => '<span class="hm-sit tom-' . $ev['tom'] . '">' . $e($ev['tipo']) . '</span>', 'sort' => $ev['tipo']],
        'ITEM' => ['text' => mb_strimwidth($ev['item'], 0, 200, '…'), 'title' => $ev['item']], 'RESPONSAVEL' => $ev['resp']], 'open' => $ev['open'], 'grupos' => 'D_' . $ev['data']];
$ant = date('Y-m', strtotime($mes . '-01 -1 month')); $prox = date('Y-m', strtotime($mes . '-01 +1 month'));
$calendario = HomeModulo::calendario($mes, $eventos, 'haCal', ['ant' => $url(['mes' => $ant]), 'prox' => $url(['mes' => $prox]), 'hoje' => $url(['mes' => ''])])
    . HomeModulo::legenda(['blue' => $T('lg.inicio', 'Início', 'Start', 'Inicio'), 'orange' => $T('lg.fim', 'Fim previsto', 'Planned end', 'Fin previsto'), 'red' => $T('lg.vencido', 'Fim vencido (aberta)', 'End passed (open)', 'Fin vencido (abierta)'), 'gray' => $T('lg.fechada', 'Encerrada/cancelada', 'Closed/cancelled', 'Cerrada/cancelada')])
    . '<div class="mt-2">' . HomeModulo::grid('haCal', ['DATA' => ['label' => $T('col.data', 'Data', 'Date', 'Fecha'), 'width' => '100px'], 'EVENTO' => ['label' => $T('col.evento', 'Evento', 'Event', 'Evento'), 'filter' => 'select', 'width' => '200px'],
        'ITEM' => ['label' => $T('col.auditoria', 'Auditoria', 'Audit', 'Auditoría'), 'width' => '380px'], 'RESPONSAVEL' => ['label' => $T('col.auditor', 'Auditor', 'Auditor', 'Auditor'), 'filter' => 'select', 'width' => '170px']], $rowsCal,
        ['transacao' => 'home_auditoria', 'ordem' => ['DATA', 'asc'], 'por_pagina' => 50, 'vazio' => $T('cal.vazio', 'Nenhuma auditoria neste mês.', 'No audits this month.', 'Ninguna auditoría en este mes.')]) . '</div>';

// ---------------------------------------------------------------- gráficos
$partes = static function (array $c) use ($TOM): array { $o = []; foreach (A::STATUS as $k => $s) if (($c[$k] ?? 0) > 0) $o[] = ['n' => $c[$k], 'tom' => $TOM[$k], 'rotulo' => A::situacao($k)]; return $o; };
$barSt = []; foreach (A::STATUS as $k => $s) $barSt[] = ['rotulo' => A::situacao($k), 'n' => $cont[$k], 'grupo' => 'S_' . $k, 'tom' => $TOM[$k]];
$barE = []; foreach ($ETAPAS as $k => [$rot, $tom]) $barE[] = ['rotulo' => $rot, 'n' => $contE[$k] ?? 0, 'grupo' => 'E_' . md5($k), 'tom' => $tom];
$bar = static function (array $por, callable $rot, string $pref) use ($partes): array { uasort($por, static fn($a, $b) => $b['n'] <=> $a['n']); $o = []; foreach ($por as $k => $c) $o[] = ['rotulo' => $rot($k), 'n' => $c['n'], 'grupo' => HomeModulo::grupo($pref, $k), 'partes' => $partes($c)]; return $o; };
ksort($porMes); $colM = [];
foreach (array_slice($porMes, -12, null, true) as $ym => $n) $colM[] = ['rotulo' => HomeModulo::mesCurto($ym), 'n' => $n, 'tom' => 'blue', 'dica' => $T('g.novas_mes', 'Auditorias novas', 'New audits', 'Auditorías nuevas') . ' ' . $ym];
$leg = HomeModulo::legenda(array_combine(array_map(static fn($k) => $TOM[$k], array_keys(A::STATUS)), array_map(static fn($k) => A::situacao($k), array_keys(A::STATUS))));
$graficos = '<div class="hm-graficos">'
    . HomeModulo::grafico($T('g.situacao', 'Auditorias por situação', 'Audits by status', 'Auditorías por situación'), 'bi-signpost-split', HomeModulo::barras($G, $barSt), $T('g.dica', 'Clique numa barra para ver as auditorias na lista.', 'Click a bar to see the audits in the list.', 'Haga clic en una barra para ver las auditorías en la lista.'))
    . HomeModulo::grafico($T('g.rnc', 'RNCs de auditoria por etapa', 'Audit NCRs by stage', 'RNC de auditoría por etapa'), 'bi-exclamation-octagon', HomeModulo::barras('haRnc', $barE))
    . HomeModulo::grafico($T('g.unidade', 'Por unidade', 'By unit', 'Por unidad'), 'bi-buildings', HomeModulo::barras($G, $bar($porUn, static fn($k) => $unNome[(int)$k] ?? '–', 'U'), 15) . $leg)
    . HomeModulo::grafico($T('g.tipo', 'Por tipo', 'By type', 'Por tipo'), 'bi-tags', HomeModulo::barras($G, $bar($porTipo, static fn($k) => $tipos[$k] ?? $k, 'T')) . $leg)
    . HomeModulo::grafico($T('g.auditor', 'Por auditor', 'By auditor', 'Por auditor'), 'bi-person-badge', HomeModulo::barras($G, $bar($porAud, static fn($k) => $usuarios[(int)$k] ?? '–', 'A'), 12) . $leg)
    . ($colM ? HomeModulo::grafico($T('g.mes', 'Auditorias novas por mês', 'New audits per month', 'Auditorías nuevas por mes'), 'bi-bar-chart', HomeModulo::colunas($G, $colM)) : '')
    . '</div>';

$cards = [];
foreach (['P', 'A', 'X', 'E', 'C', 'R'] as $k) $cards[] = ['rotulo' => A::situacao($k), 'valor' => $cont[$k], 'icone' => A::STATUS[$k][1], 'tom' => $TOM[$k], 'grupo' => 'S_' . $k];
$cards[] = ['rotulo' => $T('c.novas', 'Auditorias novas no mês', 'New audits this month', 'Auditorías nuevas en el mes'), 'valor' => $novas, 'icone' => 'bi-calendar-plus', 'tom' => 'blue', 'grupo' => 'NOVA'];
$cards[] = ['rotulo' => $T('c.vencidas', 'Abertas com período vencido', 'Open with period passed', 'Abiertas con período vencido'), 'valor' => $vencidas, 'icone' => 'bi-calendar-x', 'tom' => $vencidas ? 'red' : 'green', 'grupo' => 'VENCIDA'];
foreach ($ETAPAS as $k => [$rot, $tom]) $cards[] = ['rotulo' => $T('c.rnc', 'RNC de auditoria', 'Audit NCR', 'RNC de auditoría') . ': ' . $rot, 'valor' => $contE[$k] ?? 0, 'icone' => 'bi-exclamation-octagon', 'tom' => $tom, 'grupo' => 'E_' . md5($k), 'grid' => 'haRnc'];

$filtrosHtml = HomeModulo::filtros([
    'u' => ['rotulo' => $T('f.unidade', 'Unidade', 'Unit', 'Unidad'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $unidades],
    'r' => ['rotulo' => $T('f.auditor', 'Auditor (responsável)', 'Auditor (owner)', 'Auditor (responsable)'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $auditores],
    't' => ['rotulo' => $T('f.tipo', 'Tipo', 'Type', 'Tipo'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $tipos],
    'p' => ['rotulo' => $T('f.periodo', 'Período (início)', 'Period (start)', 'Período (inicio)'), 'opcoes' => $per],
    'de' => ['rotulo' => $T('f.de', 'De', 'From', 'De'), 'tipo' => 'date', 'oculto' => $f['p'] !== 'datas'], 'ate' => ['rotulo' => $T('f.ate', 'Até', 'To', 'Hasta'), 'tipo' => 'date', 'oculto' => $f['p'] !== 'datas'],
], $f, ['mes' => $mes !== date('Y-m') ? $mes : '']);
$abas = DashboardGrid::subabas('haAbas', [
    ['id' => 'graficos', 'icone' => 'bi-bar-chart', 'rotulo' => $T('sub.graficos', 'Gráficos', 'Charts', 'Gráficos'), 'html' => $graficos],
    ['id' => 'lista', 'icone' => 'bi-table', 'rotulo' => $T('sub.lista', 'Auditorias', 'Audits', 'Auditorías'), 'contagem' => count($linhas), 'html' => $gridAud],
    ['id' => 'rnc', 'icone' => 'bi-exclamation-octagon', 'rotulo' => $T('sub.rnc', 'RNCs de auditoria', 'Audit NCRs', 'RNC de auditoría'), 'contagem' => count($rowsR), 'html' => $gridRnc],
    ['id' => 'calendario', 'icone' => 'bi-calendar3', 'rotulo' => $T('sub.calendario', 'Calendário', 'Calendar', 'Calendario'), 'contagem' => count($rowsCal), 'html' => $calendario],
], true);

$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$titulo = $T('titulo_home', 'Home de Auditoria', 'Audit home', 'Inicio de Auditoría');
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php HomeModulo::head(); ?>
</head>
<body class="theme-<?= $e($theme) ?>" data-transacao="home_auditoria"><main class="hm">
<?= HomeModulo::cabecalho('bi-clipboard2-check', $titulo,
    $e($T('desc', 'Auditorias por situação, unidade, tipo e auditor, as RNCs abertas pelas auditorias e o calendário. Os filtros do topo valem para a página toda; clique num cartão, numa barra ou num dia para listar; duplo clique abre a auditoria.',
        'Audits by status, unit, type and auditor, the NCRs opened by audits and the calendar. The filters apply to the whole page; click a card, a bar or a day to list; double-click opens the audit.',
        'Auditorías por situación, unidad, tipo y auditor, las RNC abiertas por las auditorías y el calendario. Los filtros valen para toda la página; haga clic en una tarjeta, una barra o un día para listar; doble clic abre la auditoría.')), $aviso) ?>
<div class="mb-2"><a class="btn btn-sm btn-outline-success" href="<?= $e($tela) ?>"><i class="bi bi-clipboard2-check me-1"></i><?= $e($todas ? $T('ir.gestao', 'Gestão de Auditorias', 'Audit management', 'Gestión de Auditorías') : $T('ir.minhas', 'Minhas Auditorias', 'My audits', 'Mis Auditorías')) ?></a>
<?php if (!empty($pGestao['pode_incluir'])): ?> <a class="btn btn-sm btn-success" href="auditoria_gestao.php?novo=1"><i class="bi bi-plus-lg me-1"></i><?= $e($T('nova', 'Nova auditoria', 'New audit', 'Nueva auditoría')) ?></a><?php endif; ?></div>
<?= $filtrosHtml ?>
<?= HomeModulo::cartoes($G, $cards) ?>
<?= $abas ?>
</main>
<?php HomeModulo::scripts(); ?>
<script>(function(){var p=document.getElementById('hmF_p');if(!p)return;function v(){document.querySelectorAll('[data-hm-filtro="de"],[data-hm-filtro="ate"]').forEach(function(l){l.hidden=p.value!=='datas';});}p.addEventListener('change',v);v();})();</script>
</body></html>
