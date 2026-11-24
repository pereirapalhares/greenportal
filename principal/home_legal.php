<?php
declare(strict_types=1);

/*
 * Home do Módulo Legal (24/11/2026, Homes por módulo — itens 22/GER-1/GER-4/GER-13 da Revisão G). Modelo único (classes/HomeModulo.php),
 * no mesmo formato da Home de Licenças.
 *  - Filtros do topo: unidade, responsável, nível de controle, origem (âmbito), área, leis revogadas e vencimento da avaliação.
 *  - Cartões: requisitos, atendidos, parcialmente, não atendidos, não avaliados (com a taxa de conformidade), avaliação vencida
 *    ou a vencer, risco crítico/alto, pendências críticas, divergência manual × calculada e sem responsável — o clique lista abaixo.
 *  - Sub-abas: Gráficos (situação, risco, unidade, responsável, origem, nível de controle, área, próximas avaliações), Lista
 *    (abre o requisito), Calendário das próximas avaliações, Atualização do mês (RCL: inseridas/alteradas/revogadas) e
 *    Pendências críticas.
 * Dados (só leitura, sp_crud_select): vw_modulolegal_requisitos_situacao_grid (situação consolidada, % e risco — 284/341),
 * legislacaoxsituacao (avaliações e vencimento), vw_modulolegal_responsaveis, vw_ml_pendencias_criticas e, na base central,
 * vw_legislacao_rcl (385).
 * Quem tem "Visualizar total" em Requisitos legais (ou na Home) vê a empresa toda; os demais, só os requisitos de que são responsáveis.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit('<div style="font:13px sans-serif;padding:16px;color:#5f7268">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</div>'); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/HomeModulo.php';
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
$idioma = IdiomaService::idioma();
$T = static fn(string $c, string $pt, string $en, string $es): string => HomeModulo::t('leg.' . $c, $pt, $en, $es);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);

$pHome = PermissaoTransacao::obter('HOME_LEGAL'); $pReq = PermissaoTransacao::obter('REQUISITOS_LEGAIS');
if (empty($pHome['pode_visualizar']) && empty($pReq['pode_visualizar']))
    $sair(403, $T('negado', 'Você não tem acesso aos Requisitos legais.', 'You do not have access to Legal requirements.', 'No tiene acceso a los Requisitos legales.'));
$total = !empty($pHome['pode_visualizar_total']) || !empty($pReq['pode_visualizar_total']);

// ---------------------------------------------------------------- dados
$todos = HomeModulo::consultar($pdo, 'vw_modulolegal_requisitos_situacao_grid', ['CODLEGISLACAO', 'CODUNIDADE', 'UNIDADE', 'DSCUNIDADE', 'PODER', 'CODIGO', 'LEI_REVOGADA',
    'DSCAMBITO', 'DSCTIPO', 'NR', 'ANO', 'DSCASSUNTO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'RESPONSAVEL_IDS', 'SITUACAO_CONSOLIDADA',
    'MOTIVO_CALCULO', 'PCT_ATENDIMENTO', 'RISCO_REQUISITO', 'PENDENCIAS_CRITICAS', 'DIVERGENCIA']);
if ($todos === null) $sair(503, $T('sem_migracao', 'A situação calculada dos requisitos ainda não existe nesta empresa (migrações 284/341).', 'The calculated requirement status is not available for this company (migrations 284/341).', 'La situación calculada de los requisitos no existe en esta empresa (migraciones 284/341).'));
$nomes = HomeModulo::nomesUsuarios($pdo);
$aval = []; // "lei|unidade" => ['ult' => data, 'venc' => data, 'n' => qtd]
foreach (HomeModulo::consultar($pdo, 'legislacaoxsituacao', ['CODLEGISLACAO', 'codunidade', 'data_de_avaliacao', 'data_de_vencimento_avaliacao']) ?? [] as $s) {
    $s = array_change_key_case($s, CASE_UPPER);
    $k = (int)$s['CODLEGISLACAO'] . '|' . (int)$s['CODUNIDADE'];
    $a = HomeModulo::data($s['DATA_DE_AVALIACAO'] ?? null); $v = HomeModulo::data($s['DATA_DE_VENCIMENTO_AVALIACAO'] ?? null);
    $aval[$k] ??= ['ult' => null, 'venc' => null, 'n' => 0];
    $aval[$k]['n']++;
    if ($a !== null && ($aval[$k]['ult'] === null || $a > $aval[$k]['ult'])) $aval[$k]['ult'] = $a;
    if ($v !== null && ($aval[$k]['venc'] === null || $v > $aval[$k]['venc'])) $aval[$k]['venc'] = $v;
}
$ids = static fn(array $l): array => array_values(array_filter(array_map('intval', explode(',', trim((string)($l['RESPONSAVEL_IDS'] ?? ''), ',')))));

$aviso = '';
if (!$total) {
    $todos = array_values(array_filter($todos, static fn($l) => in_array($uid, $ids($l), true)));
    $aviso = $T('so_meus', 'Mostrando só os requisitos de que você é responsável (direto ou por grupo).', 'Showing only the requirements you are responsible for.', 'Mostrando solo los requisitos de los que usted es responsable.');
}

// ---------------------------------------------------------------- filtros
$hoje = date('Y-m-d');
$f = ['u' => (string)($_GET['u'] ?? ''), 'r' => (string)($_GET['r'] ?? ''), 'd' => (string)($_GET['d'] ?? ''), 'a' => (string)($_GET['a'] ?? ''),
    'ar' => (string)($_GET['ar'] ?? ''), 'rv' => (string)($_GET['rv'] ?? ''), 'p' => (string)($_GET['p'] ?? ''),
    'de' => HomeModulo::data($_GET['de'] ?? '') ?? '', 'ate' => HomeModulo::data($_GET['ate'] ?? '') ?? ''];
if (!isset(HomeModulo::periodos()[$f['p']])) $f['p'] = '';
if ($f['p'] !== 'datas') { $f['de'] = ''; $f['ate'] = ''; }
if (!in_array($f['rv'], ['', 'todas', 'revogadas'], true)) $f['rv'] = '';
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$rcl = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['rcl'] ?? '')) ? (string)$_GET['rcl'] : '';
$url = static function (array $mudar = []) use ($f, $mes, $rcl): string {
    $q = array_filter($mudar + $f + ['mes' => $mes !== date('Y-m') ? $mes : '', 'rcl' => $rcl], static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
$AREAS = ['MA' => ['MEIO_AMBIENTE', $T('ar.ma', 'Meio ambiente', 'Environment', 'Medio ambiente')], 'SST' => ['SEGURANCA', $T('ar.sst', 'Segurança e saúde', 'Health and safety', 'Seguridad y salud')],
    'QUA' => ['QUALIDADE', $T('ar.qua', 'Qualidade', 'Quality', 'Calidad')], 'RS' => ['RESPOSABILIDADE', $T('ar.rs', 'Responsabilidade social', 'Social responsibility', 'Responsabilidad social')]];
$sim = static fn($v): bool => in_array(mb_strtolower(trim((string)$v)), ['sim', 's', '1', '-1', 'true'], true);

$unidades = []; $responsaveis = []; $poderes = []; $ambitos = [];
foreach ($todos as $l) {
    $unidades[(string)$l['CODUNIDADE']] = trim((string)$l['UNIDADE']) !== '' ? trim((string)$l['UNIDADE']) : (trim((string)($l['DSCUNIDADE'] ?? '')) ?: '#' . $l['CODUNIDADE']);
    foreach ($ids($l) as $i) $responsaveis[(string)$i] = $nomes[$i] ?? '#' . $i;
    if (trim((string)$l['PODER']) !== '') $poderes[trim((string)$l['PODER'])] = trim((string)$l['PODER']);
    if (trim((string)$l['DSCAMBITO']) !== '') $ambitos[md5(trim((string)$l['DSCAMBITO']))] = trim((string)$l['DSCAMBITO']);
}
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($responsaveis, SORT_NATURAL | SORT_FLAG_CASE); ksort($poderes); asort($ambitos, SORT_NATURAL | SORT_FLAG_CASE);
$responsaveis = ['0' => $T('sem_resp', '(sem responsável)', '(no owner)', '(sin responsable)')] + $responsaveis;

$revogada = static fn(array $l): bool => strtoupper(trim((string)($l['LEI_REVOGADA'] ?? ''))) === 'SIM';
$chave = static fn(array $l): string => (int)$l['CODLEGISLACAO'] . '|' . (int)$l['CODUNIDADE'];
// base = todos os filtros menos o vencimento (vale para o calendário e o RCL); req = base + vencimento da avaliação
$base = array_values(array_filter($todos, static function ($l) use ($f, $ids, $revogada, $AREAS, $sim) {
    if ($f['u'] !== '' && (string)$l['CODUNIDADE'] !== $f['u']) return false;
    if ($f['r'] !== '') { $r = $ids($l); if ($f['r'] === '0' ? $r !== [] : !in_array((int)$f['r'], $r, true)) return false; }
    if ($f['d'] !== '' && trim((string)$l['PODER']) !== $f['d']) return false;
    if ($f['a'] !== '' && md5(trim((string)$l['DSCAMBITO'])) !== $f['a']) return false;
    if ($f['ar'] !== '' && (!isset($AREAS[$f['ar']]) || !$sim($l[$AREAS[$f['ar']][0]] ?? ''))) return false;
    if ($f['rv'] === '' && $revogada($l)) return false;
    if ($f['rv'] === 'revogadas' && !$revogada($l)) return false;
    return true;
}));
$req = array_values(array_filter($base, static fn($l) => HomeModulo::noPeriodo($aval[$chave($l)]['venc'] ?? null, $f['p'], $f['de'] ?: null, $f['ate'] ?: null)));

// ---------------------------------------------------------------- classificações
$SIT = ['ATENDIDO' => [$T('s.atendido', 'Atendidos', 'Complied', 'Atendidos'), 'green', 'bi-check-circle', $T('s1.atendido', 'Atendido', 'Complied', 'Atendido')],
    'PARCIAL' => [$T('s.parcial', 'Parcialmente atendidos', 'Partially complied', 'Parcialmente atendidos'), 'orange', 'bi-circle-half', $T('s1.parcial', 'Parcialmente atendido', 'Partially complied', 'Parcialmente atendido')],
    'NAO' => [$T('s.nao', 'Não atendidos', 'Not complied', 'No atendidos'), 'red', 'bi-x-circle', $T('s1.nao', 'Não atendido', 'Not complied', 'No atendido')],
    'NA' => [$T('s.na', 'Não avaliados', 'Not assessed', 'No evaluados'), 'gray', 'bi-question-circle', $T('s1.na', 'Não avaliado', 'Not assessed', 'No evaluado')]];
$sitDe = static fn(array $l): string => match (trim((string)$l['SITUACAO_CONSOLIDADA'])) { 'Atendido' => 'ATENDIDO', 'Parcialmente atendido' => 'PARCIAL', 'Não atendido' => 'NAO', default => 'NA' };
$RISCO = ['CRITICO' => [$T('r.critico', 'Crítico', 'Critical', 'Crítico'), 'red'], 'ALTO' => [$T('r.alto', 'Alto', 'High', 'Alto'), 'orange'],
    'MODERADO' => [$T('r.moderado', 'Moderado', 'Moderate', 'Moderado'), 'yellow'], 'BAIXO' => [$T('r.baixo', 'Baixo', 'Low', 'Bajo'), 'green'],
    'NAO_AVALIADO' => [$T('r.nao_avaliado', 'Risco não avaliado', 'Risk not assessed', 'Riesgo no evaluado'), 'gray'], 'SEM' => [$T('r.sem', 'Sem itens de gestão', 'No management items', 'Sin ítems de gestión'), 'teal']];
$riscoDe = static fn(array $l): string => match (trim((string)$l['RISCO_REQUISITO'])) { 'Crítico' => 'CRITICO', 'Alto' => 'ALTO', 'Moderado' => 'MODERADO', 'Baixo' => 'BAIXO', 'Não avaliado' => 'NAO_AVALIADO', default => 'SEM' };
$naoInf = $T('nao_informado', '(não informado)', '(not informed)', '(no informado)');
$norma = static fn(array $l): string => trim(trim((string)$l['DSCTIPO']) . ' ' . trim((string)$l['NR']) . (trim((string)$l['ANO']) !== '' ? '/' . trim((string)$l['ANO']) : ''));
$abrirReq = static fn(array $l): array => ['url' => 'modulolegal_requisitoslegais.php?' . http_build_query(['filtros' => json_encode(['CODLEGISLACAO' => (int)$l['CODLEGISLACAO'], 'CODUNIDADE' => (int)$l['CODUNIDADE'], 'LEI_REVOGADA' => strtoupper(trim((string)($l['LEI_REVOGADA'] ?? ''))) === 'SIM' ? 'SIM' : 'NAO'])]),
    'title' => $T('titulo_req', 'Requisitos legais', 'Legal requirements', 'Requisitos legales') . ' - ' . $l['CODIGO'] . ' · ' . $l['UNIDADE']];

// ---------------------------------------------------------------- lista
$cont = array_fill_keys(array_keys($SIT), 0); $contR = array_fill_keys(array_keys($RISCO), 0);
$avVencida = 0; $av30 = 0; $crit = 0; $div = 0; $semResp = 0; $meses = []; $depois = 0;
$porUn = []; $porResp = []; $porAmb = []; $porPod = []; $porArea = [];
$linhas = [];
foreach ($req as $l) {
    $s = $sitDe($l); $r = $riscoDe($l); $k = $chave($l); $av = $aval[$k] ?? null; $venc = $av['venc'] ?? null;
    $cont[$s]++; $contR[$r]++;
    $un = (string)$l['CODUNIDADE']; $rs = $ids($l); $amb = trim((string)$l['DSCAMBITO']); $pod = trim((string)$l['PODER']);
    $g = [HomeModulo::grupo('S', $s), HomeModulo::grupo('K', $r), HomeModulo::grupo('U', $un), HomeModulo::grupo('P', $pod === '' ? 'NA' : $pod), HomeModulo::grupo('A', $amb === '' ? 'NA' : md5($amb))];
    foreach ($rs ?: [0] as $i) { $g[] = HomeModulo::grupo('R', $i); $porResp[$i]['n'] = ($porResp[$i]['n'] ?? 0) + 1; $porResp[$i][$s] = ($porResp[$i][$s] ?? 0) + 1; }
    if (!$rs) { $g[] = 'SEMRESP'; $semResp++; }
    if ($venc !== null && $venc < $hoje) { $g[] = 'AV_VENCIDA'; $avVencida++; }
    elseif ($venc !== null && $venc <= date('Y-m-d', strtotime('+30 days'))) { $g[] = 'AV_30'; $av30++; }
    if ($venc !== null && $venc >= date('Y-m-01')) {
        if ($venc <= date('Y-m-t', strtotime('+11 months'))) { $g[] = 'M_' . substr($venc, 0, 7); $meses[substr($venc, 0, 7)] = ($meses[substr($venc, 0, 7)] ?? 0) + 1; } else $depois++;
    }
    if ((int)$l['PENDENCIAS_CRITICAS'] > 0) { $g[] = 'CRIT'; $crit++; }
    if (trim((string)$l['DIVERGENCIA']) === 'Sim') { $g[] = 'DIV'; $div++; }
    foreach ($AREAS as $ak => [$col]) if ($sim($l[$col] ?? '')) { $g[] = 'AR_' . $ak; $porArea[$ak]['n'] = ($porArea[$ak]['n'] ?? 0) + 1; $porArea[$ak][$s] = ($porArea[$ak][$s] ?? 0) + 1; }
    $porUn[$un]['n'] = ($porUn[$un]['n'] ?? 0) + 1; $porUn[$un][$s] = ($porUn[$un][$s] ?? 0) + 1;
    $porAmb[$amb]['n'] = ($porAmb[$amb]['n'] ?? 0) + 1; $porAmb[$amb][$s] = ($porAmb[$amb][$s] ?? 0) + 1;
    $porPod[$pod]['n'] = ($porPod[$pod]['n'] ?? 0) + 1; $porPod[$pod][$s] = ($porPod[$pod][$s] ?? 0) + 1;
    [, $sTom, , $sRot] = $SIT[$s]; [$rRot, $rTom] = $RISCO[$r];
    if ($r === 'CRITICO' || $r === 'ALTO') $g[] = 'K_CA';
    $pct = $l['PCT_ATENDIMENTO'];
    $nomesResp = implode(', ', array_map(static fn($i) => $nomes[$i] ?? '#' . $i, $rs));
    $linhas[] = ['cells' => [
        'UNIDADE' => (string)$l['UNIDADE'],
        'CODIGO' => (string)$l['CODIGO'],
        'NORMA' => $norma($l),
        'ASSUNTO' => (string)$l['DSCASSUNTO'],
        'AMBITO' => $amb,
        'PODER' => $pod,
        'SITUACAO' => ['text' => $sRot, 'html' => '<span class="hm-sit tom-' . $sTom . '" title="' . $e($l['MOTIVO_CALCULO'] ?? '') . '">' . $e($sRot) . '</span>', 'sort' => $sRot],
        'PCT' => ['text' => $pct === null || $pct === '' ? '' : (int)$pct . '%', 'sort' => $pct === null || $pct === '' ? '-1' : sprintf('%03d', (int)$pct)],
        'RISCO' => ['text' => $rRot, 'html' => $r === 'SEM' ? '' : '<span class="hm-sit tom-' . $rTom . '">' . $e($rRot) . '</span>', 'sort' => $rRot],
        'AVALIACAO' => ['text' => HomeModulo::dataTexto($av['ult'] ?? null), 'sort' => $av['ult'] ?? ''],
        'VENCIMENTO' => DashboardGrid::prazo($venc ?? ''),
        'RESPONSAVEIS' => $nomesResp,
    ], 'open' => $abrirReq($l), 'grupos' => implode(' ', $g)];
}
$G = 'hgReq';
$colsReq = [
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
    'CODIGO' => ['label' => $T('col.codigo', 'Código', 'Code', 'Código'), 'width' => '90px'],
    'NORMA' => ['label' => $T('col.norma', 'Norma', 'Regulation', 'Norma'), 'width' => '230px'],
    'ASSUNTO' => ['label' => $T('col.assunto', 'Assunto', 'Subject', 'Asunto'), 'width' => '200px'],
    'AMBITO' => ['label' => $T('col.ambito', 'Origem', 'Origin', 'Origen'), 'filter' => 'select', 'width' => '100px'],
    'PODER' => ['label' => $T('col.poder', 'Nível de controle', 'Control level', 'Nivel de control'), 'filter' => 'select', 'width' => '120px'],
    'SITUACAO' => ['label' => $T('col.situacao', 'Situação', 'Status', 'Situación'), 'filter' => 'select', 'width' => '150px'],
    'PCT' => ['label' => $T('col.pct', '% atendimento', '% compliance', '% atención'), 'width' => '95px'],
    'RISCO' => ['label' => $T('col.risco', 'Risco', 'Risk', 'Riesgo'), 'filter' => 'select', 'width' => '120px'],
    'AVALIACAO' => ['label' => $T('col.avaliacao', 'Última avaliação', 'Last assessment', 'Última evaluación'), 'width' => '110px'],
    'VENCIMENTO' => ['label' => $T('col.vencimento', 'Próxima avaliação', 'Next assessment', 'Próxima evaluación'), 'width' => '120px'],
    'RESPONSAVEIS' => ['label' => $T('col.responsaveis', 'Responsáveis', 'Owners', 'Responsables'), 'width' => '200px'],
];
// a lista (milhares de linhas) vem depois, por ?parte=lista, quando a sub-aba abre ou um cartão/barra filtra (HomeModulo::adiada)
if (HomeModulo::parte() === 'lista') {
    session_write_close();
    echo HomeModulo::grid($G, $colsReq, $linhas, ['transacao' => 'home_legal', 'ordem' => ['VENCIMENTO', 'asc'], 'por_pagina' => 50,
        'vazio' => $T('lista.vazia', 'Nenhum requisito com estes filtros.', 'No requirement with these filters.', 'Ningún requisito con estos filtros.')]);
    exit;
}
$gridReq = HomeModulo::adiada($G, 'lista', count($linhas));

// ---------------------------------------------------------------- calendário das avaliações (base, mês escolhido)
$eventos = []; $rowsCal = [];
foreach ($base as $l) {
    $venc = $aval[$chave($l)]['venc'] ?? null;
    if ($venc === null || substr($venc, 0, 7) !== $mes) continue;
    $eventos[] = ['data' => $venc, 'tom' => $venc < $hoje ? 'red' : 'orange', 'rotulo' => $l['CODIGO'] . ' · ' . $l['UNIDADE']];
    $rowsCal[] = ['cells' => ['DATA' => DashboardGrid::prazo($venc), 'CODIGO' => (string)$l['CODIGO'], 'NORMA' => ['text' => $norma($l), 'title' => $norma($l)], 'UNIDADE' => (string)$l['UNIDADE'],
        'SITUACAO' => $SIT[$sitDe($l)][3], 'RESPONSAVEIS' => implode(', ', array_map(static fn($i) => $nomes[$i] ?? '#' . $i, $ids($l)))], 'open' => $abrirReq($l), 'grupos' => 'D_' . $venc];
}
$ant = date('Y-m', strtotime($mes . '-01 -1 month')); $prox = date('Y-m', strtotime($mes . '-01 +1 month'));
$calendario = HomeModulo::calendario($mes, $eventos, 'hgCal', ['ant' => $url(['mes' => $ant]), 'prox' => $url(['mes' => $prox]), 'hoje' => $url(['mes' => ''])])
    . HomeModulo::legenda(['red' => $T('lg.vencida', 'Avaliação vencida', 'Assessment overdue', 'Evaluación vencida'), 'orange' => $T('lg.prevista', 'Próxima avaliação', 'Next assessment', 'Próxima evaluación')])
    . '<div class="mt-2">' . HomeModulo::grid('hgCal', ['DATA' => ['label' => $T('col.data', 'Data', 'Date', 'Fecha'), 'width' => '100px'], 'CODIGO' => ['label' => $T('col.codigo', 'Código', 'Code', 'Código'), 'width' => '90px'],
        'NORMA' => ['label' => $T('col.norma', 'Norma', 'Regulation', 'Norma'), 'width' => '260px'], 'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
        'SITUACAO' => ['label' => $T('col.situacao', 'Situação', 'Status', 'Situación'), 'filter' => 'select', 'width' => '150px'],
        'RESPONSAVEIS' => ['label' => $T('col.responsaveis', 'Responsáveis', 'Owners', 'Responsables'), 'width' => '200px']], $rowsCal,
        ['transacao' => 'home_legal', 'ordem' => ['DATA', 'asc'], 'por_pagina' => 50, 'vazio' => $T('cal.vazio', 'Nenhuma avaliação vence neste mês.', 'No assessment due this month.', 'Ninguna evaluación vence en este mes.')]) . '</div>';

// ---------------------------------------------------------------- atualização do mês (RCL, base central)
$rclHtml = ''; $rclMes = $rcl; $cRcl = ['INSERIDA' => 0, 'ALTERADA' => 0, 'REVOGADA' => 0];
try {
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $porLei = []; foreach ($base as $l) $porLei[(int)$l['CODLEGISLACAO']][(string)$l['UNIDADE']] = true;
    $lerMes = static function (string $m) use ($leg, $porLei): array {
        $out = [];
        foreach (HomeModulo::ler($leg, 'vw_legislacao_rcl', ['MES_ATUALIZACAO' => $m], 20000) ?? [] as $x) {
            if ((string)$x['MES_ATUALIZACAO'] !== $m || !isset($porLei[(int)$x['CODLEGISLACAO']])) continue;
            $out[(int)$x['CODLEGISLACAO']] ??= $x + ['ASSUNTOS' => []];
            if (trim((string)$x['DSCASSUNTO']) !== '') $out[(int)$x['CODLEGISLACAO']]['ASSUNTOS'][trim((string)$x['DSCASSUNTO'])] = true;
        }
        return $out;
    };
    $leis = [];
    if ($rclMes === '') { // sem mês escolhido: o mais recente com atualização entre os últimos 6
        for ($i = 0; $i < 6 && !$leis; $i++) { $rclMes = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $leis = $lerMes($rclMes); }
        if (!$leis) $rclMes = date('Y-m');
    } else $leis = $lerMes($rclMes);
    $rowsRcl = [];
    $TIPOS_RCL = ['Lei Inclusa' => ['INSERIDA', $T('rcl.inserida', 'Inserida', 'Added', 'Insertada'), 'green'], 'Lei Alterada' => ['ALTERADA', $T('rcl.alterada', 'Alterada', 'Amended', 'Modificada'), 'orange'],
        'Lei Revogada' => ['REVOGADA', $T('rcl.revogada', 'Revogada', 'Repealed', 'Derogada'), 'red']];
    foreach ($leis as $id => $x) {
        $t = $TIPOS_RCL[trim((string)$x['SITUACAO'])] ?? null;
        if ($t === null) continue;
        $cRcl[$t[0]]++;
        $nm = trim(trim((string)$x['DSCTIPO']) . ' ' . trim((string)$x['NR']) . (trim((string)$x['ANO']) !== '' ? '/' . trim((string)$x['ANO']) : ''));
        $uns = implode(', ', array_keys($porLei[$id]));
        $rowsRcl[] = ['cells' => ['CODIGO' => (string)$x['CODIGO'], 'NORMA' => ['text' => $nm, 'title' => $nm . ' — ' . mb_strimwidth(trim((string)$x['PADRAO']), 0, 200, '…')],
            'SITUACAO' => ['text' => $t[1], 'html' => '<span class="hm-sit tom-' . $t[2] . '">' . $e($t[1]) . '</span>', 'sort' => $t[1]], 'DEVIDO' => trim((string)$x['DEVIDO']),
            'AMBITO' => trim((string)$x['DSCAMBITO']), 'ASSUNTO' => ['text' => implode('; ', array_keys($x['ASSUNTOS'])), 'title' => implode('; ', array_keys($x['ASSUNTOS']))],
            'UNIDADES' => ['text' => $uns, 'title' => $uns]], 'grupos' => 'RC_' . $t[0]];
    }
    $opMes = [];
    for ($i = 0; $i < 18; $i++) { $m = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $opMes[$m] = HomeModulo::mesLongo($m); }
    $rclHtml = '<form method="get" class="hm-filtros" style="background:none;border:0;padding:0 0 6px">'
        . implode('', array_map(static fn($k, $v) => $v !== '' ? '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">' : '', array_keys($f + ['mes' => $mes !== date('Y-m') ? $mes : '']), $f + ['mes' => $mes !== date('Y-m') ? $mes : '']))
        . '<label class="hm-filtro"><span>' . $e($T('rcl.mes', 'Mês da atualização', 'Update month', 'Mes de la actualización')) . '</span><select class="form-select form-select-sm" name="rcl" onchange="this.form.submit()">'
        . implode('', array_map(static fn($k, $v) => '<option value="' . $e($k) . '"' . ($k === $rclMes ? ' selected' : '') . '>' . $e($v) . '</option>', array_keys($opMes), $opMes)) . '</select></label></form>'
        . HomeModulo::cartoes('hgRcl', [
            ['rotulo' => $T('rcl.c_ins', 'Requisitos inseridos', 'Requirements added', 'Requisitos insertados'), 'valor' => $cRcl['INSERIDA'], 'icone' => 'bi-plus-circle', 'tom' => 'green', 'grupo' => 'RC_INSERIDA'],
            ['rotulo' => $T('rcl.c_alt', 'Requisitos alterados', 'Requirements amended', 'Requisitos modificados'), 'valor' => $cRcl['ALTERADA'], 'icone' => 'bi-pencil-square', 'tom' => 'orange', 'grupo' => 'RC_ALTERADA'],
            ['rotulo' => $T('rcl.c_rev', 'Requisitos revogados', 'Requirements repealed', 'Requisitos derogados'), 'valor' => $cRcl['REVOGADA'], 'icone' => 'bi-x-octagon', 'tom' => 'red', 'grupo' => 'RC_REVOGADA'],
        ])
        . HomeModulo::grid('hgRcl', ['CODIGO' => ['label' => $T('col.codigo', 'Código', 'Code', 'Código'), 'width' => '90px'], 'NORMA' => ['label' => $T('col.norma', 'Norma', 'Regulation', 'Norma'), 'width' => '240px'],
            'SITUACAO' => ['label' => $T('col.sit_lei', 'Situação da lei', 'Law status', 'Situación de la ley'), 'filter' => 'select', 'width' => '110px'],
            'DEVIDO' => ['label' => $T('col.devido', 'Devido', 'Due to', 'Debido'), 'filter' => 'select', 'width' => '170px'],
            'AMBITO' => ['label' => $T('col.ambito', 'Origem', 'Origin', 'Origen'), 'filter' => 'select', 'width' => '100px'],
            'ASSUNTO' => ['label' => $T('col.assunto', 'Assunto', 'Subject', 'Asunto'), 'width' => '220px'],
            'UNIDADES' => ['label' => $T('col.unidades', 'Unidades', 'Units', 'Unidades'), 'width' => '200px']], $rowsRcl,
            ['transacao' => 'home_legal', 'ordem' => ['CODIGO', 'asc'], 'por_pagina' => 50, 'vazio' => $T('rcl.vazio', 'Nenhum requisito desta empresa foi inserido, alterado ou revogado neste mês.', 'No requirement of this company was added, amended or repealed this month.', 'Ningún requisito fue insertado, modificado o derogado en este mes.')])
        . '<div class="hm-nota">' . $e($T('rcl.nota', 'Leis da base central com "Data de atualização" no mês, que são requisitos desta empresa (com os filtros do topo). É a mesma regra da RCL Completa (Manutenção Legal › Legislação).', 'Laws in the central base updated in the month that are requirements of this company.', 'Leyes de la base central actualizadas en el mes que son requisitos de esta empresa.')) . '</div>';
} catch (Throwable $err) {
    error_log('[home_legal rcl] ' . $err->getMessage());
    $rclHtml = '<div class="hm-vazio">' . $e($T('rcl.erro', 'Não foi possível ler a atualização mensal da base de legislação.', 'Could not read the monthly legislation update.', 'No fue posible leer la actualización mensual.')) . '</div>';
}

// ---------------------------------------------------------------- pendências críticas
$pcs = HomeModulo::ler($pdo, 'vw_ml_pendencias_criticas') ?? [];
$noFiltro = array_fill_keys(array_map($chave, $req), true); $porChave = []; foreach ($req as $l) $porChave[$chave($l)] = $l;
$rowsPc = [];
foreach ($pcs as $p) {
    $k = (int)$p['CODLEGISLACAO'] . '|' . (int)$p['CODUNIDADE'];
    if (!isset($noFiltro[$k])) continue;
    $l = $porChave[$k];
    $rowsPc[] = ['cells' => ['CODIGO' => (string)$l['CODIGO'], 'UNIDADE' => (string)$l['UNIDADE'], 'AREA' => (string)$p['AREA'], 'ITEM' => ['text' => (string)$p['ITEM'], 'title' => (string)$p['ITEM']],
        'MOTIVO' => ['text' => (string)$p['MOTIVO'], 'title' => (string)$p['MOTIVO']], 'RESPONSAVEL' => (string)($p['RESPONSAVEL_ITEM'] ?? '')], 'open' => $abrirReq($l)];
}
$pcHtml = $rowsPc ? HomeModulo::grid('hgPc', ['CODIGO' => ['label' => $T('col.codigo', 'Código', 'Code', 'Código'), 'width' => '90px'], 'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
    'AREA' => ['label' => $T('col.pilar', 'Pilar', 'Pillar', 'Pilar'), 'filter' => 'select', 'width' => '120px'], 'ITEM' => ['label' => $T('col.item', 'Item', 'Item', 'Ítem'), 'width' => '260px'],
    'MOTIVO' => ['label' => $T('col.motivo', 'Motivo', 'Reason', 'Motivo'), 'width' => '300px'], 'RESPONSAVEL' => ['label' => $T('col.responsavel', 'Responsável do item', 'Item owner', 'Responsable del ítem'), 'filter' => 'select', 'width' => '170px']],
    $rowsPc, ['transacao' => 'home_legal', 'por_pagina' => 50]) : '';

// ---------------------------------------------------------------- gráficos
$partes = static function (array $c) use ($SIT): array {
    $o = []; foreach ($SIT as $k => [$rot, $tom]) if (($c[$k] ?? 0) > 0) $o[] = ['n' => $c[$k], 'tom' => $tom, 'rotulo' => $rot];
    return $o;
};
$barSit = array_map(static fn($k) => ['rotulo' => $SIT[$k][0], 'n' => $cont[$k], 'grupo' => 'S_' . $k, 'tom' => $SIT[$k][1]], array_keys($SIT));
$barRisco = array_map(static fn($k) => ['rotulo' => $RISCO[$k][0], 'n' => $contR[$k], 'grupo' => 'K_' . $k, 'tom' => $RISCO[$k][1]], array_keys($RISCO));
uasort($porUn, static fn($a, $b) => $b['n'] <=> $a['n']);
$barUn = []; foreach ($porUn as $k => $c) $barUn[] = ['rotulo' => $unidades[$k] ?? '#' . $k, 'n' => $c['n'], 'grupo' => HomeModulo::grupo('U', $k), 'partes' => $partes($c)];
uasort($porResp, static fn($a, $b) => $b['n'] <=> $a['n']);
$barResp = []; foreach ($porResp as $k => $c) $barResp[] = ['rotulo' => (int)$k === 0 ? $T('sem_resp', '(sem responsável)', '(no owner)', '(sin responsable)') : ($nomes[(int)$k] ?? '#' . $k), 'n' => $c['n'], 'grupo' => HomeModulo::grupo('R', $k), 'partes' => $partes($c)];
uasort($porAmb, static fn($a, $b) => $b['n'] <=> $a['n']);
$barAmb = []; foreach ($porAmb as $k => $c) $barAmb[] = ['rotulo' => (string)$k === '' ? $naoInf : (string)$k, 'n' => $c['n'], 'grupo' => HomeModulo::grupo('A', (string)$k === '' ? 'NA' : md5((string)$k)), 'partes' => $partes($c)];
uasort($porPod, static fn($a, $b) => $b['n'] <=> $a['n']);
$barPod = []; foreach ($porPod as $k => $c) $barPod[] = ['rotulo' => (string)$k === '' ? $naoInf : (string)$k, 'n' => $c['n'], 'grupo' => HomeModulo::grupo('P', (string)$k === '' ? 'NA' : (string)$k), 'partes' => $partes($c)];
$barArea = []; foreach ($AREAS as $k => [, $rot]) if (isset($porArea[$k])) $barArea[] = ['rotulo' => $rot, 'n' => $porArea[$k]['n'], 'grupo' => 'AR_' . $k, 'partes' => $partes($porArea[$k])];
$col = [];
for ($i = 0; $i < 12; $i++) { $m = date('Y-m', strtotime(date('Y-m-01') . " +$i month")); $col[] = ['rotulo' => HomeModulo::mesCurto($m), 'n' => $meses[$m] ?? 0, 'grupo' => 'M_' . $m, 'tom' => $i === 0 ? 'orange' : 'teal', 'dica' => HomeModulo::mesLongo($m)]; }
$legSit = HomeModulo::legenda(array_combine(array_column($SIT, 1), array_column($SIT, 0)));
$dica = $T('g.dica', 'Clique numa barra para ver os requisitos na Lista.', 'Click a bar to see the requirements in the List.', 'Haga clic en una barra para ver los requisitos en la Lista.');
$graficos = '<div class="hm-graficos">'
    . HomeModulo::grafico($T('g.situacao', 'Situação de atendimento', 'Compliance status', 'Situación de atención'), 'bi-shield-check', HomeModulo::barras($G, $barSit), $dica)
    . HomeModulo::grafico($T('g.risco', 'Risco dos requisitos', 'Requirement risk', 'Riesgo de los requisitos'), 'bi-exclamation-triangle', HomeModulo::barras($G, $barRisco),
        $T('g.risco_nota', 'Risco do requisito = pior risco entre os itens de gestão ligados (licenças, medições, controles, treinamentos, infraestrutura, fornecedores).', 'Requirement risk = worst risk among the linked management items.', 'Riesgo del requisito = peor riesgo entre los ítems de gestión vinculados.'))
    . HomeModulo::grafico($T('g.unidade', 'Por unidade', 'By unit', 'Por unidad'), 'bi-buildings', HomeModulo::barras($G, $barUn, 15) . $legSit)
    . HomeModulo::grafico($T('g.responsavel', 'Por responsável', 'By owner', 'Por responsable'), 'bi-person', HomeModulo::barras($G, $barResp, 12) . $legSit,
        $T('g.resp_nota', 'Um requisito com vários responsáveis conta para cada um. Os 11 com mais requisitos; o resto em Outros.', 'A requirement with several owners counts for each. Top 11; the rest in Others.', 'Un requisito con varios responsables cuenta para cada uno. Los 11 primeros; el resto en Otros.'))
    . HomeModulo::grafico($T('g.ambito', 'Por origem', 'By origin', 'Por origen'), 'bi-globe2', HomeModulo::barras($G, $barAmb) . $legSit)
    . HomeModulo::grafico($T('g.poder', 'Por nível de controle', 'By control level', 'Por nivel de control'), 'bi-sliders', HomeModulo::barras($G, $barPod) . $legSit)
    . ($barArea ? HomeModulo::grafico($T('g.area', 'Por área', 'By area', 'Por área'), 'bi-diagram-3', HomeModulo::barras($G, $barArea) . $legSit,
        $T('g.area_nota', 'Uma lei pode valer para mais de uma área.', 'A law may apply to more than one area.', 'Una ley puede valer para más de un área.')) : '')
    . HomeModulo::grafico($T('g.meses', 'Próximas avaliações (12 meses)', 'Upcoming assessments (12 months)', 'Próximas evaluaciones (12 meses)'), 'bi-calendar3', HomeModulo::colunas($G, $col),
        $depois ? $depois . ' ' . $T('g.depois', 'vencem depois de 12 meses.', 'are due after 12 months.', 'vencen después de 12 meses.') : '')
    . '</div>';

// ---------------------------------------------------------------- cartões
$avaliados = count($req) - $cont['NA'];
$taxa = $avaliados > 0 ? round(100 * $cont['ATENDIDO'] / $avaliados) : null;
$cards = [
    ['rotulo' => $T('c.total', 'Requisitos legais', 'Legal requirements', 'Requisitos legales'), 'valor' => count($req), 'icone' => 'bi-journal-text', 'tom' => 'blue', 'grupo' => '',
        'extra' => $taxa !== null ? $T('c.taxa', 'Taxa de conformidade', 'Compliance rate', 'Tasa de conformidad') . ': ' . $taxa . '%' : ''],
];
foreach ($SIT as $k => [$rot, $tom, $ic]) $cards[] = ['rotulo' => $rot, 'valor' => $cont[$k], 'icone' => $ic, 'tom' => $tom, 'grupo' => 'S_' . $k];
$cards[] = ['rotulo' => $T('c.av_vencida', 'Avaliação vencida', 'Assessment overdue', 'Evaluación vencida'), 'valor' => $avVencida, 'icone' => 'bi-calendar-x', 'tom' => $avVencida ? 'red' : 'green', 'grupo' => 'AV_VENCIDA'];
$cards[] = ['rotulo' => $T('c.av_30', 'Avaliação vence em 30 dias', 'Assessment due in 30 days', 'Evaluación vence en 30 días'), 'valor' => $av30, 'icone' => 'bi-alarm', 'tom' => 'orange', 'grupo' => 'AV_30'];
$cards[] = ['rotulo' => $T('c.critico', 'Risco crítico ou alto', 'Critical or high risk', 'Riesgo crítico o alto'), 'valor' => $contR['CRITICO'] + $contR['ALTO'], 'icone' => 'bi-exclamation-octagon', 'tom' => 'red', 'grupo' => 'K_CA',
    'extra' => $contR['CRITICO'] . ' ' . $T('c.criticos', 'crítico(s)', 'critical', 'crítico(s)') . ' · ' . $contR['ALTO'] . ' ' . $T('c.altos', 'alto(s)', 'high', 'alto(s)')];
$cards[] = ['rotulo' => $T('c.pend_crit', 'Com pendência crítica', 'With critical pending item', 'Con pendencia crítica'), 'valor' => $crit, 'icone' => 'bi-fire', 'tom' => $crit ? 'red' : 'green', 'grupo' => 'CRIT'];
$cards[] = ['rotulo' => $T('c.divergencia', 'Avaliação manual diverge da calculada', 'Manual differs from calculated', 'Manual difiere de la calculada'), 'valor' => $div, 'icone' => 'bi-arrow-left-right', 'tom' => $div ? 'purple' : 'green', 'grupo' => 'DIV'];
$cards[] = ['rotulo' => $T('c.sem_resp', 'Sem responsável', 'No owner', 'Sin responsable'), 'valor' => $semResp, 'icone' => 'bi-person-x', 'tom' => $semResp ? 'orange' : 'green', 'grupo' => 'SEMRESP'];

$filtrosHtml = HomeModulo::filtros([
    'u' => ['rotulo' => $T('f.unidade', 'Unidade', 'Unit', 'Unidad'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $unidades],
    'r' => ['rotulo' => $T('f.responsavel', 'Responsável', 'Owner', 'Responsable'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $responsaveis],
    'd' => ['rotulo' => $T('f.poder', 'Nível de controle', 'Control level', 'Nivel de control'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $poderes],
    'a' => ['rotulo' => $T('f.ambito', 'Origem', 'Origin', 'Origen'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $ambitos],
    'ar' => ['rotulo' => $T('f.area', 'Área', 'Area', 'Área'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => array_map(static fn($x) => $x[1], $AREAS)],
    'rv' => ['rotulo' => $T('f.revogadas', 'Leis revogadas', 'Repealed laws', 'Leyes derogadas'), 'opcoes' => ['' => $T('f.rv_nao', 'Sem as revogadas', 'Without repealed', 'Sin las derogadas'), 'todas' => $T('f.rv_todas', 'Com as revogadas', 'Including repealed', 'Con las derogadas'), 'revogadas' => $T('f.rv_so', 'Só as revogadas', 'Repealed only', 'Solo las derogadas')]],
    'p' => ['rotulo' => $T('f.periodo', 'Próxima avaliação', 'Next assessment', 'Próxima evaluación'), 'opcoes' => HomeModulo::periodos()],
    'de' => ['rotulo' => $T('f.de', 'De', 'From', 'Desde'), 'tipo' => 'date', 'oculto' => $f['p'] !== 'datas'],
    'ate' => ['rotulo' => $T('f.ate', 'Até', 'To', 'Hasta'), 'tipo' => 'date', 'oculto' => $f['p'] !== 'datas'],
], $f, ['mes' => $mes !== date('Y-m') ? $mes : '', 'rcl' => $rcl]);

$abas = DashboardGrid::subabas('hgAbas', [
    ['id' => 'graficos', 'icone' => 'bi-bar-chart', 'rotulo' => $T('sub.graficos', 'Gráficos', 'Charts', 'Gráficos'), 'html' => $graficos],
    ['id' => 'lista', 'icone' => 'bi-table', 'rotulo' => $T('sub.lista', 'Lista', 'List', 'Lista'), 'contagem' => count($req), 'html' => $gridReq],
    ['id' => 'calendario', 'icone' => 'bi-calendar3', 'rotulo' => $T('sub.calendario', 'Calendário das avaliações', 'Assessment calendar', 'Calendario de evaluaciones'), 'contagem' => count($rowsCal), 'html' => $calendario],
    ['id' => 'rcl', 'icone' => 'bi-arrow-repeat', 'rotulo' => $T('sub.rcl', 'Atualização do mês', 'Monthly update', 'Actualización del mes') . ($rclMes !== '' ? ' (' . HomeModulo::mesCurto($rclMes) . ')' : ''),
        'contagem' => array_sum($cRcl) ?: null, 'html' => $rclHtml],
    ['id' => 'criticas', 'icone' => 'bi-fire', 'rotulo' => $T('sub.criticas', 'Pendências críticas', 'Critical pending items', 'Pendencias críticas'), 'contagem' => count($rowsPc), 'html' => $pcHtml],
], true);

$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$titulo = HomeModulo::MENUS['LEGAL']['nome'][$idioma] ?? HomeModulo::MENUS['LEGAL']['nome']['pt'];
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php HomeModulo::head(); ?>
</head>
<body class="theme-<?= $e($theme) ?>" data-transacao="home_legal"><main class="hm">
<?= HomeModulo::cabecalho('bi-journal-check', $titulo,
    $e($T('desc', 'Situação de atendimento dos requisitos legais, risco, próximas avaliações e a atualização do mês. Os filtros do topo valem para a página toda; clique num cartão, numa barra do gráfico ou num dia do calendário para listar os requisitos. Duplo clique na linha abre o requisito.',
        'Compliance status of the legal requirements, risk, upcoming assessments and the monthly update. The filters at the top apply to the whole page; click a card, a chart bar or a calendar day to list the requirements. Double-click a row to open it.',
        'Situación de atención de los requisitos legales, riesgo, próximas evaluaciones y la actualización del mes. Los filtros valen para toda la página; haga clic en una tarjeta, una barra o un día para listar. Doble clic abre el requisito.')), $aviso) ?>
<?= $filtrosHtml ?>
<?= HomeModulo::cartoes($G, $cards) ?>
<?= $abas ?>
</main>
<?php HomeModulo::scripts(); ?>
</body></html>
