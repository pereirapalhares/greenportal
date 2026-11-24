<?php
declare(strict_types=1);

/*
 * Home de Não Conformidades (24/11/2026, Homes por módulo — item 156 da Revisão G), no modelo de classes/HomeModulo.php.
 *  - Ocorrências abertas: etapa, com quem está, tempo em aberto, data prevista, paradas; ações de plano vencidas; abertas × finalizadas
 *    por mês. Filtros: unidade, tipo, etapa e pessoa com quem está.
 *  - Sub-abas: Gráficos, Lista (abre a ocorrência na aba da etapa), Calendário (datas previstas e ações vencidas) e Ações vencidas.
 * Dados (só leitura, sp_crud_select): vw_dash_nc_abertas, vw_dash_nc_mes e vw_nc_acoes_vencidas (migrações 334/366).
 * Quem tem acesso à Lista geral de ocorrências vê todas; quem só tem Minhas ocorrências vê as que emitiu, gerencia, responde ou
 * em que tem a ação da etapa.
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
$T = static fn(string $c, string $pt, string $en, string $es): string => HomeModulo::t('nc.' . $c, $pt, $en, $es);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);

$pHome = PermissaoTransacao::obter('HOME_NAO_CONFORMIDADES'); $pLista = PermissaoTransacao::obter('NAO_CONFORMIDADES_LISTA_OCORRENCIAS'); $pMinhas = PermissaoTransacao::obter('NAO_CONFORMIDADES_MINHAS_OCORRENCIAS');
if (empty($pHome['pode_visualizar']) && empty($pLista['pode_visualizar']) && empty($pMinhas['pode_visualizar']))
    $sair(403, $T('negado', 'Você não tem acesso às Não conformidades.', 'You do not have access to Nonconformities.', 'No tiene acceso a las No conformidades.'));
$total = !empty($pLista['pode_visualizar']);

// ---------------------------------------------------------------- dados
$abertas = HomeModulo::consultar($pdo, 'vw_dash_nc_abertas', ['CODOCORRENCIA', 'NUMERO', 'SUMARIO', 'TIPO', 'ETAPA', 'FASE', 'CODUNIDADE', 'DSCUNIDADE', 'RAZAOSOCIAL',
    'CODUSUARIO_EMISSOR', 'CODUSUARIO_GESTOR', 'CODUSUARIO_RESPONSAVEL', 'CODUSUARIO_ACAO', 'USUARIO_ACAO', 'USUARIO_ACAO_ATIVO', 'CRIADA_EM', 'IDADE_DIAS', 'PREVISTA', 'ATRASADA', 'DIAS_PARADA']);
if ($abertas === null) $sair(503, $T('sem_migracao', 'Os indicadores de não conformidades ainda não existem nesta empresa (migração 366).', 'Nonconformity indicators are not available for this company (migration 366).', 'Los indicadores de no conformidades no existen en esta empresa (migración 366).'));
$acoes = HomeModulo::consultar($pdo, 'vw_nc_acoes_vencidas', ['CODPLANOACAO', 'CODOCORRENCIA', 'NUMERO', 'ACAO', 'CODUSUARIO', 'RESPONSAVEL', 'RESPONSAVEL_ATIVO', 'DATAFINAL', 'DIAS_ATRASO', 'STATUS', 'ETAPA']) ?? [];
$mesesNc = HomeModulo::ler($pdo, 'vw_dash_nc_mes', [], 100) ?? [];
$envolvido = static fn(array $r): bool => in_array($uid, [(int)$r['CODUSUARIO_EMISSOR'], (int)$r['CODUSUARIO_GESTOR'], (int)$r['CODUSUARIO_RESPONSAVEL'], (int)$r['CODUSUARIO_ACAO']], true);
$aviso = '';
if (!$total) {
    $abertas = array_values(array_filter($abertas, $envolvido));
    $visiveis = array_fill_keys(array_map(static fn($r) => (int)$r['CODOCORRENCIA'], $abertas), true);
    $acoes = array_values(array_filter($acoes, static fn($a) => (int)$a['CODUSUARIO'] === $uid || isset($visiveis[(int)$a['CODOCORRENCIA']])));
    $aviso = $T('so_minhas', 'Mostrando só as ocorrências em que você está envolvido (emitente, gestor, responsável ou ação da etapa).', 'Showing only the occurrences you are involved in.', 'Mostrando solo las ocurrencias en que usted participa.');
}

// ---------------------------------------------------------------- filtros
$hoje = date('Y-m-d');
$FASES = ['CRIACAO' => [$T('f.criacao', 'Em criação / devolvida', 'Being created / returned', 'En creación / devuelta'), 'gray', 'bi-pencil-square'],
    'GESTOR' => [$T('f.gestor', 'Com o gestor', 'With the manager', 'Con el gestor'), 'orange', 'bi-person-check'],
    'ANALISE' => [$T('f.analise', 'Análise da causa e plano', 'Root cause and plan', 'Análisis de causa y plan'), 'blue', 'bi-diagram-3'],
    'EFICACIA' => [$T('f.eficacia', 'Avaliação da eficácia', 'Effectiveness review', 'Evaluación de la eficacia'), 'purple', 'bi-clipboard-check'],
    'OUTRA' => [$T('f.outra', 'Outra etapa', 'Other stage', 'Otra etapa'), 'teal', 'bi-three-dots']];
$faseDe = static fn(array $r): string => isset($FASES[(string)$r['FASE']]) ? (string)$r['FASE'] : 'OUTRA';
$f = ['u' => (string)($_GET['u'] ?? ''), 't' => (string)($_GET['t'] ?? ''), 'e' => (string)($_GET['e'] ?? ''), 'r' => (string)($_GET['r'] ?? '')];
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$url = static function (array $mudar = []) use ($f, $mes): string {
    $q = array_filter($mudar + $f + ['mes' => $mes !== date('Y-m') ? $mes : ''], static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
$unidades = []; $tipos = []; $pessoas = [];
foreach ($abertas as $r) {
    $unidades[(string)(int)$r['CODUNIDADE']] = trim((string)$r['DSCUNIDADE']) !== '' ? trim((string)$r['DSCUNIDADE']) : '–';
    $tipos[(string)$r['TIPO']] = (string)$r['TIPO'] !== '' ? (string)$r['TIPO'] : '–';
    if ((int)$r['CODUSUARIO_ACAO'] > 0) $pessoas[(string)(int)$r['CODUSUARIO_ACAO']] = (string)$r['USUARIO_ACAO'] . ((int)$r['USUARIO_ACAO_ATIVO'] ? '' : ' (' . $T('inativo', 'inativo', 'inactive', 'inactivo') . ')');
}
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($tipos, SORT_NATURAL | SORT_FLAG_CASE); asort($pessoas, SORT_NATURAL | SORT_FLAG_CASE);
$pessoas = ['0' => $T('sem_pessoa', '(sem pessoa na etapa)', '(no person for the stage)', '(sin persona en la etapa)')] + $pessoas;
$lista = array_values(array_filter($abertas, static fn($r) => ($f['u'] === '' || (string)(int)$r['CODUNIDADE'] === $f['u']) && ($f['t'] === '' || (string)$r['TIPO'] === $f['t'])
    && ($f['e'] === '' || $faseDe($r) === $f['e']) && ($f['r'] === '' || (string)(int)$r['CODUSUARIO_ACAO'] === $f['r'])));
$idsLista = array_fill_keys(array_map(static fn($r) => (int)$r['CODOCORRENCIA'], $lista), true);
$filtrouNc = $f['u'] !== '' || $f['t'] !== '' || $f['e'] !== '' || $f['r'] !== '';
$acoesL = array_values(array_filter($acoes, static fn($a) => !$filtrouNc || isset($idsLista[(int)$a['CODOCORRENCIA']])));

// ---------------------------------------------------------------- lista
$IDADE = ['A30' => [$T('i.30', 'Até 30 dias', 'Up to 30 days', 'Hasta 30 días'), 'green'], 'A90' => [$T('i.90', '31 a 90 dias', '31–90 days', '31 a 90 días'), 'teal'],
    'A365' => [$T('i.365', '91 dias a 1 ano', '91 days–1 year', '91 días a 1 año'), 'orange'], 'MAIS' => [$T('i.mais', 'Mais de 1 ano', 'Over 1 year', 'Más de 1 año'), 'red']];
$faixa = static fn(int $d): string => $d <= 30 ? 'A30' : ($d <= 90 ? 'A90' : ($d <= 365 ? 'A365' : 'MAIS'));
$abrirNc = static function (array $r) use ($T, $faseDe): array {
    $aba = ['GESTOR' => 'gestor', 'ANALISE' => 'analise', 'EFICACIA' => 'eficacia'][$faseDe($r)] ?? '';
    return ['url' => 'naoconformidades_ocorrencia.php?' . http_build_query(['codigo' => (int)$r['CODOCORRENCIA']] + ($aba ? ['aba' => $aba] : [])),
        'title' => $T('titulo_nc', 'Não conformidade', 'Nonconformity', 'No conformidad') . ' ' . $r['NUMERO']];
};
$cont = array_fill_keys(array_keys($FASES), 0); $contI = array_fill_keys(array_keys($IDADE), 0);
$atrasadas = 0; $paradas = 0; $novas = 0; $semPessoa = 0; $inativos = 0;
$porTipo = []; $porUn = []; $porPessoa = []; $linhas = []; $eventos = []; $rowsCal = [];
foreach ($lista as $r) {
    $fs = $faseDe($r); $id = (int)$r['CODOCORRENCIA']; $idade = (int)$r['IDADE_DIAS']; $fx = $faixa($idade);
    $cont[$fs]++; $contI[$fx]++;
    $g = ['F_' . $fs, 'I_' . $fx, HomeModulo::grupo('U', (int)$r['CODUNIDADE']), HomeModulo::grupo('T', md5((string)$r['TIPO'])), HomeModulo::grupo('P', (int)$r['CODUSUARIO_ACAO'])];
    if ((int)$r['ATRASADA']) { $g[] = 'ATRASADA'; $atrasadas++; }
    if ((int)$r['DIAS_PARADA'] > 90) { $g[] = 'PARADA90'; $paradas++; }
    if ($idade <= 30) { $g[] = 'NOVA'; $novas++; }
    if ((int)$r['CODUSUARIO_ACAO'] <= 0) { $g[] = 'SEMPESSOA'; $semPessoa++; }
    elseif (!(int)$r['USUARIO_ACAO_ATIVO']) { $g[] = 'INATIVO'; $g[] = 'SEMPESSOA'; $inativos++; }
    $tp = (string)$r['TIPO']; $un = (int)$r['CODUNIDADE']; $ps = (int)$r['CODUSUARIO_ACAO'];
    $porTipo[$tp]['n'] = ($porTipo[$tp]['n'] ?? 0) + 1; $porTipo[$tp][$fs] = ($porTipo[$tp][$fs] ?? 0) + 1;
    $porUn[$un]['n'] = ($porUn[$un]['n'] ?? 0) + 1; $porUn[$un][$fs] = ($porUn[$un][$fs] ?? 0) + 1;
    $porPessoa[$ps]['n'] = ($porPessoa[$ps]['n'] ?? 0) + 1; $porPessoa[$ps][$fs] = ($porPessoa[$ps][$fs] ?? 0) + 1;
    $prev = HomeModulo::data($r['PREVISTA'] ?? null); $criada = HomeModulo::data($r['CRIADA_EM'] ?? null);
    $pessoa = $ps > 0 ? (string)$r['USUARIO_ACAO'] . ((int)$r['USUARIO_ACAO_ATIVO'] ? '' : ' (' . $T('inativo', 'inativo', 'inactive', 'inactivo') . ')') : '–';
    $linhas[] = ['cells' => [
        'NUMERO' => ['text' => (string)$r['NUMERO'], 'sort' => sprintf('%012d', $id)],
        'TIPO' => $tp,
        'SUMARIO' => ['text' => mb_strimwidth(trim((string)$r['SUMARIO']), 0, 120, '…'), 'title' => mb_strimwidth(trim((string)$r['SUMARIO']), 0, 400, '…')],
        'UNIDADE' => trim((string)$r['DSCUNIDADE']),
        'ETAPA' => ['text' => $FASES[$fs][0], 'html' => '<span class="hm-sit tom-' . $FASES[$fs][1] . '" title="' . $e($r['ETAPA']) . '">' . $e($FASES[$fs][0]) . '</span>', 'sort' => $FASES[$fs][0]],
        'PESSOA' => $pessoa,
        'ABERTA' => ['text' => HomeModulo::dataTexto($criada), 'sort' => $criada ?? ''],
        'IDADE' => ['text' => (string)$idade, 'html' => '<span class="hm-sit tom-' . $IDADE[$fx][1] . '">' . $idade . '</span>', 'sort' => sprintf('%06d', $idade)],
        'PREVISTA' => DashboardGrid::prazo($prev ?? ''),
        'PARADA' => ['text' => (string)(int)$r['DIAS_PARADA'], 'sort' => sprintf('%06d', (int)$r['DIAS_PARADA'])],
        'FORNECEDOR' => trim((string)($r['RAZAOSOCIAL'] ?? '')),
    ], 'open' => $abrirNc($r), 'grupos' => implode(' ', $g)];
    if ($prev !== null) $eventos[] = ['data' => $prev, 'tom' => $prev < $hoje ? 'red' : 'orange', 'rotulo' => 'NC ' . $r['NUMERO'], 'tipo' => $T('ev.prevista', 'Conclusão prevista da NC', 'NC planned completion', 'Conclusión prevista de la NC'),
        'item' => 'NC ' . $r['NUMERO'] . ' — ' . trim((string)$r['SUMARIO']), 'resp' => $pessoa, 'open' => $abrirNc($r)];
}
$G = 'hnNc';
$colsLista = [
    'NUMERO' => ['label' => $T('col.numero', 'Número', 'Number', 'Número'), 'width' => '95px'],
    'TIPO' => ['label' => $T('col.tipo', 'Tipo', 'Type', 'Tipo'), 'filter' => 'select', 'width' => '120px'],
    'SUMARIO' => ['label' => $T('col.sumario', 'Sumário', 'Summary', 'Resumen'), 'width' => '300px'],
    'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
    'ETAPA' => ['label' => $T('col.etapa', 'Etapa', 'Stage', 'Etapa'), 'filter' => 'select', 'width' => '170px'],
    'PESSOA' => ['label' => $T('col.pessoa', 'Com quem está', 'Held by', 'Con quién está'), 'filter' => 'select', 'width' => '170px'],
    'ABERTA' => ['label' => $T('col.aberta', 'Aberta em', 'Opened on', 'Abierta el'), 'width' => '100px'],
    'IDADE' => ['label' => $T('col.idade', 'Dias em aberto', 'Days open', 'Días abierta'), 'width' => '95px'],
    'PREVISTA' => ['label' => $T('col.prevista', 'Conclusão prevista', 'Planned completion', 'Conclusión prevista'), 'width' => '120px'],
    'PARADA' => ['label' => $T('col.parada', 'Dias parada', 'Days idle', 'Días parada'), 'width' => '85px'],
    'FORNECEDOR' => ['label' => $T('col.fornecedor', 'Fornecedor', 'Supplier', 'Proveedor'), 'filter' => 'select', 'width' => '180px'],
];
// a lista (milhares de ocorrências) vem depois, por ?parte=lista (HomeModulo::adiada)
if (HomeModulo::parte() === 'lista') {
    session_write_close();
    echo HomeModulo::grid($G, $colsLista, $linhas, ['transacao' => 'home_nao_conformidades', 'ordem' => ['PARADA', 'desc'], 'por_pagina' => 50, 'vazio' => $T('lista.vazia', 'Nenhuma ocorrência aberta com estes filtros.', 'No open occurrence with these filters.', 'Ninguna ocurrencia abierta con estos filtros.')]);
    exit;
}
$gridLista = HomeModulo::adiada($G, 'lista', count($linhas));

// ações vencidas
$rowsAc = [];
foreach ($acoesL as $a) {
    $fim = HomeModulo::data($a['DATAFINAL'] ?? null);
    $open = ['url' => 'naoconformidades_ocorrencia.php?' . http_build_query(['codigo' => (int)$a['CODOCORRENCIA'], 'aba' => 'analise']), 'title' => $T('titulo_nc', 'Não conformidade', 'Nonconformity', 'No conformidad') . ' ' . $a['NUMERO']];
    $rowsAc[] = ['cells' => ['NUMERO' => (string)$a['NUMERO'], 'ACAO' => ['text' => mb_strimwidth(trim((string)$a['ACAO']), 0, 250, '…'), 'title' => trim((string)$a['ACAO'])],
        'RESPONSAVEL' => (string)$a['RESPONSAVEL'] . ((int)$a['RESPONSAVEL_ATIVO'] ? '' : ' (' . $T('inativo', 'inativo', 'inactive', 'inactivo') . ')'), 'DATAFINAL' => DashboardGrid::prazo($fim ?? ''),
        'ATRASO' => ['text' => (string)(int)$a['DIAS_ATRASO'], 'sort' => sprintf('%06d', (int)$a['DIAS_ATRASO'])], 'STATUS' => ['text' => (int)$a['STATUS'] . '%', 'sort' => sprintf('%03d', (int)$a['STATUS'])], 'ETAPA' => (string)$a['ETAPA']],
        'open' => $open, 'grupos' => ((int)$a['CODUSUARIO'] === $uid ? 'MINHA' : '')];
    if ($fim !== null) $eventos[] = ['data' => $fim, 'tom' => 'purple', 'rotulo' => $T('ev.acao', 'Ação', 'Action', 'Acción') . ' NC ' . $a['NUMERO'], 'tipo' => $T('ev.acao_l', 'Ação de plano (vencida)', 'Action plan item (overdue)', 'Acción de plan (vencida)'),
        'item' => 'NC ' . $a['NUMERO'] . ' — ' . trim((string)$a['ACAO']), 'resp' => (string)$a['RESPONSAVEL'], 'open' => $open];
}
$gridAc = HomeModulo::grid('hnAcoes', ['NUMERO' => ['label' => $T('col.numero', 'Número', 'Number', 'Número'), 'width' => '95px'], 'ACAO' => ['label' => $T('col.acao', 'Ação', 'Action', 'Acción'), 'width' => '340px'],
    'RESPONSAVEL' => ['label' => $T('col.responsavel', 'Responsável', 'Owner', 'Responsable'), 'filter' => 'select', 'width' => '170px'], 'DATAFINAL' => ['label' => $T('col.data_final', 'Data final', 'Due date', 'Fecha final'), 'width' => '110px'],
    'ATRASO' => ['label' => $T('col.atraso', 'Dias de atraso', 'Days late', 'Días de atraso'), 'width' => '95px'], 'STATUS' => ['label' => $T('col.status', 'Status', 'Status', 'Estado'), 'width' => '75px'],
    'ETAPA' => ['label' => $T('col.etapa', 'Etapa', 'Stage', 'Etapa'), 'filter' => 'select', 'width' => '150px']], $rowsAc,
    ['transacao' => 'home_nao_conformidades', 'ordem' => ['ATRASO', 'desc'], 'por_pagina' => 50, 'vazio' => $T('ac.vazio', 'Nenhuma ação de plano vencida.', 'No overdue action plan items.', 'Ninguna acción de plan vencida.')]);

usort($eventos, static fn($a, $b) => strcmp($a['data'], $b['data']));
foreach ($eventos as $ev) if (substr($ev['data'], 0, 7) === $mes)
    $rowsCal[] = ['cells' => ['DATA' => DashboardGrid::prazo($ev['data'], false), 'EVENTO' => ['text' => $ev['tipo'], 'html' => '<span class="hm-sit tom-' . $ev['tom'] . '">' . $e($ev['tipo']) . '</span>', 'sort' => $ev['tipo']],
        'ITEM' => ['text' => mb_strimwidth($ev['item'], 0, 200, '…'), 'title' => $ev['item']], 'RESPONSAVEL' => $ev['resp']], 'open' => $ev['open'], 'grupos' => 'D_' . $ev['data']];
$ant = date('Y-m', strtotime($mes . '-01 -1 month')); $prox = date('Y-m', strtotime($mes . '-01 +1 month'));
$calendario = HomeModulo::calendario($mes, $eventos, 'hnCal', ['ant' => $url(['mes' => $ant]), 'prox' => $url(['mes' => $prox]), 'hoje' => $url(['mes' => ''])])
    . HomeModulo::legenda(['orange' => $T('lg.prevista', 'Conclusão prevista', 'Planned completion', 'Conclusión prevista'), 'red' => $T('lg.vencida', 'Prevista já vencida', 'Planned date passed', 'Prevista ya vencida'), 'purple' => $T('lg.acao', 'Ação de plano vencida', 'Overdue action', 'Acción vencida')])
    . '<div class="mt-2">' . HomeModulo::grid('hnCal', ['DATA' => ['label' => $T('col.data', 'Data', 'Date', 'Fecha'), 'width' => '100px'], 'EVENTO' => ['label' => $T('col.evento', 'Prazo', 'Deadline', 'Plazo'), 'filter' => 'select', 'width' => '210px'],
        'ITEM' => ['label' => $T('col.item', 'Ocorrência / ação', 'Occurrence / action', 'Ocurrencia / acción'), 'width' => '380px'], 'RESPONSAVEL' => ['label' => $T('col.pessoa', 'Com quem está', 'Held by', 'Con quién está'), 'filter' => 'select', 'width' => '180px']], $rowsCal,
        ['transacao' => 'home_nao_conformidades', 'ordem' => ['DATA', 'asc'], 'por_pagina' => 50, 'vazio' => $T('cal.vazio', 'Nenhum prazo neste mês.', 'No deadlines this month.', 'Ningún plazo en este mes.')]) . '</div>';

// ---------------------------------------------------------------- gráficos
$partes = static function (array $c) use ($FASES): array { $o = []; foreach ($FASES as $k => [$rot, $tom]) if (($c[$k] ?? 0) > 0) $o[] = ['n' => $c[$k], 'tom' => $tom, 'rotulo' => $rot]; return $o; };
$barFase = []; foreach ($FASES as $k => [$rot, $tom]) $barFase[] = ['rotulo' => $rot, 'n' => $cont[$k], 'grupo' => 'F_' . $k, 'tom' => $tom];
$barIdade = []; foreach ($IDADE as $k => [$rot, $tom]) $barIdade[] = ['rotulo' => $rot, 'n' => $contI[$k], 'grupo' => 'I_' . $k, 'tom' => $tom];
uasort($porTipo, static fn($a, $b) => $b['n'] <=> $a['n']);
$barTipo = []; foreach ($porTipo as $k => $c) $barTipo[] = ['rotulo' => (string)$k !== '' ? (string)$k : '–', 'n' => $c['n'], 'grupo' => HomeModulo::grupo('T', md5((string)$k)), 'partes' => $partes($c)];
uasort($porUn, static fn($a, $b) => $b['n'] <=> $a['n']);
$barUn = []; foreach ($porUn as $k => $c) $barUn[] = ['rotulo' => $unidades[(string)$k] ?? '#' . $k, 'n' => $c['n'], 'grupo' => HomeModulo::grupo('U', $k), 'partes' => $partes($c)];
uasort($porPessoa, static fn($a, $b) => $b['n'] <=> $a['n']);
$barPessoa = []; foreach ($porPessoa as $k => $c) $barPessoa[] = ['rotulo' => $pessoas[(string)$k] ?? '#' . $k, 'n' => $c['n'], 'grupo' => HomeModulo::grupo('P', $k), 'partes' => $partes($c)];
usort($mesesNc, static fn($a, $b) => strcmp((string)$a['MES'], (string)$b['MES']));
$colA = []; $colF = [];
foreach (array_slice($mesesNc, -12) as $m) {
    $ym = substr((string)$m['MES'], 0, 7);
    $colA[] = ['rotulo' => preg_match('/^\d{4}-\d{2}$/', $ym) ? HomeModulo::mesCurto($ym) : (string)$m['MES'], 'n' => (int)$m['ABERTAS'], 'tom' => 'orange', 'dica' => $T('g.abertas_mes', 'Abertas', 'Opened', 'Abiertas') . ' ' . $m['MES']];
    $colF[] = ['rotulo' => preg_match('/^\d{4}-\d{2}$/', $ym) ? HomeModulo::mesCurto($ym) : (string)$m['MES'], 'n' => (int)$m['FINALIZADAS'], 'tom' => 'green', 'dica' => $T('g.finalizadas_mes', 'Finalizadas', 'Closed', 'Finalizadas') . ' ' . $m['MES']];
}
$leg = HomeModulo::legenda(array_combine(array_column($FASES, 1), array_column($FASES, 0)));
$graficos = '<div class="hm-graficos">'
    . HomeModulo::grafico($T('g.etapa', 'Abertas por etapa', 'Open by stage', 'Abiertas por etapa'), 'bi-signpost-split', HomeModulo::barras($G, $barFase),
        $T('g.dica', 'Clique numa barra para ver as ocorrências na Lista.', 'Click a bar to see the occurrences in the List.', 'Haga clic en una barra para ver las ocurrencias en la Lista.'))
    . HomeModulo::grafico($T('g.idade', 'Tempo em aberto', 'Time open', 'Tiempo abierta'), 'bi-hourglass-split', HomeModulo::barras($G, $barIdade))
    . HomeModulo::grafico($T('g.tipo', 'Por tipo', 'By type', 'Por tipo'), 'bi-tags', HomeModulo::barras($G, $barTipo) . $leg)
    . HomeModulo::grafico($T('g.unidade', 'Por unidade', 'By unit', 'Por unidad'), 'bi-buildings', HomeModulo::barras($G, $barUn, 15) . $leg)
    . HomeModulo::grafico($T('g.pessoa', 'Com quem estão', 'Who holds them', 'Con quién están'), 'bi-person-exclamation', HomeModulo::barras($G, $barPessoa, 12) . $leg,
        $T('g.pessoa_nota', 'Quem age na etapa: emitente (criação), gestor (avaliar e eficácia), responsável (análise e plano).', 'Who acts in the stage: issuer, manager or owner.', 'Quién actúa en la etapa: emisor, gestor o responsable.'))
    . ($colA ? HomeModulo::grafico($T('g.mes', 'Abertas e finalizadas por mês', 'Opened and closed per month', 'Abiertas y finalizadas por mes'), 'bi-bar-chart',
        '<div class="hm-nota mb-1">' . $e($T('g.abertas', 'Abertas', 'Opened', 'Abiertas')) . '</div>' . HomeModulo::colunas($G, $colA) . '<div class="hm-nota mb-1">' . $e($T('g.finalizadas', 'Finalizadas', 'Closed', 'Finalizadas')) . '</div>' . HomeModulo::colunas($G, $colF),
        $T('g.mes_nota', 'Toda a empresa (não segue os filtros do topo).', 'Whole company (not affected by the filters).', 'Toda la empresa (no sigue los filtros).')) : '')
    . '</div>';

$cards = [['rotulo' => $T('c.abertas', 'Não conformidades abertas', 'Open nonconformities', 'No conformidades abiertas'), 'valor' => count($lista), 'icone' => 'bi-exclamation-diamond', 'tom' => 'blue', 'grupo' => '',
    'extra' => $novas . ' ' . $T('c.novas', 'aberta(s) nos últimos 30 dias', 'opened in the last 30 days', 'abierta(s) en los últimos 30 días')]];
foreach (['CRIACAO', 'GESTOR', 'ANALISE', 'EFICACIA'] as $k) $cards[] = ['rotulo' => $FASES[$k][0], 'valor' => $cont[$k], 'icone' => $FASES[$k][2], 'tom' => $FASES[$k][1], 'grupo' => 'F_' . $k];
$cards[] = ['rotulo' => $T('c.atrasadas', 'Conclusão prevista vencida', 'Planned completion passed', 'Conclusión prevista vencida'), 'valor' => $atrasadas, 'icone' => 'bi-calendar-x', 'tom' => $atrasadas ? 'red' : 'green', 'grupo' => 'ATRASADA'];
$cards[] = ['rotulo' => $T('c.paradas', 'Paradas há mais de 90 dias', 'Idle for over 90 days', 'Paradas hace más de 90 días'), 'valor' => $paradas, 'icone' => 'bi-pause-circle', 'tom' => $paradas ? 'red' : 'green', 'grupo' => 'PARADA90'];
$cards[] = ['rotulo' => $IDADE['MAIS'][0], 'valor' => $contI['MAIS'], 'icone' => 'bi-hourglass-bottom', 'tom' => $contI['MAIS'] ? 'red' : 'green', 'grupo' => 'I_MAIS'];
$cards[] = ['rotulo' => $T('c.sem_pessoa', 'Sem pessoa ou com usuário inativo', 'No person or inactive user', 'Sin persona o usuario inactivo'), 'valor' => $semPessoa + $inativos, 'icone' => 'bi-person-x', 'tom' => ($semPessoa + $inativos) ? 'orange' : 'green', 'grupo' => 'SEMPESSOA',
    'extra' => $inativos ? $inativos . ' ' . $T('c.inativos', 'com usuário inativo', 'with inactive user', 'con usuario inactivo') : ''];
$cards[] = ['rotulo' => $T('c.acoes', 'Ações de plano vencidas', 'Overdue action items', 'Acciones de plan vencidas'), 'valor' => count($rowsAc), 'icone' => 'bi-kanban', 'tom' => $rowsAc ? 'red' : 'green', 'grupo' => '', 'grid' => 'hnAcoes'];

$filtrosHtml = HomeModulo::filtros([
    'u' => ['rotulo' => $T('f.unidade', 'Unidade', 'Unit', 'Unidad'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $unidades],
    't' => ['rotulo' => $T('f.tipo', 'Tipo', 'Type', 'Tipo'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $tipos],
    'e' => ['rotulo' => $T('f.etapa', 'Etapa', 'Stage', 'Etapa'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => array_map(static fn($x) => $x[0], $FASES)],
    'r' => ['rotulo' => $T('f.pessoa', 'Com quem está', 'Held by', 'Con quién está'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $pessoas],
], $f, ['mes' => $mes !== date('Y-m') ? $mes : '']);
$abas = DashboardGrid::subabas('hnAbas', [
    ['id' => 'graficos', 'icone' => 'bi-bar-chart', 'rotulo' => $T('sub.graficos', 'Gráficos', 'Charts', 'Gráficos'), 'html' => $graficos],
    ['id' => 'lista', 'icone' => 'bi-table', 'rotulo' => $T('sub.lista', 'Abertas', 'Open', 'Abiertas'), 'contagem' => count($lista), 'html' => $gridLista],
    ['id' => 'calendario', 'icone' => 'bi-calendar3', 'rotulo' => $T('sub.calendario', 'Calendário', 'Calendar', 'Calendario'), 'contagem' => count($rowsCal), 'html' => $calendario],
    ['id' => 'acoes', 'icone' => 'bi-kanban', 'rotulo' => $T('sub.acoes', 'Ações vencidas', 'Overdue actions', 'Acciones vencidas'), 'contagem' => count($rowsAc), 'html' => $gridAc],
], true);

$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();
$titulo = HomeModulo::MENUS['NC']['nome'][$idioma] ?? HomeModulo::MENUS['NC']['nome']['pt'];
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php HomeModulo::head(); ?>
</head>
<body class="theme-<?= $e($theme) ?>" data-transacao="home_nao_conformidades"><main class="hm">
<?= HomeModulo::cabecalho('bi-exclamation-diamond', $titulo,
    $e($T('desc', 'Ocorrências abertas por etapa, com quem estão, há quanto tempo e com que prazo, e as ações de plano vencidas. Os filtros do topo valem para a página toda; clique num cartão, numa barra ou num dia do calendário para listar. Duplo clique abre a ocorrência na etapa atual.',
        'Open occurrences by stage, who holds them, for how long and with which deadline, and the overdue action items. The filters apply to the whole page; click a card, a bar or a calendar day to list. Double-click opens the occurrence at its current stage.',
        'Ocurrencias abiertas por etapa, con quién están, desde cuándo y con qué plazo, y las acciones vencidas. Los filtros valen para toda la página; haga clic en una tarjeta, una barra o un día para listar. Doble clic abre la ocurrencia en la etapa actual.')), $aviso) ?>
<?= $filtrosHtml ?>
<?= HomeModulo::cartoes($G, $cards) ?>
<?= $abas ?>
</main>
<?php HomeModulo::scripts(); ?>
</body></html>
