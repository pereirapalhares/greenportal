<?php
declare(strict_types=1);
/*
 * Dashboard da empresa (ADMINISTRADOR e ADM DA EMPRESA), em abas:
 *   Visão geral · Atendimento legal · Licenças e entregas · Pendências e equipe · Não conformidades · Fornecedores ·
 *   Administração do sistema (só ADMINISTRADOR). Migração 366: Não conformidades, situação da homologação em Fornecedores e
 *   os resumos de NC e Fornecedores na Visão geral (classes/DashboardNcFornecedores.php).
 *   Migração 368: listas no grid padrão do portal (classes/DashboardGrid.php) e os quadros de cada aba em sub-abas.
 * A Visão geral vem com a página; as outras abas são carregadas quando abertas (dashboard_admin.php?aba=...).
 * Dados só por views (migração 285: vw_dash_*; migração 284: situação legal) lidas com sp_crud_select.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) {
    http_response_code(403); exit(IdiomaService::t('dashboard.erro.acesso', ['pt' => 'Acesso não autorizado.', 'en' => 'Unauthorized access.', 'es' => 'Acceso no autorizado.']));
}
$env = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2); $env[trim($key)] = trim(trim($value), "\"'");
}
$database = (string)($_SESSION['tenant']['banco_dados'] ?? '');
if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) { http_response_code(500); exit(IdiomaService::t('dashboard.erro.tenant', ['pt' => 'Empresa inválida.', 'en' => 'Invalid company.', 'es' => 'Empresa inválida.'])); }
$companyName = trim((string)($_SESSION['tenant']['nome'] ?? $_SESSION['tenant']['empresa_nome'] ?? ''));
if ($companyName === '') {
    $companyName = preg_replace('/^g/i', '', preg_replace('/_dbp$/i', '', $database)) ?: $database;
    $companyName = ucwords(str_replace('_', ' ', $companyName));
}
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4', $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/DashboardNcFornecedores.php';
require_once dirname(__DIR__) . '/classes/DashboardGrid.php';
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(403); exit(IdiomaService::t('dashboard.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
$perfil = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$perfil = preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', $perfil) ?? $perfil) ?? $perfil;
$perfilGestor = in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA', 'GESTOR'], true);
$administrador = $perfil === 'ADMINISTRADOR';
if (!$perfilGestor && empty(PermissaoTransacao::obter('DASHBOARD')['pode_visualizar_total'])) { http_response_code(403); exit(IdiomaService::t('dashboard.erro.acesso_geral', ['pt' => 'Acesso não autorizado ao dashboard geral.', 'en' => 'Unauthorized access to the general dashboard.', 'es' => 'Acceso no autorizado al panel general.'])); }
$aba = preg_replace('/[^a-z]/', '', (string)($_GET['aba'] ?? ''));
if ($perfilGestor) {
    if ($aba === '') { require_once dirname(__DIR__) . '/classes/AuditService.php'; AuditService::record($pdo, 'DASHBOARD', 'ACESSAR', 'SUCESSO', 'transacao', 'DASHBOARD'); }
} else {
    $pdo = TransactionSecurity::connect('DASHBOARD', 'pode_visualizar');
}

// ---------------------------------------------------------------- auxiliares
$T = static fn(string $chave, array $textos): string => IdiomaService::t('dashboard.' . $chave, $textos);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sel = static function (string $view, array $filtros = [], int $limite = 1000) use ($pdo): ?array {
    try {
        $linhas = [];
        for ($offset = 0; ; $offset += 1000) {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :l, :o)');
            $s->execute(['t' => $view, 'f' => json_encode($filtros, JSON_UNESCAPED_UNICODE), 'l' => min(1000, $limite - count($linhas)), 'o' => $offset]);
            $lote = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            array_push($linhas, ...$lote);
            if (count($lote) < 1000 || count($linhas) >= $limite) break;
        }
        return $linhas;
    } catch (Throwable) { return null; }
};
$pct = static fn(int $p, int $t): string => $t > 0 ? number_format(100 * $p / $t, 1, ',', '.') . '%' : '–';
$semMigracao = static fn(string $m) => '<div class="alert alert-warning small mb-0"><i class="bi bi-info-circle me-1"></i>' . $e($T('aviso.migracao', ['pt' => 'Os indicadores desta aba ainda não estão disponíveis nesta empresa. Rode a migração', 'en' => 'The indicators of this tab are not available for this company yet. Run migration', 'es' => 'Los indicadores de esta pestaña aún no están disponibles en esta empresa. Ejecute la migración'])) . ' ' . $e($m) . '.</div>';
$rotSit = ['S' => $T('sit.s', ['pt' => 'Atendido', 'en' => 'Compliant', 'es' => 'Atendido']), 'PA' => $T('sit.pa', ['pt' => 'Parcialmente atendido', 'en' => 'Partially compliant', 'es' => 'Parcialmente atendido']),
    'N' => $T('sit.n', ['pt' => 'Não atendido', 'en' => 'Not compliant', 'es' => 'No atendido']), 'AI' => $T('sit.ai', ['pt' => 'Indefinido', 'en' => 'Undefined', 'es' => 'Indefinido'])];
$corSit = ['S' => '#2f8a5f', 'PA' => '#d39e00', 'N' => '#c95b55', 'AI' => '#9aa8a1'];
$barra = static function (array $c) use ($corSit): string {
    $t = array_sum($c); if ($t <= 0) return '';
    $h = '<div class="dash-barra">';
    foreach (['S', 'PA', 'N', 'AI'] as $k) if (($c[$k] ?? 0) > 0) $h .= '<span style="width:' . number_format(100 * $c[$k] / $t, 2, '.', '') . '%;background:' . $corSit[$k] . '" title="' . (int)$c[$k] . '"></span>';
    return $h . '</div>';
};
// Link para abrir a transação em popup já filtrada no caso (ex.: a licença, a pessoa, a unidade).
// A própria transação confere a permissão do usuário ao abrir.
$abrir = static function (string $pagina, array $filtros = [], string $titulo = '', array $outros = []) use ($e): string {
    $q = $outros + ($filtros ? ['filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE)] : []);
    return ' data-abrir-transacao="' . $e($pagina . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '')) . '" data-titulo="' . $e($titulo) . '"';
};
$cartao = static fn(string $rotulo, mixed $valor, string $icone, string $tom, string $extra = '', string $abaAlvo = '', string $atributos = ''): string =>
    '<article class="admin-card ' . $tom . ($abaAlvo !== '' ? ' dash-link" data-ir-aba="' . $abaAlvo : '') . ($atributos !== '' ? ' dash-link"' . $atributos . ' title="' . $e($T('abrir_dica', ['pt' => 'Clique para abrir', 'en' => 'Click to open', 'es' => 'Haga clic para abrir'])) : '') . '"><i class="bi ' . $icone . '"></i><strong>' . $valor . '</strong><span>' . $rotulo . '</span>' . ($extra !== '' ? '<small>' . $extra . '</small>' : '') . '</article>';
$numLink = static fn(int $n, string $atributos, string $classe = ''): string => $n > 0 ? '<a class="dash-num' . ($classe !== '' ? ' ' . $classe : '') . '"' . $atributos . '>' . $n . '</a>' : '0';

// Migração 367: o cartão filtra a tabela #id da mesma aba (assets/js/dashboard-abas.js)
$filtro = static fn(string $id, string $valor, string $rotulo): string => ' data-dash-filtro="' . $e($id) . '" data-dash-valor="' . $e($valor) . '" data-dash-rotulo="' . $e($rotulo) . '"';
// Migração 341: lente do risco no Atendimento legal (cartões, matriz risco × % de atendimento, por unidade, pendências críticas)
$rotRisco = ['CRITICO' => [$T('risco.critico', ['pt' => 'Crítico', 'en' => 'Critical', 'es' => 'Crítico']), '#dc3545', 'Crítico'], 'ALTO' => [$T('risco.alto', ['pt' => 'Alto', 'en' => 'High', 'es' => 'Alto']), '#fd7e14', 'Alto'],
    'MODERADO' => [$T('risco.moderado', ['pt' => 'Moderado', 'en' => 'Moderate', 'es' => 'Moderado']), '#d39e00', 'Moderado'], 'BAIXO' => [$T('risco.baixo', ['pt' => 'Baixo', 'en' => 'Low', 'es' => 'Bajo']), '#2f8a5f', 'Baixo'],
    'NAO_AVALIADO' => [$T('risco.nao_avaliado', ['pt' => 'Não avaliado', 'en' => 'Not assessed', 'es' => 'No evaluado']), '#9aa8a1', 'Não avaliado']];
$blocoRisco = static function (array $unidades, array $matriz, callable $abrirReq) use ($e, $T, $cartao, $rotRisco): string {
    $t = ['ITENS_APLICAVEIS' => 0, 'ITENS_ATENDIDOS' => 0, 'PENDENCIAS_CRITICAS' => 0, 'REQUISITOS_PENDENCIA_CRITICA' => 0];
    foreach (array_keys($rotRisco) as $k) $t['RISCO_' . $k] = 0;
    foreach ($unidades as $u) foreach ($t as $k => $_) $t[$k] += (int)($u[$k] ?? 0);
    $pctItens = $t['ITENS_APLICAVEIS'] > 0 ? 100 * $t['ITENS_ATENDIDOS'] / $t['ITENS_APLICAVEIS'] : null;
    $fmt = static fn(?float $v): string => $v === null ? '–' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',') . '%';
    $h = '<h3 class="dash-secao mt-3"><i class="bi bi-shield-exclamation me-1"></i>' . $e($T('risco.titulo', ['pt' => 'Atendimento e risco (duas dimensões)', 'en' => 'Compliance and risk (two dimensions)', 'es' => 'Cumplimiento y riesgo (dos dimensiones)'])) . '</h3>'
        . '<div class="admin-cards dash-cards-6">'
        . $cartao($e($T('risco.pct_itens', ['pt' => '% de atendimento (itens aplicáveis)', 'en' => 'Compliance % (applicable items)', 'es' => '% de cumplimiento (ítems aplicables)'])), $fmt($pctItens), 'bi-graph-up-arrow', 'green', $t['ITENS_ATENDIDOS'] . ' / ' . $t['ITENS_APLICAVEIS'], '', ' data-ir-ancora="dash-risco-matriz"')
        . $cartao($e($T('risco.pendencias', ['pt' => 'Pendências críticas — ação imediata', 'en' => 'Critical pending — immediate action', 'es' => 'Pendientes críticos — acción inmediata'])), $t['PENDENCIAS_CRITICAS'], 'bi-exclamation-octagon', 'red', $t['REQUISITOS_PENDENCIA_CRITICA'] . ' ' . $e($T('risco.requisitos', ['pt' => 'requisito(s)', 'en' => 'requirement(s)', 'es' => 'requisito(s)'])), '', ' data-ir-ancora="dash-pendencias-criticas"');
    foreach (['CRITICO' => ['bi-fire', 'red'], 'ALTO' => ['bi-exclamation-triangle', 'orange'], 'MODERADO' => ['bi-dash-circle', 'orange'], 'NAO_AVALIADO' => ['bi-question-diamond', 'gray']] as $k => [$ic, $tom])
        $h .= $cartao($e($T('risco.req_' . strtolower($k), ['pt' => 'Requisitos de risco ' . $rotRisco[$k][2], 'en' => 'Requirements with risk ' . $rotRisco[$k][0], 'es' => 'Requisitos de riesgo ' . $rotRisco[$k][0]])), $t['RISCO_' . $k], $ic, $tom, '', '',
            $abrirReq(['RISCO_REQUISITO' => ['value' => $rotRisco[$k][2], 'exact' => true]], $rotRisco[$k][0]));
    $h .= '</div><!--dash-split-->';
    // Matriz: requisitos por risco (linhas) × faixa de % de atendimento (colunas) — não mistura as duas dimensões
    $faixas = ['F100' => '100%', 'F50' => '50–99%', 'F1' => '1–49%', 'F0' => '0%'];
    $m = [];
    foreach ($matriz as $l) $m[(string)$l['RISCO']][(string)$l['FAIXA']] = ($m[(string)$l['RISCO']][(string)$l['FAIXA']] ?? 0) + (int)$l['TOTAL'];
    $h .= '<div class="admin-grid dash-grid-2"><article class="admin-panel" id="dash-risco-matriz"><h3><i class="bi bi-grid-3x3 me-1"></i>' . $e($T('risco.matriz', ['pt' => 'Requisitos: risco × % de atendimento', 'en' => 'Requirements: risk × compliance %', 'es' => 'Requisitos: riesgo × % de cumplimiento'])) . '</h3>'
        . '<table class="admin-table dash-matriz"><thead><tr><th>' . $e($T('risco.col_risco', ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'])) . '</th>';
    foreach ($faixas as $f) $h .= '<th class="num">' . $e($f) . '</th>';
    $h .= '<th class="num">' . $e($T('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'])) . '</th></tr></thead><tbody>';
    foreach ($rotRisco as $k => [$rot, $cor]) {
        $linha = $m[$k] ?? []; $tl = array_sum(array_intersect_key($linha, $faixas));
        $h .= '<tr><td><span class="dash-risco-ponto" style="background:' . $cor . '"></span>' . $e($rot) . '</td>';
        foreach (array_keys($faixas) as $f) { $n = (int)($linha[$f] ?? 0); $alerta = $n > 0 && $k === 'CRITICO' && $f !== 'F100'; $h .= '<td class="num' . ($alerta ? ' dash-matriz-alerta' : '') . '">' . ($n ?: '·') . '</td>'; }
        $h .= '<td class="num"><b>' . $tl . '</b></td></tr>';
    }
    $semItens = array_sum($m['SEM_ITENS'] ?? []);
    $h .= '</tbody></table><p class="dash-nota">' . $e($T('risco.matriz_nota', ['pt' => 'Risco do requisito = pior risco entre os itens aplicáveis; % = itens atendidos ÷ itens aplicáveis. Em vermelho: risco Crítico sem atendimento total.', 'en' => 'Requirement risk = worst risk among the applicable items; % = items met ÷ applicable items. In red: Critical risk without full compliance.', 'es' => 'Riesgo del requisito = peor riesgo entre los ítems aplicables; % = ítems cumplidos ÷ ítems aplicables. En rojo: riesgo Crítico sin cumplimiento total.']))
        . ($semItens ? ' ' . $semItens . ' ' . $e($T('risco.sem_itens', ['pt' => 'requisito(s) sem itens aplicáveis.', 'en' => 'requirement(s) without applicable items.', 'es' => 'requisito(s) sin ítems aplicables.'])) : '') . '</p></article>';
    // Por unidade
    usort($unidades, static fn($a, $b) => strnatcasecmp((string)$a['UNIDADE'], (string)$b['UNIDADE']));
    $h .= '<article class="admin-panel"><h3><i class="bi bi-buildings me-1"></i>' . $e($T('risco.por_unidade', ['pt' => 'Risco por unidade', 'en' => 'Risk by unit', 'es' => 'Riesgo por unidad'])) . '</h3><div class="dash-rolagem"><table class="admin-table"><thead><tr><th>' . $e($T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) . '</th><th class="num">%</th>';
    foreach ($rotRisco as [$rot, $cor]) $h .= '<th class="num" title="' . $e($rot) . '"><span class="dash-risco-ponto" style="background:' . $cor . '"></span>' . $e(mb_substr($rot, 0, 4)) . '</th>';
    $h .= '<th class="num" title="' . $e($T('risco.pendencias', ['pt' => 'Pendências críticas — ação imediata', 'en' => 'Critical pending — immediate action', 'es' => 'Pendientes críticos — acción inmediata'])) . '"><i class="bi bi-exclamation-octagon"></i></th></tr></thead><tbody>';
    foreach ($unidades as $u) {
        $h .= '<tr><td>' . $e($u['UNIDADE']) . '</td><td class="num">' . $fmt($u['PCT_ATENDIMENTO'] === null ? null : (float)$u['PCT_ATENDIMENTO']) . '</td>';
        foreach (array_keys($rotRisco) as $k) $h .= '<td class="num">' . ((int)$u['RISCO_' . $k] ?: '·') . '</td>';
        $h .= '<td class="num' . ((int)$u['PENDENCIAS_CRITICAS'] > 0 ? ' status-danger' : '') . '"><b>' . (int)$u['PENDENCIAS_CRITICAS'] . '</b></td></tr>';
    }
    return $h . '</tbody></table></div></article></div>';
};
$listasRisco = static function (array $pend, array $semRisco) use ($e, $T, $abrir): string {
    $area = ['LICENCAS' => $T('area.lic', ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']), 'MEDICAO' => $T('area.med', ['pt' => 'Medição', 'en' => 'Measurement', 'es' => 'Medición']), 'TREINAMENTOS' => $T('area.tre', ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación']),
        'INFRAESTRUTURA' => $T('area.inf', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura']), 'CONTROLES' => $T('area.con', ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control']), 'HOMOLOGACAO' => $T('area.for', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])];
    $status = ['FALHA' => $T('st.falha', ['pt' => 'Falha', 'en' => 'Failure', 'es' => 'Falla']), 'CRITICO' => $T('st.vencido', ['pt' => 'Vencido', 'en' => 'Expired', 'es' => 'Vencido'])];
    // Uma linha por pendência (requisito × item) com os responsáveis juntos; e o total por responsável, em evidência
    $linhas = []; $porResp = [];
    foreach ($pend as $p) {
        $k = $p['CODLEGISLACAO'] . ':' . $p['CODUNIDADE'] . ':' . $p['AREA'] . ':' . $p['ITEM_ID'];
        $linhas[$k] ??= $p + ['RESPS' => []];
        $nome = trim((string)$p['RESPONSAVEL_ITEM']);
        if ($nome !== '') { $linhas[$k]['RESPS'][$nome] = true; $porResp[$nome][$k] = true; }
        else $porResp['—'][$k] = true;
    }
    $h = '<div id="dash-pendencias-criticas"><article class="admin-panel dash-pendencias"><h3 class="text-danger"><i class="bi bi-exclamation-octagon me-1"></i>' . $e($T('risco.lista_pend', ['pt' => 'Pendências críticas — ação imediata', 'en' => 'Critical pending — immediate action', 'es' => 'Pendientes críticos — acción inmediata'])) . ' (' . count($linhas) . ')</h3>';
    if (!$linhas) $h .= '<div class="dash-nota">' . $e($T('risco.lista_pend_vazia', ['pt' => 'Nenhum item não atendido com risco Crítico.', 'en' => 'No item not met with Critical risk.', 'es' => 'Ningún ítem no cumplido con riesgo Crítico.'])) . '</div>';
    else {
        // Migração 368: grid padrão
        $rows = [];
        foreach ($linhas as $p) {
            $norma = trim(($p['DSCTIPO'] ?? '') . ' ' . ($p['NR'] ?? '') . (($p['ANO'] ?? '') !== '' ? '/' . $p['ANO'] : ''));
            $item = ($area[(string)$p['AREA']] ?? $p['AREA']) . ': ' . $p['ITEM'];
            $resps = $p['RESPS'] ? implode(', ', array_keys($p['RESPS'])) : $T('risco.sem_resp', ['pt' => 'sem responsável', 'en' => 'no owner', 'es' => 'sin responsable']);
            $rows[] = ['cells' => ['UNIDADE' => (string)$p['UNIDADE'], 'CODIGO' => ['text' => (string)($p['CODIGO'] ?? ''), 'title' => $norma], 'ITEM' => ['text' => $item, 'title' => $item],
                'STATUS' => ['text' => (string)($status[(string)$p['STATUS']] ?? $p['STATUS']), 'html' => '<span class="badge text-bg-danger">' . $e($status[(string)$p['STATUS']] ?? $p['STATUS']) . '</span> <small class="text-muted">' . (int)$p['SEVERIDADE'] . '×' . (int)$p['PROBABILIDADE'] . '</small>'],
                'MOTIVO' => ['text' => (string)$p['MOTIVO'], 'title' => (string)$p['MOTIVO']], 'RESP' => ['text' => $resps, 'html' => $p['RESPS'] ? '<b>' . $e($resps) . '</b>' : '<span class="text-danger">' . $e($resps) . '</span>']],
                'open' => DashboardGrid::abrirSituacao((int)$p['CODLEGISLACAO'], (int)$p['CODUNIDADE'], (string)($p['CODIGO'] ?? ''))];
        }
        $h .= DashboardGrid::grid('dash-pend-criticas', [
            'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
            'CODIGO' => ['label' => $T('col.requisito', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito']), 'width' => '110px'],
            'ITEM' => ['label' => $T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])],
            'STATUS' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '120px'],
            'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
            'RESP' => ['label' => $T('col.responsavel', ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '180px'],
        ], $rows, ['transacao' => 'dashboard_admin']);
    }
    $h .= '</article></div><!--dash-split--><article class="admin-panel"><h3><i class="bi bi-person-exclamation me-1"></i>' . $e($T('risco.responsaveis', ['pt' => 'Responsáveis em evidência (pendências críticas)', 'en' => 'Owners in focus (critical pending)', 'es' => 'Responsables destacados (pendientes críticos)'])) . '</h3>';
    if (!$porResp) $h .= '<div class="dash-nota">–</div>';
    else {
        uasort($porResp, static fn($a, $b) => count($b) <=> count($a));
        $h .= '<table class="admin-table"><tbody>';
        foreach ($porResp as $nome => $ks) $h .= '<tr><td>' . ($nome === '—' ? '<span class="text-danger">' . $e($T('risco.sem_resp', ['pt' => 'sem responsável', 'en' => 'no owner', 'es' => 'sin responsable'])) . '</span>' : '<b>' . $e($nome) . '</b>') . '</td><td class="num status-danger"><b>' . count($ks) . '</b></td></tr>';
        $h .= '</tbody></table>';
    }
    $h .= '<h3 class="mt-3"><i class="bi bi-question-diamond me-1"></i>' . $e($T('risco.nao_avaliados', ['pt' => 'Itens com risco não avaliado', 'en' => 'Items with risk not assessed', 'es' => 'Ítems con riesgo no evaluado'])) . ' (' . count($semRisco) . (count($semRisco) >= 500 ? '+' : '') . ')</h3>';
    if (!$semRisco) $h .= '<div class="dash-nota">' . $e($T('risco.nao_avaliados_vazio', ['pt' => 'Todos os itens vinculados têm severidade e probabilidade.', 'en' => 'All linked items have severity and probability.', 'es' => 'Todos los ítems vinculados tienen severidad y probabilidad.'])) . '</div>';
    else {
        $porArea = [];
        foreach ($semRisco as $i) $porArea[(string)$i['AREA']][] = $i;
        // Migração 342: a IA sugere a Severidade e a Probabilidade (pedido e revisão na tela Sugestões de risco (IA))
        if (is_file(__DIR__ . '/modulolegal_risco_sugestoes.php')) $h .= '<div class="mb-2"><button type="button" class="btn btn-sm btn-outline-primary"' . $abrir('modulolegal_risco_sugestoes.php', [], $T('risco.ia_titulo', ['pt' => 'Sugestões de risco (IA)', 'en' => 'Risk suggestions (AI)', 'es' => 'Sugerencias de riesgo (IA)']))
            . '><i class="bi bi-stars me-1"></i>' . $e($T('risco.ia_botao', ['pt' => 'Sugerir risco com IA para os itens não avaliados', 'en' => 'Suggest risk with AI for items not assessed', 'es' => 'Sugerir riesgo con IA para los ítems no evaluados'])) . '</button> <small class="text-muted">' . $e($T('risco.ia_nota', ['pt' => 'A IA propõe; você aceita, ajusta ou recusa.', 'en' => 'The AI proposes; you accept, adjust or reject.', 'es' => 'La IA propone; usted acepta, ajusta o rechaza.'])) . '</small></div>';
        $h .= '<p class="dash-nota">' . $e($T('risco.nao_avaliados_nota', ['pt' => 'Pendência de cadastro: informe a Severidade e a Probabilidade no cadastro de cada item (Licenças, Medição e monitoramento, Treinamentos, Infraestrutura, Controles, Fornecedores).', 'en' => 'Registration pending: fill in Severity and Probability in each item register.', 'es' => 'Pendiente de registro: informe la Severidad y la Probabilidad en el registro de cada ítem.'])) . '</p><div class="dash-rolagem"><table class="admin-table"><thead><tr><th>' . $e($T('col.area', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área'])) . '</th><th>' . $e($T('col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])) . '</th><th class="num">' . $e($T('col.requisitos', ['pt' => 'Requisitos', 'en' => 'Requirements', 'es' => 'Requisitos'])) . '</th></tr></thead><tbody>';
        foreach ($porArea as $a => $itens) foreach ($itens as $i) $h .= '<tr><td>' . $e($area[$a] ?? $a) . '</td><td>' . $e($i['ITEM']) . '</td><td class="num">' . (int)$i['REQUISITOS'] . '</td></tr>';
        $h .= '</tbody></table></div>';
    }
    return $h . '</article>';
};

// ---------------------------------------------------------------- abas (fragmentos)
if ($aba !== '') {
    header('Content-Type: text/html; charset=utf-8');
    if ($aba === 'legal') {
        $unidades = $sel('vw_dash_legal_unidade');
        if ($unidades === null) exit($semMigracao('284 e 285'));
        $tot = ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0]; $semEv = 0; $div = 0; $areas = [];
        $nomesArea = ['MA' => $T('area.ma', ['pt' => 'Meio ambiente', 'en' => 'Environment', 'es' => 'Medio ambiente']), 'SEG' => $T('area.seg', ['pt' => 'Segurança', 'en' => 'Safety', 'es' => 'Seguridad']),
            'QUA' => $T('area.qua', ['pt' => 'Qualidade', 'en' => 'Quality', 'es' => 'Calidad']), 'SOC' => $T('area.soc', ['pt' => 'Social', 'en' => 'Social', 'es' => 'Social'])];
        foreach ($nomesArea as $p => $n) $areas[$p] = ['TOTAL' => 0, 'S' => 0, 'PA' => 0, 'N' => 0];
        $colArea = ['MA' => 'MEIO_AMBIENTE', 'SEG' => 'SEGURANCA', 'QUA' => 'QUALIDADE', 'SOC' => 'RESPOSABILIDADE'];
        foreach ($unidades as $u) {
            foreach ($tot as $k => $_) $tot[$k] += (int)$u[$k];
            $semEv += (int)$u['SEM_EVIDENCIA']; $div += (int)$u['DIVERGENTES'];
            foreach ($areas as $p => $_) foreach (['TOTAL', 'S', 'PA', 'N'] as $k) $areas[$p][$k] += (int)($u[$p . '_' . $k] ?? 0);
        }
        usort($unidades, static fn($a, $b) => strnatcasecmp((string)$a['UNIDADE'], (string)$b['UNIDADE']));
        $total = array_sum($tot);
        $filtroBase = ['LEI_REVOGADA' => 'NAO'];
        $naoAtendidos = $sel('vw_modulolegal_requisitos_situacao_grid', $filtroBase + ['SITUACAO_CONSOLIDADA' => 'Não atendido'], 5000) ?? [];
        $divergentes = $sel('vw_modulolegal_requisitos_situacao_grid', $filtroBase + ['DIVERGENCIA' => 'Sim'], 5000) ?? [];
        $relatorios = $sel('exportacoes_grid', ['transacao' => 'modulolegal_relatorio_atendimento'], 1000) ?? [];
        usort($relatorios, static fn($a, $b) => (int)$b['codexportacaogrid'] <=> (int)$a['codexportacaogrid']);
        $relatorios = array_slice($relatorios, 0, 6);
        $tituloReq = $T('legal.requisitos_legais', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']);
        $valorSit = ['S' => 'Atendido', 'PA' => 'Parcialmente atendido', 'N' => 'Não atendido', 'AI' => 'Indefinido'];
        $abrirReq = static fn(array $f, string $sub) => $abrir('modulolegal_requisitoslegais.php', ['LEI_REVOGADA' => 'NAO'] + $f, $tituloReq . ' - ' . $sub);
        $h = '<div class="admin-cards dash-cards-6">'
            . $cartao($e($T('legal.requisitos', ['pt' => 'Requisitos aplicáveis', 'en' => 'Applicable requirements', 'es' => 'Requisitos aplicables'])), $total, 'bi-journal-text', 'blue', '', '', $abrir('modulolegal_requisitoslegais.php', ['LEI_REVOGADA' => 'NAO'], $tituloReq));
        foreach (['S' => ['bi-check-circle', 'green'], 'PA' => ['bi-dash-circle', 'orange'], 'N' => ['bi-x-circle', 'red'], 'AI' => ['bi-question-circle', 'gray']] as $k => [$ic, $tom])
            $h .= $cartao($e($rotSit[$k]), $tot[$k], $ic, $tom, $pct($tot[$k], $total), '', $abrirReq(['SITUACAO_CONSOLIDADA' => ['value' => $valorSit[$k], 'exact' => true]], $rotSit[$k]));
        $h .= $cartao($e($T('legal.pct', ['pt' => '% atendido (com situação definida)', 'en' => '% compliant (defined status)', 'es' => '% atendido (situación definida)'])), $pct($tot['S'], $total - $tot['AI']), 'bi-graph-up', 'green', '', '', ' data-ir-ancora="dash-legal-unidade"')
            . '</div>' . '<div class="dash-barra-grande">' . $barra($tot) . '</div>'
            . '<p class="dash-nota">' . $e($T('legal.nota', ['pt' => 'Situação consolidada: a calculada pelos itens de gestão vinculados ou, sem itens vinculados ou com a avaliação mantida pelo responsável, a avaliação manual.', 'en' => 'Consolidated status: calculated from the linked management items or, when there are none or the owner kept the assessment, the manual assessment.', 'es' => 'Situación consolidada: la calculada por los ítems de gestión vinculados o, sin ítems vinculados o con la evaluación mantenida por el responsable, la evaluación manual.']))
            . ' ' . $semEv . ' ' . $e($T('legal.sem_ev', ['pt' => 'sem evidências vinculadas', 'en' => 'without linked evidence', 'es' => 'sin evidencias vinculadas'])) . ' · ' . $div . ' ' . $e($T('legal.div', ['pt' => 'com divergência', 'en' => 'divergent', 'es' => 'con divergencia'])) . '.</p>';
        $h .= '<div class="dash-acoes"><button type="button" class="btn btn-sm btn-success" data-gerar-relatorio="' . $e(json_encode(['LEI_REVOGADA' => 'NAO'])) . '"><i class="bi bi-file-earmark-bar-graph me-1"></i>' . $e($T('legal.gerar', ['pt' => 'Gerar Relatório de Atendimento Legal (PDF e Excel)', 'en' => 'Generate Legal Compliance Report (PDF and Excel)', 'es' => 'Generar Informe de Cumplimiento Legal (PDF y Excel)'])) . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary"' . $abrir('modulolegal_requisitoslegais.php', [], $tituloReq) . '><i class="bi bi-box-arrow-up-right me-1"></i>' . $e($T('legal.abrir_requisitos', ['pt' => 'Abrir Requisitos legais', 'en' => 'Open legal requirements', 'es' => 'Abrir requisitos legales'])) . '</button>';
        if ($relatorios) {
            $h .= '<span class="dash-relatorios"><span class="text-muted">' . $e($T('legal.ultimos', ['pt' => 'Últimos relatórios:', 'en' => 'Latest reports:', 'es' => 'Últimos informes:'])) . '</span>';
            foreach ($relatorios as $r) {
                $arq = (string)($r['arquivo'] ?? ''); $quando = $r['concluido_em'] ?? $r['solicitado_em'] ?? '';
                $rot = (str_ends_with(strtolower($arq), '.pdf') ? 'PDF' : (str_ends_with(strtolower($arq), '.xlsx') ? 'Excel' : '')) . ' ' . ($quando ? date('d/m H:i', strtotime((string)$quando)) : '');
                $h .= (string)$r['status'] === 'CONCLUIDA' && $arq !== '' ? (str_ends_with(strtolower($arq), '.pdf') ? '<a href="..' . $e($arq) . '" data-abrir-arquivo data-titulo="' . $e(basename($arq)) . '"><i class="bi bi-file-earmark-pdf me-1"></i>' : '<a href="..' . $e($arq) . '" download><i class="bi bi-download me-1"></i>') . $e(trim($rot)) . '</a>'
                    : '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>' . $e($T('legal.gerando', ['pt' => 'gerando', 'en' => 'generating', 'es' => 'generando'])) . '</span>';
            }
            $h .= '</span>';
        }
        $h .= '</div>';
        // Migração 341: as duas dimensões do requisito — % de atendimento e risco — e as pendências críticas (ação imediata)
        $riscoUn = $sel('vw_dash_legal_risco_unidade');
        $pendCrit = $riscoUn !== null ? ($sel('vw_dash_legal_pendencias_criticas', [], 500) ?? []) : [];
        $semRisco = $riscoUn !== null ? ($sel('vw_ml_risco_nao_avaliados', [], 500) ?? []) : [];
        $quadroRisco = '';
        if ($riscoUn !== null) { [$cardsRisco, $quadroRisco] = explode('<!--dash-split-->', $blocoRisco($riscoUn, $sel('vw_dash_legal_risco_matriz', [], 5000) ?? [], $abrirReq), 2); $h .= $cardsRisco; }
        $cardsLegal = $h; $h = '<div class="admin-grid dash-grid-2">';
        $tabelaCont = static function (string $titulo, array $linhas) use ($e, $T, $pct, $barra, $rotSit, $abrirReq, $valorSit, $numLink): string {
            $t = '<table class="admin-table"><thead><tr><th>' . $e($titulo) . '</th><th class="num">' . $e($T('col.total', ['pt' => 'Total', 'en' => 'Total', 'es' => 'Total'])) . '</th><th class="num">' . $e($rotSit['S']) . '</th><th class="num">' . $e($T('col.parcial', ['pt' => 'Parcial', 'en' => 'Partial', 'es' => 'Parcial'])) . '</th><th class="num">' . $e($rotSit['N']) . '</th><th class="num" title="' . $e($T('legal.pct', ['pt' => '% atendido (com situação definida)', 'en' => '% compliant (defined status)', 'es' => '% atendido (situación definida)'])) . '">% ' . $e($T('legal.pct_curto', ['pt' => 'atendido', 'en' => 'compliant', 'es' => 'atendido'])) . '*</th><th style="width:28%"></th></tr></thead><tbody>';
            foreach ($linhas as [$nome, $c, $f]) {
                $tt = array_sum($c);
                $t .= '<tr class="dash-clique"' . $abrirReq($f, $nome) . '><td>' . $e($nome) . '</td><td class="num">' . $tt . '</td>';
                foreach (['S' => '', 'PA' => '', 'N' => 'status-danger'] as $k => $cls)
                    $t .= '<td class="num">' . $numLink($c[$k], $abrirReq($f + ['SITUACAO_CONSOLIDADA' => ['value' => $valorSit[$k], 'exact' => true]], $nome . ' - ' . $rotSit[$k]), $cls) . '</td>';
                $t .= '<td class="num">' . $pct($c['S'], $tt - $c['AI']) . '</td><td>' . $barra($c) . '</td></tr>';
            }
            return $t . '</tbody></table>';
        };
        $h .= '<article class="admin-panel" id="dash-legal-unidade"><h3><i class="bi bi-buildings me-1"></i>' . $e($T('legal.por_unidade', ['pt' => 'Por unidade', 'en' => 'By unit', 'es' => 'Por unidad'])) . '</h3>'
            . $tabelaCont($T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), array_map(static fn($u) => [(string)$u['UNIDADE'], ['S' => (int)$u['S'], 'PA' => (int)$u['PA'], 'N' => (int)$u['N'], 'AI' => (int)$u['AI']], ['UNIDADE' => ['value' => (string)$u['UNIDADE'], 'exact' => true]]], $unidades)) . '</article>';
        $h .= '<article class="admin-panel"><h3><i class="bi bi-diagram-3 me-1"></i>' . $e($T('legal.por_area', ['pt' => 'Por área', 'en' => 'By area', 'es' => 'Por área'])) . '</h3>'
            . $tabelaCont($T('col.area', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área']), array_map(static fn($p) => [$nomesArea[$p], ['S' => $areas[$p]['S'], 'PA' => $areas[$p]['PA'], 'N' => $areas[$p]['N'], 'AI' => $areas[$p]['TOTAL'] - $areas[$p]['S'] - $areas[$p]['PA'] - $areas[$p]['N']], [$colArea[$p] => ['value' => 'Sim', 'exact' => true]]], array_keys($areas))) . '</article></div>';
        $quadroUnidade = $h;
        // Migração 368: listas de requisitos no grid padrão (abrem a situação do requisito)
        $lista = static function (string $titulo, string $icone, array $linhas, string $vazio, string $id) use ($T): array {
            $rows = [];
            foreach ($linhas as $r) {
                $norma = trim(($r['DSCTIPO'] ?? '') . ' ' . ($r['NR'] ?? '') . (($r['ANO'] ?? '') !== '' ? '/' . $r['ANO'] : ''));
                $rows[] = ['cells' => ['UNIDADE' => (string)($r['UNIDADE'] ?? ''), 'CODIGO' => (string)($r['CODIGO'] ?? ''), 'NORMA' => ['text' => $norma, 'title' => $norma], 'ATENDIDO' => (string)($r['ATENDIDO'] ?? ''),
                    'CALCULADA' => (string)($r['SITUACAO_CALCULADA'] ?? ''), 'MOTIVO' => ['text' => (string)($r['MOTIVO_CALCULO'] ?? ''), 'title' => (string)($r['MOTIVO_CALCULO'] ?? '')]],
                    'open' => DashboardGrid::abrirSituacao((int)$r['CODLEGISLACAO'], (int)$r['CODUNIDADE'], (string)($r['CODIGO'] ?? ''))];
            }
            return ['id' => $id, 'icone' => $icone, 'rotulo' => $titulo, 'contagem' => count($linhas), 'html' => DashboardGrid::grid($id, [
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '160px'],
                'CODIGO' => ['label' => $T('col.requisito', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito']), 'width' => '110px'],
                'NORMA' => ['label' => $T('col.norma', ['pt' => 'Norma', 'en' => 'Standard', 'es' => 'Norma'])],
                'ATENDIDO' => ['label' => $T('col.avaliacao', ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación']), 'filter' => 'select', 'width' => '140px'],
                'CALCULADA' => ['label' => $T('col.calculada', ['pt' => 'Calculada', 'en' => 'Calculated', 'es' => 'Calculada']), 'filter' => 'select', 'width' => '150px'],
                'MOTIVO' => ['label' => $T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])],
            ], $rows, ['vazio' => $vazio, 'transacao' => 'dashboard_admin'])];
        };
        $listaAntiga = static function (string $titulo, string $icone, array $linhas, string $vazio) use ($e, $T): string {
            $t = '<article class="admin-panel mt-2"><h3><i class="bi ' . $icone . ' me-1"></i>' . $e($titulo) . ' (' . count($linhas) . (count($linhas) >= 100 ? '+' : '') . ')</h3>';
            if (!$linhas) return $t . '<div class="dash-nota">' . $e($vazio) . '</div></article>';
            $t .= '<div class="dash-rolagem"><table class="admin-table"><thead><tr><th>' . $e($T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'])) . '</th><th>' . $e($T('col.requisito', ['pt' => 'Requisito', 'en' => 'Requirement', 'es' => 'Requisito'])) . '</th><th>' . $e($T('col.norma', ['pt' => 'Norma', 'en' => 'Standard', 'es' => 'Norma'])) . '</th><th>' . $e($T('col.avaliacao', ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación'])) . '</th><th>' . $e($T('col.calculada', ['pt' => 'Calculada', 'en' => 'Calculated', 'es' => 'Calculada'])) . '</th><th>' . $e($T('col.motivo', ['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])) . '</th></tr></thead><tbody>';
            foreach ($linhas as $r) {
                $norma = trim(($r['DSCTIPO'] ?? '') . ' ' . ($r['NR'] ?? '') . (($r['ANO'] ?? '') !== '' ? '/' . $r['ANO'] : ''));
                $t .= '<tr class="dash-clique" data-situacao="' . (int)$r['CODLEGISLACAO'] . ':' . (int)$r['CODUNIDADE'] . '" data-codigo="' . $e($r['CODIGO'] ?? '') . '"><td>' . $e($r['UNIDADE'] ?? '') . '</td><td><b>' . $e($r['CODIGO'] ?? '') . '</b></td><td>' . $e($norma) . '</td><td>' . $e($r['ATENDIDO'] ?? '') . '</td><td>' . $e($r['SITUACAO_CALCULADA'] ?? '') . '</td><td class="dash-motivo" title="' . $e($r['MOTIVO_CALCULO'] ?? '') . '">' . $e($r['MOTIVO_CALCULO'] ?? '') . '</td></tr>';
            }
            return $t . '</tbody></table></div></article>';
        };
        $sNao = $lista($T('legal.lista_nao', ['pt' => 'Requisitos não atendidos', 'en' => 'Non-compliant requirements', 'es' => 'Requisitos no atendidos']), 'bi-x-octagon', $naoAtendidos, $T('legal.lista_nao_vazia', ['pt' => 'Nenhum requisito não atendido.', 'en' => 'No non-compliant requirement.', 'es' => 'Ningún requisito no atendido.']), 'dash-req-nao');
        $sDiv = $lista($T('legal.lista_div', ['pt' => 'Divergências a decidir (avaliação diferente da calculada)', 'en' => 'Divergences to decide (assessment differs from calculated)', 'es' => 'Divergencias a decidir (evaluación distinta de la calculada)']), 'bi-exclamation-triangle', $divergentes, $T('legal.lista_div_vazia', ['pt' => 'Nenhuma divergência pendente.', 'en' => 'No pending divergence.', 'es' => 'Ninguna divergencia pendiente.']), 'dash-req-div');
        $sub = [$sNao, $sDiv];
        if ($riscoUn !== null) {
            [$sPend, $sResp] = explode('<!--dash-split-->', $listasRisco($pendCrit, $semRisco), 2);
            $sub[] = ['id' => 'pend', 'icone' => 'bi-exclamation-octagon', 'rotulo' => $T('risco.sub_pend', ['pt' => 'Pendências críticas', 'en' => 'Critical pending', 'es' => 'Pendientes críticos']), 'contagem' => count($pendCrit), 'html' => $sPend];
            $sub[] = ['id' => 'resp', 'icone' => 'bi-person-exclamation', 'rotulo' => $T('risco.sub_resp', ['pt' => 'Responsáveis e itens sem risco', 'en' => 'Owners and items without risk', 'es' => 'Responsables e ítems sin riesgo']), 'html' => $sResp];
            $sub[] = ['id' => 'risco', 'icone' => 'bi-grid-3x3', 'rotulo' => $T('risco.sub_matriz', ['pt' => 'Risco × atendimento e por unidade', 'en' => 'Risk × compliance and by unit', 'es' => 'Riesgo × cumplimiento y por unidad']), 'html' => $quadroRisco];
        }
        $sub[] = ['id' => 'unidade', 'icone' => 'bi-buildings', 'rotulo' => $T('legal.sub_unidade', ['pt' => 'Por unidade e área', 'en' => 'By unit and area', 'es' => 'Por unidad y área']), 'html' => $quadroUnidade];
        exit($cardsLegal . DashboardGrid::subabas('legal', $sub));
    }
    if ($aba === 'licencas') {
        $lic = $sel('vw_dash_licencas', [], 20000);
        if ($lic === null) exit($semMigracao('285'));
        // Migração 288: EM_PROTOCOLO = vencida com a renovação protocolada no prazo (continua válida); fora do prazo = VENCIDA
        $faixas = ['VENCIDA' => [$T('fx.vencida2', ['pt' => 'Vencidas (sem protocolo ou protocolo fora do prazo)', 'en' => 'Expired (no renewal filed or filed late)', 'es' => 'Vencidas (sin protocolo o protocolo fuera de plazo)']), 'bi-calendar-x', 'red'],
            'PROT_SEM_PRAZO' => [$T('fx.prot_sem_prazo', ['pt' => 'Em protocolo (prazo não informado)', 'en' => 'Renewal filed (deadline not informed)', 'es' => 'En protocolo (plazo no informado)']), 'bi-hourglass-bottom', 'orange', 'warning'],
            'EM_PROTOCOLO' => [$T('fx.protocolo2', ['pt' => 'Válidas (em protocolo)', 'en' => 'Valid (renewal filed)', 'es' => 'Válidas (en protocolo)']), 'bi-hourglass-split', 'blue'],
            'VENCE_30' => [$T('fx.30', ['pt' => 'Vencem em até 30 dias', 'en' => 'Expire within 30 days', 'es' => 'Vencen en hasta 30 días']), 'bi-alarm', 'orange'],
            'VENCE_60' => [$T('fx.60', ['pt' => 'Vencem em 31 a 60 dias', 'en' => 'Expire in 31 to 60 days', 'es' => 'Vencen en 31 a 60 días']), 'bi-calendar-event', 'orange'],
            'VENCE_90' => [$T('fx.90', ['pt' => 'Vencem em 61 a 90 dias', 'en' => 'Expire in 61 to 90 days', 'es' => 'Vencen en 61 a 90 días']), 'bi-calendar3', 'green'],
            'SEM_VALIDADE' => [$T('fx.sem', ['pt' => 'Sem data de validade', 'en' => 'No expiry date', 'es' => 'Sin fecha de validez']), 'bi-question-circle', 'gray'],
            'ENTREGA_ATRASADA' => [$T('fx.ent_atr', ['pt' => 'Entregas periódicas atrasadas', 'en' => 'Overdue periodic submissions', 'es' => 'Entregas periódicas atrasadas']), 'bi-send-x', 'red'],
            'ENTREGA_30' => [$T('fx.ent_30', ['pt' => 'Entregas com prazo em até 30 dias', 'en' => 'Submissions due within 30 days', 'es' => 'Entregas con plazo en hasta 30 días']), 'bi-send', 'orange']];
        $cont = array_fill_keys(array_keys($faixas), 0); $vigentes = 0; $periodicas = 0;
        foreach ($lic as $l) { $f = (string)$l['FAIXA']; if (isset($cont[$f])) $cont[$f]++; if ($f === 'VIGENTE') $vigentes++; if ($l['NATUREZA'] === 'ENTREGA_PERIODICA') $periodicas++; }
        $rotVig = $T('lic.vigentes', ['pt' => 'Vigentes (mais de 90 dias)', 'en' => 'Valid (over 90 days)', 'es' => 'Vigentes (más de 90 días)']);
        $h = '<div class="admin-cards dash-cards-4">' . $cartao($e($T('lic.total', ['pt' => 'Licenças e documentos ativos', 'en' => 'Active licenses and documents', 'es' => 'Licencias y documentos activos'])), count($lic), 'bi-files', 'blue', $periodicas . ' ' . $e($T('lic.periodicas', ['pt' => 'entregas periódicas', 'en' => 'periodic submissions', 'es' => 'entregas periódicas'])), '', $abrir('manutencaolegal_licencas.php', [], $T('lic.cadastro', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'])))
            . $cartao($e($rotVig), $vigentes, 'bi-check-circle', 'green', '', '', $filtro('dash-lic-acao', 'VIGENTE', $rotVig));
        foreach ($faixas as $f => [$rot, $ic, $tom]) $h .= $cartao($e($rot), $cont[$f], $ic, $tom, '', '', $filtro('dash-lic-acao', $f, $rot));
        $h .= '</div>';
        // Situação das licenças (sem as entregas periódicas): Válidas x Válidas (em protocolo) x Vencidas — migração 288
        $tituloLic = $T('lic.cadastro', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']);
        if ($lic && array_key_exists('SITUACAO_LICENCA', $lic[0])) {
            $sitCont = ['VALIDA' => 0, 'PROTOCOLO' => 0, 'PROT_SEM_PRAZO' => 0, 'VENCIDA' => 0, 'SEM_DATA' => 0];
            foreach ($lic as $l) { $s = (string)($l['SITUACAO_LICENCA'] ?? ''); if (isset($sitCont[$s])) $sitCont[$s]++; }
            $sitRot = ['VALIDA' => [$T('sit.valida', ['pt' => 'Válidas', 'en' => 'Valid', 'es' => 'Válidas']), 'bi-patch-check', 'green'],
                'PROTOCOLO' => [$T('sit.protocolo', ['pt' => 'Válidas (em protocolo)', 'en' => 'Valid (renewal filed)', 'es' => 'Válidas (en protocolo)']), 'bi-hourglass-split', 'blue'],
                'PROT_SEM_PRAZO' => [$T('sit.prot_sem_prazo', ['pt' => 'Em protocolo (prazo não informado)', 'en' => 'Renewal filed (deadline not informed)', 'es' => 'En protocolo (plazo no informado)']), 'bi-hourglass-bottom', 'orange'],
                'VENCIDA' => [$T('sit.vencida', ['pt' => 'Vencidas', 'en' => 'Expired', 'es' => 'Vencidas']), 'bi-calendar-x', 'red'],
                'SEM_DATA' => [$T('sit.sem_data', ['pt' => 'Sem data de vencimento', 'en' => 'No due date', 'es' => 'Sin fecha de vencimiento']), 'bi-question-circle', 'gray']];
            if ($sitCont['PROT_SEM_PRAZO'] === 0) unset($sitRot['PROT_SEM_PRAZO']); // só aparece quando há (decisão de 27/10/2026)
            $sitTotal = array_sum($sitCont);
            $h .= '<article class="admin-panel mt-2"><h3><i class="bi bi-shield-check me-1"></i>' . $e($T('sit.titulo', ['pt' => 'Situação das licenças: válidas x em protocolo x vencidas', 'en' => 'License status: valid x renewal filed x expired', 'es' => 'Situación de las licencias: válidas x en protocolo x vencidas'])) . ' (' . $sitTotal . ')</h3><div class="admin-cards ' . (count($sitRot) > 4 ? 'dash-cards-6' : 'dash-cards-4') . '">';
            foreach ($sitRot as $s => [$rot, $ic, $tom])
                $h .= $cartao($e($rot), $sitCont[$s], $ic, $tom, $sitTotal > 0 ? number_format(100 * $sitCont[$s] / $sitTotal, 1, ',', '.') . '%' : '', '',
                    $abrir('manutencaolegal_licencas.php', ['SITUACAO_LICENCA' => ['value' => $s, 'exact' => true]], $tituloLic . ' - ' . $rot));
            $h .= '</div><div class="dash-nota">' . $e($T('sit.nota', ['pt' => 'Válida (em protocolo): vencida, mas com a renovação protocolada no órgão dentro do prazo; continua valendo até a resposta do órgão. Protocolada fora do prazo conta como vencida. Entregas periódicas ficam fora desta conta.', 'en' => 'Valid (renewal filed): expired, but the renewal was filed on time; it remains valid until the agency responds. Filed late counts as expired. Periodic submissions are not counted here.', 'es' => 'Válida (en protocolo): vencida, pero con la renovación protocolada dentro del plazo; sigue vigente hasta la respuesta del organismo. Protocolada fuera de plazo cuenta como vencida. Las entregas periódicas no se cuentan aquí.'])) . '</div></article>';
        }
        // Migração 367: a tabela traz todas (Vigentes e "Vencem em 61 a 90 dias" escondidas no padrão) para os cartões filtrarem
        $todasLic = array_values(array_filter($lic, static fn($l) => isset($faixas[(string)$l['FAIXA']]) || (string)$l['FAIXA'] === 'VIGENTE'));
        $atencao = array_values(array_filter($todasLic, static fn($l) => !in_array((string)$l['FAIXA'], ['VENCE_90', 'VIGENTE'], true)));
        $ordem = array_flip(['VENCIDA', 'PROT_SEM_PRAZO', 'ENTREGA_ATRASADA', 'VENCE_30', 'ENTREGA_30', 'EM_PROTOCOLO', 'VENCE_60', 'SEM_VALIDADE', 'VENCE_90', 'VIGENTE']);
        usort($todasLic, static fn($a, $b) => [$ordem[$a['FAIXA']] ?? 9, (int)$a['DIAS']] <=> [$ordem[$b['FAIXA']] ?? 9, (int)$b['DIAS']]);
        $faixas['VIGENTE'] = [$rotVig, 'bi-check-circle', 'green'];
        $cores = ['VENCIDA' => 'danger', 'PROT_SEM_PRAZO' => 'warning', 'ENTREGA_ATRASADA' => 'danger', 'VENCE_30' => 'warning', 'ENTREGA_30' => 'warning', 'EM_PROTOCOLO' => 'primary', 'VENCE_60' => 'secondary', 'SEM_VALIDADE' => 'secondary', 'VENCE_90' => 'success', 'VIGENTE' => 'success'];
        $tituloLic = $T('lic.cadastro', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']);
        $h .= '<div class="dash-acoes"><button type="button" class="btn btn-sm btn-outline-secondary"' . $abrir('manutencaolegal_licencas.php', [], $tituloLic) . '><i class="bi bi-box-arrow-up-right me-1"></i>' . $e($T('lic.abrir', ['pt' => 'Abrir cadastro de licenças', 'en' => 'Open licenses', 'es' => 'Abrir catastro de licencias'])) . '</button>'
            . '<span class="dash-nota">' . $e($T('lic.nota', ['pt' => 'Versão ativa de cada licença. Entrega periódica = tipo documental marcado como tal ou nome reconhecido (RAPP, inventário, relatório anual...).', 'en' => 'Active version of each license. Periodic submission = document type marked as such or recognized name (RAPP, inventory, annual report...).', 'es' => 'Versión activa de cada licencia. Entrega periódica = tipo documental marcado o nombre reconocido (RAPP, inventario, informe anual...).'])) . '</span></div>';
        // Migração 368: grid padrão; abre filtrado nas que precisam de ação
        $rowsLic = [];
        foreach (array_slice($todasLic, 0, 5000) as $l) {
            $f = (string)$l['FAIXA'];
            $rowsLic[] = ['cells' => ['SITUACAO' => ['text' => $faixas[$f][0], 'html' => '<span class="badge text-bg-' . $cores[$f] . '" title="' . $e((string)($l['MOTIVO_SITUACAO'] ?? '')) . '">' . $e($faixas[$f][0]) . '</span>'],
                'UNIDADE' => (string)$l['UNIDADE'], 'LICENCA' => ['text' => (string)$l['LICENCA'], 'title' => (string)$l['LICENCA']], 'TIPO' => (string)$l['TIPO_DOCUMENTAL'],
                'VALIDADE' => DashboardGrid::prazo($l['DATA_VALIDADE']), 'DIAS' => DashboardGrid::numero($l['DIAS'] !== null ? (int)$l['DIAS'] : '', $l['DIAS'] !== null && (int)$l['DIAS'] < 0 ? 'status-danger' : ''),
                'RESPONSAVEL' => (string)$l['RESPONSAVEL']],
                'open' => ['url' => 'manutencaolegal_licencas.php?filtros=' . rawurlencode((string)json_encode(['CODLICENCA' => (int)$l['CODLICENCA']])), 'title' => $tituloLic . ' - ' . mb_strimwidth((string)$l['LICENCA'], 0, 70, '…')],
                'grupos' => $f . (in_array($f, ['VENCE_90', 'VIGENTE'], true) ? '' : ' ACAO')];
        }
        $rotAcao = $T('lic.atencao', ['pt' => 'Precisam de ação', 'en' => 'Need action', 'es' => 'Necesitan acción']);
        $subLic = [['id' => 'acao', 'icone' => 'bi-exclamation-diamond', 'rotulo' => $T('lic.sub_lista', ['pt' => 'Licenças e entregas', 'en' => 'Licenses and submissions', 'es' => 'Licencias y entregas']), 'contagem' => count($atencao),
            'html' => DashboardGrid::grid('dash-lic-acao', [
                'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '200px'],
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
                'LICENCA' => ['label' => $T('col.licenca', ['pt' => 'Licença / documento', 'en' => 'License / document', 'es' => 'Licencia / documento'])],
                'TIPO' => ['label' => $T('col.tipo', ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental']), 'filter' => 'select', 'width' => '170px'],
                'VALIDADE' => ['label' => $T('col.validade', ['pt' => 'Validade / prazo', 'en' => 'Expiry / due', 'es' => 'Validez / plazo']), 'width' => '115px'],
                'DIAS' => ['label' => $T('col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), 'width' => '70px'],
                'RESPONSAVEL' => ['label' => $T('col.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '170px'],
            ], $rowsLic, ['vazio' => $T('lic.nada', ['pt' => 'Nenhuma licença ou entrega precisando de ação.', 'en' => 'No license or submission needing action.', 'es' => 'Ninguna licencia o entrega que necesite acción.']), 'transacao' => 'dashboard_admin', 'grupo_inicial' => ['ACAO', $rotAcao]])]];
        $cardsLic = $h; $h = '';
        // Migração 344: taxas das licenças (não pagas e pagas no ano)
        $taxas = $sel('vw_dash_li_taxas', [], 20000);
        if ($taxas !== null) {
            $tituloTaxas = $T('tx.cadastro', ['pt' => 'Taxas das licenças', 'en' => 'License fees', 'es' => 'Tasas de las licencias']);
            $soma = ['VENCIDA' => [0, 0.0], 'VENCE_30' => [0, 0.0], 'EM_ABERTO' => [0, 0.0], 'PAGA' => [0, 0.0]];
            foreach ($taxas as $t) {
                $sit = (string)$t['SITUACAO_TAXA']; if ($sit === 'SEM_VENC') $sit = 'EM_ABERTO';
                if (!isset($soma[$sit])) continue;
                $soma[$sit][0]++; $soma[$sit][1] += (float)($sit === 'PAGA' ? ($t['VALOR_PAGO_NUM'] ?? $t['VALOR_NUM'] ?? 0) : ($t['VALOR_NUM'] ?? 0));
            }
            $reais = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
            $rotTx = ['VENCIDA' => [$T('tx.vencidas', ['pt' => 'Vencidas sem pagamento', 'en' => 'Overdue unpaid', 'es' => 'Vencidas sin pago']), 'bi-exclamation-octagon', 'red'],
                'VENCE_30' => [$T('tx.30', ['pt' => 'Vencem em até 30 dias', 'en' => 'Due within 30 days', 'es' => 'Vencen en hasta 30 días']), 'bi-alarm', 'orange'],
                'EM_ABERTO' => [$T('tx.a_pagar', ['pt' => 'A pagar (mais de 30 dias)', 'en' => 'To pay (over 30 days)', 'es' => 'A pagar (más de 30 días)']), 'bi-calendar3', 'blue'],
                'PAGA' => [$T('tx.pagas', ['pt' => 'Pagas no ano', 'en' => 'Paid this year', 'es' => 'Pagadas en el año']), 'bi-check2-circle', 'green']];
            $h .= '<article class="admin-panel"><h3><i class="bi bi-cash-coin me-1"></i>' . $e($tituloTaxas) . '</h3><div class="admin-cards dash-cards-4">';
            foreach ($rotTx as $k => [$rot, $ic, $tom])
                $h .= $cartao($e($rot), $soma[$k][0], $ic, $tom, $e($reais($soma[$k][1])), '', $abrir('manutencaolegal_licencas_taxas.php', ['SITUACAO_TAXA' => ['value' => $k, 'exact' => true]], $tituloTaxas . ' - ' . $rot));
            $h .= '</div>';
            $acao = array_values(array_filter($taxas, static fn($t) => in_array((string)$t['SITUACAO_TAXA'], ['VENCIDA', 'VENCE_30'], true)));
            usort($acao, static fn($a, $b) => [(string)$a['VENCIMENTO'], (int)$a['CODTAXA']] <=> [(string)$b['VENCIMENTO'], (int)$b['CODTAXA']]);
            $rowsTx = [];
            foreach ($acao as $t) {
                $taxaTxt = trim((string)$t['TIPO_DESC'] . (trim((string)$t['DESCRICAO']) !== '' ? ' — ' . $t['DESCRICAO'] : ''));
                $rowsTx[] = ['cells' => ['SITUACAO' => ['text' => (string)$t['SITUACAO_TAXA_DESC'], 'html' => '<span class="badge text-bg-' . ((string)$t['SITUACAO_TAXA'] === 'VENCIDA' ? 'danger' : 'warning') . '">' . $e($t['SITUACAO_TAXA_DESC']) . '</span>'],
                    'UNIDADE' => (string)($t['UNIDADE'] ?? ''), 'LICENCA' => ['text' => (string)($t['LICENCA'] ?? ''), 'title' => (string)($t['LICENCA'] ?? '')], 'TAXA' => ['text' => $taxaTxt, 'title' => $taxaTxt],
                    'VENCIMENTO' => DashboardGrid::prazo($t['VENCIMENTO']), 'VALOR' => ['text' => $t['VALOR'] !== null && $t['VALOR'] !== '' ? 'R$ ' . $t['VALOR'] : '–', 'sort' => (string)(float)($t['VALOR_NUM'] ?? 0)],
                    'RESPONSAVEL' => (string)($t['RESPONSAVEL'] ?? '')],
                    'open' => ['url' => 'manutencaolegal_licencas_taxas.php?filtros=' . rawurlencode((string)json_encode(['CODTAXA' => ['value' => (string)(int)$t['CODTAXA'], 'exact' => true]])), 'title' => $tituloTaxas . ' - ' . mb_strimwidth((string)($t['LICENCA'] ?? ''), 0, 60, '…')]];
            }
            $h .= DashboardGrid::grid('dash-taxas', [
                'SITUACAO' => ['label' => $T('col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), 'filter' => 'select', 'width' => '170px'],
                'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
                'LICENCA' => ['label' => $T('col.licenca', ['pt' => 'Licença / documento', 'en' => 'License / document', 'es' => 'Licencia / documento'])],
                'TAXA' => ['label' => $T('col.taxa', ['pt' => 'Taxa', 'en' => 'Fee', 'es' => 'Tasa'])],
                'VENCIMENTO' => ['label' => $T('col.vencimento', ['pt' => 'Vencimento', 'en' => 'Due date', 'es' => 'Vencimiento']), 'width' => '115px'],
                'VALOR' => ['label' => $T('col.valor', ['pt' => 'Valor', 'en' => 'Amount', 'es' => 'Valor']), 'width' => '110px'],
                'RESPONSAVEL' => ['label' => $T('col.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable']), 'filter' => 'select', 'width' => '170px'],
            ], $rowsTx, ['vazio' => $T('tx.nada', ['pt' => 'Nenhuma taxa vencida ou vencendo em 30 dias.', 'en' => 'No fee overdue or due within 30 days.', 'es' => 'Ninguna tasa vencida o por vencer en 30 días.']), 'transacao' => 'dashboard_admin']);
            $h .= '<div class="dash-acoes"><button type="button" class="btn btn-sm btn-outline-secondary"' . $abrir('manutencaolegal_licencas_taxas.php', [], $tituloTaxas) . '><i class="bi bi-box-arrow-up-right me-1"></i>' . $e($T('tx.abrir', ['pt' => 'Abrir Taxas das licenças', 'en' => 'Open license fees', 'es' => 'Abrir tasas de las licencias'])) . '</button>'
                . '<span class="dash-nota">' . $e($T('tx.nota', ['pt' => 'Taxa vencida e não paga deixa a licença em Atenção na situação legal. Pagas no ano: soma do valor pago.', 'en' => 'An overdue unpaid fee puts the license in Attention in the legal status. Paid this year: sum of the amount paid.', 'es' => 'Una tasa vencida sin pago deja la licencia en Atención en la situación legal. Pagadas en el año: suma del valor pagado.'])) . '</span></div></article>';
        }
        if ($h !== '') $subLic[] = ['id' => 'taxas', 'icone' => 'bi-cash-coin', 'rotulo' => $T('tx.cadastro', ['pt' => 'Taxas das licenças', 'en' => 'License fees', 'es' => 'Tasas de las licencias']), 'html' => $h];
        exit($cardsLic . DashboardGrid::subabas('licencas', $subLic));
    }
    if ($aba === 'equipe') {
        $eq = $sel('vw_dash_equipe', [], 20000);
        if ($eq === null) exit($semMigracao('285'));
        $rotPerfil = ['APROVADO' => [$T('pa.aprovado', ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado']), 'success'], 'PENDENTE_APROVACAO' => [$T('pa.pendente', ['pt' => 'Aguardando aprovação', 'en' => 'Awaiting approval', 'es' => 'Esperando aprobación']), 'warning'],
            'DEVOLVIDO' => [$T('pa.devolvido', ['pt' => 'Devolvido', 'en' => 'Returned', 'es' => 'Devuelto']), 'danger'], 'VENCIDO' => [$T('pa.vencido', ['pt' => 'Revisão vencida', 'en' => 'Review overdue', 'es' => 'Revisión vencida']), 'danger'],
            'SEM_ENVIO' => [$T('pa.sem', ['pt' => 'Não preenchido', 'en' => 'Not filled in', 'es' => 'No completado']), 'secondary'], 'NAO_APLICA' => [$T('pa.na', ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica']), 'light']];
        $c = array_fill_keys(array_keys($rotPerfil), 0); $comLic = 0; $comMed = 0;
        foreach ($eq as &$u) {
            $c[(string)$u['PERFIL_ATRIBUICOES']] = ($c[(string)$u['PERFIL_ATRIBUICOES']] ?? 0) + 1;
            if ((int)$u['LICENCAS_VENCIDAS'] > 0) $comLic++; if ((int)$u['MEDICOES_ATRASADAS'] > 0) $comMed++;
            $u['_sinais'] = (int)$u['LICENCAS_VENCIDAS'] * 3 + (int)$u['MEDICOES_ATRASADAS'] * 2 + (int)$u['LICENCAS_30'] + (in_array($u['PERFIL_ATRIBUICOES'], ['DEVOLVIDO', 'VENCIDO', 'SEM_ENVIO', 'PENDENTE_APROVACAO'], true) ? 1 : 0);
        }
        unset($u);
        usort($eq, static fn($a, $b) => [$b['_sinais'], $a['USUARIO']] <=> [$a['_sinais'], $b['USUARIO']]);
        $tituloPerfil = $T('eq.gestao', ['pt' => 'Perfis de atribuições da equipe', 'en' => 'Team duties profiles', 'es' => 'Perfiles de atribuciones del equipo']);
        $tituloLic = $T('lic.cadastro', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']);
        $tituloMed = $T('eq.medicoes', ['pt' => 'Medição e monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo']);
        $rotEq = [$T('eq.usuarios', ['pt' => 'Usuários ativos', 'en' => 'Active users', 'es' => 'Usuarios activos']), $T('eq.lic_atrasadas', ['pt' => 'Pessoas com licenças vencidas ou entregas atrasadas', 'en' => 'People with expired licenses or late deliveries', 'es' => 'Personas con licencias vencidas o entregas atrasadas']), $T('eq.med', ['pt' => 'Pessoas com medições atrasadas', 'en' => 'People with late measurements', 'es' => 'Personas con mediciones atrasadas'])];
        $h = '<div class="admin-cards dash-cards-4">' . $cartao($e($rotEq[0]), count($eq), 'bi-people', 'blue', '', '', $filtro('dash-equipe', '*', $rotEq[0]))
            . $cartao($e($rotEq[1]), $comLic, 'bi-calendar-x', 'red', '', '', $filtro('dash-equipe', 'LIC', $rotEq[1]))
            . $cartao($e($rotEq[2]), $comMed, 'bi-graph-down', 'orange', '', '', $filtro('dash-equipe', 'MED', $rotEq[2]));
        foreach (['APROVADO' => 'bi-person-check', 'PENDENTE_APROVACAO' => 'bi-person-up', 'DEVOLVIDO' => 'bi-person-x', 'VENCIDO' => 'bi-person-exclamation', 'SEM_ENVIO' => 'bi-person-dash'] as $k => $ic)
            $h .= $cartao($e($T('eq.perfil', ['pt' => 'Perfil de atribuições', 'en' => 'Duties profile', 'es' => 'Perfil de atribuciones'])) . ': ' . $e($rotPerfil[$k][0]), $c[$k] ?? 0, $ic, ['success' => 'green', 'warning' => 'orange', 'danger' => 'red', 'secondary' => 'gray'][$rotPerfil[$k][1]], '', '', $filtro('dash-equipe', 'P_' . $k, $T('eq.perfil', ['pt' => 'Perfil de atribuições', 'en' => 'Duties profile', 'es' => 'Perfil de atribuciones']) . ': ' . $rotPerfil[$k][0]));
        $h .= '</div><div class="dash-acoes">'
            . '<button type="button" class="btn btn-sm btn-outline-secondary"' . $abrir('perfil_atribuicoes_gestao.php', [], $tituloPerfil) . '><i class="bi bi-box-arrow-up-right me-1"></i>' . $e($T('eq.abrir', ['pt' => 'Aprovar perfis de atribuições', 'en' => 'Approve duties profiles', 'es' => 'Aprobar perfiles de atribuciones'])) . '</button></div>';
        // Migração 368: grid padrão; abre com quem tem pendência (filtro removível)
        $rowsEq = [];
        foreach ($eq as $u) {
            [$rot, $cor] = $rotPerfil[(string)$u['PERFIL_ATRIBUICOES']] ?? [(string)$u['PERFIL_ATRIBUICOES'], 'secondary'];
            $cod = (int)$u['CODUSUARIO']; $nome = (string)$u['USUARIO'];
            $perfilId = (int)($u['ULTIMO_CODPERFILATRIB'] ?? 0);
            $badge = '<span class="badge text-bg-' . $cor . '">' . $e($rot) . '</span>';
            if ($perfilId > 0) $badge = '<a class="dash-num"' . $abrir('perfil_atribuicoes_gestao.php', [], $tituloPerfil . ' - ' . $nome, ['id' => $perfilId]) . '>' . $badge . '</a>';
            $grupos = trim(((int)$u['LICENCAS_VENCIDAS'] > 0 ? 'LIC ' : '') . ((int)$u['MEDICOES_ATRASADAS'] > 0 ? 'MED ' : '') . 'P_' . $u['PERFIL_ATRIBUICOES'] . ($u['_sinais'] > 0 ? ' TOP' : ''));
            $linkLic = $abrir('manutencaolegal_licencas.php', ['CODUSUARIO' => $cod], $tituloLic . ' - ' . $nome);
            $linkMed = $abrir('medicaoemonitoramento_mm_monitoramento.php', ['CODUSUARIO' => $cod], $tituloMed . ' - ' . $nome);
            $num = static fn(int $v, string $link, string $cls = ''): array => ['text' => (string)$v, 'html' => $numLink($v, $link, $cls), 'sort' => (string)$v];
            $rowsEq[] = ['cells' => ['PESSOA' => $nome, 'GESTOR' => (string)$u['GESTOR'],
                'PERFIL' => ['text' => $rot, 'html' => $badge . ($u['PERFIL_VALIDO_ATE'] ? ' <small class="text-muted">' . $e(date('d/m/Y', strtotime((string)$u['PERFIL_VALIDO_ATE']))) . '</small>' : '')],
                'LIC' => $num((int)$u['LICENCAS'], $linkLic), 'LIC_V' => $num((int)$u['LICENCAS_VENCIDAS'], $linkLic, 'status-danger'), 'LIC_30' => $num((int)$u['LICENCAS_30'], $linkLic, 'status-warning'),
                'MED' => $num((int)$u['MEDICOES'], $linkMed), 'MED_A' => $num((int)$u['MEDICOES_ATRASADAS'], $linkMed, 'status-danger')],
                'open' => DashboardGrid::abrirDe($linkLic), 'grupos' => $grupos];
        }
        $h .= DashboardGrid::subabas('equipe', [['id' => 'responsaveis', 'icone' => 'bi-person-lines-fill', 'rotulo' => $T('eq.titulo', ['pt' => 'Pendências por responsável', 'en' => 'Pending items by owner', 'es' => 'Pendientes por responsable']),
            'html' => DashboardGrid::grid('dash-equipe', [
                'PESSOA' => ['label' => $T('col.pessoa', ['pt' => 'Pessoa', 'en' => 'Person', 'es' => 'Persona'])],
                'GESTOR' => ['label' => $T('col.gestor', ['pt' => 'Gestor', 'en' => 'Manager', 'es' => 'Gestor']), 'filter' => 'select', 'width' => '170px'],
                'PERFIL' => ['label' => $T('eq.perfil', ['pt' => 'Perfil de atribuições', 'en' => 'Duties profile', 'es' => 'Perfil de atribuciones']), 'filter' => 'select', 'width' => '190px'],
                'LIC' => ['label' => $T('col.lic', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']), 'filter' => 'none', 'width' => '80px'],
                'LIC_V' => ['label' => $T('col.lic_venc_atr', ['pt' => 'Vencidas / atrasadas', 'en' => 'Expired / late', 'es' => 'Vencidas / atrasadas']), 'filter' => 'none', 'width' => '95px'],
                'LIC_30' => ['label' => $T('col.lic_prazo_30', ['pt' => 'Vencem / entregar em 30 dias', 'en' => 'Due in 30 days', 'es' => 'Vencen / entregar en 30 días']), 'filter' => 'none', 'width' => '110px'],
                'MED' => ['label' => $T('col.med', ['pt' => 'Medições', 'en' => 'Measurements', 'es' => 'Mediciones']), 'filter' => 'none', 'width' => '85px'],
                'MED_A' => ['label' => $T('col.med_atr', ['pt' => 'Medições atrasadas', 'en' => 'Late measurements', 'es' => 'Mediciones atrasadas']), 'filter' => 'none', 'width' => '95px'],
            ], $rowsEq, ['transacao' => 'dashboard_admin', 'grupo_inicial' => ['TOP', $T('eq.com_pend', ['pt' => 'Com pendências', 'en' => 'With pending items', 'es' => 'Con pendientes'])]])]]);
        exit($h);
    }
    if ($aba === 'nc') exit((new DashboardNcFornecedores($pdo))->ncEmpresa());
    if ($aba === 'fornecedores') {
        // Migração 366: situação da homologação (fornecedores ativos) antes do ranking
        $temHom = $sel('vw_dash_fornecedor_resumo', [], 1) !== null;
        $blocoHomologacao = '';
        // Ranking de fornecedores (migração 289): faixas, bloqueados e os que mais precisam de atenção
        $forn = $sel('vw_fornecedor_ranking', [], 20000);
        if ($forn === null) exit($temHom ? (new DashboardNcFornecedores($pdo))->fornecedoresEmpresa() : $semMigracao('289'));
        $cfgR = ($sel('vw_fornecedor_ranking_config') ?? [])[0] ?? [];
        $tituloR = $T('forn.ranking', ['pt' => 'Ranking de fornecedores', 'en' => 'Supplier ranking', 'es' => 'Ranking de proveedores']);
        $contR = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, '' => 0]; $bloqR = [];
        foreach ($forn as $f) { $contR[(string)($f['FAIXA'] ?? '')]++; if ($f['BLOQUEADO'] === 'Sim') $bloqR[] = $f; }
        $tons = ['A' => 'green', 'B' => 'green', 'C' => 'orange', 'D' => 'red'];
        $h = '<div class="admin-cards dash-cards-4">';
        foreach (['A', 'B', 'C', 'D'] as $fx) $h .= $cartao($e($rFx = $T('forn.faixa', ['pt' => 'Faixa', 'en' => 'Band', 'es' => 'Franja']) . ' ' . $fx), $contR[$fx], 'bi-trophy', $tons[$fx], $fx === 'D' ? $e('< ' . (int)($cfgR['FAIXA_C'] ?? 50)) : $e('≥ ' . (int)($cfgR['FAIXA_' . $fx] ?? 0)), '', $filtro('dash-ranking', 'FX_' . $fx, $rFx));
        $h .= $cartao($e($rSD = $T('forn.sem_dados', ['pt' => 'Sem dados', 'en' => 'No data', 'es' => 'Sin datos'])), $contR[''], 'bi-question-circle', 'gray', '', '', $filtro('dash-ranking', 'FX_', $rSD))
            . $cartao($e($rBl = $T('forn.bloqueados', ['pt' => 'Bloqueados', 'en' => 'Blocked', 'es' => 'Bloqueados'])), count($bloqR), 'bi-lock', 'red', '', '', $filtro('dash-ranking', 'BLOQ', $rBl));
        $h .= '</div><div class="dash-acoes"><button type="button" class="btn btn-sm btn-outline-secondary"' . $abrir('homologacaofornecedor_ranking.php', [], $tituloR) . '><i class="bi bi-trophy me-1"></i>' . $e($T('forn.abrir', ['pt' => 'Abrir ranking de fornecedores', 'en' => 'Open supplier ranking', 'es' => 'Abrir ranking de proveedores'])) . '</button>'
            . '<span class="dash-nota">' . $e($T('forn.calculado', ['pt' => 'Notas calculadas em', 'en' => 'Scores calculated on', 'es' => 'Notas calculadas el'])) . ' ' . $e(($cfgR['ULTIMO_CALCULO'] ?? '') ? date('d/m/Y H:i', strtotime((string)$cfgR['ULTIMO_CALCULO'])) : '–') . '</span></div>';
        // Migração 368: um grid com todos os fornecedores do ranking (abre nas faixas C e D; os cartões filtram)
        usort($forn, static fn($a, $b) => (float)$a['NOTA'] <=> (float)$b['NOTA']);
        $coresR = ['A' => '#1f8a5b', 'B' => '#5aa469', 'C' => '#d99a1e', 'D' => '#c0392b'];
        $rowsR = [];
        foreach ($forn as $f) {
            $fx = (string)($f['FAIXA'] ?? '');
            $bloq = $f['BLOQUEADO'] === 'Sim';
            $rowsR[] = ['cells' => ['NOTA' => ['text' => $fx !== '' ? number_format((float)$f['NOTA'], 1, ',', '.') : '–', 'sort' => $fx !== '' ? (string)(float)$f['NOTA'] : '-1'],
                'FAIXA' => ['text' => $fx !== '' ? $fx : '–', 'html' => $fx !== '' ? '<span class="badge" style="background:' . ($coresR[$fx] ?? '#888') . '">' . $e($fx) . '</span>' : '–'],
                'FORNECEDOR' => ['text' => (string)$f['RAZAOSOCIAL'], 'title' => (string)$f['RAZAOSOCIAL']], 'UNIDADE' => (string)$f['UNIDADE'], 'NC' => DashboardGrid::numero((int)$f['NC_ABERTAS'], 'status-warning'),
                'BLOQUEIO' => ['text' => $bloq ? (string)$f['MOTIVO_BLOQUEIO'] : '', 'title' => (string)($f['MOTIVO_BLOQUEIO'] ?? ''), 'html' => $bloq ? '<span class="badge text-bg-danger"><i class="bi bi-lock-fill"></i> ' . $e($T('forn.bloqueado', ['pt' => 'Bloqueado', 'en' => 'Blocked', 'es' => 'Bloqueado'])) . '</span> <span class="text-muted small">' . $e(mb_strimwidth((string)$f['MOTIVO_BLOQUEIO'], 0, 90, '…')) . '</span>' : '']],
                'open' => DashboardGrid::abrirDe($abrir('homologacaofornecedor_ranking_detalhe.php', [], $T('forn.detalhe', ['pt' => 'Nota do fornecedor', 'en' => 'Supplier score', 'es' => 'Nota del proveedor']) . ' - ' . $f['RAZAOSOCIAL'], ['fornecedor' => (int)$f['CODFORNECEDOR']])),
                'grupos' => 'FX_' . $fx . (in_array($fx, ['C', 'D'], true) ? ' CD' : '') . ($bloq ? ' BLOQ' : '')];
        }
        $h .= DashboardGrid::grid('dash-ranking', [
            'NOTA' => ['label' => $T('forn.col_nota', ['pt' => 'Nota', 'en' => 'Score', 'es' => 'Nota']), 'filter' => 'none', 'width' => '70px'],
            'FAIXA' => ['label' => $T('forn.col_faixa', ['pt' => 'Faixa', 'en' => 'Band', 'es' => 'Franja']), 'filter' => 'select', 'width' => '80px'],
            'FORNECEDOR' => ['label' => $T('forn.col_fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'])],
            'UNIDADE' => ['label' => $T('col.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '150px'],
            'NC' => ['label' => $T('forn.col_nc', ['pt' => 'NCs abertas', 'en' => 'Open NCs', 'es' => 'NC abiertas']), 'filter' => 'none', 'width' => '85px'],
            'BLOQUEIO' => ['label' => $T('forn.col_bloqueio', ['pt' => 'Bloqueio', 'en' => 'Block', 'es' => 'Bloqueo'])],
        ], $rowsR, ['transacao' => 'dashboard_admin', 'ordem' => ['NOTA', 'asc'], 'grupo_inicial' => $contR['C'] + $contR['D'] > 0 ? ['CD', $T('forn.atencao', ['pt' => 'Faixas C e D (menores notas)', 'en' => 'Bands C and D (lowest scores)', 'es' => 'Franjas C y D (menores notas)'])] : []]);
        $subRanking = ['id' => 'ranking', 'icone' => 'bi-trophy', 'rotulo' => $tituloR, 'contagem' => count($forn), 'html' => $h];
        if ($temHom) exit((new DashboardNcFornecedores($pdo))->fornecedoresEmpresa([$subRanking]));
        exit($h);
    }
    if ($aba === 'admin' && $administrador) {
        $int = ($sel('vw_dash_integridade') ?? [])[0] ?? null;
        $ia = $sel('vw_dash_ia_status') ?? [];
        $statusTexto = static function (string $codigo) use ($T): string {
            $mapa = ['PENDENTE' => ['pt' => 'Pendente', 'en' => 'Pending', 'es' => 'Pendiente'], 'PROCESSANDO' => ['pt' => 'Processando', 'en' => 'Processing', 'es' => 'Procesando'], 'CONCLUIDA' => ['pt' => 'Concluída', 'en' => 'Completed', 'es' => 'Concluida'], 'ERRO' => ['pt' => 'Erro', 'en' => 'Error', 'es' => 'Error'], 'CANCELADA' => ['pt' => 'Cancelada', 'en' => 'Cancelled', 'es' => 'Cancelada'], 'SEM_STATUS' => ['pt' => 'Sem status', 'en' => 'No status', 'es' => 'Sin estado']];
            $k = strtoupper(trim($codigo)); return isset($mapa[$k]) ? $T('status.' . strtolower($k), $mapa[$k]) : $codigo;
        };
        $h = '<div class="admin-grid">';
        $h .= '<article class="admin-panel"><h3><i class="bi bi-shield-check me-1"></i>' . $e($T('admin.integridade', ['pt' => 'Integridade dos dados', 'en' => 'Data integrity', 'es' => 'Integridad de los datos'])) . '</h3><div class="admin-subtitle">' . $e($T('admin.integridade_sub', ['pt' => 'Pontos que exigem correção administrativa', 'en' => 'Items that require administrative correction', 'es' => 'Puntos que requieren corrección administrativa'])) . '</div>';
        if ($int === null) $h .= $semMigracao('285');
        else {
            $h .= '<table class="admin-table">';
            foreach ([['REQUISITOS_SEM_UNIDADE', $T('admin.sem_unidade', ['pt' => 'Requisitos sem unidade', 'en' => 'Requirements without unit', 'es' => 'Requisitos sin unidad']), 'danger'],
                ['REQUISITOS_SEM_TRATAMENTO', $T('admin.sem_tratamento', ['pt' => 'Requisitos sem avaliação e sem evidência', 'en' => 'Requirements without assessment or evidence', 'es' => 'Requisitos sin evaluación ni evidencia']), 'warning'],
                ['LICENCAS_VENCIDAS', $T('admin.lic_vencidas_sem_protocolo', ['pt' => 'Licenças vencidas sem protocolo', 'en' => 'Expired licenses without protocol', 'es' => 'Licencias vencidas sin protocolo']), 'danger'],
                ['SITUACOES_PENDENTES', $T('admin.situacoes', ['pt' => 'Avaliações manuais não atendidas', 'en' => 'Manual assessments not compliant', 'es' => 'Evaluaciones manuales no atendidas']), 'warning']] as [$k, $rot, $cls])
                $h .= '<tr' . ($k === 'LICENCAS_VENCIDAS' && (int)$int[$k] > 0 ? ' class="dash-clique" data-ir-aba="licencas"' : '') . '><td>' . $e($rot) . '</td><td class="num status-' . ((int)$int[$k] > 0 ? $cls : 'ok') . '"><b>' . (int)$int[$k] . '</b></td></tr>';
            $h .= '</table>';
        }
        $h .= '</article><article class="admin-panel"><h3><i class="bi bi-stars me-1"></i>' . $e($T('admin.ia', ['pt' => 'Processamento de IA', 'en' => 'AI processing', 'es' => 'Procesamiento de IA'])) . '</h3><div class="admin-subtitle">' . $e($T('admin.ia_sub', ['pt' => 'Solicitações por status', 'en' => 'Requests by status', 'es' => 'Solicitudes por estado'])) . '</div><table class="admin-table">';
        foreach ($ia as $r) $h .= '<tr><td>' . $e($statusTexto((string)$r['STATUS'])) . '</td><td class="num">' . (int)$r['TOTAL'] . '</td></tr>';
        if (!$ia) $h .= '<tr><td class="text-muted">–</td></tr>';
        $h .= '</table></article>';
        $backupPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'backups';
        $backups = is_dir($backupPath) ? array_values(array_filter(scandir($backupPath) ?: [], static fn(string $f): bool => $f !== '.' && $f !== '..' && is_file($backupPath . DIRECTORY_SEPARATOR . $f))) : [];
        usort($backups, static fn(string $a, string $b): int => filemtime($backupPath . DIRECTORY_SEPARATOR . $b) <=> filemtime($backupPath . DIRECTORY_SEPARATOR . $a));
        $h .= '<article class="admin-panel"><h3><i class="bi bi-database-check me-1"></i>' . $e($T('admin.backups', ['pt' => 'Backups do ambiente', 'en' => 'Environment backups', 'es' => 'Respaldos del entorno'])) . '</h3><div class="admin-subtitle">' . $e($T('admin.backups_sub', ['pt' => 'Arquivos encontrados em runtime/backups', 'en' => 'Files found in runtime/backups', 'es' => 'Archivos encontrados en runtime/backups'])) . '</div><table class="admin-table">';
        foreach (array_slice($backups, 0, 6) as $b) $h .= '<tr><td>' . $e($b) . '</td><td class="num">' . $e(date('d/m/Y H:i', filemtime($backupPath . DIRECTORY_SEPARATOR . $b))) . '</td></tr>';
        if (!$backups) $h .= '<tr><td class="text-muted">–</td></tr>';
        exit($h . '</table><div class="dash-nota">' . $e($T('admin.backups_total', ['pt' => 'Total de arquivos', 'en' => 'Total files', 'es' => 'Total de archivos'])) . ': ' . count($backups) . '</div></article></div>');
    }
    http_response_code(404); exit('');
}

// ---------------------------------------------------------------- página + Visão geral
$vg = ($sel('vw_dash_visao_geral') ?? [])[0] ?? null;
$licResumo = ['VENCIDA' => 0, 'VENCE_30' => 0, 'ENTREGA_ATRASADA' => 0];
foreach ($sel('vw_dash_licencas', [], 20000) ?? [] as $l) if (isset($licResumo[(string)$l['FAIXA']])) $licResumo[(string)$l['FAIXA']]++;
$calc = [];
foreach ($sel('vw_situacao_legal_totais') ?? [] as $t) $calc[(string)$t['SITUACAO']] = (int)$t['TOTAL'];
$eqResumo = ['DEVOLVIDO' => 0, 'PENDENTE_APROVACAO' => 0, 'SEM_ENVIO' => 0];
foreach ($sel('vw_dash_equipe', [], 20000) ?? [] as $u) if (isset($eqResumo[(string)$u['PERFIL_ATRIBUICOES']])) $eqResumo[(string)$u['PERFIL_ATRIBUICOES']]++;
$abas = ['geral' => ['bi-grid-1x2', $T('aba.geral', ['pt' => 'Visão geral', 'en' => 'Overview', 'es' => 'Visión general'])],
    'legal' => ['bi-journal-check', $T('aba.legal', ['pt' => 'Atendimento legal', 'en' => 'Legal compliance', 'es' => 'Cumplimiento legal'])],
    'licencas' => ['bi-file-earmark-text', $T('aba.licencas', ['pt' => 'Licenças e entregas', 'en' => 'Licenses and submissions', 'es' => 'Licencias y entregas'])],
    'equipe' => ['bi-people', $T('aba.equipe', ['pt' => 'Pendências e equipe', 'en' => 'Pending items and team', 'es' => 'Pendientes y equipo'])]];
// Migração 366: Não conformidades e situação da homologação (se as views existem nesta empresa)
$temNc366 = $sel('vw_dash_nc_resumo', [], 1) !== null;
$temFo366 = $sel('vw_dash_fornecedor_resumo', [], 1) !== null;
if ($temNc366) $abas['nc'] = ['bi-exclamation-diamond', $T('aba.nc', ['pt' => 'Não conformidades', 'en' => 'Nonconformities', 'es' => 'No conformidades'])];
if (($sel('vw_fornecedor_ranking_config') ?? null) !== null || $temFo366) $abas['fornecedores'] = ['bi-trophy', $T('aba.fornecedores', ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'])];
if ($administrador) $abas['admin'] = ['bi-gear', $T('aba.admin', ['pt' => 'Administração do sistema', 'en' => 'System administration', 'es' => 'Administración del sistema'])];
$perfilRotulo = $administrador ? $T('admin.perfil', ['pt' => 'ADMINISTRADOR', 'en' => 'ADMINISTRATOR', 'es' => 'ADMINISTRADOR']) : $T('admin.perfil_adm', ['pt' => 'ADM DA EMPRESA', 'en' => 'COMPANY ADMIN', 'es' => 'ADM DE LA EMPRESA']);
?>
<!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($T('admin.titulo', ['pt' => 'Dashboard da empresa', 'en' => 'Company dashboard', 'es' => 'Panel de la empresa'])) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=3"><link rel="stylesheet" href="../assets/css/main.css?v=15">
<?php DashboardGrid::head(); ?><link rel="stylesheet" href="../assets/css/dashboard-abas.css?v=4"></head>
<body><main class="admin-dashboard" data-dash-fonte="dashboard_admin.php" data-dash-chave="greenportal.dashboard.aba" data-dash-textos="<?= $e(json_encode([
    'relatorio' => $T('legal.relatorio_titulo', ['pt' => 'Relatório de Atendimento Legal', 'en' => 'Legal Compliance Report', 'es' => 'Informe de Cumplimiento Legal']),
    'erro' => $T('legal.relatorio_erro', ['pt' => 'Não foi possível solicitar o relatório.', 'en' => 'The report could not be requested.', 'es' => 'No fue posible solicitar el informe.']),
    'erro_aba' => $T('erro_aba', ['pt' => 'Não foi possível carregar os indicadores desta aba.', 'en' => 'The indicators of this tab could not be loaded.', 'es' => 'No fue posible cargar los indicadores de esta pestaña.']),
    'situacao' => $T('legal.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'filtro' => $T('filtro', ['pt' => 'Mostrando', 'en' => 'Showing', 'es' => 'Mostrando']),
    'limpar_filtro' => $T('limpar_filtro', ['pt' => 'Mostrar como antes', 'en' => 'Show as before', 'es' => 'Mostrar como antes']),
], JSON_UNESCAPED_UNICODE)) ?>">
<header class="admin-head"><div><h2><i class="bi bi-speedometer2 me-2"></i><?= $e($T('admin.titulo', ['pt' => 'Dashboard da empresa', 'en' => 'Company dashboard', 'es' => 'Panel de la empresa'])) ?></h2><small><?= $e($T('admin.situacao_geral', ['pt' => 'Situação geral da empresa', 'en' => 'Overall status of company', 'es' => 'Situación general de la empresa'])) ?> <?= $e($companyName) ?></small></div><span class="admin-badge"><i class="bi bi-person-check me-1"></i><?= $e($perfilRotulo) ?></span></header>
<ul class="nav dash-abas" role="tablist">
<?php foreach ($abas as $id => [$ic, $rot]): ?><li class="nav-item" role="presentation"><button class="nav-link<?= $id === 'geral' ? ' active' : '' ?>" type="button" role="tab" data-aba="<?= $id ?>"><i class="bi <?= $ic ?> me-1"></i><?= $e($rot) ?></button></li><?php endforeach; ?>
</ul>
<section data-painel="geral">
<?php if ($vg === null): ?><?= $semMigracao('285') ?><?php else: ?>
<div class="admin-cards">
<?= $cartao($e($T('card.usuarios', ['pt' => 'Usuários ativos', 'en' => 'Active users', 'es' => 'Usuarios activos'])), (int)$vg['USUARIOS_ATIVOS'], 'bi-people', 'green', '', 'equipe') ?>
<?= $cartao($e($T('card.unidades', ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades'])), (int)$vg['UNIDADES'], 'bi-buildings', 'blue', '', '', $abrir('administracao_unidades.php', [], $T('card.unidades', ['pt' => 'Unidades', 'en' => 'Units', 'es' => 'Unidades']))) ?>
<?= $cartao($e($T('card.perfis', ['pt' => 'Perfis ativos', 'en' => 'Active profiles', 'es' => 'Perfiles activos'])), (int)$vg['PERFIS_ATIVOS'], 'bi-person-badge', 'green', '', '', $abrir('seguranca_perfil.php', [], $T('card.perfis', ['pt' => 'Perfis ativos', 'en' => 'Active profiles', 'es' => 'Perfiles activos']))) ?>
<?= $cartao($e($T('card.permissoes', ['pt' => 'Permissões cadastradas', 'en' => 'Registered permissions', 'es' => 'Permisos registrados'])), (int)$vg['PERMISSOES'], 'bi-shield-lock', 'purple', '', '', $abrir('seguranca_permissoes.php', [], $T('card.permissoes', ['pt' => 'Permissões cadastradas', 'en' => 'Registered permissions', 'es' => 'Permisos registrados']))) ?>
<?= $cartao($e($T('card.requisitos', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales'])), (int)$vg['REQUISITOS'], 'bi-journal-text', 'blue', '', 'legal') ?>
<?= $cartao($e($T('card.licencas_atencao', ['pt' => 'Licenças em atenção', 'en' => 'Licenses needing attention', 'es' => 'Licencias en atención'])), (int)$vg['LICENCAS_ATENCAO'], 'bi-calendar-x', 'orange', '', 'licencas') ?>
<?= $cartao($e($T('card.jobs', ['pt' => 'Jobs pendentes', 'en' => 'Pending jobs', 'es' => 'Trabajos pendientes'])), (int)$vg['JOBS_PENDENTES'], 'bi-arrow-repeat', 'orange', '', '', $abrir('servicos.php', [], $T('card.jobs', ['pt' => 'Jobs pendentes', 'en' => 'Pending jobs', 'es' => 'Trabajos pendientes']))) ?>
<?= $cartao($e($T('card.alertas', ['pt' => 'Alertas não lidos', 'en' => 'Unread alerts', 'es' => 'Alertas no leídas'])), (int)$vg['ALERTAS_NAO_LIDOS'], 'bi-bell', 'red', '', '', $abrir('alertas.php', [], $T('card.alertas', ['pt' => 'Alertas não lidos', 'en' => 'Unread alerts', 'es' => 'Alertas no leídas']))) ?>
</div>
<?php endif; ?>
<div class="dash-resumos">
<article class="admin-panel dash-resumo"><h3><i class="bi bi-journal-check me-1"></i><?= $e($abas['legal'][1]) ?><a data-ir-aba="legal"><?= $e($T('resumo.ver', ['pt' => 'ver aba', 'en' => 'open tab', 'es' => 'ver pestaña'])) ?> <i class="bi bi-arrow-right"></i></a></h3>
<?php if ($calc): ?>
<div class="linha"><span><?= $e($T('resumo.calc_n', ['pt' => 'Não atendidos pelo cálculo', 'en' => 'Not compliant by calculation', 'es' => 'No atendidos por el cálculo'])) ?></span><b class="<?= ($calc['N'] ?? 0) ? 'status-danger' : 'status-ok' ?>"><?= (int)($calc['N'] ?? 0) ?></b></div>
<div class="linha"><span><?= $e($T('resumo.calc_pa', ['pt' => 'Parcialmente atendidos pelo cálculo', 'en' => 'Partially compliant by calculation', 'es' => 'Parcialmente atendidos por el cálculo'])) ?></span><b><?= (int)($calc['PA'] ?? 0) ?></b></div>
<div class="linha"><span><?= $e($T('resumo.calc_ev', ['pt' => 'Requisitos com evidências vinculadas', 'en' => 'Requirements with linked evidence', 'es' => 'Requisitos con evidencias vinculadas'])) ?></span><b><?= array_sum($calc) - (int)($calc['AI'] ?? 0) ?> / <?= array_sum($calc) ?></b></div>
<?php else: ?><div class="dash-nota"><?= $e($T('resumo.sem_calc', ['pt' => 'Situação calculada ainda não disponível (migração 284).', 'en' => 'Calculated status not available yet (migration 284).', 'es' => 'Situación calculada aún no disponible (migración 284).'])) ?></div><?php endif; ?>
</article>
<article class="admin-panel dash-resumo"><h3><i class="bi bi-file-earmark-text me-1"></i><?= $e($abas['licencas'][1]) ?><a data-ir-aba="licencas"><?= $e($T('resumo.ver', ['pt' => 'ver aba', 'en' => 'open tab', 'es' => 'ver pestaña'])) ?> <i class="bi bi-arrow-right"></i></a></h3>
<div class="linha"><span><?= $e($T('fx.vencida', ['pt' => 'Vencidas sem protocolo', 'en' => 'Expired without protocol', 'es' => 'Vencidas sin protocolo'])) ?></span><b class="<?= $licResumo['VENCIDA'] ? 'status-danger' : 'status-ok' ?>"><?= $licResumo['VENCIDA'] ?></b></div>
<div class="linha"><span><?= $e($T('fx.30', ['pt' => 'Vencem em até 30 dias', 'en' => 'Expire within 30 days', 'es' => 'Vencen en hasta 30 días'])) ?></span><b class="<?= $licResumo['VENCE_30'] ? 'status-warning' : 'status-ok' ?>"><?= $licResumo['VENCE_30'] ?></b></div>
<div class="linha"><span><?= $e($T('fx.ent_atr', ['pt' => 'Entregas periódicas atrasadas', 'en' => 'Overdue periodic submissions', 'es' => 'Entregas periódicas atrasadas'])) ?></span><b class="<?= $licResumo['ENTREGA_ATRASADA'] ? 'status-danger' : 'status-ok' ?>"><?= $licResumo['ENTREGA_ATRASADA'] ?></b></div>
</article>
<article class="admin-panel dash-resumo"><h3><i class="bi bi-people me-1"></i><?= $e($abas['equipe'][1]) ?><a data-ir-aba="equipe"><?= $e($T('resumo.ver', ['pt' => 'ver aba', 'en' => 'open tab', 'es' => 'ver pestaña'])) ?> <i class="bi bi-arrow-right"></i></a></h3>
<div class="linha"><span><?= $e($T('resumo.pa_pend', ['pt' => 'Perfis de atribuições aguardando aprovação', 'en' => 'Duties profiles awaiting approval', 'es' => 'Perfiles de atribuciones esperando aprobación'])) ?></span><?= $eqResumo['PENDENTE_APROVACAO'] ? '<a class="dash-num status-warning"' . $abrir('perfil_atribuicoes_gestao.php', [], $T('eq.gestao', ['pt' => 'Perfis de atribuições da equipe', 'en' => 'Team duties profiles', 'es' => 'Perfiles de atribuciones del equipo'])) . '>' . $eqResumo['PENDENTE_APROVACAO'] . '</a>' : '<b class="status-ok">0</b>' ?></div>
<div class="linha"><span><?= $e($T('resumo.pa_dev', ['pt' => 'Perfis devolvidos para correção', 'en' => 'Profiles returned for correction', 'es' => 'Perfiles devueltos para corrección'])) ?></span><b class="<?= $eqResumo['DEVOLVIDO'] ? 'status-danger' : 'status-ok' ?>"><?= $eqResumo['DEVOLVIDO'] ?></b></div>
<div class="linha"><span><?= $e($T('resumo.pa_sem', ['pt' => 'Pessoas sem perfil preenchido', 'en' => 'People without a filled-in profile', 'es' => 'Personas sin perfil completado'])) ?></span><b><?= $eqResumo['SEM_ENVIO'] ?></b></div>
</article>
<?= (new DashboardNcFornecedores($pdo))->resumosEmpresa($temNc366, $temFo366) ?>
</div>
</section>
<?php foreach (array_keys($abas) as $id): if ($id === 'geral') continue; ?><section data-painel="<?= $id ?>" hidden><div class="dash-carregando"><span class="spinner-border spinner-border-sm me-2"></span><?= $e($T('carregando', ['pt' => 'Carregando indicadores...', 'en' => 'Loading indicators...', 'es' => 'Cargando indicadores...'])) ?></div></section><?php endforeach; ?>
</main>
<?php DashboardGrid::scripts(); ?>
<script src="../assets/js/dashboard-abas.js?v=6"></script>
</body></html>
