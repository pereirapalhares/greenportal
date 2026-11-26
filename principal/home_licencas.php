<?php
declare(strict_types=1);

/*
 * Home de Licenças (migração 381; GER-1 Home por módulo, GER-4 gráfico + tabela com clique, GER-13 calendário por módulo).
 * Primeira Home feita com o modelo único (classes/HomeModulo.php); as dos outros módulos seguem o mesmo formato.
 *  - Filtros no topo (unidade, responsável, tipo, vencimento): valem para cartões, gráficos, lista, calendário, condicionantes e taxas
 *    (o vencimento não vale no calendário, que tem o próprio mês).
 *  - Cartões por faixa de vencimento (as mesmas do Dashboard da empresa, vw_dash_licencas — migrações 285/288): o clique lista abaixo.
 *  - Sub-abas: Gráficos (situação, vencimentos em 12 meses, unidade, responsável, tipo documental, classificação, órgão emissor) —
 *    cada barra filtra a Lista —, Lista (grid padrão; abre a licença), Calendário do mês (validade, início da renovação, taxas e
 *    ações das condicionantes), Condicionantes e planos, Taxas (quando houver).
 * Quem tem "Visualizar total" em Licenças vê a empresa toda; os demais, só as licenças sob a própria responsabilidade
 * (vw_minhas_licencas_grid: responsável, atribuições e regras da 361).
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
$T = static fn(string $c, string $pt, string $en, string $es): string => HomeModulo::t('lic.' . $c, $pt, $en, $es);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);

$perm = static fn(string $menu): array => PermissaoTransacao::obter($menu);
$pHome = $perm('HOME_LICENCAS'); $pLic = $perm('LICENCAS'); $pMinhas = $perm('MINHAS_LICENCAS');
if (empty($pHome['pode_visualizar']) && empty($pLic['pode_visualizar']) && empty($pMinhas['pode_visualizar']))
    $sair(403, $T('negado', 'Você não tem acesso às Licenças.', 'You do not have access to Licenses.', 'No tiene acceso a las Licencias.'));
$total = !empty($pHome['pode_visualizar_total']) || !empty($pLic['pode_visualizar_total']);

// ---------------------------------------------------------------- dados
$todas = HomeModulo::ler($pdo, 'vw_dash_licencas');
if ($todas === null) $sair(503, $T('sem_migracao', 'Os indicadores de licenças ainda não existem nesta empresa (migrações 285/288).', 'License indicators are not available for this company (migrations 285/288).', 'Los indicadores de licencias no existen en esta empresa (migraciones 285/288).'));
$extra = [];
foreach (HomeModulo::ler($pdo, 'vw_licencas_grid') ?? [] as $g) $extra[(int)$g['CODLICENCA']] = $g;
$orgaos = [];
foreach (HomeModulo::ler($pdo, 'vw_li_licencas_completo') ?? [] as $g) if (array_key_exists('ORGAO_EMISSOR', $g)) $orgaos[(int)$g['CODLICENCA']] = trim((string)$g['ORGAO_EMISSOR']);
$nomeClass = [];
foreach (HomeModulo::ler($pdo, 'li_classificacao') ?? [] as $c) $nomeClass[(string)($c['CODCLASSIFICACAO'] ?? $c['codclassificacao'] ?? '')] = trim((string)($c['DSCCLASSIFICACAO'] ?? $c['dscclassificacao'] ?? ''));

$aviso = '';
if (!$total) {
    $minhas = [];
    foreach (HomeModulo::ler($pdo, 'vw_minhas_licencas_grid', ['CODUSUARIO' => ['value' => (string)$uid, 'exact' => true]]) ?? [] as $m) $minhas[(int)$m['CODLICENCA']] = true;
    $todas = array_values(array_filter($todas, static fn($l) => isset($minhas[(int)$l['CODLICENCA']]) || (int)$l['CODUSUARIO'] === $uid));
    $aviso = $T('so_minhas', 'Mostrando só as licenças sob a sua responsabilidade (responsável, atribuições e regras).', 'Showing only the licenses under your responsibility.', 'Mostrando solo las licencias bajo su responsabilidad.');
}

// ---------------------------------------------------------------- filtros
$hoje = date('Y-m-d');
$f = ['u' => (string)($_GET['u'] ?? ''), 'r' => (string)($_GET['r'] ?? ''), 'n' => (string)($_GET['n'] ?? ''), 'p' => (string)($_GET['p'] ?? ''),
    'de' => HomeModulo::data($_GET['de'] ?? '') ?? '', 'ate' => HomeModulo::data($_GET['ate'] ?? '') ?? ''];
if (!isset(HomeModulo::periodos()[$f['p']])) $f['p'] = '';
if ($f['p'] !== 'datas') { $f['de'] = ''; $f['ate'] = ''; }
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$url = static function (array $mudar = []) use ($f, $mes): string {
    $q = array_filter($mudar + $f + ['mes' => $mes !== date('Y-m') ? $mes : ''], static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};

$unidades = []; $responsaveis = [];
foreach ($todas as $l) {
    $unidades[(string)$l['CODUNIDADE']] = (string)$l['UNIDADE'] !== '' ? (string)$l['UNIDADE'] : '#' . $l['CODUNIDADE'];
    if ((int)$l['CODUSUARIO'] > 0) $responsaveis[(string)(int)$l['CODUSUARIO']] = (string)$l['RESPONSAVEL'] !== '' ? (string)$l['RESPONSAVEL'] : '#' . $l['CODUSUARIO'];
}
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($responsaveis, SORT_NATURAL | SORT_FLAG_CASE);
$responsaveis = ['0' => $T('sem_resp', '(sem responsável)', '(no owner)', '(sin responsable)')] + $responsaveis;

// base = unidade + responsável + tipo (vale para calendário, condicionantes e taxas); lista = base + vencimento
$base = array_values(array_filter($todas, static fn($l) => ($f['u'] === '' || (string)$l['CODUNIDADE'] === $f['u'])
    && ($f['r'] === '' || (string)(int)$l['CODUSUARIO'] === $f['r']) && ($f['n'] === '' || (string)$l['NATUREZA'] === $f['n'])));
$lic = array_values(array_filter($base, static fn($l) => HomeModulo::noPeriodo(HomeModulo::data($l['DATA_VALIDADE']), $f['p'], $f['de'] ?: null, $f['ate'] ?: null)));
$idsBase = array_fill_keys(array_map(static fn($l) => (int)$l['CODLICENCA'], $base), true);
$porCod = []; foreach ($todas as $l) $porCod[(int)$l['CODLICENCA']] = $l;

// ---------------------------------------------------------------- classificações
$FAIXAS = [
    'VENCIDA' => [$T('fx.vencida', 'Vencidas', 'Expired', 'Vencidas'), 'bi-calendar-x', 'red'],
    'VENCE_30' => [$T('fx.30', 'Vencem em até 30 dias', 'Expire within 30 days', 'Vencen en hasta 30 días'), 'bi-alarm', 'orange'],
    'VENCE_60' => [$T('fx.60', 'Vencem em 31 a 60 dias', 'Expire in 31–60 days', 'Vencen en 31 a 60 días'), 'bi-calendar-event', 'yellow'],
    'VENCE_90' => [$T('fx.90', 'Vencem em 61 a 90 dias', 'Expire in 61–90 days', 'Vencen en 61 a 90 días'), 'bi-calendar3', 'teal'],
    'VIGENTE' => [$T('fx.vigente', 'Vigentes (mais de 90 dias)', 'Valid (over 90 days)', 'Vigentes (más de 90 días)'), 'bi-check-circle', 'green'],
    'EM_PROTOCOLO' => [$T('fx.protocolo', 'Válidas em protocolo', 'Valid, renewal filed', 'Válidas en protocolo'), 'bi-hourglass-split', 'blue'],
    'PROT_SEM_PRAZO' => [$T('fx.prot_sem', 'Em protocolo (prazo não informado)', 'Renewal filed (no deadline)', 'En protocolo (sin plazo)'), 'bi-hourglass-bottom', 'orange'],
    'SEM_VALIDADE' => [$T('fx.sem', 'Sem data de validade', 'No expiry date', 'Sin fecha de validez'), 'bi-question-circle', 'gray'],
    'NAO_VENCE' => [$T('fx.nao_vence', 'Sem validade (não vencem)', 'No expiry (do not expire)', 'Sin validez (no vencen)'), 'bi-infinity', 'green'],
    'ENTREGA_ATRASADA' => [$T('fx.ent_atr', 'Entregas periódicas atrasadas', 'Overdue periodic submissions', 'Entregas periódicas atrasadas'), 'bi-send-x', 'red'],
    'ENTREGA_30' => [$T('fx.ent_30', 'Entregas em até 30 dias', 'Submissions due in 30 days', 'Entregas en hasta 30 días'), 'bi-send', 'orange'],
    'ENTREGA_EM_DIA' => [$T('fx.ent_dia', 'Entregas em dia', 'Submissions on schedule', 'Entregas al día'), 'bi-send-check', 'green'],
];
$SIT = [
    'VALIDA' => [$T('sit.valida', 'Válida', 'Valid', 'Válida'), 'green'], 'PROTOCOLO' => [$T('sit.protocolo', 'Válida (em protocolo)', 'Valid (renewal filed)', 'Válida (en protocolo)'), 'blue'],
    'PROT_SEM_PRAZO' => [$T('sit.prot_sem', 'Em protocolo (sem prazo)', 'Renewal filed (no deadline)', 'En protocolo (sin plazo)'), 'orange'],
    'VENCIDA' => [$T('sit.vencida', 'Vencida', 'Expired', 'Vencida'), 'red'], 'SEM_DATA' => [$T('sit.sem_data', 'Sem data de vencimento', 'No due date', 'Sin fecha de vencimiento'), 'gray'], 'NAO_VENCE' => [$T('sit.nao_vence', 'Sem validade (não vence)', 'No expiry', 'Sin validez'), 'green'],
    'ENTREGA' => [$T('sit.entrega', 'Entrega periódica', 'Periodic submission', 'Entrega periódica'), 'teal'],
];
// categoria de prazo (cores das barras empilhadas)
$CAT = ['VENC' => [$T('cat.venc', 'Vencidas / atrasadas', 'Expired / overdue', 'Vencidas / atrasadas'), 'red'], 'PROX' => [$T('cat.prox', 'Vencem em até 90 dias', 'Due within 90 days', 'Vencen en hasta 90 días'), 'orange'],
    'PROT' => [$T('cat.prot', 'Em protocolo', 'Renewal filed', 'En protocolo'), 'blue'], 'OK' => [$T('cat.ok', 'Em dia', 'On schedule', 'Al día'), 'green'], 'SEM' => [$T('cat.sem', 'Sem data', 'No date', 'Sin fecha'), 'gray']];
$cat = static fn(string $fx): string => match ($fx) {
    'VENCIDA', 'ENTREGA_ATRASADA' => 'VENC', 'VENCE_30', 'VENCE_60', 'VENCE_90', 'ENTREGA_30' => 'PROX', 'EM_PROTOCOLO', 'PROT_SEM_PRAZO' => 'PROT', 'SEM_VALIDADE' => 'SEM', default => 'OK' };
$naoInf = $T('nao_informado', '(não informado)', '(not informed)', '(no informado)');
$tipoDe = static fn(array $l): string => trim((string)($l['TIPO_DOCUMENTAL'] ?? '')) !== '' ? trim((string)$l['TIPO_DOCUMENTAL']) : trim((string)($extra[(int)$l['CODLICENCA']]['DSCTIPODOCUMENTO'] ?? ''));
$classDe = static function (array $l) use ($extra, $nomeClass): array {
    $c = (string)($extra[(int)$l['CODLICENCA']]['CODCLASSIFICACAO'] ?? '');
    return [$c, ($c !== '' && $c !== '0') ? (($nomeClass[$c] ?? '') !== '' ? $nomeClass[$c] : 'nº ' . $c) : ''];
};

// ---------------------------------------------------------------- lista
$abrirLic = static fn(array $l): array => ['url' => 'manutencaolegal_licencas.php?' . http_build_query(['filtros' => json_encode(['CODLICENCA' => (int)$l['CODLICENCA']])]),
    'title' => $T('titulo_lic', 'Licenças', 'Licenses', 'Licencias') . ' - ' . mb_strimwidth((string)$l['LICENCA'], 0, 70, '…')];
$linhas = []; $cont = array_fill_keys(array_keys($FAIXAS), 0); $contSit = array_fill_keys(array_keys($SIT), 0); $meses = []; $antes = 0;
$porUn = []; $porResp = []; $porTipo = []; $porClass = []; $porOrgao = [];
foreach ($lic as $l) {
    $cod = (int)$l['CODLICENCA']; $fx = (string)$l['FAIXA']; $s = (string)$l['SITUACAO_LICENCA']; $val = HomeModulo::data($l['DATA_VALIDADE']); $c = $cat($fx);
    if (isset($cont[$fx])) $cont[$fx]++;
    if (isset($contSit[$s])) $contSit[$s]++;
    $tipo = $tipoDe($l); [$cc, $cn] = $classDe($l); $org = $orgaos[$cod] ?? '';
    $un = (string)$l['CODUNIDADE']; $rp = (string)(int)$l['CODUSUARIO'];
    $grupos = [HomeModulo::grupo('F', $fx), HomeModulo::grupo('S', $s), HomeModulo::grupo('U', $un), HomeModulo::grupo('R', $rp), HomeModulo::grupo('T', $tipo === '' ? 'NA' : md5($tipo)),
        HomeModulo::grupo('C', $cc === '' || $cc === '0' ? 'NA' : $cc), HomeModulo::grupo('O', $org === '' ? 'NA' : md5($org)), 'K_' . $c];
    if (in_array($fx, ['EM_PROTOCOLO', 'PROT_SEM_PRAZO'], true)) $grupos[] = 'F_PROTOCOLOS';
    if ($val !== null && $val < $hoje && $c === 'VENC') { $grupos[] = 'M_ANTES'; $antes++; }
    elseif ($val !== null) { $grupos[] = 'M_' . substr($val, 0, 7); $meses[substr($val, 0, 7)] = ($meses[substr($val, 0, 7)] ?? 0) + 1; }
    $porUn[$un]['n'] = ($porUn[$un]['n'] ?? 0) + 1; $porUn[$un][$c] = ($porUn[$un][$c] ?? 0) + 1;
    $porResp[$rp]['n'] = ($porResp[$rp]['n'] ?? 0) + 1; $porResp[$rp][$c] = ($porResp[$rp][$c] ?? 0) + 1;
    $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1; $porClass[$cc === '0' ? '' : $cc] = ($porClass[$cc === '0' ? '' : $cc] ?? 0) + 1; $porOrgao[$org] = ($porOrgao[$org] ?? 0) + 1;
    [$sRot, $sTom] = $SIT[$s] ?? [(string)$l['SITUACAO_LICENCA_DESC'], 'gray'];
    $prot = HomeModulo::data($l['PROTOCOLADO_EM']);
    $linhas[] = ['cells' => [
        'UNIDADE' => (string)$l['UNIDADE'],
        'LICENCA' => ['text' => (string)$l['LICENCA'], 'title' => (string)$l['LICENCA']],
        'TIPO' => (string)$l['NATUREZA'] === 'ENTREGA_PERIODICA' ? $T('nat.entrega', 'Entrega periódica', 'Periodic submission', 'Entrega periódica') . ($tipo !== '' ? ' · ' . $tipo : '') : ($tipo !== '' ? $tipo : $T('nat.licenca', 'Licença', 'License', 'Licencia')),
        'SITUACAO' => ['text' => $sRot, 'html' => '<span class="hm-sit tom-' . $sTom . '" title="' . $e($l['MOTIVO_SITUACAO'] ?? '') . '">' . $e($sRot) . '</span>', 'sort' => $sRot],
        'FAIXA' => ['text' => $FAIXAS[$fx][0] ?? $fx, 'html' => '<span class="hm-sit tom-' . ($FAIXAS[$fx][2] ?? 'gray') . '">' . $e($FAIXAS[$fx][0] ?? $fx) . '</span>', 'sort' => $FAIXAS[$fx][0] ?? $fx],
        'VALIDADE' => DashboardGrid::prazo($val ?? ''),
        'REACAO' => ['text' => HomeModulo::dataTexto(HomeModulo::data($l['DATA_REACAO'])), 'sort' => HomeModulo::data($l['DATA_REACAO']) ?? '9999-12-31'],
        'PROTOCOLO' => ['text' => trim(HomeModulo::dataTexto($prot) . ((string)($l['NUMERO_PROTOCOLO'] ?? '') !== '' ? ' · nº ' . $l['NUMERO_PROTOCOLO'] : '')), 'sort' => $prot ?? ''],
        'RESPONSAVEL' => (string)$l['RESPONSAVEL'],
        'CLASSIFICACAO' => $cn,
        'ORGAO' => $org,
    ], 'open' => $abrirLic($l), 'grupos' => implode(' ', $grupos)];
}
$colsLic = [
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '150px'],
    'LICENCA' => ['label' => $T('col.licenca', 'Licença / documento', 'License / document', 'Licencia / documento'), 'width' => '320px'],
    'TIPO' => ['label' => $T('col.tipo', 'Tipo', 'Type', 'Tipo'), 'filter' => 'select', 'width' => '140px'],
    'SITUACAO' => ['label' => $T('col.situacao', 'Situação', 'Status', 'Situación'), 'filter' => 'select', 'width' => '150px'],
    'FAIXA' => ['label' => $T('col.faixa', 'Prazo', 'Deadline', 'Plazo'), 'filter' => 'select', 'width' => '170px'],
    'VALIDADE' => ['label' => $T('col.validade', 'Validade', 'Expiry', 'Validez'), 'width' => '110px'],
    'REACAO' => ['label' => $T('col.reacao', 'Início da renovação', 'Renewal start', 'Inicio de la renovación'), 'width' => '120px'],
    'PROTOCOLO' => ['label' => $T('col.protocolo', 'Protocolo', 'Renewal filed', 'Protocolo'), 'width' => '150px'],
    'RESPONSAVEL' => ['label' => $T('col.responsavel', 'Responsável', 'Owner', 'Responsable'), 'filter' => 'select', 'width' => '170px'],
    'CLASSIFICACAO' => ['label' => $T('col.classificacao', 'Classificação', 'Classification', 'Clasificación'), 'filter' => 'select', 'width' => '150px'],
];
if (array_filter($orgaos)) $colsLic['ORGAO'] = ['label' => $T('col.orgao', 'Órgão emissor', 'Issuing agency', 'Órgano emisor'), 'filter' => 'select', 'width' => '150px'];
$gridLic = HomeModulo::grid('hlLic', $colsLic, $linhas, ['transacao' => 'home_licencas', 'ordem' => ['VALIDADE', 'asc'], 'por_pagina' => 50,
    'vazio' => $T('lista.vazia', 'Nenhuma licença com estes filtros.', 'No license with these filters.', 'Ninguna licencia con estos filtros.')]);

// ---------------------------------------------------------------- condicionantes e planos (base: sem o filtro de vencimento)
$planos = HomeModulo::ler($pdo, 'vw_licencas_condicionantes_planodeacao_grid') ?? [];
$conds = HomeModulo::ler($pdo, 'li_condicionantes') ?? [];
$condLic = [];
foreach ($conds as $c) $condLic[(int)($c['CODCONDICIONANTE'] ?? 0)] = (int)($c['CODLICENCA'] ?? 0);
$conds = array_values(array_filter($conds, static fn($c) => isset($idsBase[(int)($c['CODLICENCA'] ?? 0)])));
$planos = array_values(array_filter($planos, static fn($p) => isset($idsBase[(int)($p['CODLICENCA'] ?: ($condLic[(int)$p['CODCONDICIONANTE']] ?? 0))])));
$nomeLic = static fn(int $cod): string => isset($porCod[$cod]) ? (string)$porCod[$cod]['LICENCA'] : '#' . $cod;
$unLic = static fn(int $cod): string => isset($porCod[$cod]) ? (string)$porCod[$cod]['UNIDADE'] : '';
$cAt = ['Sim' => 0, 'Não' => 0, '' => 0]; $rowsCond = [];
foreach ($conds as $c) {
    $at = trim((string)($c['ATENDIDO'] ?? '')); $k = in_array(mb_strtolower($at), ['sim', 's'], true) ? 'Sim' : (in_array(mb_strtolower($at), ['não', 'nao', 'n'], true) ? 'Não' : '');
    $cAt[$k]++; $codL = (int)$c['CODLICENCA'];
    $rowsCond[] = ['cells' => ['UNIDADE' => $unLic($codL), 'LICENCA' => ['text' => $nomeLic($codL), 'title' => $nomeLic($codL)],
        'CONDICIONANTE' => ['text' => mb_strimwidth(trim((string)($c['DSCCONDICIONANTE'] ?? '')), 0, 300, '…'), 'title' => trim((string)($c['DSCCONDICIONANTE'] ?? ''))],
        'FREQUENCIA' => (string)($c['FREQUENCIA'] ?? ''),
        'ATENDIDO' => ['text' => $k === '' ? $T('cond.sem', 'Sem avaliação', 'Not assessed', 'Sin evaluación') : $k, 'html' => '<span class="hm-sit tom-' . ($k === 'Sim' ? 'green' : ($k === 'Não' ? 'red' : 'gray')) . '">' . $e($k === '' ? $T('cond.sem', 'Sem avaliação', 'Not assessed', 'Sin evaluación') : $k) . '</span>'],
    ], 'open' => ['url' => 'manutencaolegal_licencas_condicionantes.php?' . http_build_query(['filtros' => json_encode(['CODLICENCA' => (string)$codL])]), 'title' => $T('titulo_cond', 'Condicionantes', 'Conditions', 'Condicionantes') . ' - ' . mb_strimwidth($nomeLic($codL), 0, 60, '…')],
        'grupos' => 'AT_' . ($k === 'Sim' ? 'SIM' : ($k === 'Não' ? 'NAO' : 'NA'))];
}
$pAb = 0; $pAtr = 0; $rowsPlano = []; $eventos = []; $rowsCal = [];
foreach ($planos as $p) {
    $st = (int)$p['STATUS']; $fim = HomeModulo::data($p['DATAFINAL']); $aberta = $st < 100; $atr = $aberta && $fim !== null && $fim < $hoje;
    if ($aberta) $pAb++; if ($atr) $pAtr++;
    $codL = (int)($p['CODLICENCA'] ?: ($condLic[(int)$p['CODCONDICIONANTE']] ?? 0));
    $open = ['url' => 'manutencaolegal_licencas_condicionantesplanodeacao.php?' . http_build_query(['filtros' => json_encode(['CODCONDICIONANTE' => (string)(int)$p['CODCONDICIONANTE']])]), 'title' => $T('titulo_plano', 'Plano de ação', 'Action plan', 'Plan de acción') . ' - ' . mb_strimwidth($nomeLic($codL), 0, 60, '…')];
    $rowsPlano[] = ['cells' => ['LICENCA' => ['text' => $nomeLic($codL), 'title' => $nomeLic($codL)], 'ACAO' => ['text' => mb_strimwidth(trim((string)$p['ACAO']), 0, 300, '…'), 'title' => trim((string)$p['ACAO'])],
        'RESPONSAVEL' => (string)($p['RESPONSAVEL'] ?? ''), 'DATAFINAL' => DashboardGrid::prazo($aberta ? ($fim ?? '') : ($fim ?? ''), $aberta), 'STATUS' => ['text' => $st . '%', 'sort' => sprintf('%03d', $st)],
        'EFICACIA' => (string)($p['EFICACIA_SITUACAO'] ?? ($p['EFICACIA'] ?? ''))], 'open' => $open, 'grupos' => ($aberta ? 'ABERTA' : 'CONCLUIDA') . ($atr ? ' ATRASADA' : '')];
    if ($aberta && $fim !== null) $eventos[] = ['data' => $fim, 'tom' => 'purple', 'rotulo' => $T('ev.acao', 'Ação', 'Action', 'Acción') . ': ' . trim((string)$p['ACAO']), 'tipo' => $T('ev.acao_cond', 'Ação de condicionante', 'Condition action', 'Acción de condicionante'),
        'item' => trim((string)$p['ACAO']), 'unidade' => $unLic($codL), 'resp' => (string)($p['RESPONSAVEL'] ?? ''), 'open' => $open];
}
$rotAberta = $T('pl.abertas', 'Ações abertas', 'Open actions', 'Acciones abiertas');
$gridCond = HomeModulo::grid('hlCond', [
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
    'LICENCA' => ['label' => $T('col.licenca', 'Licença / documento', 'License / document', 'Licencia / documento'), 'width' => '260px'],
    'CONDICIONANTE' => ['label' => $T('col.condicionante', 'Condicionante', 'Condition', 'Condicionante'), 'width' => '380px'],
    'FREQUENCIA' => ['label' => $T('col.frequencia', 'Frequência', 'Frequency', 'Frecuencia'), 'filter' => 'select', 'width' => '110px'],
    'ATENDIDO' => ['label' => $T('col.atende', 'Atende?', 'Complies?', '¿Atiende?'), 'filter' => 'select', 'width' => '110px'],
], $rowsCond, ['transacao' => 'home_licencas', 'por_pagina' => 50, 'vazio' => $T('cond.vazio', 'Nenhuma condicionante.', 'No conditions.', 'Ninguna condicionante.')]);
$gridPlano = HomeModulo::grid('hlPlanos', [
    'LICENCA' => ['label' => $T('col.licenca', 'Licença / documento', 'License / document', 'Licencia / documento'), 'width' => '240px'],
    'ACAO' => ['label' => $T('col.acao', 'Ação', 'Action', 'Acción'), 'width' => '360px'],
    'RESPONSAVEL' => ['label' => $T('col.responsavel', 'Responsável', 'Owner', 'Responsable'), 'filter' => 'select', 'width' => '160px'],
    'DATAFINAL' => ['label' => $T('col.data_final', 'Data final', 'Due date', 'Fecha final'), 'width' => '110px'],
    'STATUS' => ['label' => $T('col.status', 'Status', 'Status', 'Estado'), 'filter' => 'select', 'width' => '80px'],
    'EFICACIA' => ['label' => $T('col.eficacia', 'Eficácia', 'Effectiveness', 'Eficacia'), 'filter' => 'select', 'width' => '130px'],
], $rowsPlano, ['transacao' => 'home_licencas', 'ordem' => ['DATAFINAL', 'asc'], 'grupo_inicial' => $pAb ? ['ABERTA', $rotAberta] : [],
    'vazio' => $T('pl.vazio', 'Nenhuma ação de plano.', 'No action plan items.', 'Ninguna acción de plan.')]);

// ---------------------------------------------------------------- taxas (base)
$taxas = HomeModulo::ler($pdo, 'vw_dash_li_taxas') ?? [];
$taxas = array_values(array_filter($taxas, static fn($x) => isset($idsBase[(int)($x['CODLICENCA_ATUAL'] ?: $x['CODLICENCA'])])));
$TX = ['VENCIDA' => [$T('tx.vencida', 'Taxas vencidas sem pagamento', 'Overdue unpaid fees', 'Tasas vencidas sin pago'), 'red', 'bi-cash-coin'],
    'VENCE_30' => [$T('tx.30', 'Taxas que vencem em 30 dias', 'Fees due in 30 days', 'Tasas que vencen en 30 días'), 'orange', 'bi-alarm'],
    'A_PAGAR' => [$T('tx.pagar', 'Taxas a pagar', 'Fees to pay', 'Tasas a pagar'), 'blue', 'bi-receipt'],
    'SEM_VENC' => [$T('tx.sem', 'Taxas sem vencimento', 'Fees with no due date', 'Tasas sin vencimiento'), 'gray', 'bi-question-circle'],
    'PAGA' => [$T('tx.paga', 'Pagas no ano', 'Paid this year', 'Pagadas en el año'), 'green', 'bi-check2-circle']];
$cTx = array_fill_keys(array_keys($TX), 0); $rowsTx = [];
foreach ($taxas as $x) {
    $s = (string)$x['SITUACAO_TAXA']; $k = isset($TX[$s]) ? $s : 'A_PAGAR'; $cTx[$k]++;
    $venc = HomeModulo::data($x['VENCIMENTO']);
    $open = ['url' => 'manutencaolegal_licencas_taxas.php?' . http_build_query(['codtaxa' => (int)$x['CODTAXA']]), 'title' => $T('titulo_taxa', 'Taxa', 'Fee', 'Tasa') . ' - ' . mb_strimwidth((string)$x['LICENCA'], 0, 60, '…')];
    $rowsTx[] = ['cells' => ['UNIDADE' => (string)$x['UNIDADE'], 'LICENCA' => ['text' => (string)$x['LICENCA'], 'title' => (string)$x['LICENCA']], 'TIPO' => (string)$x['TIPO_DESC'],
        'DESCRICAO' => (string)$x['DESCRICAO'], 'VALOR' => ['text' => (string)$x['VALOR'] !== '' ? 'R$ ' . $x['VALOR'] : '', 'sort' => sprintf('%015.2f', (float)$x['VALOR_NUM'])],
        'VENCIMENTO' => DashboardGrid::prazo($k === 'PAGA' ? '' : ($venc ?? ''), $k !== 'PAGA'), 'SITUACAO' => (string)$x['SITUACAO_TAXA_DESC']], 'open' => $open, 'grupos' => 'TX_' . $k];
    if ($k !== 'PAGA' && $venc !== null) $eventos[] = ['data' => $venc, 'tom' => 'yellow', 'rotulo' => $T('ev.taxa', 'Taxa', 'Fee', 'Tasa') . ': ' . $x['LICENCA'], 'tipo' => $T('ev.taxa_venc', 'Vencimento de taxa', 'Fee due', 'Vencimiento de tasa'),
        'item' => trim($x['TIPO_DESC'] . ' ' . $x['DESCRICAO']) . ' — ' . $x['LICENCA'], 'unidade' => (string)$x['UNIDADE'], 'resp' => (string)$x['RESPONSAVEL'], 'open' => $open];
}
$gridTx = $taxas ? HomeModulo::grid('hlTaxas', [
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
    'LICENCA' => ['label' => $T('col.licenca', 'Licença / documento', 'License / document', 'Licencia / documento'), 'width' => '260px'],
    'TIPO' => ['label' => $T('col.tipo', 'Tipo', 'Type', 'Tipo'), 'filter' => 'select', 'width' => '120px'],
    'DESCRICAO' => ['label' => $T('col.descricao', 'Descrição', 'Description', 'Descripción'), 'width' => '200px'],
    'VALOR' => ['label' => $T('col.valor', 'Valor', 'Amount', 'Valor'), 'width' => '110px'],
    'VENCIMENTO' => ['label' => $T('col.vencimento', 'Vencimento', 'Due date', 'Vencimiento'), 'width' => '110px'],
    'SITUACAO' => ['label' => $T('col.situacao', 'Situação', 'Status', 'Situación'), 'width' => '170px'],
], $rowsTx, ['transacao' => 'home_licencas', 'ordem' => ['VENCIMENTO', 'asc']]) : '';

// ---------------------------------------------------------------- calendário (base, mês escolhido)
foreach ($base as $l) {
    $val = HomeModulo::data($l['DATA_VALIDADE']); $rea = HomeModulo::data($l['DATA_REACAO']);
    $per = (string)$l['NATUREZA'] === 'ENTREGA_PERIODICA';
    if ($val !== null) $eventos[] = ['data' => $val, 'tom' => $val < $hoje ? 'red' : 'orange', 'rotulo' => ($per ? $T('ev.entrega', 'Entrega', 'Submission', 'Entrega') : $T('ev.vence', 'Vence', 'Expires', 'Vence')) . ': ' . $l['LICENCA'],
        'tipo' => $per ? $T('ev.entrega_prazo', 'Prazo da entrega periódica', 'Periodic submission due', 'Plazo de la entrega periódica') : $T('ev.validade', 'Vencimento da licença', 'License expiry', 'Vencimiento de la licencia'),
        'item' => (string)$l['LICENCA'], 'unidade' => (string)$l['UNIDADE'], 'resp' => (string)$l['RESPONSAVEL'], 'open' => $abrirLic($l)];
    if ($rea !== null && !$per && !in_array((string)$l['FAIXA'], ['EM_PROTOCOLO', 'PROT_SEM_PRAZO'], true) && $rea !== $val)
        $eventos[] = ['data' => $rea, 'tom' => 'blue', 'rotulo' => $T('ev.renovar', 'Renovar', 'Renew', 'Renovar') . ': ' . $l['LICENCA'], 'tipo' => $T('ev.reacao', 'Início da renovação (dias de reação)', 'Renewal start (reaction days)', 'Inicio de la renovación'),
            'item' => (string)$l['LICENCA'], 'unidade' => (string)$l['UNIDADE'], 'resp' => (string)$l['RESPONSAVEL'], 'open' => $abrirLic($l)];
}
usort($eventos, static fn($a, $b) => strcmp($a['data'], $b['data']));
foreach ($eventos as $ev) if (substr($ev['data'], 0, 7) === $mes)
    $rowsCal[] = ['cells' => ['DATA' => DashboardGrid::prazo($ev['data'], false), 'EVENTO' => ['text' => $ev['tipo'], 'html' => '<span class="hm-sit tom-' . $ev['tom'] . '">' . $e($ev['tipo']) . '</span>', 'sort' => $ev['tipo']],
        'ITEM' => ['text' => $ev['item'], 'title' => $ev['item']], 'UNIDADE' => $ev['unidade'], 'RESPONSAVEL' => $ev['resp']], 'open' => $ev['open'], 'grupos' => 'D_' . $ev['data']];
$ant = date('Y-m', strtotime($mes . '-01 -1 month')); $prox = date('Y-m', strtotime($mes . '-01 +1 month'));
$calendario = HomeModulo::calendario($mes, $eventos, 'hlCal', ['ant' => $url(['mes' => $ant]), 'prox' => $url(['mes' => $prox]), 'hoje' => $url(['mes' => ''])])
    . HomeModulo::legenda(['red' => $T('lg.vencida', 'Vencimento já passado', 'Past expiry', 'Vencimiento ya pasado'), 'orange' => $T('lg.vence', 'Vencimento / entrega', 'Expiry / submission', 'Vencimiento / entrega'),
        'blue' => $T('lg.reacao', 'Início da renovação', 'Renewal start', 'Inicio de la renovación'), 'purple' => $T('lg.acao', 'Ação de condicionante', 'Condition action', 'Acción de condicionante'), 'yellow' => $T('lg.taxa', 'Taxa', 'Fee', 'Tasa')])
    . '<div class="mt-2">' . HomeModulo::grid('hlCal', ['DATA' => ['label' => $T('col.data', 'Data', 'Date', 'Fecha'), 'width' => '100px'], 'EVENTO' => ['label' => $T('col.evento', 'Prazo', 'Deadline', 'Plazo'), 'filter' => 'select', 'width' => '220px'],
        'ITEM' => ['label' => $T('col.item', 'Licença / item', 'License / item', 'Licencia / ítem'), 'width' => '360px'], 'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
        'RESPONSAVEL' => ['label' => $T('col.responsavel', 'Responsável', 'Owner', 'Responsable'), 'filter' => 'select', 'width' => '160px']], $rowsCal,
        ['transacao' => 'home_licencas', 'ordem' => ['DATA', 'asc'], 'por_pagina' => 50, 'vazio' => $T('cal.vazio', 'Nenhum prazo neste mês.', 'No deadlines this month.', 'Ningún plazo en este mes.')]) . '</div>';

// ---------------------------------------------------------------- cartões
$n = static fn(string ...$fx): int => array_sum(array_map(static fn($k) => $cont[$k] ?? 0, $fx));
$periodicas = count(array_filter($lic, static fn($l) => (string)$l['NATUREZA'] === 'ENTREGA_PERIODICA'));
$cards = [['rotulo' => $T('c.total', 'Licenças e documentos', 'Licenses and documents', 'Licencias y documentos'), 'valor' => count($lic), 'icone' => 'bi-files', 'tom' => 'blue', 'grupo' => '',
    'extra' => $periodicas ? $periodicas . ' ' . $T('c.periodicas', 'entregas periódicas', 'periodic submissions', 'entregas periódicas') : '']];
foreach (['VENCIDA', 'VENCE_30', 'VENCE_60', 'VENCE_90', 'VIGENTE'] as $k) $cards[] = ['rotulo' => $FAIXAS[$k][0], 'valor' => $cont[$k], 'icone' => $FAIXAS[$k][1], 'tom' => $FAIXAS[$k][2], 'grupo' => 'F_' . $k];
$cards[] = ['rotulo' => $T('c.protocolo', 'Em protocolo (renovação pedida)', 'Renewal filed', 'En protocolo (renovación pedida)'), 'valor' => $n('EM_PROTOCOLO', 'PROT_SEM_PRAZO'), 'icone' => 'bi-hourglass-split', 'tom' => 'blue', 'grupo' => 'F_PROTOCOLOS',
    'extra' => $cont['PROT_SEM_PRAZO'] ? $cont['PROT_SEM_PRAZO'] . ' ' . $T('c.sem_prazo', 'sem prazo informado', 'without deadline', 'sin plazo') : ''];
$cards[] = ['rotulo' => $FAIXAS['SEM_VALIDADE'][0], 'valor' => $cont['SEM_VALIDADE'], 'icone' => 'bi-question-circle', 'tom' => 'gray', 'grupo' => 'F_SEM_VALIDADE'];
if ($periodicas || $f['n'] === 'ENTREGA_PERIODICA') {
    $cards[] = ['rotulo' => $FAIXAS['ENTREGA_ATRASADA'][0], 'valor' => $cont['ENTREGA_ATRASADA'], 'icone' => 'bi-send-x', 'tom' => 'red', 'grupo' => 'F_ENTREGA_ATRASADA'];
    $cards[] = ['rotulo' => $FAIXAS['ENTREGA_30'][0], 'valor' => $cont['ENTREGA_30'], 'icone' => 'bi-send', 'tom' => 'orange', 'grupo' => 'F_ENTREGA_30'];
}
if ($planos) $cards[] = ['rotulo' => $T('c.acoes_atr', 'Ações de condicionantes atrasadas', 'Overdue condition actions', 'Acciones de condicionantes atrasadas'), 'valor' => $pAtr, 'icone' => 'bi-list-check', 'tom' => $pAtr ? 'red' : 'green', 'grupo' => 'ATRASADA', 'grid' => 'hlPlanos',
    'extra' => $pAb . ' ' . $T('c.abertas', 'abertas', 'open', 'abiertas')];
if ($taxas) $cards[] = ['rotulo' => $TX['VENCIDA'][0], 'valor' => $cTx['VENCIDA'], 'icone' => 'bi-cash-coin', 'tom' => $cTx['VENCIDA'] ? 'red' : 'green', 'grupo' => 'TX_VENCIDA', 'grid' => 'hlTaxas',
    'extra' => $cTx['VENCE_30'] . ' ' . $T('c.tx_30', 'vencem em 30 dias', 'due in 30 days', 'vencen en 30 días')];

// ---------------------------------------------------------------- gráficos
$G = 'hlLic';
$barSit = [];
foreach ($SIT as $k => [$rot, $tom]) $barSit[] = ['rotulo' => $rot, 'n' => $contSit[$k], 'grupo' => 'S_' . $k, 'tom' => $tom];
$col = [['rotulo' => $T('g.vencidas_curto', 'Venc.', 'Exp.', 'Venc.'), 'dica' => $T('g.vencidas_dica', 'Vencidas e entregas atrasadas', 'Expired and overdue submissions', 'Vencidas y entregas atrasadas'), 'n' => $antes, 'grupo' => 'M_ANTES', 'tom' => 'red']];
for ($i = 0; $i < 12; $i++) {
    $ym = date('Y-m', strtotime(date('Y-m-01') . " +$i month"));
    $col[] = ['rotulo' => HomeModulo::mesCurto($ym), 'dica' => HomeModulo::mesLongo($ym), 'n' => $meses[$ym] ?? 0, 'grupo' => 'M_' . $ym, 'tom' => $i === 0 ? 'orange' : ($i < 3 ? 'yellow' : 'teal')];
}
$depois = array_sum(array_filter($meses, static fn($k) => $k > date('Y-m', strtotime(date('Y-m-01') . ' +11 month')), ARRAY_FILTER_USE_KEY));
$partes = static function (array $x) use ($CAT): array { $p = []; foreach ($CAT as $k => [$rot, $tom]) $p[] = ['n' => $x[$k] ?? 0, 'tom' => $tom, 'rotulo' => $rot]; return $p; };
$ordenar = static function (array $a): array { uasort($a, static fn($x, $y) => $y['n'] <=> $x['n'] ?: ($y['VENC'] ?? 0) <=> ($x['VENC'] ?? 0)); return $a; };
$barUn = []; foreach ($ordenar($porUn) as $k => $x) $barUn[] = ['rotulo' => $unidades[$k] ?? '#' . $k, 'n' => $x['n'], 'grupo' => 'U_' . $k, 'partes' => $partes($x)];
$barResp = []; foreach ($ordenar($porResp) as $k => $x) $barResp[] = ['rotulo' => $responsaveis[$k] ?? '#' . $k, 'n' => $x['n'], 'grupo' => 'R_' . $k, 'partes' => $partes($x)];
$simples = static function (array $cont, callable $rot, string $pref, callable $chave) use ($naoInf): array {
    arsort($cont); $out = [];
    foreach ($cont as $k => $q) $out[] = ['rotulo' => (string)$k === '' ? $naoInf : $rot((string)$k), 'n' => $q, 'grupo' => HomeModulo::grupo($pref, (string)$k === '' ? 'NA' : $chave((string)$k)), 'tom' => (string)$k === '' ? 'gray' : 'teal'];
    return $out;
};
$barTipo = $simples($porTipo, static fn($k) => $k, 'T', static fn($k) => md5($k));
$rotClass = static fn(string $k): string => ($nomeClass[$k] ?? '') !== '' ? $nomeClass[$k] : $T('classe_n', 'Classificação nº', 'Classification no.', 'Clasificación nº') . ' ' . $k;
$barClass = $simples($porClass, $rotClass, 'C', static fn($k) => $k);
$semNomeClass = !array_filter($nomeClass) && array_filter(array_keys($porClass), static fn($k) => (string)$k !== '');
$barOrgao = $simples($porOrgao, static fn($k) => $k, 'O', static fn($k) => md5($k));
$legCat = HomeModulo::legenda(array_combine(array_column($CAT, 1), array_column($CAT, 0)));
$dicaBarra = $T('g.dica', 'Clique numa barra para ver as licenças na Lista.', 'Click a bar to see the licenses in the List.', 'Haga clic en una barra para ver las licencias en la Lista.');
$graficos = '<div class="hm-graficos">'
    . HomeModulo::grafico($T('g.situacao', 'Situação das licenças', 'License status', 'Situación de las licencias'), 'bi-shield-check', HomeModulo::barras($G, $barSit), $dicaBarra)
    . HomeModulo::grafico($T('g.meses', 'Vencimentos nos próximos 12 meses', 'Expiries in the next 12 months', 'Vencimientos en los próximos 12 meses'), 'bi-calendar3', HomeModulo::colunas($G, $col),
        $depois ? $depois . ' ' . $T('g.depois', 'vencem depois de 12 meses (cartão Vigentes).', 'expire after 12 months (Valid card).', 'vencen después de 12 meses (tarjeta Vigentes).') : '')
    . HomeModulo::grafico($T('g.unidade', 'Por unidade', 'By unit', 'Por unidad'), 'bi-buildings', HomeModulo::barras($G, $barUn, 15) . $legCat)
    . HomeModulo::grafico($T('g.responsavel', 'Por responsável', 'By owner', 'Por responsable'), 'bi-person', HomeModulo::barras($G, $barResp, 12) . $legCat,
        $T('g.resp_nota', 'Os 11 com mais licenças; o resto em Outros (use o filtro Responsável no topo). Vermelho: vencidas.', 'The 11 with most licenses; the rest in Others. Red: expired.', 'Los 11 con más licencias; el resto en Otros. Rojo: vencidas.'))
    . HomeModulo::grafico($T('g.tipo', 'Por tipo documental', 'By document type', 'Por tipo documental'), 'bi-tags', HomeModulo::barras($G, $barTipo),
        isset($porTipo['']) && count($porTipo) === 1 ? $T('g.tipo_vazio', 'Nenhuma licença tem o Tipo documental preenchido: preencha no cadastro de Licenças para usar este gráfico e as regras de responsáveis por tipo.', 'No license has the document type filled in.', 'Ninguna licencia tiene el tipo documental.') : '')
    . HomeModulo::grafico($T('g.classificacao', 'Por classificação', 'By classification', 'Por clasificación'), 'bi-diagram-3', HomeModulo::barras($G, $barClass),
        $semNomeClass ? $T('g.class_sem_nome', 'As classificações usadas nas licenças não têm nome cadastrado nesta empresa (tabela de classificações vazia); aparecem pelo número.', 'The classifications have no names registered in this company; shown by number.', 'Las clasificaciones no tienen nombre registrado; se muestran por número.') : '')
    . (array_filter($orgaos) ? HomeModulo::grafico($T('g.orgao', 'Por órgão emissor', 'By issuing agency', 'Por órgano emisor'), 'bi-bank', HomeModulo::barras($G, $barOrgao)) : '')
    . '</div>';

// ---------------------------------------------------------------- condicionantes (sub-abas internas)
$condHtml = '';
if ($conds || $planos) {
    $condHtml = HomeModulo::cartoes('hlCond', [
        ['rotulo' => $T('cd.total', 'Condicionantes', 'Conditions', 'Condicionantes'), 'valor' => count($conds), 'icone' => 'bi-card-checklist', 'tom' => 'blue', 'grupo' => ''],
        ['rotulo' => $T('cd.sim', 'Atendidas', 'Complied', 'Atendidas'), 'valor' => $cAt['Sim'], 'icone' => 'bi-check2-circle', 'tom' => 'green', 'grupo' => 'AT_SIM'],
        ['rotulo' => $T('cd.nao', 'Não atendidas', 'Not complied', 'No atendidas'), 'valor' => $cAt['Não'], 'icone' => 'bi-x-circle', 'tom' => 'red', 'grupo' => 'AT_NAO'],
        ['rotulo' => $T('cd.na', 'Sem avaliação', 'Not assessed', 'Sin evaluación'), 'valor' => $cAt[''], 'icone' => 'bi-question-circle', 'tom' => 'gray', 'grupo' => 'AT_NA'],
        ['rotulo' => $rotAberta, 'valor' => $pAb, 'icone' => 'bi-list-task', 'tom' => 'orange', 'grupo' => 'ABERTA', 'grid' => 'hlPlanos'],
        ['rotulo' => $T('pl.atrasadas', 'Ações atrasadas', 'Overdue actions', 'Acciones atrasadas'), 'valor' => $pAtr, 'icone' => 'bi-alarm', 'tom' => 'red', 'grupo' => 'ATRASADA', 'grid' => 'hlPlanos'],
    ]) . DashboardGrid::subabas('hlCondSub', [
        ['id' => 'cond', 'icone' => 'bi-card-checklist', 'rotulo' => $T('sub.cond', 'Condicionantes', 'Conditions', 'Condicionantes'), 'contagem' => count($conds), 'html' => $gridCond],
        ['id' => 'planos', 'icone' => 'bi-list-check', 'rotulo' => $T('sub.planos', 'Ações dos planos', 'Action plan items', 'Acciones de los planes'), 'contagem' => count($planos), 'html' => $gridPlano],
    ]);
}
$taxasHtml = $taxas ? HomeModulo::cartoes('hlTaxas', array_map(static fn($k) => ['rotulo' => $TX[$k][0], 'valor' => $cTx[$k], 'icone' => $TX[$k][2], 'tom' => $TX[$k][1], 'grupo' => 'TX_' . $k], array_keys($TX))) . $gridTx : '';

$filtrosHtml = HomeModulo::filtros([
    'u' => ['rotulo' => $T('f.unidade', 'Unidade', 'Unit', 'Unidad'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $unidades],
    'r' => ['rotulo' => $T('f.responsavel', 'Responsável', 'Owner', 'Responsable'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $responsaveis],
    'n' => ['rotulo' => $T('f.natureza', 'Tipo', 'Kind', 'Tipo'), 'todos' => $T('f.nat_todas', 'Licenças e entregas periódicas', 'Licenses and periodic submissions', 'Licencias y entregas periódicas'),
        'opcoes' => ['LICENCA' => $T('f.so_lic', 'Só licenças', 'Licenses only', 'Solo licencias'), 'ENTREGA_PERIODICA' => $T('f.so_ent', 'Só entregas periódicas', 'Periodic submissions only', 'Solo entregas periódicas')]],
    'p' => ['rotulo' => $T('f.periodo', 'Vencimento', 'Due date', 'Vencimiento'), 'opcoes' => HomeModulo::periodos()],
    'de' => ['rotulo' => $T('f.de', 'De', 'From', 'Desde'), 'tipo' => 'date', 'oculto' => $f['p'] !== 'datas'],
    'ate' => ['rotulo' => $T('f.ate', 'Até', 'To', 'Hasta'), 'tipo' => 'date', 'oculto' => $f['p'] !== 'datas'],
], $f, ['mes' => $mes !== date('Y-m') ? $mes : '']);

$abas = DashboardGrid::subabas('hlAbas', [
    ['id' => 'graficos', 'icone' => 'bi-bar-chart', 'rotulo' => $T('sub.graficos', 'Gráficos', 'Charts', 'Gráficos'), 'html' => $graficos],
    ['id' => 'lista', 'icone' => 'bi-table', 'rotulo' => $T('sub.lista', 'Lista', 'List', 'Lista'), 'contagem' => count($lic), 'html' => $gridLic],
    ['id' => 'calendario', 'icone' => 'bi-calendar3', 'rotulo' => $T('sub.calendario', 'Calendário', 'Calendar', 'Calendario'), 'contagem' => count($rowsCal), 'html' => $calendario],
    ['id' => 'condicionantes', 'icone' => 'bi-card-checklist', 'rotulo' => $T('sub.condicionantes', 'Condicionantes e planos', 'Conditions and plans', 'Condicionantes y planes'), 'contagem' => $pAb ?: null, 'html' => $condHtml],
    ['id' => 'taxas', 'icone' => 'bi-cash-coin', 'rotulo' => $T('sub.taxas', 'Taxas', 'Fees', 'Tasas'), 'contagem' => count($taxas), 'html' => $taxasHtml],
], true);

$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$titulo = HomeModulo::MENUS['LICENCAS']['nome'][$idioma] ?? HomeModulo::MENUS['LICENCAS']['nome']['pt'];
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php HomeModulo::head(); ?>
</head>
<body class="theme-<?= $e($theme) ?>" data-transacao="home_licencas"><main class="hm">
<?= HomeModulo::cabecalho('bi-file-earmark-text', $titulo,
    $e($T('desc', 'Situação das licenças, entregas periódicas, condicionantes e taxas. Os filtros do topo valem para a página toda; clique num cartão, numa barra do gráfico ou num dia do calendário para listar os itens. Duplo clique na linha abre o cadastro.',
        'Status of licenses, periodic submissions, conditions and fees. The filters at the top apply to the whole page; click a card, a chart bar or a calendar day to list the items. Double-click a row to open it.',
        'Situación de las licencias, entregas periódicas, condicionantes y tasas. Los filtros valen para toda la página; haga clic en una tarjeta, una barra o un día para listar. Doble clic abre el registro.')), $aviso) ?>
<?= $filtrosHtml ?>
<?= HomeModulo::cartoes('hlLic', $cards) ?>
<?= $abas ?>
</main>
<?php HomeModulo::scripts(); ?>
</body></html>
